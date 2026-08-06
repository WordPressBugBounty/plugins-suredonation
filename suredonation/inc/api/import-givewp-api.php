<?php
/**
 * REST API endpoints for the GiveWP migration tool.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\API;

use SureDonation\Inc\Import\Givewp\Csv_Parser;
use SureDonation\Inc\Import\Givewp\Importer;
use SureDonation\Inc\Import\Givewp\Session;
use SureDonation\Inc\Import\Givewp\Source;
use SureDonation\Inc\Traits\Get_Instance;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Import_Givewp_API class.
 *
 * Registers a small surface of routes under suredonation/v1/import/givewp/...
 * Each route is admin-only (manage_options).
 *
 * @since 1.0.0
 */
class Import_Givewp_API {
	use Get_Instance;

	/**
	 * Get endpoints to register with the central Rest_Api orchestrator.
	 *
	 * @return array<string,mixed>
	 * @since  1.0.0
	 */
	public function get_endpoints() {
		return [
			'/import/givewp/counts'  => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_counts' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
			],
			'/import/givewp/preview' => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_preview' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
			],
			'/import/givewp/start'   => [
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'start' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'campaign_ids'              => [
							'type'  => 'array',
							'items' => [ 'type' => 'integer' ],
						],
						'include_standalone_donors' => [ 'type' => 'boolean' ],
					],
				],
			],
			'/import/givewp/batch'   => [
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'run_batch' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'import_id' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			],
			'/import/givewp/csv'     => [
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'csv_upload' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
			],
		];
	}

	/**
	 * POST /import/givewp/csv
	 *
	 * Accepts a multipart file upload (field name `file`) containing a
	 * GiveWP CSV export, parses it via Csv_Parser, and returns aggregated
	 * results.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 * @since  1.0.0
	 */
	public function csv_upload( $request ) {
		unset( $request );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST permission_callback already enforces capability and X-WP-Nonce.
		if ( empty( $_FILES['file'] ) ) {
			return new WP_Error(
				'no_file',
				__( 'No file uploaded.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES handled by WP-native helpers below.
		$file = $_FILES['file'];

		// PHP-side upload error code (UPLOAD_ERR_OK = 0).
		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error(
				'upload_error',
				__( 'The upload did not complete successfully.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! isset( $file['tmp_name'] ) || '' === $file['tmp_name'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error(
				'invalid_upload',
				__( 'Invalid file upload.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		// Extension + MIME allowlist. The client-side picker enforces
		// `.csv`, but the REST route is also reachable directly with
		// any payload by an authenticated admin — keep the server-side
		// gate so a misclick (or a custom client) can't slip a non-CSV
		// blob into the parser.
		$name          = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext           = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$type          = isset( $file['type'] ) ? (string) $file['type'] : '';
		$allowed_mimes = [ 'text/csv', 'application/vnd.ms-excel', 'application/csv' ];
		if ( 'csv' !== $ext || ( '' !== $type && ! in_array( $type, $allowed_mimes, true ) ) ) {
			return new WP_Error(
				'invalid_extension',
				__( 'Only .csv files are supported.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		$results = Csv_Parser::get_instance()->parse_donations( $file['tmp_name'] );

		return rest_ensure_response(
			[
				'status'  => 'complete',
				'results' => [
					'donations' => $results,
				],
			]
		);
	}

	/**
	 * Capability check. Write requests (POST/PUT/PATCH/DELETE) additionally
	 * require a valid wp_rest nonce, matching Donors_API — these endpoints bulk
	 * import into custom tables, so the write boundary is pinned explicitly.
	 *
	 * @param \WP_REST_Request<array<string,mixed>>|null $request Current request.
	 * @return bool|\WP_Error
	 * @since  1.0.0
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
	 * GET /import/givewp/counts
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 * @since  1.0.0
	 */
	public function get_counts( $request ) {
		unset( $request );
		return rest_ensure_response( Importer::get_instance()->get_counts() );
	}

	/**
	 * GET /import/givewp/preview
	 *
	 * Per-form aggregate breakdown the UI shows in the two-step migration
	 * flow. The admin picks one or more campaigns from this list, then
	 * POST /start with the chosen `campaign_ids`.
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 * @since  1.0.0
	 */
	public function get_preview( $request ) {
		unset( $request );

		$source = Source::get_instance();
		if ( ! $source->has_givewp_data() ) {
			return new WP_Error(
				'no_givewp_data',
				__( 'No GiveWP data was found on this site — nothing to preview.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response( $source->get_campaigns_preview() );
	}

	/**
	 * POST /import/givewp/start
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 * @since  1.0.0
	 */
	public function start( $request ) {
		$importer = Importer::get_instance();
		$counts   = $importer->get_counts();

		if ( empty( $counts['has_data'] ) ) {
			return new WP_Error(
				'no_givewp_data',
				__( 'No GiveWP data was found on this site — nothing to migrate.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		$campaign_ids = $request->get_param( 'campaign_ids' );
		$campaign_ids = is_array( $campaign_ids )
			? array_values( array_unique( array_filter( array_map( 'absint', $campaign_ids ) ) ) )
			: [];

		if ( empty( $campaign_ids ) ) {
			return new WP_Error(
				'no_campaigns_selected',
				__( 'Select at least one campaign to migrate.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		$options = [
			'campaign_ids'              => $campaign_ids,
			'include_standalone_donors' => (bool) $request->get_param( 'include_standalone_donors' ),
		];

		$progress = Session::get_instance()->create( $options );

		if ( empty( $progress ) ) {
			return new WP_Error(
				'no_phases',
				__( 'No import phases were registered for this session.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response(
			[
				'import_id'   => isset( $progress['import_id'] ) ? (string) $progress['import_id'] : '',
				'phases'      => isset( $progress['phases'] ) ? $progress['phases'] : [],
				'total_items' => $this->estimate_total_items( $progress['phases'], $campaign_ids, $options['include_standalone_donors'] ),
				'counts'      => $counts['counts'],
			]
		);
	}

	/**
	 * POST /import/givewp/batch
	 *
	 * @param  WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 * @since  1.0.0
	 */
	public function run_batch( $request ) {
		$import_id = (string) $request->get_param( 'import_id' );
		$progress  = Importer::get_instance()->run_batch( $import_id );

		if ( false === $progress ) {
			return new WP_Error(
				'invalid_session',
				__( 'Import session not found or already completed.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response( $this->shape_progress_response( $progress ) );
	}

	/**
	 * Shape a session progress payload into the response body the UI consumes.
	 *
	 * @param  array $progress Session progress.
	 * @return array
	 * @since  1.0.0
	 */
	private function shape_progress_response( $progress ) {
		$phases        = isset( $progress['phases'] ) && is_array( $progress['phases'] ) ? $progress['phases'] : [];
		$current_index = isset( $progress['current_phase'] ) ? (int) $progress['current_phase'] : 0;
		$current_phase = isset( $phases[ $current_index ] ) ? (string) $phases[ $current_index ] : '';

		return [
			'import_id'     => isset( $progress['import_id'] ) ? (string) $progress['import_id'] : '',
			'status'        => isset( $progress['status'] ) ? (string) $progress['status'] : 'running',
			'phases'        => $phases,
			'current_phase' => $current_phase,
			'phase_index'   => $current_index,
			'offset'        => isset( $progress['offset'] ) ? (int) $progress['offset'] : 0,
			'results'       => isset( $progress['results'] ) ? $progress['results'] : [],
			'options'       => isset( $progress['options'] ) ? $progress['options'] : [],
			'started_at'    => isset( $progress['started_at'] ) ? (string) $progress['started_at'] : '',
			'completed_at'  => isset( $progress['completed_at'] ) ? (string) $progress['completed_at'] : '',
		];
	}

	/**
	 * Estimate total items the session will process for progress-bar maths.
	 *
	 * Sums per-form donations / donors / subscriptions across the selected
	 * campaigns (plus the standalone-donor count when that phase is opted
	 * in) from the preview aggregate query — the same numbers the UI just
	 * showed the admin, so the progress denominator matches what they
	 * expect.
	 *
	 * @param  array $phases                    Active phases for this session.
	 * @param  array $campaign_ids              Selected form IDs.
	 * @param  bool  $include_standalone_donors Whether the standalone-donors phase is opted in.
	 * @return int
	 * @since  1.0.0
	 */
	private function estimate_total_items( $phases, $campaign_ids, $include_standalone_donors ) {
		$preview          = Source::get_instance()->get_campaigns_preview();
		$campaigns        = isset( $preview['campaigns'] ) && is_array( $preview['campaigns'] ) ? $preview['campaigns'] : [];
		$selected_lookup  = array_flip( array_map( 'intval', $campaign_ids ) );

		$has_campaigns_phase     = in_array( 'campaigns', $phases, true );
		$has_donations_phase     = in_array( 'donations', $phases, true );
		$has_subscriptions_phase = in_array( 'subscriptions', $phases, true );

		$total = 0;
		foreach ( $campaigns as $row ) {
			$form_id = isset( $row['form_id'] ) ? (int) $row['form_id'] : 0;
			if ( ! isset( $selected_lookup[ $form_id ] ) ) {
				continue;
			}
			if ( $has_campaigns_phase ) {
				++$total;
			}
			if ( $has_donations_phase ) {
				$total += isset( $row['donations'] ) ? (int) $row['donations'] : 0;
			}
			if ( $has_subscriptions_phase ) {
				$total += isset( $row['subscriptions'] ) ? (int) $row['subscriptions'] : 0;
			}
		}

		if ( $include_standalone_donors && in_array( 'standalone_donors', $phases, true ) ) {
			$total += isset( $preview['standalone_donors'] ) ? (int) $preview['standalone_donors'] : 0;
		}

		return $total;
	}
}
