<?php
/**
 * Import & Export REST API endpoints.
 *
 * SureDonation own-data import/export (donations, donors, campaigns, settings).
 * Distinct from the GiveWP migration API — this reads/writes SureDonation's own
 * export format. Route handlers are filled in by later tasks; this scaffold
 * registers the routes and enforces the capability + nonce baseline.
 *
 * @package SureDonation
 * @since 1.3.0
 */

namespace SureDonation\Inc\API;

use SureDonation\Inc\Campaigns\Campaign_Cpt;
use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Import_Export\Config_IO;
use SureDonation\Inc\Import_Export\Csv_Exporter;
use SureDonation\Inc\Import_Export\Import\Column_Map;
use SureDonation\Inc\Import_Export\Import\Csv_File;
use SureDonation\Inc\Import_Export\Import\Import_Runner;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import & Export API class.
 *
 * @since 1.3.0
 */
class Import_Export_API {

	/**
	 * Get Import & Export endpoints.
	 *
	 * Exports are READABLE (filters passed as query args, matching the existing
	 * `/donors/export` route); imports are EDITABLE (they upload and write).
	 *
	 * @return array<string, mixed>
	 * @since 1.3.0
	 */
	public function get_endpoints() {
		return [
			// --- Export (read) ---
			'/export/donations'                     => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export_donations' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/export/donors'                        => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export_donors' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/export/campaigns'                     => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export_campaigns' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/export/settings'                      => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],

			// --- Import (write) ---
			'/import/analyze'                       => [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_analyze' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/import/start'                         => [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_start' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/import/batch'                         => [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_batch' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/import/status/(?P<id>[A-Za-z0-9\-]+)' => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'import_status' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/import/campaigns'                     => [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_campaigns' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
			'/import/settings'                      => [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_settings' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],
		];
	}

	/**
	 * Capability + nonce gate for every route.
	 *
	 * Requires `manage_options`. For write methods, also verifies the
	 * `X-WP-Nonce` header (or `_wpnonce` param) against the `wp_rest` action.
	 * Mirrors the check used by the other SureDonation REST controllers.
	 *
	 * @param \WP_REST_Request|null $request Request object.
	 * @return bool|WP_Error True when allowed, WP_Error otherwise.
	 * @since 1.3.0
	 */
	public function check_permissions( $request = null ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to import or export data.', 'suredonation' ),
				[ 'status' => rest_authorization_required_code() ]
			);
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
					return new WP_Error(
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
	 * Export one-time donations to CSV.
	 *
	 * Reads optional filters (payment status, campaign, payment mode, gateway,
	 * and a created_at date range), fetches the matching one-time donations, and
	 * returns the CSV as a string for the client to download. Recurring and
	 * renewal rows are excluded — the free export is one-time only. Custom form
	 * field values in donation_data['fields'] are appended as trailing columns.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response with { success, csv, filename, truncated, total_count, exported }.
	 * @since 1.3.0
	 */
	public function export_donations( $request ) {
		$filters = [
			'status'       => sanitize_text_field( (string) $request->get_param( 'status' ) ),
			'campaign_id'  => absint( $request->get_param( 'campaign' ) ),
			'payment_mode' => sanitize_text_field( (string) $request->get_param( 'mode' ) ),
			'gateway'      => sanitize_text_field( (string) $request->get_param( 'gateway' ) ),
			'after'        => sanitize_text_field( (string) $request->get_param( 'after' ) ),
			'before'       => sanitize_text_field( (string) $request->get_param( 'before' ) ),
		];

		// Cap the payload at 10k rows (matches the donor export) and signal
		// truncation so an admin knows whether the file is complete.
		$export_cap  = 10000;
		$total_count = Donations::count_for_export( $filters );
		$truncated   = $total_count > $export_cap;
		$donations   = Donations::get_for_export( $filters, $export_cap, 0 );

		// First pass: collect the union of custom-field labels so every row
		// shares one consistent set of trailing columns.
		$field_labels = [];
		foreach ( $donations as $donation ) {
			foreach ( $this->get_donation_custom_fields( $donation ) as $label => $value ) {
				if ( ! in_array( $label, $field_labels, true ) ) {
					$field_labels[] = $label;
				}
			}
		}

		$rows   = [];
		$rows[] = array_merge(
			Column_Map::standard_donation_export_labels(),
			$field_labels
		);

		$title_cache = [];
		foreach ( $donations as $donation ) {
			$campaign_id = absint( Helper::get_string_value( $donation['campaign_id'] ?? 0 ) );
			$form_id     = absint( Helper::get_string_value( $donation['form_id'] ?? 0 ) );

			$row = [
				$donation['id'] ?? '',
				$campaign_id ? $campaign_id : '',
				$campaign_id ? $this->resolve_title( $campaign_id, $title_cache ) : '',
				$form_id ? $form_id : '',
				$form_id ? $this->resolve_title( $form_id, $title_cache ) : '',
				$donation['donor_id'] ?? '',
				$donation['donor_name'] ?? '',
				$donation['donor_email'] ?? '',
				$donation['donor_phone'] ?? '',
				$donation['amount'] ?? '',
				$donation['fees_covered'] ?? '',
				$donation['refunded_amount'] ?? '',
				$donation['currency'] ?? '',
				$donation['gateway'] ?? '',
				$donation['payment_status'] ?? '',
				$donation['payment_mode'] ?? '',
				$donation['transaction_id'] ?? '',
				$donation['donation_type'] ?? '',
				$donation['subscription_id'] ?? '',
				$donation['subscription_status'] ?? '',
				! empty( $donation['parent_subscription_id'] ) ? $donation['parent_subscription_id'] : '',
				// Untranslated on purpose: this is interchange data, not display
				// copy, and the importer's to_bool() matches English tokens. A
				// translated "Ja"/"Oui" here silently imported back as not
				// anonymous, so an export/import round trip un-masked every
				// anonymous donor on a localised site. Every other value in this
				// row is raw for the same reason.
				! empty( $donation['is_anonymous'] ) ? 'yes' : 'no',
				$donation['donor_comment'] ?? '',
				$donation['ip_address'] ?? '',
				$donation['created_at'] ?? '',
				$donation['import_source'] ?? '',
				! empty( $donation['import_source_id'] ) ? $donation['import_source_id'] : '',
			];

			$field_values = $this->get_donation_custom_fields( $donation );
			foreach ( $field_labels as $label ) {
				$row[] = $field_values[ $label ] ?? '';
			}

			$rows[] = $row;
		}

		return new WP_REST_Response(
			[
				'success'     => true,
				'csv'         => Csv_Exporter::build( $rows ),
				'filename'    => 'suredonation-donations-export-' . gmdate( 'Y-m-d' ) . '.csv',
				'truncated'   => $truncated,
				'total_count' => $total_count,
				'exported'    => count( $donations ),
			],
			200
		);
	}

	/**
	 * Extract a donation's submitted custom form-field values as a label => value map.
	 *
	 * @param array<string, mixed> $donation Decoded donation row.
	 * @return array<string, string> Custom-field values keyed by label.
	 * @since 1.3.0
	 */
	private function get_donation_custom_fields( $donation ) {
		$out    = [];
		$data   = ( isset( $donation['donation_data'] ) && is_array( $donation['donation_data'] ) )
			? $donation['donation_data']
			: [];
		$fields = ( isset( $data['fields'] ) && is_array( $data['fields'] ) )
			? $data['fields']
			: [];

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['label'] ) ) {
				continue;
			}
			$label = Helper::get_string_value( $field['label'] );
			if ( '' !== $label ) {
				$out[ $label ] = Helper::get_string_value( $field['value'] ?? '' );
			}
		}

		return $out;
	}

	/**
	 * Resolve and cache a post title (campaign or form) for the export.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $cache   Title cache, keyed by post id, by reference.
	 * @return string Post title.
	 * @since 1.3.0
	 */
	private function resolve_title( $post_id, &$cache ) {
		$key = (string) $post_id;
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = (string) get_the_title( $post_id );
		}
		return $cache[ $key ];
	}

