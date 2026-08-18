<?php
/**
 * Onboarding REST endpoints.
 *
 * Routes (under `suredonation/v1`):
 *  - GET    /onboarding/get-status        — return { completed: 'yes'|'no' }
 *  - POST   /onboarding/set-status        — write completion + optional analytics
 *  - POST   /onboarding/create-campaign   — create a published suredonation_cmpgn
 *  - POST   /onboarding/user-details      — persist lead capture (free-only step)
 *  - POST   /onboarding/set-tour-seen     — mark the campaign guided tour seen (per-user meta)
 *  - POST   /onboarding/set-tour-progress — persist the tour's resume step (per-user meta)
 *  - POST   /onboarding/track-tour        — fire the one-time "tour shown" analytics event
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\API;

use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Onboarding;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Onboarding REST API endpoints.
 *
 * @since 1.0.0
 */
class Onboarding_API {
	/**
	 * Allowed goal types for the create-campaign endpoint.
	 *
	 * @since 1.0.0
	 * @var array<int,string>
	 */
	private const GOAL_TYPES = [ 'raised_amount', 'donation_count' ];

	/**
	 * User-meta key for the "campaign guided tour seen" flag.
	 *
	 * Stored per-user (not a site option) so the "seen once" state follows the
	 * user across devices, unlike the onboarding completion flag.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const TOUR_SEEN_META = 'suredonation_campaign_tour_seen';

	/**
	 * User-meta key for the campaign guided tour's resume point.
	 *
	 * Holds the step key the tour should resume at (empty string = no saved
	 * progress). Persisted per-user so an interactive, multi-surface run
	 * survives a page reload or a trip to the form builder / page editor.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const TOUR_PROGRESS_META = 'suredonation_campaign_tour_progress';

	/**
	 * User-meta key arming the first-run tour for a specific campaign.
	 *
	 * Holds the campaign id whose detail page should auto-start the guided tour
	 * the first time it is opened. The onboarding wizard sets this because it
	 * creates a campaign and then exits via a full page reload to the dashboard
	 * / payments screen — so the in-memory `justCreated` navigation flag the SPA
	 * flow relies on never reaches the campaign page. Cleared once the tour is
	 * seen. Absent / 0 = nothing pending.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const TOUR_PENDING_META = 'suredonation_campaign_tour_pending';

	/**
	 * Return endpoint definitions for Rest_Api to register.
	 *
	 * @return array<string,mixed>
	 * @since 1.0.0
	 */
	public function get_endpoints() {
		return [
			'/onboarding/get-status'        => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/onboarding/set-status'        => [
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'set_status' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/onboarding/create-campaign'   => [
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'create_campaign' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/onboarding/user-details'      => [
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'save_user_details' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/onboarding/set-tour-seen'     => [
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'set_tour_seen' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/onboarding/set-tour-progress' => [
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'set_tour_progress' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/onboarding/track-tour'        => [
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'track_tour' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					// Omitted => "tour shown", the endpoint's original meaning.
					'event' => [
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'validate_callback' => static function ( $value ) {
							return in_array(
								$value,
								[ '', 'shown', 'completed', 'dismissed', 'opted_out', 'manual_started' ],
								true
							);
						},
					],
					// Free-form step key, only meaningful for `dismissed`. It is
					// sent on to analytics, so keep it to a bounded slug.
					'step'  => [
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					],
				],
			],
		];
	}

	/**
	 * Permission gate. Write requests (POST/PUT/PATCH/DELETE) additionally
	 * require a valid wp_rest nonce, matching Donors_API — onboarding forwards a
	 * lead to the BSF CRM, so the write boundary is pinned explicitly.
	 *
	 * @param \WP_REST_Request<array<string,mixed>>|null $request Current request.
	 * @return bool|\WP_Error
	 * @since 1.0.0
	 */
	public function check_permissions( $request = null ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( $request instanceof \WP_REST_Request ) {
			$method = strtoupper( $request->get_method() );
			if ( in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
				$nonce = $request->get_header( 'X-WP-Nonce' );
				if ( empty( $nonce ) ) {
					$nonce_param = $request->get_param( '_wpnonce' );
					$nonce       = is_string( $nonce_param ) ? $nonce_param : '';
				}
				if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
					return new \WP_Error(
						'rest_forbidden',
						__( 'Invalid or missing nonce.', 'suredonation' ),
						[ 'status' => 403 ]
					);
				}
			}
		}

		return true;
	}

	/**
	 * GET /onboarding/get-status.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function get_status() {
		return new WP_REST_Response(
			[
				'completed' => Onboarding::get_instance()->is_completed() ? 'yes' : 'no',
			]
		);
	}

	/**
	 * POST /onboarding/set-status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function set_status( $request ) {
		$completed = $request->get_param( 'completed' );
		Onboarding::get_instance()->set_completed( 'yes' === $completed ? 'yes' : 'no' );

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * POST /onboarding/create-campaign.
	 *
	 * Creates a published campaign post + writes its meta. Returns the new
	 * campaign id + edit URL so the JS can persist it in onboarding state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 * @since 1.0.0
	 */
	public function create_campaign( $request ) {
		$name        = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$goal_type   = (string) $request->get_param( 'goal_type' );
		$description = wp_kses_post( (string) $request->get_param( 'description' ) );

		// Clamp to a non-negative, finite, sane range. The JS already
		// validates this, but the endpoint is callable directly by any
		// manage_options user and shouldn't trust client-side bounds.
		$goal_amount = (float) $request->get_param( 'goal_amount' );
		if ( ! is_finite( $goal_amount ) || $goal_amount < 0 ) {
			$goal_amount = 0.0;
		}
		// Cap at 1e9 so a stray "1e308" can't poison campaign meta.
		$goal_amount = min( $goal_amount, 1000000000.0 );

		if ( '' === trim( $name ) ) {
			return new WP_Error(
				'suredonation_campaign_name_required',
				__( 'Campaign name is required.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! in_array( $goal_type, self::GOAL_TYPES, true ) ) {
			$goal_type = 'raised_amount';
		}

		// Publish the campaign so it behaves like one created via the normal
		// flow: the save_post_suredonation_cmpgn hook auto-creates its default
		// donation form, and the campaign becomes selectable in the Donation
		// Form block (whose query is limited to published campaigns).
		$result = wp_insert_post(
			[
				'post_type'    => Campaign_Cpt::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_excerpt' => $description,
				'post_author'  => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'suredonation_campaign_create_failed',
				$result->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		$campaign_id = (int) $result;

		if ( $campaign_id <= 0 ) {
			return new WP_Error(
				'suredonation_campaign_create_failed',
				__( 'Could not create the campaign.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		Helper::update_campaign_meta(
			$campaign_id,
			[
				'goal_type'   => $goal_type,
				'goal_amount' => $goal_amount,
			]
		);

		// Arm the first-run tour for this campaign: the wizard leaves via a full
		// page reload, so the SPA's transient `justCreated` flag never reaches the
		// campaign page. The pending pointer makes the tour fire the first time the
		// user opens this campaign's detail page instead.
		update_user_meta( get_current_user_id(), self::TOUR_PENDING_META, $campaign_id );

		return new WP_REST_Response(
			[
				'success'     => true,
				'campaign_id' => $campaign_id,
				'edit_url'    => admin_url( 'admin.php?page=suredonation#/campaigns/' . $campaign_id ),
			]
		);
	}

	/**
	 * POST /onboarding/user-details.
	 *
	 * Stores the lead-capture payload under suredonation_options so we
	 * don't re-prompt on subsequent setup passes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function save_user_details( $request ) {
		$onboarding = Onboarding::get_instance();

		$payload = [
			'first_name' => sanitize_text_field( (string) $request->get_param( 'first_name' ) ),
			'last_name'  => sanitize_text_field( (string) $request->get_param( 'last_name' ) ),
			'email'      => sanitize_email( (string) $request->get_param( 'email' ) ),
			'opted_in'   => (bool) $request->get_param( 'opted_in' ),
		];

		$onboarding->set_user_details( $payload );

		update_site_option(
			'suredonation_usage_optin',
			$payload['opted_in'] ? 'yes' : 'no'
		);

		if ( ! $onboarding->is_lead_sent() && $this->forward_lead_to_crm( $payload ) ) {
			$onboarding->mark_lead_sent();
		}

		/**
		 * Fires after onboarding lead-capture details are persisted.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $payload Sanitised payload.
		 */
		do_action( 'suredonation_onboarding_user_details_saved', $payload );

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * POST /onboarding/set-tour-seen.
	 *
	 * Marks the campaign guided tour as seen for the current user so it does
	 * not reappear on future campaign creations. Written when the user either
	 * completes the tour or opts out via "Don't show again".
	 *
	 * @return WP_REST_Response
	 * @since 1.5.0
	 */
	public function set_tour_seen() {
		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::TOUR_SEEN_META, 'yes' );
		// The tour is finished with — drop any saved resume point and the
		// wizard's pending pointer so neither can re-trigger it.
		delete_user_meta( $user_id, self::TOUR_PROGRESS_META );
		delete_user_meta( $user_id, self::TOUR_PENDING_META );

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * POST /onboarding/set-tour-progress.
	 *
	 * Persists the step the guided tour should resume at (per-user), so an
	 * interactive run survives a reload or a trip to another screen. Stored as
	 * `"<campaign_id>:<step>"` so a run only resumes on the campaign it started
	 * on. An empty `step` (or missing campaign) clears the saved point.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 * @since 1.5.0
	 */
	public function set_tour_progress( $request ) {
		$user_id     = get_current_user_id();
		$step        = sanitize_key( (string) $request->get_param( 'step' ) );
		$campaign_id = absint( $request->get_param( 'campaign_id' ) );

		if ( '' === $step || $campaign_id <= 0 ) {
			delete_user_meta( $user_id, self::TOUR_PROGRESS_META );
		} else {
			update_user_meta( $user_id, self::TOUR_PROGRESS_META, $campaign_id . ':' . $step );
		}

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * POST /onboarding/track-tour.
	 *
	 * Signals a campaign guided-tour analytics moment. With no `event` param this
	 * means "the tour was shown", which is what the endpoint did originally and
	 * what an older script bundle still sends. An `event` param instead reports
	 * how a run ended (completed / dismissed / opted_out) or that a replay was
	 * started manually.
	 *
	 * The actions below fire on every call; the built-in analytics listener
	 * decides what to record and how often (the BSF events tracker dedups by
	 * event name), so repeat calls are cheap.
	 *
	 * @param \WP_REST_Request<array<string,mixed>>|null $request Request object. A
	 *              missing request is treated as the bare "shown" signal, so the
	 *              endpoint's original no-argument contract still holds.
	 * @return WP_REST_Response
	 * @since 1.5.0
	 */
	public function track_tour( $request = null ) {
		// Both params are read up front so the outcome branch below does not have
		// to re-establish that the request exists.
		$event = '';
		$step  = '';

		if ( $request instanceof WP_REST_Request ) {
			$event = Helper::get_string_value( $request->get_param( 'event' ) );
			$step  = Helper::get_string_value( $request->get_param( 'step' ) );
		}

		if ( '' === $event || 'shown' === $event ) {
			/**
			 * Fires whenever the campaign guided tour is shown (i.e. on every call
			 * to this endpoint). The built-in listener dedups recording per site;
			 * additional listeners run on each fire and must dedup themselves if
			 * they need once-only behavior.
			 *
			 * @since 1.5.0
			 */
			do_action( 'suredonation_campaign_tour_shown' );

			return new WP_REST_Response( [ 'success' => true ] );
		}

		/**
		 * Fires when a campaign guided-tour run ends, or when a manual replay
		 * starts.
		 *
		 * @param string $event The outcome: 'completed', 'dismissed',
		 *                      'opted_out' or 'manual_started'.
		 * @param string $step  Step key the run ended on; empty when not applicable.
		 * @since 1.5.0
		 */
		do_action( 'suredonation_campaign_tour_outcome', $event, $step );

		return new WP_REST_Response( [ 'success' => true ] );
	}

	/**
	 * Generate lead.
	 *
	 * @param array<string,mixed> $payload Sanitised lead-capture payload.
	 * @return bool True when the CRM accepted the lead, false otherwise.
	 * @since 1.1.2
	 */
	private function forward_lead_to_crm( array $payload ) {
		$email_raw = $payload['email'] ?? '';
		$email     = is_string( $email_raw ) ? sanitize_email( $email_raw ) : '';
		if ( empty( $email ) || ! is_email( $email ) ) {
			return false;
		}

		$url = 'https://metrics.brainstormforce.com/wp-json/bsf-metrics-server/v1/subscribe';

		if ( defined( 'SUREDONATION_METRICS_ENDPOINT' ) && is_string( SUREDONATION_METRICS_ENDPOINT ) ) {
			$url = SUREDONATION_METRICS_ENDPOINT;
		}

		/**
		 * Filters the endpoint.
		 *
		 * @since 1.1.2
		 *
		 * @param string              $url     Endpoint URL.
		 * @param array<string,mixed> $payload Lead payload being sent.
		 */
		$filtered = apply_filters( 'suredonation_metrics_subscribe_url', $url, $payload );
		$url      = is_string( $filtered ) ? $filtered : $url;

		if ( '' === $url ) {
			return false;
		}

		$first_name = isset( $payload['first_name'] ) && is_string( $payload['first_name'] ) ? $payload['first_name'] : '';
		$last_name  = isset( $payload['last_name'] ) && is_string( $payload['last_name'] ) ? $payload['last_name'] : '';
		$domain     = wp_parse_url( home_url(), PHP_URL_HOST );
		$domain     = is_string( $domain ) ? $domain : '';

		$body = wp_json_encode(
			[
				// Lowercase keys satisfy the current BSF Metrics REST args.
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'domain'     => $domain,
				'source'     => 'suredonation',
				// Legacy uppercase keys kept for backward compatibility.
				'EMAIL'      => $email,
				'FIRSTNAME'  => $first_name,
				'LASTNAME'   => $last_name,
				'DOMAIN'     => $domain,
			]
		);

		if ( false === $body ) {
			return false;
		}

		// `source` identifies the originating plugin on the shared CRM server.
		// wp_safe_remote_post with WP's default 5s timeout keeps a slow or
		// hung endpoint from stalling onboarding completion.
		$response = wp_safe_remote_post(
			$url,
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return in_array( $code, [ 200, 201, 204 ], true );
	}
}
