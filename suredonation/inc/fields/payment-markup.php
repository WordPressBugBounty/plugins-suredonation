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
	 * Whether the resolved Stripe account is known to be unable to charge cards.
	 *
	 * @var bool
	 * @since 1.5.1
	 */
	protected $stripe_card_blocked = false;

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
	 * The payment type as configured in the editor, before the default-choice and
	 * Pro-availability rewrites below.
	 *
	 * $payment_type holds the *active* type being rendered; this holds the admin's
	 * intent, so 'both' can be distinguished from a plain single-mode block.
	 *
	 * @var string
	 * @since 1.5.1
	 */
	protected $original_payment_type = 'one-time';

	/**
	 * Which choice is pre-selected in 'both' mode ('one-time' or 'subscription').
	 *
	 * @var string
	 * @since 1.5.1
	 */
	protected $default_payment_choice = 'one-time';

	/**
	 * Admin-configured label for the "make it recurring" opt-in checkbox, shown only
	 * in 'both' mode.
	 *
	 * @var string
	 * @since 1.5.1
	 */
	protected $recurring_toggle_label = '';

	/**
	 * One-time amount configuration. Used only in 'both' mode.
	 *
	 * @var array<string, mixed>
	 * @since 1.5.1
	 */
	protected $one_time_config = [];

	/**
	 * Subscription amount configuration. Used only in 'both' mode.
	 *
	 * @var array<string, mixed>
	 * @since 1.5.1
	 */
	protected $subscription_config = [];

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

		// Get the publishable key for this form's selected account (mode-aware).
		$stripe_account_id            = Stripe_Helper::resolve_account_for_form( $this->form_id );
		$this->stripe_publishable_key = Stripe_Helper::get_stripe_publishable_key( '', $stripe_account_id );

		// Stripe told us at connect (or on account.updated) that this account
		// cannot charge cards in this mode. Rendering the card box anyway is what
		// gave donors an empty "Credit / Debit Card" accordion and lost every
		// attempt in silence. Only a positively-known-bad state counts — an
		// account we have never checked stays enabled, because a stale flag
		// hiding a working gateway is a worse failure than the one being fixed.
		$this->stripe_card_blocked = Stripe_Helper::is_card_capability_blocked( $stripe_account_id );

		// Fall back to one-time if a recurring path is configured but Pro is not
		// active or too old. 'both' collapses too, which is what hides the donor's
		// recurring choice: the chooser only renders while the type is still 'both'.
		//
		// The collapse lives in Payment_Helper::effective_payment_type(), shared
		// with validate_payment_type(). Both have to reach the same answer: if the
		// validator disagrees with what this markup rendered, it rejects the very
		// request its own form invited.
		//
		// $configured_type stays the RAW attribute — the 'both' branch further down
		// keys on it deliberately, because a collapsed form still has 'both' stored
		// and validate_payment_amount() reads that stored config.
		$configured_type         = $attributes['paymentType'] ?? 'one-time';
		$this->payment_type      = Payment_Helper::effective_payment_type( $configured_type );
		$this->subscription_plan = $attributes['subscriptionPlan'] ?? [];
		$this->amount_type       = $attributes['amountType'] ?? 'fixed';
		$this->fixed_amount      = $attributes['fixedAmount'] ?? 10;
		$this->minimum_amount    = $attributes['minimumAmount'] ?? 0;

		// Set customer field mappings.
		$this->customer_name_field  = $attributes['customerNameField'] ?? '';
		$this->customer_email_field = $attributes['customerEmailField'] ?? '';

		// Set variable amount field mapping.
		$this->variable_amount_field = $attributes['variableAmountField'] ?? '';

		// Dual-mode ('both') configuration. $payment_type is rewritten below to the
		// active choice, so keep the admin's intent separately.
		$this->original_payment_type = $this->payment_type;

		$this->default_payment_choice = $attributes['defaultPaymentChoice'] ?? 'one-time';
		if ( ! in_array( $this->default_payment_choice, [ 'one-time', 'subscription' ], true ) ) {
			$this->default_payment_choice = 'one-time';
		}

		$this->recurring_toggle_label = $attributes['recurringToggleLabel'] ?? __( 'Make this a recurring donation', 'suredonation' );

		$this->one_time_config = [
			'amount_type'    => $attributes['oneTimeAmountType'] ?? 'fixed',
			'fixed_amount'   => isset( $attributes['oneTimeFixedAmount'] ) ? (float) $attributes['oneTimeFixedAmount'] : 10.0,
			'minimum_amount' => isset( $attributes['oneTimeMinimumAmount'] ) ? (float) $attributes['oneTimeMinimumAmount'] : 0.0,
			'variable_field' => $attributes['oneTimeVariableAmountField'] ?? '',
		];

		$this->subscription_config = [
			'amount_type'    => $attributes['subscriptionAmountType'] ?? 'fixed',
			'fixed_amount'   => isset( $attributes['subscriptionFixedAmount'] ) ? (float) $attributes['subscriptionFixedAmount'] : 10.0,
			'minimum_amount' => isset( $attributes['subscriptionMinimumAmount'] ) ? (float) $attributes['subscriptionMinimumAmount'] : 0.0,
			'variable_field' => $attributes['subscriptionVariableAmountField'] ?? '',
		];

		// In 'both' mode the initially visible amount — and the mode Stripe Elements
		// initialises in — is the default choice. Rewrite the scalar properties so all
		// the rendering below keeps working unchanged; the chooser's JS re-syncs them
		// client-side when the donor switches.
		//
		// Keyed on the CONFIGURED type, not the (possibly collapsed) active type: when
		// the recurring add-on is inactive a 'both' form collapses to one-time and the
		// chooser never renders, but validate_payment_amount() still reads the stored
		// 'both' config and checks the submitted amount against the one_time sub-config.
		// Seed the active scalars from that sub-config rather than the shared
		// attributes, so the price the donor sees matches the price validation enforces
		// — otherwise a form whose shared and one-time amounts diverge renders one
		// price and then rejects it with no admin signal.
		if ( 'both' === $configured_type ) {
			// The chooser now defaults to one-time and opts into recurring via a
			// checkbox, so a 'both' block always initialises in one-time regardless of
			// the (legacy) defaultPaymentChoice — the JS reveals the subscription panel
			// and re-syncs these scalars when the box is ticked. This is also what
			// validate_payment_amount() demands when Pro is inactive and the form has
			// collapsed to one-time, so the two cases converge.
			$this->default_payment_choice = 'one-time';
			$this->payment_type           = 'one-time';
			$this->amount_type            = $this->one_time_config['amount_type'];
			$this->fixed_amount           = $this->one_time_config['fixed_amount'];
			$this->minimum_amount         = $this->one_time_config['minimum_amount'];
			$this->variable_amount_field  = $this->one_time_config['variable_field'];
		}

		// Parse payment methods from block attributes (with backward compat fallback).
		$block_payment_methods = $attributes['paymentMethods'] ?? null;
		if ( ! is_array( $block_payment_methods ) || empty( $block_payment_methods ) ) {
			$gateway               = $attributes['gateway'] ?? 'stripe';
			$block_payment_methods = [ is_string( $gateway ) ? $gateway : 'stripe' ];
		}

		// Filter to only globally-available gateways.
		$available = [];
		foreach ( $block_payment_methods as $method ) {
			if ( 'stripe' === $method && $this->stripe_connected && ! empty( $this->stripe_publishable_key ) && ! $this->stripe_card_blocked ) {
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

		// The fallback exists so a form still advertises something when nothing
		// resolved as available, but it must not resurrect a gateway we
		// positively know cannot charge: `payment_methods` is what
		// `data-payment-methods` advertises to the frontend script, and listing
		// a gateway whose container was never rendered makes the script select
		// it, try to mount into nothing, and never initialise the gateway that
		// does work — an empty box again, by a different route.
		$fallback = $this->stripe_card_blocked
			? array_values( array_diff( $block_payment_methods, [ 'stripe' ] ) )
			: $block_payment_methods;

		$this->payment_methods = ! empty( $available ) ? $available : $fallback;

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
			<?php
			// data-stripe-key and data-payment-mode are render-time values and only
			// a fallback: the form script fetches the current gateway configuration
			// at runtime (Payment_Helper::get_frontend_gateway_config()) because a
			// page cache can serve this markup long after a test/live switch.
			?>
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
			<?php } elseif ( '' !== $this->get_inactive_variable_field() ) { ?>
				<?php // In 'both' mode, populate the top-level variable-field attribute from whichever choice is the variable one so the amount is resolvable in the initial (one-time) markup; the runtime switch then rewrites it per choice from the per-choice data-* attributes. ?>
				data-variable-amount-field="<?php echo esc_attr( $this->get_inactive_variable_field() ); ?>"
			<?php } ?>
			<?php if ( $this->minimum_amount > 0 ) { ?>
				data-minimum-amount="<?php echo esc_attr( (string) $this->minimum_amount ); ?>"
			<?php } ?>
			<?php if ( 'both' === $this->original_payment_type ) { ?>
				data-original-payment-type="both"
				data-default-payment-choice="<?php echo esc_attr( $this->default_payment_choice ); ?>"
				data-one-time-amount-type="<?php echo esc_attr( Helper::get_string_value( $this->one_time_config['amount_type'] ) ); ?>"
				data-one-time-fixed-amount="<?php echo esc_attr( Helper::get_string_value( $this->one_time_config['fixed_amount'] ) ); ?>"
				data-one-time-minimum-amount="<?php echo esc_attr( Helper::get_string_value( $this->one_time_config['minimum_amount'] ) ); ?>"
				data-one-time-variable-amount-field="<?php echo esc_attr( Helper::get_string_value( $this->one_time_config['variable_field'] ) ); ?>"
				data-subscription-amount-type="<?php echo esc_attr( Helper::get_string_value( $this->subscription_config['amount_type'] ) ); ?>"
				data-subscription-fixed-amount="<?php echo esc_attr( Helper::get_string_value( $this->subscription_config['fixed_amount'] ) ); ?>"
				data-subscription-minimum-amount="<?php echo esc_attr( Helper::get_string_value( $this->subscription_config['minimum_amount'] ) ); ?>"
				data-subscription-variable-amount-field="<?php echo esc_attr( Helper::get_string_value( $this->subscription_config['variable_field'] ) ); ?>"
			<?php } ?>
			<?php if ( $this->has_subscription_path() && ! empty( $this->subscription_plan ) ) { ?>
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
				if ( 'both' === $this->original_payment_type ) {
					// Donor picks the type; both amount panels are rendered and the
					// chooser's JS toggles which one is visible.
					echo wp_kses( $this->render_payment_type_chooser(), Helper::get_allowed_form_html() );
					echo wp_kses( $this->render_dual_amount_displays(), Helper::get_allowed_form_html() );
				} else {
					echo wp_kses( $this->render_amount_display(), Helper::get_allowed_form_html() );
				}

				// Test mode notice.
				if ( 'test' === $this->payment_mode && $has_stripe ) {
					echo wp_kses_post( $this->get_test_mode_notice() );
				}

				// Admin-only: the form renders (Offline is enabled) but no real
				// gateway is connected.
				echo wp_kses_post( $this->get_gateway_setup_notice() );

				// Admin-only: the form renders on its other gateways, but the
				// card form was dropped because Stripe cannot charge.
				echo wp_kses_post( $this->get_capability_notice() );

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
				// The capability check belongs here rather than in the
				// `suredonation_available_payment_methods` loop above: that loop
				// falls back to the raw block selection when it ends up empty, so
				// dropping Stripe there would put it straight back on a form that
				// offers nothing else.
				'enabled'         => $this->stripe_connected && ! empty( $this->stripe_publishable_key ) && ! $this->stripe_card_blocked,
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
	 * Whether this block can create a subscription — either it is subscription-only,
	 * or it is 'both' and the donor may choose recurring.
	 *
	 * @return bool
	 * @since 1.5.1
	 */
	private function has_subscription_path() {
		return 'subscription' === $this->payment_type || 'both' === $this->original_payment_type;
	}

	/**
	 * Variable-amount field slug of the choice that is *not* active on page load.
	 *
	 * Returns '' outside 'both' mode, or when the inactive choice is not variable.
	 *
	 * @return string
	 * @since 1.5.1
	 */
	private function get_inactive_variable_field() {
		if ( 'both' !== $this->original_payment_type ) {
			return '';
		}

		$inactive = 'subscription' === $this->default_payment_choice
			? $this->one_time_config
			: $this->subscription_config;

		return 'variable' === $inactive['amount_type']
			? Helper::get_string_value( $inactive['variable_field'] )
			: '';
	}

	/**
	 * Render the "make it recurring" toggle, shown only in 'both' mode.
	 *
	 * The block always initialises as one-time; ticking this checkbox opts the donor
	 * into the recurring choice (the JS reveals the subscription amount panel and
	 * re-points the block at that config). A single opt-in checkbox is friendlier than
	 * a two-button chooser for a form that is one-time by default.
	 *
	 * @return string Chooser markup.
	 * @since 1.5.1
	 */
	private function render_payment_type_chooser() {
		$checkbox_id        = 'sd-payment-recurring-' . $this->block_id;
		$one_time_panel     = 'sd-payment-amount-' . $this->block_id . '-one-time';
		$subscription_panel = 'sd-payment-amount-' . $this->block_id . '-subscription';

		/**
		 * Filter the label on the "make it recurring" opt-in checkbox. Defaults to the
		 * admin-configured `recurringToggleLabel` block attribute.
		 *
		 * @since 1.5.1
		 * @param string $label    Checkbox label.
		 * @param string $block_id The payment block id.
		 */
		$label = apply_filters(
			'suredonation_payment_recurring_toggle_label',
			$this->recurring_toggle_label,
			$this->block_id
		);

		// Mirror the checkbox-field markup (sd-checkbox-wrap / sd-checkbox-label /
		// sd-input-checkbox / sd-checkbox-text) so this opt-in inherits the exact same
		// styling as every other SureDonation checkbox instead of a bespoke look.
		ob_start();
		?>
		<div class="sd-payment-recurring-toggle">
			<div class="sd-block-wrap sd-checkbox-wrap">
				<label class="sd-checkbox-label" for="<?php echo esc_attr( $checkbox_id ); ?>">
					<input
						class="sd-input-checkbox sd-payment-recurring-checkbox"
						type="checkbox"
						id="<?php echo esc_attr( $checkbox_id ); ?>"
						value="1"
						<?php // Without this, a soft reload restores the tick while the script re-runs from scratch. The handler reconciles the mismatch too; this stops it arising. ?>
						autocomplete="off"
						aria-controls="<?php echo esc_attr( $one_time_panel . ' ' . $subscription_panel ); ?>"
					/>
					<span class="sd-checkbox-text"><?php echo esc_html( Helper::get_string_value( $label ) ); ?></span>
				</label>
			</div>
		</div>
		<?php
		$markup = ob_get_clean();
		return is_string( $markup ) ? $markup : '';
	}

	/**
	 * Render both amount panels for 'both' mode, one per choice. Only the default
	 * choice's panel is visible; the chooser's JS toggles them.
	 *
	 * @return string Amount panels markup.
	 * @since 1.5.1
	 */
	private function render_dual_amount_displays() {
		$panels = [
			'one-time'     => $this->one_time_config,
			'subscription' => $this->subscription_config,
		];

		ob_start();
		foreach ( $panels as $type => $config ) {
			$panel_id = 'sd-payment-amount-' . $this->block_id . '-' . $type;
			?>
			<div
				id="<?php echo esc_attr( $panel_id ); ?>"
				class="sd-payment-amount-block sd-payment-amount-block--<?php echo esc_attr( $type ); ?>"
				data-payment-type="<?php echo esc_attr( $type ); ?>"
				<?php echo $type === $this->default_payment_choice ? '' : 'hidden'; ?>
			>
				<?php
				echo wp_kses(
					$this->render_amount_display(
						$type,
						Helper::get_string_value( $config['amount_type'] ),
						floatval( Helper::get_string_value( $config['fixed_amount'] ) ),
						floatval( Helper::get_string_value( $config['minimum_amount'] ) )
					),
					Helper::get_allowed_form_html()
				);
				?>
			</div>
			<?php
		}
		$markup = ob_get_clean();
		return is_string( $markup ) ? $markup : '';
	}

	/**
	 * Render amount display section.
	 *
	 * Defaults to this block's active configuration. 'both' mode passes each choice's
	 * configuration explicitly so the same renderer produces both panels.
	 *
	 * @param string|null $payment_type   Payment type to render for, or null for the active one.
	 * @param string|null $amount_type    'fixed' or 'variable', or null for the active one.
	 * @param float|null  $fixed_amount   Fixed amount, or null for the active one.
	 * @param float|null  $minimum_amount Minimum amount, or null for the active one.
	 * @return string Amount display HTML.
	 * @since 0.0.1
	 */
	private function render_amount_display( $payment_type = null, $amount_type = null, $fixed_amount = null, $minimum_amount = null ) {
		$payment_type   = null === $payment_type ? $this->payment_type : $payment_type;
		$amount_type    = null === $amount_type ? $this->amount_type : $amount_type;
		$fixed_amount   = null === $fixed_amount ? $this->fixed_amount : $fixed_amount;
		$minimum_amount = null === $minimum_amount ? $this->minimum_amount : $minimum_amount;

		ob_start();

		if ( 'fixed' === $amount_type ) {
			?>
			<!-- Fixed Payment Amount Display. -->
			<div class="sd-payment-amount sd-label">
				<span class="sd-payment-value">
					<?php
					if ( 'subscription' === $payment_type && ! empty( $this->subscription_plan ) ) {
						$interval       = isset( $this->subscription_plan['interval'] ) ? Helper::get_string_value( $this->subscription_plan['interval'] ) : 'month';
						$billing_cycles = isset( $this->subscription_plan['billingCycles'] ) ? Helper::get_string_value( $this->subscription_plan['billingCycles'] ) : '0';
						$interval_label = $this->get_interval_label( $interval );

						// Build subscription text.
						if ( 'ongoing' === $billing_cycles ) {
							echo esc_html(
								sprintf(
									/* translators: 1: Amount with currency, 2: Interval (day/week/month/quarter/year) */
									__( '%1$s per %2$s (until cancelled)', 'suredonation' ),
									$this->format_currency( $fixed_amount, $this->currency ),
									$interval_label
								)
							);
						} elseif ( (int) $billing_cycles > 0 ) {
							echo esc_html(
								sprintf(
									/* translators: 1: Amount with currency, 2: Interval (day/week/month/quarter/year), 3: Number of billing cycles */
									__( '%1$s per %2$s (%3$s payments)', 'suredonation' ),
									$this->format_currency( $fixed_amount, $this->currency ),
									$interval_label,
									$billing_cycles
								)
							);
						} else {
							echo esc_html(
								sprintf(
									/* translators: 1: Amount with currency, 2: Interval (day/week/month/quarter/year) */
									__( '%1$s per %2$s', 'suredonation' ),
									$this->format_currency( $fixed_amount, $this->currency ),
									$interval_label
								)
							);
						}
					} else {
						echo esc_html( $this->format_currency( $fixed_amount, $this->currency ) );
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
					if ( 'subscription' === $payment_type && ! empty( $this->subscription_plan ) ) {
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
				<?php if ( $minimum_amount > 0 ) { ?>
					<span class="sd-help">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: Minimum amount with currency */
								__( 'Minimum amount: %s', 'suredonation' ),
								$this->format_currency( $minimum_amount, $this->currency )
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

		// Check subscription-specific requirements. Keyed on whether a subscription
		// is reachable at all, not on the active type: in 'both' mode the donor can
		// switch to recurring even when one-time is the default, so the name field
		// and plan must be configured either way.
		if ( $this->has_subscription_path() ) {
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
		// Delegate to the single source of truth so decimal handling and the
		// currency sign position stay consistent with every other surface.
		return Payment_Helper::format_amount( $amount, $currency );
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

		$this->prevent_page_cache();

		// Build dynamic link to payment settings (shared, hash-routed URL).
		// Tagged so the settings screen can record that this notice is what brought
		// the admin over. No subpage: the payments screen falls back to the Stripe
		// panel, which is where the arrival is read.
		$settings_url = Payment_Helper::get_settings_url( '', [ 'sd_notice' => 'frontend_test_mode' ] );

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

		// Both audiences are told the gateways are not configured, but only donors
		// are told to contact the administrator — an admin *is* that person and
		// gets the actionable button below, so the sentence would send them in a
		// circle. Mirrors the capability + settings-URL pattern used by
		// get_test_mode_notice().
		$is_admin       = current_user_can( 'manage_options' );
		$configure_url  = '';
		$configure_text = '';

		// The two audiences get different copy here, so either variant being
		// cached and replayed to the other is wrong.
		if ( $is_admin ) {
			$this->prevent_page_cache();
		}

		// "Not configured" is wrong when Stripe is connected and simply cannot
		// charge — the admin would go looking for a setup step they already
		// completed, and reconnecting does not fix an account-side restriction.
		// Say what is actually true instead. Donors are never told which
		// gateway or why: it is the site's problem, not theirs.
		$blocked_only = $this->stripe_card_blocked;

		if ( $blocked_only ) {
			$notice_text = $is_admin
				? __( 'Stripe is connected but cannot accept card payments for this account.', 'suredonation' )
				: __( 'Card payments are currently unavailable. Please contact the site administrator.', 'suredonation' );
		} else {
			$notice_text = $is_admin
				? __( 'Payment gateways are not configured.', 'suredonation' )
				: __( 'Payment gateways are not configured. Please contact the site administrator.', 'suredonation' );
		}

		if ( $is_admin && $blocked_only ) {
			// The fix is in the gateway's own settings, where the capability
			// warning and the Stripe dashboard link live.
			$configure_url  = Payment_Helper::get_settings_url( 'stripe', [ 'sd_notice' => 'stripe_capability' ] );
			$configure_text = __( 'Review Stripe account status', 'suredonation' );
		} elseif ( $is_admin ) {
			// Route the admin to the right place. If a gateway is already usable
			// on the site, the block simply hasn't selected it — send them to
			// this form's editor to fix the payment block. Otherwise no gateway
			// is set up at all — send them to global payment settings.
			$edit_link = $this->form_id > 0 ? get_edit_post_link( (int) $this->form_id ) : '';

			if ( Payment_Helper::has_usable_gateway() && $edit_link ) {
				// Deliberately untagged: this branch goes to the block editor, not
				// the settings screen, so there is no arrival for it to record.
				// Tracking it would need a hook in the editor, which is not worth
				// a tracker on a public page — the reason this whole mechanism
				// records arrivals rather than clicks.
				$configure_url  = $edit_link;
				$configure_text = __( 'Edit this form’s payment settings', 'suredonation' );
			} else {
				// No gateway connected — send the admin straight to the gateway
				// connect screen (Stripe) rather than the currency/mode page.
				$configure_url  = Payment_Helper::get_settings_url( 'stripe', [ 'sd_notice' => 'frontend_gateway_unavailable' ] );
				$configure_text = __( 'Configure payment gateway', 'suredonation' );
			}
		}

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
					<p><?php echo esc_html( $notice_text ); ?></p>
					<?php echo wp_kses_post( $is_admin ? $this->render_configure_link( $configure_url, $configure_text ) : '' ); ?>
				</div>
			</div>
		</div>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Render the admin-only notice shown when the payment field renders but no
	 * real payment gateway is connected.
	 *
	 * Enabling Offline Donation makes the field render normally, so the
	 * donor-facing "gateways are not configured" notice never fires — an admin
	 * viewing the live form gets no signal that neither Stripe nor PayPal was
	 * ever connected. Offline is a manual method, not a gateway, so the prompt
	 * is still warranted. Donors see nothing: the form works for them.
	 *
	 * @return string Notice markup, or empty string when a gateway is connected
	 *                or the viewer is not an administrator.
	 * @since 1.5.1
	 */
	private function get_gateway_setup_notice() {
		if ( ! current_user_can( 'manage_options' ) || Payment_Helper::is_any_gateway_connected() ) {
			return '';
		}

		$this->prevent_page_cache();

		ob_start();
		?>
		<div class="sd-payment-notice sd-payment-notice--admin" role="status">
			<p><?php esc_html_e( 'No payment gateway is connected, so donors can only give using the offline method.', 'suredonation' ); ?></p>
			<?php
			echo wp_kses_post(
				$this->render_configure_link(
					Payment_Helper::get_settings_url( 'stripe', [ 'sd_notice' => 'frontend_gateway_setup' ] ),
					__( 'Configure payment gateway', 'suredonation' )
				)
			);
			?>
		</div>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Render the admin-only notice shown when the card form was dropped because
	 * the connected Stripe account cannot charge cards.
	 *
	 * Only fires when the field still renders, which means another gateway
	 * covered for it. That is the quiet case: donors can still give, so the
	 * donor-facing notice never runs, and an admin looking at the live form sees
	 * a working PayPal button with no hint that the card option they configured
	 * has silently gone. Leaving that unsaid is the same silence this whole
	 * feature exists to end, just relocated — the site would keep taking a
	 * fraction of the donations it expects until someone happened to notice.
	 *
	 * Donors see nothing: the form works for them, and the account state is the
	 * site's business.
	 *
	 * @return string Notice markup, or empty string when the card form was not
	 *                dropped or the viewer is not an administrator.
	 * @since 1.5.1
	 */
	private function get_capability_notice() {
		if ( ! $this->stripe_card_blocked || ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$this->prevent_page_cache();

		ob_start();
		?>
		<div class="sd-payment-notice sd-payment-notice--admin" role="status">
			<p><?php esc_html_e( 'Stripe is connected but cannot accept card payments for this account, so the card option is hidden on this form.', 'suredonation' ); ?></p>
			<?php
			echo wp_kses_post(
				$this->render_configure_link(
					// The marker rides the query string so the settings screen
					// can record that this notice is what brought the admin
					// there. Tracking the arrival rather than the click keeps
					// every byte of analytics off the public page the notice
					// renders on.
					Payment_Helper::get_settings_url( 'stripe', [ 'sd_notice' => 'stripe_capability' ] ),
					__( 'Review Stripe account status', 'suredonation' )
				)
			);
			?>
		</div>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Keep the current response out of any full-page cache.
	 *
	 * Several notices here are chosen by a `current_user_can()` check while
	 * rendering a public donation page, so the HTML an admin receives differs
	 * from a donor's. A full-page cache in front of the site does not know that:
	 * if it stores an admin's copy, every later visitor is served admin-only
	 * text — the account's Stripe status, and a deep link into wp-admin — and if
	 * it stores a donor's, an admin stops being warned at all.
	 *
	 * Most caches already skip logged-in users, so this is belt to that braces;
	 * it is cheap, and the failure it prevents is silent. `DONOTCACHEPAGE` is the
	 * convention every major WordPress cache honours, and it is still read after
	 * a block renders. `nocache_headers()` covers proxies, guarded because
	 * headers are usually long gone by the time a block runs.
	 *
	 * @return void
	 * @since 1.5.1
	 */
	private function prevent_page_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// Intentionally unprefixed: this is the name the caching plugins
			// read, so a prefixed one would do nothing at all.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}

		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Render the actionable settings link shared by the payment notices.
	 *
	 * @param string $url  Target URL.
	 * @param string $text Link text.
	 * @return string Link markup, or empty string when there is nothing to link to.
	 * @since 1.5.1
	 */
	private function render_configure_link( $url, $text ) {
		if ( '' === $url || '' === $text ) {
			return '';
		}

		ob_start();
		?>
		<a
			class="sd-payment-notice__configure"
			href="<?php echo esc_url( $url ); ?>"
			target="_blank"
			rel="noopener noreferrer"
		>
			<?php echo esc_html( $text ); ?>
		</a>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}
}