	/**
	 * Export donors to CSV.
	 *
	 * Delegates to the existing donor export (Donors_API::export_donors_csv) so
	 * the CSV columns and query stay in one place — the Import & Export tab just
	 * exposes it under the unified /export namespace. Honors the same `search`
	 * and `campaign` query args.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error CSV payload or error.
	 * @since 1.3.0
	 */
	public function export_donors( $request ) {
		return ( new Donors_API() )->export_donors_csv( $request );
	}

	/**
	 * Export campaigns (with their linked forms) as JSON.
	 *
	 * Optional `ids` query arg (comma-separated) limits the export to specific
	 * campaigns; omit it to export all. The response carries the JSON object the
	 * client downloads as a .json file.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response with { success, data, filename, count }.
	 * @since 1.3.0
	 */
	public function export_campaigns( $request ) {
		$ids_param = $request->get_param( 'ids' );
		$ids       = [];
		if ( is_string( $ids_param ) && '' !== $ids_param ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $ids_param ) ) );
		} elseif ( is_array( $ids_param ) ) {
			$ids = array_filter( array_map( 'absint', $ids_param ) );
		}

		$campaigns = Config_IO::export_campaigns( $ids );

		return new WP_REST_Response(
			[
				'success'  => true,
				'data'     => [
					'type'      => 'suredonation-campaigns',
					'version'   => defined( 'SUREDONATION_VER' ) ? SUREDONATION_VER : '',
					'campaigns' => $campaigns,
				],
				'filename' => 'suredonation-campaigns-export-' . gmdate( 'Y-m-d' ) . '.json',
				'count'    => count( $campaigns ),
			],
			200
		);
	}

	/**
	 * Export SureDonation settings as JSON (credentials excluded).
	 *
	 * @return WP_REST_Response Response with { success, data, filename }.
	 * @since 1.3.0
	 */
	public function export_settings() {
		return new WP_REST_Response(
			[
				'success'  => true,
				'data'     => [
					'type'     => 'suredonation-settings',
					'version'  => defined( 'SUREDONATION_VER' ) ? SUREDONATION_VER : '',
					'settings' => Config_IO::export_settings(),
				],
				'filename' => 'suredonation-settings-export-' . gmdate( 'Y-m-d' ) . '.json',
			],
			200
		);
	}

	/**
	 * Analyze an uploaded CSV: store it, read the header + a sample, auto-map
	 * columns to fields, and detect the entity (donations vs donors).
	 *
	 * Returns a token the import start/batch calls reference so the same stored
	 * file is reused without re-uploading.
	 *
	 * @param \WP_REST_Request $request Request object (multipart with a `file`).
	 * @return WP_REST_Response|WP_Error Analysis payload or error.
	 * @since 1.3.0
	 */
	public function import_analyze( $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;

		if ( null === $file || empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'suredonation_no_file', __( 'No file was uploaded.', 'suredonation' ), [ 'status' => 400 ] );
		}

		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		if ( ! preg_match( '/\.csv$/i', $name ) ) {
			return new WP_Error( 'suredonation_invalid_file', __( 'Please upload a .csv file.', 'suredonation' ), [ 'status' => 400 ] );
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 || $size > 20 * MB_IN_BYTES ) {
			return new WP_Error( 'suredonation_invalid_size', __( 'The file is empty or larger than 20 MB.', 'suredonation' ), [ 'status' => 400 ] );
		}

		$token = Csv_File::store( (string) $file['tmp_name'] );
		if ( false === $token ) {
			return new WP_Error( 'suredonation_store_failed', __( 'Could not process the uploaded file.', 'suredonation' ), [ 'status' => 500 ] );
		}

		$headers = Csv_File::read_header( $token );
		if ( empty( $headers ) ) {
			Csv_File::delete( $token );
			return new WP_Error( 'suredonation_empty_file', __( 'The file has no header row.', 'suredonation' ), [ 'status' => 400 ] );
		}

		$entity = sanitize_text_field( (string) $request->get_param( 'entity' ) );
		if ( ! in_array( $entity, [ 'donations', 'donors' ], true ) ) {
			$entity = Column_Map::detect_entity( $headers );
		}

		$mapping = Column_Map::auto_map( $headers, $entity );

		$fields = [];
		foreach ( Column_Map::fields_for( $entity ) as $key => $def ) {
			$fields[] = [
				'field'    => $key,
				'label'    => $def['label'],
				'required' => ! empty( $def['required'] ),
			];
		}

		return new WP_REST_Response(
			[
				'success'    => true,
				'token'      => $token,
				'entity'     => $entity,
				'headers'    => $headers,
				'mapping'    => $mapping,
				'fields'     => $fields,
				'sample'     => Csv_File::read_sample( $token, 5 ),
				'total_rows' => Csv_File::count_rows( $token ),
			],
			200
		);
	}

	/**
	 * Start a CSV import session from a previously analyzed file.
	 *
	 * @param \WP_REST_Request $request Request with token, entity, mapping, options.
	 * @return WP_REST_Response|WP_Error Session info or error.
	 * @since 1.3.0
	 */
	public function import_start( $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( false === Csv_File::path_for( $token ) ) {
			return new WP_Error( 'suredonation_invalid_token', __( 'The uploaded file could not be found. Please upload it again.', 'suredonation' ), [ 'status' => 400 ] );
		}

		$entity = sanitize_text_field( (string) $request->get_param( 'entity' ) );
		if ( ! in_array( $entity, [ 'donations', 'donors' ], true ) ) {
			return new WP_Error( 'suredonation_invalid_entity', __( 'Invalid import type.', 'suredonation' ), [ 'status' => 400 ] );
		}

		// Columns are mapped server-side from the file's header row. A
		// SureDonation export auto-maps in full, so there is no manual mapping
		// step; a file whose required columns don't resolve is rejected.
		$headers = Csv_File::read_header( $token );
		$mapping = Column_Map::auto_map( $headers, $entity );

		$missing = Column_Map::missing_required( $mapping, $entity );
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'suredonation_unrecognized_csv',
				sprintf(
					/* translators: %s: comma-separated required column labels. */
					__( 'This does not look like a SureDonation export. Required columns were not found: %s. Please upload the CSV exported by SureDonation.', 'suredonation' ),
					implode( ', ', $missing )
				),
				[ 'status' => 400 ]
			);
		}

		$options = [
			'headers' => $headers,
			'dry_run' => (bool) $request->get_param( 'dry_run' ),
		];

		// Donations are imported into an explicitly chosen campaign.
		if ( 'donations' === $entity ) {
			$campaign_id = absint( $request->get_param( 'campaign_id' ) );
			$campaign    = $campaign_id ? get_post( $campaign_id ) : null;
			if ( ! $campaign instanceof \WP_Post || Campaign_Cpt::POST_TYPE !== $campaign->post_type ) {
				return new WP_Error( 'suredonation_invalid_campaign', __( 'Please choose a campaign to import the donations into.', 'suredonation' ), [ 'status' => 400 ] );
			}
			$options['campaign_id'] = $campaign_id;
		}

		$total_rows = Csv_File::count_rows( $token );
		$progress   = Import_Runner::create( $entity, $token, $mapping, $options, $total_rows );

		return new WP_REST_Response(
			[
				'success'    => true,
				'import_id'  => $progress['import_id'],
				'total_rows' => $total_rows,
				'batch_size' => Import_Runner::BATCH_SIZE,
			],
			200
		);
	}

	/**
	 * Process the next batch of an import session.
	 *
	 * @param \WP_REST_Request $request Request with import_id.
	 * @return WP_REST_Response|WP_Error Progress or error.
	 * @since 1.3.0
	 */
	public function import_batch( $request ) {
		$import_id = sanitize_text_field( (string) $request->get_param( 'import_id' ) );
		$session   = Import_Runner::get( $import_id );
		if ( false === $session ) {
			return new WP_Error( 'suredonation_invalid_import', __( 'Import session not found or already finished.', 'suredonation' ), [ 'status' => 400 ] );
		}
		if ( ! $this->user_owns_session( $session ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot modify an import started by another user.', 'suredonation' ), [ 'status' => rest_authorization_required_code() ] );
		}
		$progress = Import_Runner::run_batch( $import_id );
		if ( false === $progress ) {
			return new WP_Error( 'suredonation_invalid_import', __( 'Import session not found or already finished.', 'suredonation' ), [ 'status' => 400 ] );
		}
		return new WP_REST_Response( $this->import_progress_response( $progress ), 200 );
	}

	/**
	 * Whether the current user may act on an import session.
	 *
	 * All callers already hold `manage_options`; this additionally scopes a
	 * session to the admin who started it. Sessions with no recorded owner
	 * fall back to the capability gate.
	 *
	 * @param array<string, mixed> $progress Session payload.
	 * @return bool
	 * @since 1.3.0
	 */
	private function user_owns_session( $progress ) {
		$owner = Helper::get_integer_value( $progress['started_by'] ?? 0 );
		return 0 === $owner || get_current_user_id() === $owner;
	}

	/**
	 * Read an import session's current status.
	 *
	 * @param \WP_REST_Request $request Request with the id path param.
	 * @return WP_REST_Response|WP_Error Progress or error.
	 * @since 1.3.0
	 */
	public function import_status( $request ) {
		$import_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$progress  = Import_Runner::get( $import_id );
		if ( false === $progress ) {
			return new WP_Error( 'suredonation_invalid_import', __( 'Import session not found.', 'suredonation' ), [ 'status' => 404 ] );
		}
		if ( ! $this->user_owns_session( $progress ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot view an import started by another user.', 'suredonation' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return new WP_REST_Response( $this->import_progress_response( $progress ), 200 );
	}

	/**
	 * Shape an import session's progress for the client.
	 *
	 * @param array<string, mixed> $progress Session payload.
	 * @return array<string, mixed> Trimmed progress view.
	 * @since 1.3.0
	 */
	private function import_progress_response( $progress ) {
		$entity  = is_string( $progress['entity'] ?? null ) ? $progress['entity'] : '';
		$results = is_array( $progress['results'] ?? null ) ? $progress['results'] : [];
		$result  = is_array( $results[ $entity ] ?? null ) ? $results[ $entity ] : [];

		$processed = Helper::get_integer_value( $result['imported'] ?? 0 ) + Helper::get_integer_value( $result['skipped'] ?? 0 ) + Helper::get_integer_value( $result['errors'] ?? 0 );
		$total     = Helper::get_integer_value( $progress['total_rows'] ?? 0 );
		$status    = is_string( $progress['status'] ?? null ) ? $progress['status'] : '';

		$percentage = $total > 0 ? min( 100, (int) round( $processed / $total * 100 ) ) : 100;
		if ( 'complete' === $status ) {
			$percentage = 100;
		}

		return [
			'success'    => true,
			'import_id'  => is_string( $progress['import_id'] ?? null ) ? $progress['import_id'] : '',
			'status'     => $status,
			'entity'     => $entity,
			'total_rows' => $total,
			'processed'  => $processed,
			'percentage' => $percentage,
			'results'    => $result,
			'error'      => is_string( $progress['error'] ?? null ) ? $progress['error'] : '',
		];
	}

	/**
	 * Import campaigns (with their forms) from an exported JSON payload.
	 *
	 * @param \WP_REST_Request $request Request with a `data` object ({ campaigns: [...] }).
	 * @return WP_REST_Response|WP_Error Result or error.
	 * @since 1.3.0
	 */
	public function import_campaigns( $request ) {
		$data      = $request->get_param( 'data' );
		$campaigns = ( is_array( $data ) && is_array( $data['campaigns'] ?? null ) )
			? $data['campaigns']
			: [];

		if ( empty( $campaigns ) ) {
			return new WP_Error( 'suredonation_no_campaigns', __( 'No campaigns were found in the file.', 'suredonation' ), [ 'status' => 400 ] );
		}

		$result = Config_IO::import_campaigns( $campaigns );

		return new WP_REST_Response(
			[
				'success'   => true,
				'message'   => sprintf(
					/* translators: 1: campaign count, 2: form count. */
					__( 'Imported %1$d campaigns and %2$d forms.', 'suredonation' ),
					$result['campaigns'],
					$result['forms']
				),
				'campaigns' => $result['campaigns'],
				'forms'     => $result['forms'],
			],
			200
		);
	}

	/**
	 * Import settings from an exported JSON payload (Merge/Replace, secret-safe).
	 *
	 * @param \WP_REST_Request $request Request with a `data` object ({ settings: {...} }) and `mode`.
	 * @return WP_REST_Response|WP_Error Result or error.
	 * @since 1.3.0
	 */
	public function import_settings( $request ) {
		$data     = $request->get_param( 'data' );
		$settings = ( is_array( $data ) && is_array( $data['settings'] ?? null ) )
			? $data['settings']
			: [];
		$mode     = 'replace' === $request->get_param( 'mode' ) ? 'replace' : 'merge';

		if ( empty( $settings ) ) {
			return new WP_Error( 'suredonation_no_settings', __( 'No settings were found in the file.', 'suredonation' ), [ 'status' => 400 ] );
		}

		$result = Config_IO::import_settings( $settings, $mode );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Settings imported.', 'suredonation' ),
				'notice'  => __( 'Payment credentials were not imported. Reconnect your gateways under Settings → Payment Methods.', 'suredonation' ),
				'applied' => $result['applied'],
			],
			200
		);
	}
}
