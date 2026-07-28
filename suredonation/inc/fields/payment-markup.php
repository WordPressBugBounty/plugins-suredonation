<?php
/**
 * SureDonation Payment Markup Class file.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc\Fields;

use SureDonation\Inc\Helper;
use SureDonation\Inc\Payments\Offline\Offline_Helper;
use SureDonation\Inc\Payments\Payment_Helper;
use SureDonation\Inc\Payments\Stripe\Stripe_Helper;
use SureDonation\Inc\Post_Types\Donation_Form;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SureDonation Payment Markup Class.
 *
 * @since 0.0.1
 */
class Payment_Markup extends Base {
	/**
	 * Payment amount.
	 *
	 * @var float
	 * @since 0.0.1
	 */
	protected $amount;

	/**
	 * Payment currency.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $currency;

	/**
	 * Stripe publishable key.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $stripe_publishable_key;

	/**
	 * Whether Stripe is connected.
	 *
	 * @var bool
	 * @since 0.0.1
	 */
	protected $stripe_connected;

	/**
	 * Payment mode (live or test).
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $payment_mode;

	/**
	 * Payment type.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $payment_type;

	/**
	 * Subscription plans.
	 *
	 * @var array<string, mixed>
	 * @since 0.0.1
	 */
	protected $subscription_plan;

	/**
	 * Amount type.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $amount_type;

	/**
	 * Fixed amount.
	 *
	 * @var float
	 * @since 0.0.1
	 */
	protected $fixed_amount;

	/**
	 * Minimum amount.
	 *
	 * @var float
	 * @since 0.0.1
	 */
	protected $minimum_amount;

	/**
	 * Customer name field slug.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $customer_name_field;

	/**
	 * Customer email field slug.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $customer_email_field;

	/**
	 * Variable amount field slug.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $variable_amount_field;

	/**
	 * Payment methods enabled for this block.
	 *
	 * @var array<string>
	 * @since 1.0.0
	 */
	protected $payment_methods = [];

	/**
	 * Aria-describedby attribute value.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	protected $aria_described_by = '';

	/**
	 * Constructor for the Payment Markup class.
	 *
	 * @param array<mixed> $attributes Block attributes.
	 * @since 0.0.1
	 */
	public function __construct( $attributes ) {
		// Get payment settings from Stripe Helper.
		$this->stripe_connected = Stripe_Helper::is_stripe_connected();
		$this->payment_mode     = Payment_Helper::get_payment_mode();

		$this->slug = 'payment';
		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();

		// Build aria-describedby attribute.
		$this->aria_described_by = $this->get_aria_describedby();

		// Set payment-specific properties.
		$this->amount   = $attributes['amount'] ?? 10;
		$this->currency = $attributes['currency'] ?? 'USD';

		// Use currency from settings if not specified in block.
		if ( empty( $this->currency ) || 'USD' === $this->currency ) {
			$this->currency = Payment_Helper::get_currency();
		}

		// Get appropriate Stripe publishable key based on mode.
		$this->stripe_publishable_key = Stripe_Helper::get_stripe_publishable_key();

		// Fall back to one-time if subscription is configured but pro is not active.
		$configured_type         = $attributes['paymentType'] ?? 'one-time';
		$this->payment_type      = 'subscription' === $configured_type && ! defined( 'SUREDONATION_PRO_VER' ) ? 'one-time' : $configured_type;
		$this->subscription_plan = $attributes['subscriptionPlan'] ?? [];
		$this->amount_type       = $attributes['amountType'] ?? 'fixed';
		$this->fixed_amount      = $attributes['fixedAmount'] ?? 10;
		$this->minimum_amount    = $attributes['minimumAmount'] ?? 0;

		// Set customer field mappings.
		$this->customer_name_field  = $attributes['customerNameField'] ?? '';
		$this->customer_email_field = $attributes['customerEmailField'] ?? '';

		// Set variable amount field mapping.
		$this->variable_amount_field = $attributes['variableAmountField'] ?? '';

		// Parse payment methods from block attributes (with backward compat fallback).
		$block_payment_methods = $attributes['paymentMethods'] ?? null;
		if ( ! is_array( $block_payment_methods ) || empty( $block_payment_methods ) ) {
			$gateway               = $attributes['gateway'] ?? 'stripe';
			$block_payment_methods = [ is_string( $gateway ) ? $gateway : 'stripe' ];
		}

		// Filter to only globally-available gateways.
		$available = [];
		foreach ( $block_payment_methods as $method ) {
			if ( 'stripe' === $method && $this->stripe_connected && ! empty( $this->stripe_publishable_key ) ) {
				$available[] = 'stripe';
			} elseif ( 'offline' === $method && Offline_Helper::is_offline_enabled() ) {
				$available[] = 'offline';
			}
		}

		/**
		 * Filter the list of available payment methods for this block.
		 *
		 * Allows extensions (e.g., PayPal) to add themselves when connected.
		 *
		 * @param array<string>       $available            Currently available method IDs.
		 * @param array<string>       $block_payment_methods Methods selected in the block.
		 * @param array<string,mixed> $attributes           Block attributes.
		 * @since 1.0.0
		 */
		$available = apply_filters( 'suredonation_available_payment_methods', $available, $block_payment_methods, $attributes );

		$this->payment_methods = ! empty( $available ) ? $available : $block_payment_methods;

		// BACKWARD COMPATIBILITY: Migrate customer fields from subscriptionPlan.
		if ( empty( $this->customer_name_field ) && ! empty( $this->subscription_plan['customer_name'] ) ) {
			$this->customer_name_field = $this->subscription_plan['customer_name'];
		}

		if ( empty( $this->customer_email_field ) && ! empty( $this->subscription_plan['customer_email'] ) ) {
			$this->customer_email_field = $this->subscription_plan['customer_email'];
		}
	}

