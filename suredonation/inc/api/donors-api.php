<?php
/**
 * Donors REST API endpoints.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\API;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Helper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donors API class.
 *
 * @since 1.0.0
 */
class Donors_API {
	/**
	 * Get donor endpoints.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function get_endpoints() {
		return [
			// Get donors list.
			'/donors'                       => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_donors' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'page'     => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
					'per_page' => [
						'default'           => 20,
						'sanitize_callback' => 'absint',
					],
					'search'   => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'campaign' => [
						'sanitize_callback' => 'absint',
					],
					'after'    => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'before'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'sort_by'  => [
						'default'           => 'created_at',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'order'    => [
						'default'           => 'desc',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],

			// Get donor aggregate stats.
			'/donors/stats'                 => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_donor_stats' ],
				'permission_callback' => [ $this, 'check_permissions' ],
			],

			// Export donors as CSV.
			'/donors/export'                => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export_donors_csv' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'search'   => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'campaign' => [
						'sanitize_callback' => 'absint',
					],
				],
			],

			// Bulk actions.
			'/donors/bulk'                  => [
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'bulk_action' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'action' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => [ 'delete', 'update_status' ],
					],
					'ids'    => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_array( $param ) && ! empty( $param );
						},
					],
					'status' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],

			// Get, update, delete single donor.
			'/donors/(?P<id>\d+)'           => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_donor' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'validate_callback' => static function ( $param ) {
								return is_numeric( $param );
							},
						],
					],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_donor' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'validate_callback' => static function ( $param ) {
								return is_numeric( $param );
							},
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_donor' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'validate_callback' => static function ( $param ) {
								return is_numeric( $param );
							},
						],
					],
				],
			],

			// Get donor's donation history.
			'/donors/(?P<id>\d+)/donations' => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_donor_donations' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id'       => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
					'page'     => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
					'per_page' => [
						'default'           => 10,
						'sanitize_callback' => 'absint',
					],
				],
			],

			// Get donor activity (chart data + stats).
			'/donors/(?P<id>\d+)/activity'  => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_donor_activity' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id'     => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
					'after'  => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'before' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],

			// Export single donor as CSV.
			'/donors/(?P<id>\d+)/export'    => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export_single_donor_csv' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			],
		];
	}

	/**
	 * Get donors list with filters, sorting, and pagination.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function get_donors( $request ) {
		$page        = $request->get_param( 'page' ) ?? 1;
		$per_page    = $request->get_param( 'per_page' ) ?? 20;
		$search      = $request->get_param( 'search' ) ?? '';
		$campaign_id = $request->get_param( 'campaign' ) ?? 0;
		$after       = $request->get_param( 'after' ) ?? '';
		$before      = $request->get_param( 'before' ) ?? '';
		$sort_by     = $request->get_param( 'sort_by' ) ?? 'created_at';
		$order       = $request->get_param( 'order' ) ?? 'desc';

		$limit  = absint( $per_page );
		$offset = ( absint( $page ) - 1 ) * $limit;

		$results = Donors::get_admin_list(
			sanitize_text_field( $search ),
			! empty( $campaign_id ) ? absint( $campaign_id ) : 0,
			'all',
			$limit,
			$offset,
			$sort_by,
			strtoupper( $order ),
			sanitize_text_field( $after ),
			sanitize_text_field( $before )
		);

		$total = Donors::get_total_donors_filtered(
			sanitize_text_field( $search ),
			! empty( $campaign_id ) ? absint( $campaign_id ) : 0,
			'all',
			sanitize_text_field( $after ),
			sanitize_text_field( $before )
		);

		$donors = [];
		foreach ( $results as $donor ) {
			if ( is_array( $donor ) ) {
				$donors[] = $this->format_donor( $donor );
			}
		}

		return new WP_REST_Response(
			[
				'donors'     => $donors,
				'pagination' => [
					'total'       => (int) $total,
					'total_pages' => (int) ceil( $total / $per_page ),
					'per_page'    => (int) $per_page,
					'current'     => (int) $page,
				],
			]
		);
	}

	/**
	 * Get aggregate donor stats.
	 *
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function get_donor_stats() {
		$stats = Donors::get_aggregate_stats();

		return new WP_REST_Response(
			[
				'success' => true,
				'stats'   => $stats,
			],
			200
		);
	}

	/**
	 * Get a single donor by ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.0.0
	 */
	public function get_donor( $request ) {
		$donor_id = absint( $request->get_param( 'id' ) );
		$donor    = Donors::get( $donor_id );

		if ( ! $donor ) {
			return new WP_Error(
				'donor_not_found',
				__( 'Donor not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'donor'   => $this->format_donor( $donor ),
			],
			200
		);
	}

	/**
	 * Update a donor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.0.0
	 */
	public function update_donor( $request ) {
		$donor_id = absint( $request->get_param( 'id' ) );
		$donor    = Donors::get( $donor_id );

		if ( ! $donor ) {
			return new WP_Error(
				'donor_not_found',
				__( 'Donor not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		$update_data = [];

		$name = $request->get_param( 'name' );
		if ( ! is_null( $name ) ) {
			$update_data['name'] = sanitize_text_field( $name );
		}

		$email = $request->get_param( 'email' );
		if ( ! is_null( $email ) ) {
			$sanitized_email = sanitize_email( $email );
			if ( empty( $sanitized_email ) ) {
				return new WP_Error(
					'invalid_email',
					__( 'Please provide a valid email address.', 'suredonation' ),
					[ 'status' => 400 ]
				);
			}
			$update_data['email'] = $sanitized_email;
		}

		$phone = $request->get_param( 'phone' );
		if ( ! is_null( $phone ) ) {
			$update_data['phone'] = sanitize_text_field( $phone );
		}

		$company = $request->get_param( 'company' );
		if ( ! is_null( $company ) ) {
			$update_data['company'] = sanitize_text_field( $company );
		}

		$address = $request->get_param( 'address' );
		if ( ! is_null( $address ) ) {
			$update_data['address'] = sanitize_textarea_field( $address );
		}

		$donor_status = $request->get_param( 'donor_status' );
		if ( ! is_null( $donor_status ) ) {
			if ( ! in_array( $donor_status, Donors::get_valid_statuses(), true ) ) {
				return new WP_Error(
					'invalid_status',
					__( 'Invalid donor status.', 'suredonation' ),
					[ 'status' => 400 ]
				);
			}
			$update_data['donor_status'] = $donor_status;
		}

		$donor_tags = $request->get_param( 'donor_tags' );
		if ( ! is_null( $donor_tags ) ) {
			$update_data['donor_tags'] = is_array( $donor_tags )
				? array_map( 'sanitize_text_field', $donor_tags )
				: [];
		}

		if ( ! empty( $update_data ) ) {
			Donors::update( $donor_id, $update_data );
		}

		$updated_donor = Donors::get( $donor_id );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Donor updated successfully.', 'suredonation' ),
				'donor'   => is_array( $updated_donor ) ? $this->format_donor( $updated_donor ) : [],
			],
			200
		);
	}

	/**
	 * Delete a donor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.0.0
	 */
	public function delete_donor( $request ) {
		$donor_id = absint( $request->get_param( 'id' ) );
		$result   = Donors::delete( $donor_id );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete donor.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Donor deleted successfully.', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Bulk action on donors.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.0.0
	 */
	public function bulk_action( $request ) {
		$action = $request->get_param( 'action' );
		$ids    = $request->get_param( 'ids' );

		if ( ! is_array( $ids ) ) {
			$ids = [];
		}

		// Cap bulk operations at 200 IDs per request. Each ID triggers a
		// per-row SELECT + DELETE / UPDATE — 100k IDs in one request would
		// chew through the database serially and time out the response.
		// 200 is enough headroom for any realistic admin UI selection;
		// larger jobs should be split client-side.
		if ( count( $ids ) > 200 ) {
			return new WP_Error(
				'too_many_items',
				__( 'Bulk actions are limited to 200 donors per request.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		$success_count = 0;
		$error_count   = 0;

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				++$error_count;
				continue;
			}

			switch ( $action ) {
				case 'delete':
					$result = Donors::delete( $id );
					break;
				case 'update_status':
					$status = $request->get_param( 'status' );
					if ( ! $status || ! in_array( $status, Donors::get_valid_statuses(), true ) ) {
						++$error_count;
						continue 2;
					}
					$result = Donors::update( $id, [ 'donor_status' => $status ] );
					break;
				default:
					$result = false;
					break;
			}

			if ( false !== $result ) {
				++$success_count;
			} else {
				++$error_count;
			}
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => sprintf(
					// translators: %1$d is the success count, %2$d is the error count.
					__( '%1$d donor(s) processed, %2$d error(s).', 'suredonation' ),
					$success_count,
					$error_count
				),
			],
			200
		);
	}

	/**
	 * Get paginated donation history for a donor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.0.0
	 */
	public function get_donor_donations( $request ) {
		$donor_id = absint( $request->get_param( 'id' ) );
		$page     = absint( $request->get_param( 'page' ) );
		$page     = $page > 0 ? $page : 1;
		$per_page = absint( $request->get_param( 'per_page' ) );
		$per_page = $per_page > 0 ? $per_page : 10;

		$donor = Donors::get( $donor_id );
		if ( ! $donor ) {
			return new WP_Error(
				'donor_not_found',
				__( 'Donor not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		$offset = ( $page - 1 ) * $per_page;
		$data   = Donations::get_by_donor_id( $donor_id, $per_page, $offset );

		$donations = [];
		foreach ( $data['donations'] as $donation ) {
			if ( is_array( $donation ) ) {
				$campaign_id = isset( $donation['campaign_id'] ) ? Helper::get_integer_value( $donation['campaign_id'] ) : 0;
				$donations[] = [
					'id'             => isset( $donation['id'] ) ? Helper::get_integer_value( $donation['id'] ) : 0,
					'campaign_id'    => $campaign_id,
					'campaign_title' => $campaign_id ? wp_kses_post( (string) get_the_title( $campaign_id ) ) : '',
					'amount'         => Helper::get_float_value( $donation['amount'] ?? 0 ),
					'currency'       => esc_html( Helper::get_string_value( $donation['currency'] ?? 'USD' ) ),
					'payment_status' => esc_html( Helper::get_string_value( $donation['payment_status'] ?? 'pending' ) ),
					'donation_type'  => esc_html( Helper::get_string_value( $donation['donation_type'] ?? 'one-time' ) ),
					'created_at'     => esc_html( Helper::get_string_value( $donation['created_at'] ?? '' ) ),
				];
			}
		}

		return new WP_REST_Response(
			[
				'success'    => true,
				'donations'  => $donations,
				'pagination' => [
					'total'       => $data['total'],
					'total_pages' => (int) ceil( $data['total'] / $per_page ),
					'per_page'    => $per_page,
					'current'     => $page,
				],
			],
			200
		);
	}

	/**
	 * Get donor activity data (chart + stats).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.0.0
	 */
	public function get_donor_activity( $request ) {
		$donor_id = absint( $request->get_param( 'id' ) );
		$after    = $request->get_param( 'after' ) ?? '';
		$before   = $request->get_param( 'before' ) ?? '';

		$donor = Donors::get( $donor_id );
		if ( ! $donor ) {
			return new WP_Error(
				'donor_not_found',
				__( 'Donor not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		$activity = Donations::get_donor_activity( $donor_id, sanitize_text_field( $after ), sanitize_text_field( $before ) );

		return new WP_REST_Response(
			[
				'success'    => true,
				'chart_data' => $activity['chart_data'],
				'stats'      => $activity['stats'],
			],
			200
		);
	}

	/**
	 * Export donors as CSV.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.0.0
	 */
	public function export_donors_csv( $request ) {
		$search      = $request->get_param( 'search' ) ?? '';
		$campaign_id = $request->get_param( 'campaign' ) ?? 0;

		// Hard cap at 10k rows per export. Total matching count is computed
		// up-front so the response can signal truncation — admins acting on
		// the exported file shouldn't have to guess whether it was complete.
		$export_cap  = 10000;
		$total_count = Donors::get_total_donors_filtered(
			sanitize_text_field( $search ),
			! empty( $campaign_id ) ? absint( $campaign_id ) : 0,
			'all'
		);
		$truncated   = $total_count > $export_cap;

		// Get all matching donors (no pagination limit for export).
		$donors = Donors::get_admin_list(
			sanitize_text_field( $search ),
			! empty( $campaign_id ) ? absint( $campaign_id ) : 0,
			'all',
			$export_cap,
			0,
			'created_at',
			'DESC'
		);

		$csv_lines   = [];
		$csv_lines[] = [
			__( 'ID', 'suredonation' ),
			__( 'Name', 'suredonation' ),
			__( 'Email', 'suredonation' ),
			__( 'Phone', 'suredonation' ),
			__( 'Company', 'suredonation' ),
			__( 'Address', 'suredonation' ),
			__( 'Status', 'suredonation' ),
			__( 'Total Donated', 'suredonation' ),
			__( 'Donation Count', 'suredonation' ),
			__( 'Largest Donation', 'suredonation' ),
			__( 'First Donation', 'suredonation' ),
			__( 'Last Donation', 'suredonation' ),
			__( 'Created At', 'suredonation' ),
		];

		foreach ( $donors as $donor ) {
			if ( ! is_array( $donor ) ) {
				continue;
			}
			$csv_lines[] = [
				$donor['id'] ?? '',
				$this->sanitize_csv_value( $donor['name'] ?? '' ),
				$this->sanitize_csv_value( $donor['email'] ?? '' ),
				$this->sanitize_csv_value( $donor['phone'] ?? '' ),
				$this->sanitize_csv_value( $donor['company'] ?? '' ),
				$this->sanitize_csv_value( $donor['address'] ?? '' ),
				$this->sanitize_csv_value( $donor['donor_status'] ?? '' ),
				$donor['total_donated'] ?? 0,
				$donor['donation_count'] ?? 0,
				$donor['largest_donation'] ?? 0,
				$donor['first_donation_date'] ?? '',
				$donor['last_donation_date'] ?? '',
				$donor['created_at'] ?? '',
			];
		}

		// Build CSV string using php://temp (in-memory stream, not filesystem).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing to in-memory stream, not filesystem.
		$output = fopen( 'php://temp', 'r+' );
		if ( false === $output ) {
			return new WP_Error(
				'export_failed',
				__( 'Failed to generate CSV.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		foreach ( $csv_lines as $line ) {
			fputcsv( $output, $line );
		}

		rewind( $output );
		$csv_content = stream_get_contents( $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing in-memory stream.
		fclose( $output );

		return new WP_REST_Response(
			[
				'success'     => true,
				'csv'         => $csv_content,
				'filename'    => 'suredonation-donors-export-' . gmdate( 'Y-m-d' ) . '.csv',
				'truncated'   => $truncated,
				'total_count' => $total_count,
				'exported'    => count( $donors ),
			],
			200
		);
	}

	/**
	 * Export a single donor's donation history as CSV.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 1.0.0
	 */
	public function export_single_donor_csv( $request ) {
		$donor_id = absint( $request->get_param( 'id' ) );
		$donor    = Donors::get( $donor_id );

		if ( ! $donor ) {
			return new WP_Error(
				'donor_not_found',
				__( 'Donor not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		$formatted = $this->format_donor( $donor );

		// Get all donations for this donor.
		$donations_data = Donations::get_by_donor_id( $donor_id, 10000, 0 );

		// Build CSV using php://temp (in-memory stream, not filesystem).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing to in-memory stream, not filesystem.
		$output = fopen( 'php://temp', 'r+' );
		if ( false === $output ) {
			return new WP_Error(
				'export_failed',
				__( 'Failed to generate CSV.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		// Header row.
		fputcsv(
			$output,
			[
				__( 'ID', 'suredonation' ),
				__( 'Campaign', 'suredonation' ),
				__( 'Amount', 'suredonation' ),
				__( 'Currency', 'suredonation' ),
				__( 'Status', 'suredonation' ),
				__( 'Type', 'suredonation' ),
				__( 'Payment Method', 'suredonation' ),
				__( 'Date', 'suredonation' ),
			]
		);

		foreach ( $donations_data['donations'] as $donation ) {
			if ( ! is_array( $donation ) ) {
				continue;
			}
			$campaign_id    = isset( $donation['campaign_id'] ) ? Helper::get_integer_value( $donation['campaign_id'] ) : 0;
			$gateway        = Helper::get_string_value( $donation['gateway'] ?? '' );
			$campaign_title = $campaign_id ? wp_strip_all_tags( (string) get_the_title( $campaign_id ) ) : '';

			// @phpstan-var array<int, string|int|float> $row
			$row = [
				Helper::get_string_value( $donation['id'] ?? '' ),
				$this->sanitize_csv_value( $campaign_title ),
				Helper::get_float_value( $donation['amount'] ?? 0 ),
				Helper::get_string_value( $donation['currency'] ?? 'USD' ),
				Helper::get_string_value( $donation['payment_status'] ?? '' ),
				Helper::get_string_value( $donation['donation_type'] ?? 'one-time' ),
				$this->sanitize_csv_value( ucfirst( $gateway ) ),
				Helper::get_string_value( $donation['created_at'] ?? '' ),
			];
			fputcsv( $output, $row );
		}

		rewind( $output );
		$csv_content = stream_get_contents( $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing in-memory stream.
		fclose( $output );

		$donor_name = Helper::get_string_value( $formatted['name'] ?? '' );
		$slug       = sanitize_title( ! empty( $donor_name ) ? $donor_name : 'donor-' . $donor_id );

		return new WP_REST_Response(
			[
				'success'  => true,
				'csv'      => $csv_content,
				'filename' => 'donor-' . $slug . '-' . gmdate( 'Y-m-d' ) . '.csv',
			],
			200
		);
	}

	/**
	 * Check if user has permission to manage donors.
	 *
	 * For state-changing methods (POST/PUT/PATCH/DELETE), also verifies the
	 * WP REST nonce so a logged-in admin's session can't be CSRF'd by a
	 * cross-site fetch into bulk-deleting donors or editing PII. Default WP
	 * REST cookie-auth doesn't enforce nonces — it only uses them to
	 * authenticate, not to gate writes — so the explicit check belongs here.
	 *
	 * @param \WP_REST_Request|null $request REST request (passed by
	 *                                       permission_callback).
	 * @return bool|\WP_Error True if user has permission.
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
	 * Sanitize a value for safe CSV output.
	 *
	 * Prevents CSV injection by prefixing formula-triggering characters
	 * with a single quote, which neutralizes them in spreadsheet applications.
	 *
	 * @param string $value The value to sanitize.
	 * @return string Sanitized value.
	 * @since 1.0.0
	 */
	private function sanitize_csv_value( string $value ) {
		// Strip leading whitespace before inspecting the first character —
		// Excel still interprets "  =cmd|..." as a formula even with leading
		// spaces. Also catches `|` and `%` which trigger DDE in some Excel
		// locales beyond the standard formula-prefix set.
		$stripped = ltrim( $value );
		if ( '' !== $stripped && in_array( $stripped[0], [ '=', '+', '-', '@', '|', '%', "\t", "\r" ], true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Format donor data for API response.
	 *
	 * @param array<string, mixed> $donor Donor data from database.
	 * @return array<string, mixed> Formatted donor data.
	 * @since 1.0.0
	 */
	private function format_donor( $donor ) {
		// Return raw (validated/sanitized at storage) strings in the JSON
		// payload. React JSX renders text nodes with built-in escaping, so
		// server-side esc_html() would double-escape — a donor named
		// "Smith & Co" would display as "Smith &amp; Co". esc_html is still
		// the right tool for HTML-only output paths (admin notices,
		// server-rendered templates), just not JSON-bound REST responses.
		return [
			'id'                  => isset( $donor['id'] ) ? Helper::get_integer_value( $donor['id'] ) : 0,
			'email'               => sanitize_email( Helper::get_string_value( $donor['email'] ?? '' ) ),
			'name'                => Helper::get_string_value( $donor['name'] ?? '' ),
			'phone'               => Helper::get_string_value( $donor['phone'] ?? '' ),
			'company'             => Helper::get_string_value( $donor['company'] ?? '' ),
			'address'             => Helper::get_string_value( $donor['address'] ?? '' ),
			'user_id'             => isset( $donor['user_id'] ) ? Helper::get_integer_value( $donor['user_id'] ) : 0,
			'total_donated'       => Helper::get_float_value( $donor['total_donated'] ?? 0 ),
			'donation_count'      => isset( $donor['donation_count'] ) ? Helper::get_integer_value( $donor['donation_count'] ) : 0,
			'largest_donation'    => Helper::get_float_value( $donor['largest_donation'] ?? 0 ),
			'first_donation_date' => Helper::get_string_value( $donor['first_donation_date'] ?? '' ),
			'last_donation_date'  => Helper::get_string_value( $donor['last_donation_date'] ?? '' ),
			'donor_tags'          => is_array( $donor['donor_tags'] ?? null ) ? array_values( array_filter( array_map( [ Helper::class, 'get_string_value' ], $donor['donor_tags'] ) ) ) : [],
			'donor_status'        => Helper::get_string_value( $donor['donor_status'] ?? 'active' ),
			'stripe_customer_id'  => Helper::get_string_value( $donor['stripe_customer_id'] ?? '' ),
			'created_at'          => Helper::get_string_value( $donor['created_at'] ?? '' ),
			'updated_at'          => Helper::get_string_value( $donor['updated_at'] ?? '' ),
		];
	}
}
