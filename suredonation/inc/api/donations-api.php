<?php
/**
 * Donations REST API endpoints.
 *
 * @package SureDonation
 */

namespace SureDonation\Inc\API;

use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Database\Tables\Donors;
use SureDonation\Inc\Emails\Email_Handler;
use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Payments\Stripe\Stripe_Helper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donations API class.
 *
 * @since 0.0.1
 */
class Donations_API {
	/**
	 * Get donation endpoints.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public function get_endpoints() {
		return [
			// Get donations list & create donation.
			'/donations'                      => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_donations' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'after'  => [
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => [ $this, 'validate_date_param' ],
						],
						'before' => [
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => [ $this, 'validate_date_param' ],
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_donation' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => $this->get_donation_args(),
				],
			],

			// Get, update, delete single donation.
			'/donations/(?P<id>\d+)'          => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_donation' ],
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
					'callback'            => [ $this, 'update_donation' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => array_merge(
						[
							'id' => [
								'required'          => true,
								'validate_callback' => static function ( $param ) {
									return is_numeric( $param );
								},
							],
						],
						$this->get_donation_args( false )
					),
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_donation' ],
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

			// Update donation status.
			'/donations/(?P<id>\d+)/status'   => [
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_donation_status' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id'     => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
					'status' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => [ 'pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled' ],
					],
				],
			],

			// Get donations by campaign.
			'/donations/campaign/(?P<id>\d+)' => [
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_campaign_donations' ],
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

			// Bulk actions.
			'/donations/bulk'                 => [
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
						'enum'              => [ 'pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled' ],
					],
				],
			],

			// Refund donation payment.
			'/donations/(?P<id>\d+)/refund'   => [
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'refund_donation' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id'             => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
					'transaction_id' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'refund_amount'  => [
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'refund_type'    => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => [ 'full', 'partial' ],
					],
					'refund_notes'   => [
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			],

			// Delete donation log entry.
			'/donations/(?P<id>\d+)/log/(?P<log_index>\d+)' => [
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_donation_log' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id'        => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
					'log_index' => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param ) && $param >= 0;
						},
					],
				],
			],

			// Get and add donation notes.
			'/donations/(?P<id>\d+)/notes'    => [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_donation_notes' ],
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
							'default'           => 3,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'add_donation_note' ],
					'permission_callback' => [ $this, 'check_permissions' ],
					'args'                => [
						'id'   => [
							'required'          => true,
							'validate_callback' => static function ( $param ) {
								return is_numeric( $param );
							},
						],
						'note' => [
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
						],
					],
				],
			],

			// Delete donation note.
			'/donations/(?P<id>\d+)/notes/(?P<note_id>[\w.]+)' => [
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_donation_note' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id'      => [
						'required'          => true,
						'validate_callback' => static function ( $param ) {
							return is_numeric( $param );
						},
					],
					'note_id' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
		];
	}

	/**
	 * Get a single donation by ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function get_donation( $request ) {
		$donation_id = absint( $request->get_param( 'id' ) );

		// Get the donation from database.
		$donation = Donations::get( $donation_id );

		if ( ! $donation ) {
			return new WP_Error(
				'donation_not_found',
				__( 'Donation not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Format and return the donation data.
		$formatted = $this->format_donation( $donation );

		return new WP_REST_Response(
			[
				'success'  => true,
				'donation' => $formatted,
			],
			200
		);
	}

	/**
	 * Get donations list with filters, sorting, and pagination.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function get_donations( $request ) {
		$page = $request->get_param( 'page' ) ?? 1;
		// Clamp to a minimum of 1 so the total_pages calculation below can never
		// divide by zero (per_page=0 would otherwise trigger a DivisionByZeroError).
		$per_page = max( 1, absint( $request->get_param( 'per_page' ) ?? 20 ) );
		$search   = $request->get_param( 'search' ) ?? '';
		$status   = $request->get_param( 'status' ) ?? 'all';
		$campaign = $request->get_param( 'campaign' ) ?? '';
		$donor    = $request->get_param( 'donor' ) ?? '';
		$sort_by  = $request->get_param( 'sort_by' ) ?? 'created_at';
		$order    = $request->get_param( 'order' ) ?? 'desc';

		// Calculate pagination.
		$limit  = absint( $per_page );
		$offset = ( absint( $page ) - 1 ) * $limit;

		// If filtering by donor, use the donor-specific query.
		if ( ! empty( $donor ) ) {
			$donor_data = Donations::get_by_donor_id( absint( $donor ), $limit, $offset );
			$results    = $donor_data['donations'];
			$total      = $donor_data['total'];
		} else {
			// Get donations from database using admin list method with filters.
			$results = Donations::get_admin_list(
				$status,
				! empty( $campaign ) ? absint( $campaign ) : 0,
				sanitize_text_field( $search ),
				$limit,
				$offset,
				$sort_by, // using whitelist validation in the method.
				strtoupper( $order ) // using whitelist validation in the method.
			);

			// Get total count.
			$total = Donations::count_admin_list( $status, ! empty( $campaign ) ? absint( $campaign ) : 0, sanitize_text_field( $search ) );
		}

		// Format donations data.
		$donations = [];
		foreach ( $results as $donation ) {
			if ( is_array( $donation ) ) {
				$donations[] = $this->format_donation( $donation );
			}
		}

		// Prepare response.
		return new WP_REST_Response(
			[
				'donations'  => $donations,
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
	 * Get donations for a specific campaign.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function get_campaign_donations( $request ) {
		$campaign_id = absint( $request->get_param( 'id' ) );
		$limit       = absint( $request->get_param( 'limit' ) ?? 5 );

		$results = Donations::get_recent_donations( $campaign_id, $limit );

		$donations = [];
		foreach ( $results as $donation ) {
			if ( is_array( $donation ) ) {
				$donations[] = $this->format_donation( $donation );
			}
		}

		return new WP_REST_Response(
			[
				'success'   => true,
				'donations' => $donations,
			],
			200
		);
	}

	/**
	 * Create a new donation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function create_donation( $request ) {
		$campaign_id    = $request->get_param( 'campaign_id' );
		$donor_name     = $request->get_param( 'donor_name' ) ?? '';
		$donor_email    = $request->get_param( 'donor_email' ) ?? '';
		$donor_phone    = $request->get_param( 'donor_phone' ) ?? '';
		$amount         = $request->get_param( 'amount' );
		$fees_covered   = $request->get_param( 'fees_covered' ) ?? 0;
		$payment_status = $request->get_param( 'payment_status' ) ?? 'pending';
		$donation_type  = $request->get_param( 'donation_type' ) ?? 'one-time';
		$is_anonymous   = $request->get_param( 'is_anonymous' ) ?? false;
		$donor_comment  = $request->get_param( 'donor_comment' ) ?? '';
		$gateway        = $request->get_param( 'gateway' ) ?? 'manual';
		$transaction_id = $request->get_param( 'transaction_id' ) ?? '';

		// Get or create donor.
		$donor_id = 0;
		if ( ! empty( $donor_email ) ) {
			$donor_id = Donors::get_or_create( $donor_email, $donor_name, $donor_phone );
		}

		// Build donation data — pro can add subscription fields via filter.
		$donation_data = [
			'campaign_id'    => $campaign_id,
			'donor_id'       => $donor_id ? $donor_id : 0,
			'amount'         => $amount,
			'fees_covered'   => $fees_covered,
			'currency'       => Payment_Helper::get_currency(),
			'gateway'        => $gateway,
			'payment_status' => $payment_status,
			'payment_mode'   => Payment_Helper::get_payment_mode(),
			'donor_name'     => $donor_name,
			'donor_email'    => $donor_email,
			'donor_phone'    => $donor_phone,
			'is_anonymous'   => $is_anonymous ? 1 : 0,
			'donation_type'  => $donation_type,
			'donor_comment'  => $donor_comment,
			'transaction_id' => $transaction_id,
		];

		/**
		 * Filter donation data before insertion.
		 *
		 * Pro uses this to add subscription_id, subscription_status, parent_subscription_id.
		 *
		 * @param array<string, mixed> $donation_data Donation data to insert.
		 * @param \WP_REST_Request     $request       The original REST request.
		 * @since 1.0.0
		 */
		$donation_data = apply_filters( 'suredonation_create_donation_data', $donation_data, $request );