	/**
	 * Render the payment field markup.
	 *
	 * @return string
	 * @since 0.0.1
	 */
	public function markup() {
		$has_stripe = in_array( 'stripe', $this->payment_methods, true );

		// Determine which gateways are actually connected/available for this form.
		// get_registered_payment_methods() returns only enabled methods (Stripe when
		// connected, Offline when enabled, plus any added by extensions such as
		// PayPal), so it is the single gateway-agnostic source of truth.
		$methods = $this->get_registered_payment_methods();

		// No payment gateway is connected/available. Show a clear message instead of
		// returning an empty string, which previously left donors with a silently
		// broken form and no way to donate. See issue #219.
		if ( empty( $methods ) ) {
			return $this->render_gateway_unavailable_notice();
		}

		// Validate payment field requirements.
		if ( ! $this->validate_payment_requirements() ) {
			return '';
		}

		$field_classes       = $this->get_field_classes();
		$payment_methods_csv = implode( ',', $this->payment_methods );
		$default_gateway     = $this->payment_methods[0];

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>"
			data-form-id="<?php echo esc_attr( $this->form_id ); ?>"
			class="<?php echo esc_attr( $field_classes ); ?>"
			data-payment-methods="<?php echo esc_attr( $payment_methods_csv ); ?>"
			data-gateway="<?php echo esc_attr( $default_gateway ); ?>"
			<?php if ( $has_stripe && ! empty( $this->stripe_publishable_key ) ) { ?>
				data-stripe-key="<?php echo esc_attr( $this->stripe_publishable_key ); ?>"
			<?php } ?>
			data-currency="<?php echo esc_attr( strtolower( $this->currency ) ); ?>"
			data-currency-symbol="<?php echo esc_attr( Payment_Helper::get_currency_symbol( $this->currency ) ); ?>"
			data-payment-mode="<?php echo esc_attr( $this->payment_mode ); ?>"
			data-amount-type="<?php echo esc_attr( $this->amount_type ); ?>"
			data-fixed-amount="<?php echo esc_attr( (string) $this->fixed_amount ); ?>"
			data-payment-type="<?php echo esc_attr( $this->payment_type ); ?>"
			data-customer-name-field="<?php echo esc_attr( $this->customer_name_field ); ?>"
			data-customer-email-field="<?php echo esc_attr( $this->customer_email_field ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'suredonation_donation_form' ) ); ?>"
			<?php if ( 'variable' === $this->amount_type ) { ?>
				data-variable-amount-field="<?php echo esc_attr( $this->variable_amount_field ); ?>"
			<?php } ?>
			<?php if ( $this->minimum_amount > 0 ) { ?>
				data-minimum-amount="<?php echo esc_attr( (string) $this->minimum_amount ); ?>"
			<?php } ?>
			<?php if ( 'subscription' === $this->payment_type && ! empty( $this->subscription_plan ) ) { ?>
				data-subscription-plan-name="<?php echo esc_attr( isset( $this->subscription_plan['name'] ) ? Helper::get_string_value( $this->subscription_plan['name'] ) : __( 'Subscription Plan', 'suredonation' ) ); ?>"
				data-subscription-interval="<?php echo esc_attr( isset( $this->subscription_plan['interval'] ) ? Helper::get_string_value( $this->subscription_plan['interval'] ) : 'month' ); ?>"
				data-subscription-billing-cycles="<?php echo esc_attr( isset( $this->subscription_plan['billingCycles'] ) ? Helper::get_string_value( $this->subscription_plan['billingCycles'] ) : '0' ); ?>"
			<?php } ?>
		>
			<?php echo wp_kses_post( $this->label_markup ); ?>
			<?php echo wp_kses_post( $this->help_markup ); ?>
			<div class="sd-payment-field-wrapper">
				<?php
				// Amount display — uses wp_kses with data attributes allowed
				// because wp_kses_post strips data-message-format, data-currency-symbol etc.
				echo wp_kses( $this->render_amount_display(), Helper::get_allowed_form_html() );

				// Test mode notice.
				if ( 'test' === $this->payment_mode && $has_stripe ) {
					echo wp_kses_post( $this->get_test_mode_notice() );
				}

				// Payment methods accordion ( $methods computed above ).
				echo $this->render_payment_methods_accordion( $methods ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method builds markup with esc_attr/esc_html on all dynamic values and wp_kses_post on content.
				?>

				<!-- Payment error display (hidden via CSS; shown by JS on error). -->
				<div class="sd-payment-error"></div>
			</div>
		</div>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Get registered payment methods for display.
	 *
	 * @return array<mixed> Array of payment method configurations.
	 * @since 1.0.0
	 */
	private function get_registered_payment_methods() {
		$available_methods = [
			'stripe'  => [
				'id'              => 'stripe',
				'label'           => __( 'Credit / Debit Card', 'suredonation' ),
				'enabled'         => $this->stripe_connected && ! empty( $this->stripe_publishable_key ),
				'container_class' => 'sd-payment-element',
				'container_id'    => 'sd-payment-element-' . $this->block_id,
			],
			'offline' => [
				'id'              => 'offline',
				'label'           => __( 'Offline Donation', 'suredonation' ),
				'enabled'         => Offline_Helper::is_offline_enabled(),
				'container_class' => 'sd-offline-instructions',
				'content'         => Offline_Helper::get_offline_instructions( $this->get_campaign_name() ),
			],
		];

		$methods = [];
		foreach ( $this->payment_methods as $method_id ) {
			if ( isset( $available_methods[ $method_id ] ) && $available_methods[ $method_id ]['enabled'] ) {
				$methods[ $method_id ] = $available_methods[ $method_id ];
			}
		}

		/**
		 * Filter the registered payment methods and their display config.
		 *
		 * Allows extensions to add payment methods (e.g., PayPal) with their
		 * label, container class, and enabled state for frontend rendering.
		 *
		 * @param array<string, array<string,mixed>> $methods         Registered methods keyed by ID.
		 * @param array<string>                      $payment_methods Methods selected in this block.
		 * @param string                             $block_id        The block ID.
		 * @since 1.0.0
		 */
		return apply_filters( 'suredonation_registered_payment_methods', $methods, $this->payment_methods, $this->block_id );
	}

	/**
	 * Get the campaign name for this form.
	 *
	 * @return string Campaign name or empty string.
	 * @since 1.0.0
	 */
	private function get_campaign_name() {
		if ( empty( $this->form_id ) ) {
			return '';
		}

		$campaign_id = Donation_Form::get_form_campaign_id( absint( $this->form_id ) );

		if ( empty( $campaign_id ) ) {
			return '';
		}

		return get_the_title( $campaign_id );
	}

	/**
	 * Render payment methods as accordion.
	 * Each payment method is an accordion item with header and collapsible content.
	 *
	 * @param array<mixed> $methods Array of payment methods.
	 * @return string Payment methods accordion markup.
	 * @since 1.0.0
	 */
	private function render_payment_methods_accordion( $methods ) {
		if ( empty( $methods ) || ! is_array( $methods ) ) {
			return '';
		}

		$is_single_method = count( $methods ) === 1;
		$is_first         = true;

		ob_start();
		?>
		<div class="sd-payment-methods-accordion <?php echo esc_attr( $is_single_method ? 'sd-single-payment-method' : '' ); ?>">
			<?php foreach ( $methods as $method ) { ?>
				<div
					class="sd-accordion-item <?php echo esc_attr( $is_first ? 'sd-payment-active' : '' ); ?>"
					data-method="<?php echo esc_attr( $method['id'] ); ?>"
				>
					<?php if ( $is_single_method ) { ?>
						<?php // Single method: the header is a static label, not an accordion toggle, so omit the interactive button semantics (keyboard/screen-reader users shouldn't hit an inert "button"). ?>
						<div class="sd-accordion-header">
					<?php } else { ?>
						<div
							class="sd-accordion-header"
							role="button"
							tabindex="0"
							aria-expanded="<?php echo esc_attr( $is_first ? 'true' : 'false' ); ?>"
							aria-controls="sd-accordion-content-<?php echo esc_attr( $method['id'] ); ?>-<?php echo esc_attr( $this->block_id ); ?>"
						>
					<?php } ?>
						<div class="sd-payment-input-wrapper">
							<input
								type="radio"
								name="sd-gateway-choice-<?php echo esc_attr( $this->block_id ); ?>"
								value="<?php echo esc_attr( $method['id'] ); ?>"
								class="sd-payment-method-radio"
								data-method="<?php echo esc_attr( $method['id'] ); ?>"
								<?php checked( $is_first ); ?>
								aria-label="<?php echo esc_attr( $method['label'] ); ?>"
							/>
							<span class="sd-accordion-title sd-block-label">
								<?php echo esc_html( $method['label'] ); ?>
							</span>
						</div>
					</div>
					<div
						id="sd-accordion-content-<?php echo esc_attr( $method['id'] ); ?>-<?php echo esc_attr( $this->block_id ); ?>"
						class="sd-accordion-content<?php echo Offline_Helper::is_blank_instructions( $method['content'] ?? '' ) ? ' sd-accordion-content-empty' : ''; ?>"
						role="region"
					>
						<div
							class="sd-payment-method-content"
							data-method="<?php echo esc_attr( $method['id'] ); ?>"
						>
							<div
								<?php if ( ! empty( $method['container_id'] ) ) { ?>
									id="<?php echo esc_attr( $method['container_id'] ); ?>"
								<?php } ?>
								class="<?php echo esc_attr( $method['container_class'] ); ?>"
							>
								<?php
								if ( ! empty( $method['content'] ) ) {
									echo wp_kses_post( $method['content'] );
								}
								?>
							</div>
						</div>
					</div>
				</div>
				<?php $is_first = false; ?>
			<?php } ?>
		</div>
		<?php
		$template = ob_get_clean();

		return is_string( $template ) ? $template : '';
	}

	/**
	 * Render amount display section.
	 *
	 * @return string Amount display HTML.
	 * @since 0.0.1
	 */
	private function render_amount_display() {
		ob_start();

		if ( 'fixed' === $this->amount_type ) {
			?>
			<!-- Fixed Payment Amount Display. -->
			<div class="sd-payment-amount sd-label">
				<span class="sd-payment-value">
					<?php
					if ( 'subscription' === $this->payment_type && ! empty( $this->subscription_plan ) ) {
						$interval       = isset( $this->subscription_plan['interval'] ) ? Helper::get_string_value( $this->subscription_plan['interval'] ) : 'month';
						$billing_cycles = isset( $this->subscription_plan['billingCycles'] ) ? Helper::get_string_value( $this->subscription_plan['billingCycles'] ) : '0';
						$interval_label = $this->get_interval_label( $interval );

						// Build subscription text.
						if ( 'ongoing' === $billing_cycles ) {
							echo esc_html(
								sprintf(
									/* translators: 1: Amount with currency, 2: Interval (day/week/month/quarter/year) */
									__( '%1$s per %2$s (until cancelled)', 'suredonation' ),
									$this->format_currency( $this->fixed_amount, $this->currency ),
									$interval_label
								)
							);
						} elseif ( (int) $billing_cycles > 0 ) {
							echo esc_html(
								sprintf(
									/* translators: 1: Amount with currency, 2: Interval (day/week/month/quarter/year), 3: Number of billing cycles */
									__( '%1$s per %2$s (%3$s payments)', 'suredonation' ),
									$this->format_currency( $this->fixed_amount, $this->currency ),
									$interval_label,
									$billing_cycles
								)
							);
						} else {
							echo esc_html(
								sprintf(
									/* translators: 1: Amount with currency, 2: Interval (day/week/month/quarter/year) */
									__( '%1$s per %2$s', 'suredonation' ),
									$this->format_currency( $this->fixed_amount, $this->currency ),
									$interval_label
								)
							);
						}
					} else {
						echo esc_html( $this->format_currency( $this->fixed_amount, $this->currency ) );
					}
					?>
				</span>
			</div>
			<?php
		} else {
			?>
			<!-- Variable Payment Amount Display. -->
			<div class="sd-variable-amount-display sd-label">
				<div class="sd-payment-amount-wrapper">
					<?php
					// Generate message format for variable amounts.
					$message_format = '{amount}';
					if ( 'subscription' === $this->payment_type && ! empty( $this->subscription_plan ) ) {
						$interval       = isset( $this->subscription_plan['interval'] ) ? Helper::get_string_value( $this->subscription_plan['interval'] ) : 'month';
						$billing_cycles = isset( $this->subscription_plan['billingCycles'] ) ? Helper::get_string_value( $this->subscription_plan['billingCycles'] ) : '0';
						$interval_label = $this->get_interval_label( $interval );

						// Build message format based on billing cycles.
						if ( 'ongoing' === $billing_cycles ) {
							/* translators: 1: Amount with currency placeholder, 2: Interval (day/week/month/quarter/year) */
							$message_format = sprintf( __( '{amount} per %s (until cancelled)', 'suredonation' ), $interval_label );
						} elseif ( (int) $billing_cycles > 0 ) {
							/* translators: 1: Amount with currency placeholder, 2: Interval (day/week/month/quarter/year), 3: Number of billing cycles */
							$message_format = sprintf( __( '{amount} per %1$s (%2$s payments)', 'suredonation' ), $interval_label, $billing_cycles );
						} else {
							/* translators: 1: Amount with currency placeholder, 2: Interval (day/week/month/quarter/year) */
							$message_format = sprintf( __( '{amount} per %s', 'suredonation' ), $interval_label );
						}
					}
					?>
					<span class="sd-payment-value" data-currency="<?php echo esc_attr( strtolower( $this->currency ) ); ?>" data-currency-symbol="<?php echo esc_attr( Payment_Helper::get_currency_symbol( $this->currency ) ); ?>" data-message-format="<?php echo esc_attr( $message_format ); ?>">
						<?php esc_html_e( 'Complete the form to view the amount.', 'suredonation' ); ?>
					</span>
				</div>
				<?php if ( $this->minimum_amount > 0 ) { ?>
					<span class="sd-help">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: Minimum amount with currency */
								__( 'Minimum amount: %s', 'suredonation' ),
								$this->format_currency( $this->minimum_amount, $this->currency )
							)
						);
						?>
					</span>
				<?php } ?>
			</div>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * Validate payment field requirements.
	 *
	 * @return bool True if validation passes, false otherwise.
	 * @since 0.0.1
	 */
	private function validate_payment_requirements() {
		// Check customer email field requirement (highest priority).
		if ( empty( $this->customer_email_field ) ) {
			return false;
		}

		// Check subscription-specific requirements.
		if ( 'subscription' === $this->payment_type ) {
			if ( empty( $this->customer_name_field ) ) {
				return false;
			}

			if ( empty( $this->subscription_plan ) || empty( $this->subscription_plan['name'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Format currency for display.
	 *
	 * @param float  $amount   Amount to format.
	 * @param string $currency Currency code.
	 * @return string
	 * @since 0.0.1
	 */
	private function format_currency( $amount, $currency ) {
		$symbol = Payment_Helper::get_currency_symbol( $currency );

		// Format based on currency.
		if ( in_array( $currency, [ 'JPY', 'KRW' ], true ) ) {
			// No decimal places for these currencies.
			return $symbol . number_format( $amount, 0 );
		}

		return $symbol . number_format( $amount, 2 );
	}

	/**
	 * Get the human-readable label for a payment interval slug.
	 *
	 * @param string $interval_slug The slug (e.g., 'day', 'week', 'month', 'quarter', 'yearly').
	 * @return string The translated interval label, or the slug itself if not found.
	 * @since 0.0.1
	 */
	private function get_interval_label( $interval_slug ) {
		$interval_labels = [
			'day'     => __( 'day', 'suredonation' ),
			'week'    => __( 'week', 'suredonation' ),
			'month'   => __( 'month', 'suredonation' ),
			'quarter' => __( 'quarter', 'suredonation' ),
			'yearly'  => __( 'year', 'suredonation' ),
		];

		return $interval_labels[ $interval_slug ] ?? $interval_slug;
	}

	/**
	 * Render test mode notice for admin users.
	 * Only shows if user has manage_options capability.
	 *
	 * @return string Test mode notice markup or empty string.
	 * @since 0.0.1
	 */
	private function get_test_mode_notice() {
		// Only show to users with manage_options capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		// Build dynamic link to payment settings.
		$settings_url = admin_url( 'admin.php?page=suredonation_settings&tab=payments' );

		ob_start();
		?>
		<div class="sd-test-mode-notice">
			<strong><?php esc_html_e( 'Test mode is enabled:', 'suredonation' ); ?></strong>
			<a href="<?php echo esc_url( $settings_url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Click here to enable live mode and accept payment', 'suredonation' ); ?>
			</a>
		</div>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Render the notice shown when no payment gateway is connected/available.
	 *
	 * Replaces the previous behavior of rendering nothing, which left donors with
	 * a silently broken form. The `data-payment-available="0"` marker lets the
	 * frontend script skip gateway init and hide the otherwise-inert submit
	 * button. See issue #219.
	 *
	 * @return string Notice markup.
	 * @since 1.1.1
	 */
	private function render_gateway_unavailable_notice() {
		$field_classes = $this->get_field_classes( [ 'sd-payment-unavailable' ] );

		ob_start();
		?>
		<div
			data-block-id="<?php echo esc_attr( $this->block_id ); ?>"
			data-form-id="<?php echo esc_attr( $this->form_id ); ?>"
			data-payment-available="0"
			class="<?php echo esc_attr( $field_classes ); ?>"
		>
			<?php echo wp_kses_post( $this->label_markup ); ?>
			<div class="sd-payment-field-wrapper">
				<div class="sd-payment-notice" role="status">
					<p><?php esc_html_e( 'Payment gateways are not configured. Please contact the site administrator.', 'suredonation' ); ?></p>
				</div>
			</div>
		</div>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