		// Create the donation in database.
		$donation_id = Donations::add( $donation_data );

		if ( ! $donation_id ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create donation.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		$donation = Donations::get( $donation_id );

		return new WP_REST_Response(
			[
				'success'  => true,
				'message'  => __( 'Donation created successfully.', 'suredonation' ),
				'donation' => is_array( $donation ) ? $this->format_donation( $donation ) : [],
			],
			201
		);
	}

	/**
	 * Update an existing donation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function update_donation( $request ) {
		$donation_id = absint( $request->get_param( 'id' ) );

		// Check if donation exists.
		$donation = Donations::get( $donation_id );
		if ( ! $donation ) {
			return new WP_Error(
				'donation_not_found',
				__( 'Donation not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Build update data.
		$update_data = [];
		$fields      = [
			'campaign_id',
			'donor_name',
			'donor_email',
			'donor_phone',
			'amount',
			'fees_covered',
			'donation_type',
			'is_anonymous',
			'donor_comment',
			'payment_status',
			'gateway',
			'transaction_id',
		];

		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			if ( ! is_null( $value ) ) {
				if ( 'is_anonymous' === $field ) {
					$update_data[ $field ] = $value ? 1 : 0;
				} else {
					$update_data[ $field ] = $value;
				}
			}
		}

		/**
		 * Filter donation update data before saving.
		 *
		 * Pro uses this to add subscription fields to the update.
		 *
		 * @param array<string, mixed> $update_data  Data to update.
		 * @param \WP_REST_Request     $request      The REST request.
		 * @param int                  $donation_id  Donation ID.
		 * @since 1.0.0
		 */
		$update_data = apply_filters( 'suredonation_update_donation_data', $update_data, $request, $donation_id );

		if ( ! empty( $update_data ) ) {
			Donations::update( $donation_id, $update_data );
		}

		$updated_donation = Donations::get( $donation_id );

		return new WP_REST_Response(
			[
				'success'  => true,
				'message'  => __( 'Donation updated successfully.', 'suredonation' ),
				'donation' => is_array( $updated_donation ) ? $this->format_donation( $updated_donation ) : [],
			],
			200
		);
	}

	/**
	 * Update donation payment status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function update_donation_status( $request ) {
		$donation_id = absint( $request->get_param( 'id' ) );
		$status      = $request->get_param( 'status' );

		$donation = Donations::get( $donation_id );
		if ( ! $donation ) {
			return new WP_Error(
				'donation_not_found',
				__( 'Donation not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		$old_status = $donation['payment_status'] ?? 'pending';
		Donations::update_status( $donation_id, $status );

		// If status changed to completed, update donor stats.
		if ( 'completed' !== $old_status && 'completed' === $status ) {
			if ( ! empty( $donation['donor_id'] ) ) {
				Donors::record_donation( $donation['donor_id'], floatval( $donation['amount'] ) );
			}
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Donation status updated successfully.', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Delete donation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function delete_donation( $request ) {
		$donation_id = absint( $request->get_param( 'id' ) );

		$result = Donations::delete( $donation_id );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete donation.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Donation deleted successfully.', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Bulk action on donations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function bulk_action( $request ) {
		$action = $request->get_param( 'action' );
		$ids    = $request->get_param( 'ids' );

		if ( ! is_array( $ids ) ) {
			$ids = [];
		}

		// Cap bulk operations at 200 IDs per request. Each ID triggers a
		// per-row SELECT + DELETE / UPDATE — an arbitrarily large array in one
		// request would chew through the database serially and time out the
		// response. 200 is enough headroom for any realistic admin UI
		// selection; larger jobs should be split client-side (parity with the
		// donors bulk-action endpoint).
		if ( count( $ids ) > 200 ) {
			return new WP_Error(
				'too_many_items',
				__( 'Bulk actions are limited to 200 donations per request.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		$success_count = 0;
		$error_count   = 0;

		foreach ( $ids as $id ) {
			$result = false;

			if ( 'delete' === $action ) {
				$result = Donations::delete( absint( $id ) );
			} elseif ( 'update_status' === $action ) {
				$status = $request->get_param( 'status' );
				if ( $status ) {
					$result = Donations::update_status( absint( $id ), $status );
				}
			}

			if ( $result ) {
				++$success_count;
			} else {
				++$error_count;
			}
		}

		return new WP_REST_Response(
			[
				'success'       => true,
				'message'       => sprintf(
					// translators: %1$d: success count, %2$d: error count.
					__( 'Bulk action completed. Success: %1$d, Failed: %2$d', 'suredonation' ),
					$success_count,
					$error_count
				),
				'success_count' => $success_count,
				'error_count'   => $error_count,
			],
			200
		);
	}

	/**
	 * Refund a donation payment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function refund_donation( $request ) {
		$donation_id    = absint( $request->get_param( 'id' ) );
		$transaction_id = $request->get_param( 'transaction_id' );
		$refund_amount  = absint( $request->get_param( 'refund_amount' ) );

		// Get the donation.
		$donation = Donations::get( $donation_id );
		if ( ! $donation ) {
			return new WP_Error(
				'donation_not_found',
				__( 'Donation not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Verify the donation is in a refundable state.
		$refundable_statuses = [ 'completed', 'partially_refunded' ];
		if ( ! in_array( $donation['payment_status'], $refundable_statuses, true ) ) {
			return new WP_Error(
				'not_refundable',
				__( 'Only completed or partially refunded donations can be refunded.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		// Verify transaction ID matches.
		if ( $transaction_id !== $donation['transaction_id'] ) {
			return new WP_Error(
				'transaction_mismatch',
				__( 'Transaction ID mismatch.', 'suredonation' ),
				[ 'status' => 400 ]
			);
		}

		// Validate refund amount.
		$gateway         = $donation['gateway'] ?? 'stripe';
		$currency        = $donation['currency'] ?? 'USD';
		$total_amount    = $this->amount_to_stripe_format( floatval( $donation['amount'] ), $currency );
		$refunded_amount = $this->amount_to_stripe_format( floatval( $donation['refunded_amount'] ?? 0 ), $currency );
		$refundable      = $total_amount - $refunded_amount;

		if ( $refund_amount > $refundable ) {
			return new WP_Error(
				'exceeds_refundable',
				sprintf(
					/* translators: %s: maximum refundable amount */
					__( 'Refund amount exceeds maximum refundable amount of %s.', 'suredonation' ),
					$this->amount_from_stripe_format( $refundable, $currency )
				),
				[ 'status' => 400 ]
			);
		}

		// Process refund through the appropriate gateway.
		if ( 'paypal' === $gateway ) {
			$refund_amount_major = $this->amount_from_stripe_format( $refund_amount, $currency );
			$refund_result       = \SureDonation\Inc\Payments\PayPal\PayPal_Api_Payments::refund_capture(
				$transaction_id,
				$refund_amount_major,
				$currency
			);
		} else {
			// Check if Stripe is connected.
			if ( ! Stripe_Helper::is_stripe_connected() ) {
				return new WP_Error(
					'stripe_not_connected',
					__( 'Stripe is not connected. Please configure Stripe in settings.', 'suredonation' ),
					[ 'status' => 400 ]
				);
			}
			$refund_account_id = isset( $donation['stripe_account_id'] ) && is_string( $donation['stripe_account_id'] ) ? $donation['stripe_account_id'] : '';
			$refund_result     = Stripe_Helper::create_refund( $transaction_id, $refund_amount, 'requested_by_customer', $refund_account_id );
		}

		if ( is_wp_error( $refund_result ) ) {
			return new WP_Error(
				'refund_failed',
				$refund_result->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		// Calculate new refunded amount in cents for comparison.
		$new_refunded_in_cents = $refunded_amount + $refund_amount;

		// Determine new status by comparing in cents to avoid floating point precision issues.
		$new_status = $new_refunded_in_cents >= $total_amount ? 'refunded' : 'partially_refunded';

		// Convert back to major currency unit for storage.
		$new_refunded_amount = $this->amount_from_stripe_format( $new_refunded_in_cents, $currency );

		// Store refund in donation_data FIRST (prevents webhook duplicate processing).
		$refund_id = $refund_result['id'] ?? '';
		if ( ! empty( $refund_id ) ) {
			$refund_data = [
				'refund_id'   => $refund_id,
				'amount'      => absint( $refund_amount ),
				'currency'    => strtoupper( $currency ),
				'status'      => $refund_result['status'] ?? 'succeeded',
				'created'     => time(),
				'reason'      => 'requested_by_customer',
				'refunded_by' => 'admin',
				'refunded_at' => gmdate( 'Y-m-d H:i:s' ),
			];
			Donations::add_refund_to_donation_data( $donation_id, $refund_data );
		}

		// Update donation record with new status and refunded amount.
		Donations::update(
			$donation_id,
			[
				'payment_status'  => $new_status,
				'refunded_amount' => $new_refunded_amount,
			]
		);

		// Determine refund type for log message.
		$refund_type = $new_refunded_in_cents >= $total_amount
			? __( 'Full', 'suredonation' )
			: __( 'Partial', 'suredonation' );

		// Add log entry.
		Donations::add_log(
			$donation_id,
			'refund',
			sprintf(
				/* translators: %s: Refund type (Full/Partial) */
				__( '%s refund processed via admin', 'suredonation' ),
				$refund_type
			),
			[
				'refund_id'       => $refund_id,
				'refund_amount'   => $this->amount_from_stripe_format( $refund_amount, $currency ),
				'total_refunded'  => $new_refunded_amount,
				'original_amount' => floatval( $donation['amount'] ),
				'payment_status'  => $new_status,
				'currency'        => strtoupper( $currency ),
			]
		);

		// Send refund email notifications.
		$campaign_id   = isset( $donation['campaign_id'] ) && is_numeric( $donation['campaign_id'] ) ? absint( $donation['campaign_id'] ) : 0;
		$form_id       = isset( $donation['form_id'] ) && is_numeric( $donation['form_id'] ) ? absint( $donation['form_id'] ) : 0;
		$donation_data = [
			'id'            => $donation_id,
			'donor_name'    => $donation['donor_name'] ?? '',
			'donor_email'   => $donation['donor_email'] ?? '',
			'amount'        => $donation['amount'] ?? 0,
			'currency'      => strtoupper( $currency ),
			'refund_amount' => $this->amount_from_stripe_format( $refund_amount, $currency ),
			'donation_type' => $donation['donation_type'] ?? 'one-time',
			'gateway'       => 'stripe',
		];

		Email_Handler::send_refund_processed( $donation_id, $campaign_id, $donation_data, $form_id );

		// Get updated donation.
		$updated_donation = Donations::get( $donation_id );

		return new WP_REST_Response(
			[
				'success'   => true,
				'message'   => __( 'Refund processed successfully.', 'suredonation' ),
				'refund_id' => $refund_id,
				'status'    => $refund_result['status'] ?? 'succeeded',
				'donation'  => is_array( $updated_donation ) ? $this->format_donation( $updated_donation ) : [],
			],
			200
		);
	}
	/**
	 * Check if user has permission to manage donations.
	 *
	 * @return bool True if user has permission.
	 * @since 0.0.1
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Delete a log entry from a donation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function delete_donation_log( $request ) {
		$donation_id = absint( $request->get_param( 'id' ) );
		$log_index   = absint( $request->get_param( 'log_index' ) );

		// Get the donation from database.
		$donation = Donations::get( $donation_id );

		if ( ! $donation ) {
			return new WP_Error(
				'donation_not_found',
				__( 'Donation not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Get current logs.
		$logs = Donations::get_log( $donation_id );

		if ( ! is_array( $logs ) || empty( $logs ) ) {
			return new WP_Error(
				'no_logs',
				__( 'No logs found for this donation.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Check if log index exists.
		if ( ! isset( $logs[ $log_index ] ) ) {
			return new WP_Error(
				'log_not_found',
				__( 'Log entry not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Remove log at specified index.
		array_splice( $logs, $log_index, 1 );

		// Re-index array to prevent gaps.
		$logs = array_values( $logs );

		// Update log column with modified logs array.
		$result = Donations::update( $donation_id, [ 'log' => $logs ] );

		if ( false === $result ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to delete log entry.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Log entry deleted successfully.', 'suredonation' ),
				'logs'    => $logs,
			],
			200
		);
	}

	/**
	 * Get notes for a donation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function get_donation_notes( $request ) {
		$donation_id = absint( $request->get_param( 'id' ) );
		$page        = absint( $request->get_param( 'page' ) ) ?? 1;
		$per_page    = absint( $request->get_param( 'per_page' ) ) ?? 3;

		// Get the donation from database.
		$donation = Donations::get( $donation_id );

		if ( ! $donation ) {
			return new WP_Error(
				'donation_not_found',
				__( 'Donation not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Get paginated notes.
		$notes_data = Donations::get_notes( $donation_id, $page, $per_page );

		return new WP_REST_Response(
			[
				'success'     => true,
				'notes'       => $notes_data['notes'],
				'total'       => $notes_data['total'],
				'total_pages' => $notes_data['total_pages'],
			],
			200
		);
	}

	/**
	 * Add a note to a donation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function add_donation_note( $request ) {
		$donation_id = absint( $request->get_param( 'id' ) );
		$note        = $request->get_param( 'note' );

		// Get the donation from database.
		$donation = Donations::get( $donation_id );

		if ( ! $donation ) {
			return new WP_Error(
				'donation_not_found',
				__( 'Donation not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Add the note.
		$result = Donations::add_note( $donation_id, $note, get_current_user_id() );

		if ( ! $result['success'] ) {
			return new WP_Error(
				'note_failed',
				__( 'Failed to add note.', 'suredonation' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Note added successfully.', 'suredonation' ),
				'note_id' => $result['note_id'],
			],
			201
		);
	}

	/**
	 * Delete a note from a donation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 * @since 0.0.1
	 */
	public function delete_donation_note( $request ) {
		$donation_id = absint( $request->get_param( 'id' ) );
		$note_id     = $request->get_param( 'note_id' );

		// Get the donation from database.
		$donation = Donations::get( $donation_id );

		if ( ! $donation ) {
			return new WP_Error(
				'donation_not_found',
				__( 'Donation not found.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		// Delete the note.
		$result = Donations::delete_note( $donation_id, $note_id );

		if ( ! $result ) {
			return new WP_Error(
				'note_not_found',
				__( 'Note not found or could not be deleted.', 'suredonation' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Note deleted successfully.', 'suredonation' ),
			],
			200
		);
	}

	/**
	 * Get donation arguments schema.
	 *
	 * @param bool $required Whether fields are required.
	 * @return array<string, array<string, mixed>>
	 * @since 0.0.1
	 */
	private function get_donation_args( $required = true ) {
		return [
			'campaign_id'    => [
				'required'          => $required,
				'sanitize_callback' => 'absint',
			],
			'donor_name'     => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'donor_email'    => [
				'sanitize_callback' => 'sanitize_email',
			],
			'donor_phone'    => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'amount'         => [
				'required'          => $required,
				'sanitize_callback' => static function ( $value ) {
					return floatval( $value );
				},
			],
			'fees_covered'   => [
				'sanitize_callback' => static function ( $value ) {
					return floatval( $value );
				},
			],
			'donation_type'  => [
				'default'           => 'one-time',
				'enum'              => [ 'one-time', 'recurring', 'renewal' ],
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $param ) {
					return in_array( $param, [ 'one-time', 'recurring', 'renewal' ], true );
				},
			],
			'is_anonymous'   => [
				'sanitize_callback' => 'rest_sanitize_boolean',
			],
			'donor_comment'  => [
				'sanitize_callback' => 'wp_kses_post',
			],
			'payment_status' => [
				'default'           => 'pending',
				'enum'              => [ 'pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled' ],
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $param ) {
					return in_array( $param, [ 'pending', 'processing', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled' ], true );
				},
			],
			'gateway'        => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'transaction_id' => [
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Validate REST date filter parameters.
	 *
	 * @param mixed $param Date parameter.
	 * @return bool Whether the date is valid.
	 * @since 0.0.1
	 */
	public function validate_date_param( $param ) {
		if ( '' === $param || null === $param ) {
			return true;
		}

		return is_string( $param ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $param );
	}

	/**
	 * Convert amount to Stripe's smallest currency unit.
	 *
	 * @param float  $amount   Amount in major currency unit.
	 * @param string $currency Currency code.
	 * @return int Amount in smallest currency unit.
	 * @since 0.0.1
	 */
	private function amount_to_stripe_format( $amount, $currency ) {
		// Delegates rather than repeating the zero-decimal list: the abilities
		// layer guards refunds with Payment_Helper, so a second hardcoded list
		// here could disagree with the guard about what a currency's minor unit
		// is. Payment_Helper derives it from the currency data table.
		return Payment_Helper::amount_to_stripe_format( $amount, $currency );
	}

	/**
	 * Convert amount from Stripe's smallest currency unit.
	 *
	 * @param int    $amount   Amount in smallest currency unit.
	 * @param string $currency Currency code.
	 * @return float Amount in major currency unit.
	 * @since 0.0.1
	 */
	private function amount_from_stripe_format( $amount, $currency ) {
		return Payment_Helper::amount_from_stripe_format( $amount, $currency );
	}

	/**
	 * Format donation data for API response.
	 *
	 * @param array<string, mixed> $donation Donation data from database.
	 * @return array<string, mixed> Formatted donation data.
	 * @since 0.0.1
	 */
	private function format_donation( $donation ) {
		$campaign_id = isset( $donation['campaign_id'] ) ? Helper::get_integer_value( $donation['campaign_id'] ) : 0;
		$donation_id = isset( $donation['id'] ) ? Helper::get_integer_value( $donation['id'] ) : 0;
		$form_id     = isset( $donation['form_id'] ) ? Helper::get_integer_value( $donation['form_id'] ) : 0;

		// Get payment logs for this donation.
		$logs = $donation_id ? Donations::get_log( $donation_id ) : [];

		// Get payment mode for Stripe dashboard URL.
		$payment_mode = $donation['payment_mode'] ?? 'test';

		$form_edit_url = '';
		if ( $form_id && current_user_can( 'edit_post', $form_id ) ) {
			$form_edit_url = esc_url_raw( get_edit_post_link( $form_id, 'raw' ) );
		}

		// Parse donation_data for subscription metadata.
		$donation_data = $donation['donation_data'] ?? [];
		if ( is_string( $donation_data ) && ! empty( $donation_data ) ) {
			$donation_data = json_decode( $donation_data, true );
		}
		if ( ! is_array( $donation_data ) ) {
			$donation_data = [];
		}

		// Build the persisted submitted fields list (label/value/group). The
		// group is the parent block label (e.g. "Address") used to nest
		// sub-fields on the entry screen; '' for standalone fields.
		$submitted_fields = [];
		if ( isset( $donation_data['fields'] ) && is_array( $donation_data['fields'] ) ) {
			foreach ( $donation_data['fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				// sanitize_text_field (not esc_html) for REST data: the values are
				// already sanitized at write time and React escapes on render, so
				// esc_html here would double-encode (e.g. "Cats & Dogs" -> "Cats &amp; Dogs").
				$submitted_fields[] = [
					'label' => sanitize_text_field( Helper::get_string_value( $field['label'] ?? '' ) ),
					'value' => sanitize_text_field( Helper::get_string_value( $field['value'] ?? '' ) ),
					'group' => sanitize_text_field( Helper::get_string_value( $field['group'] ?? '' ) ),
				];
			}
		}

		return [
			'id'                     => $donation_id,
			'campaign_id'            => $campaign_id,
			// Plain-text titles rendered by React (which escapes text nodes and does
			// not decode HTML entities). get_the_title() runs wptexturize, whose
			// default replacements are entities (e.g. " - " -> "&#8211;"), so decode
			// them here; wp_kses_post would leave the entity and it would show raw.
			'campaign_title'         => $campaign_id ? html_entity_decode( wp_strip_all_tags( (string) get_the_title( $campaign_id ) ), ENT_QUOTES, 'UTF-8' ) : '',
			'form_id'                => $form_id,
			'form_title'             => $form_id ? html_entity_decode( wp_strip_all_tags( (string) get_the_title( $form_id ) ), ENT_QUOTES, 'UTF-8' ) : '',
			'form_edit_url'          => $form_edit_url,
			'donor_id'               => isset( $donation['donor_id'] ) ? Helper::get_integer_value( $donation['donor_id'] ) : 0,
			'donor_name'             => esc_html( Helper::get_string_value( $donation['donor_name'] ?? '' ) ),
			'donor_email'            => sanitize_email( Helper::get_string_value( $donation['donor_email'] ?? '' ) ),
			'donor_phone'            => esc_html( Helper::get_string_value( $donation['donor_phone'] ?? '' ) ),
			'amount'                 => Helper::get_float_value( $donation['amount'] ?? 0 ),
			'fees_covered'           => Helper::get_float_value( $donation['fees_covered'] ?? 0 ),
			'refunded_amount'        => Helper::get_float_value( $donation['refunded_amount'] ?? 0 ),
			'currency'               => esc_html( Helper::get_string_value( $donation['currency'] ?? 'USD' ) ),
			'donation_type'          => esc_html( Helper::get_string_value( $donation['donation_type'] ?? 'one-time' ) ),
			'is_anonymous'           => ! empty( $donation['is_anonymous'] ),
			'donor_comment'          => wp_kses_post( Helper::get_string_value( $donation['donor_comment'] ?? '' ) ),
			'payment_status'         => esc_html( Helper::get_string_value( $donation['payment_status'] ?? 'pending' ) ),
			'payment_mode'           => esc_html( Helper::get_string_value( $payment_mode ) ),
			'gateway'                => esc_html( Helper::get_string_value( $donation['gateway'] ?? '' ) ),
			'transaction_id'         => esc_html( Helper::get_string_value( $donation['transaction_id'] ?? '' ) ),
			'stripe_customer_id'     => esc_html( Helper::get_string_value( $donation['customer_id'] ?? '' ) ),
			'subscription_id'        => esc_html( Helper::get_string_value( $donation['subscription_id'] ?? '' ) ),
			'subscription_status'    => esc_html( Helper::get_string_value( $donation['subscription_status'] ?? '' ) ),
			'parent_subscription_id' => isset( $donation['parent_subscription_id'] ) ? Helper::get_integer_value( $donation['parent_subscription_id'] ) : 0,
			'subscription_interval'  => esc_html( Helper::get_string_value( $donation_data['subscription_interval'] ?? '' ) ),
			'billing_cycles'         => esc_html( Helper::get_string_value( $donation_data['billing_cycles'] ?? '' ) ),
			'fields'                 => $submitted_fields,
			'created_at'             => esc_html( Helper::get_string_value( $donation['created_at'] ?? '' ) ),
			'updated_at'             => esc_html( Helper::get_string_value( $donation['updated_at'] ?? '' ) ),
			'logs'                   => $logs,
		];
	}
}
