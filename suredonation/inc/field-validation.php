<?php
/**
 * Field Validation Class
 *
 * Handles field validation for SureDonation forms.
 * Stores block configuration on form save and retrieves it for validation.
 *
 * @package SureDonation
 * @since 0.0.1
 */

namespace SureDonation\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Field Validation Class
 */
class Field_Validation {
	/**
	 * Meta key for storing block configuration.
	 *
	 * @since 0.0.1
	 */
	public const BLOCK_CONFIG_META_KEY = '_suredonation_block_config';

	/**
	 * Key within the consolidated suredonation_options array that stores the
	 * admin-overridden default validation messages (Global Settings → Form
	 * Validation). Per-field messages always take precedence over these.
	 *
	 * @since 1.1.0
	 */
	public const VALIDATION_MESSAGES_OPTION_KEY = 'validation_messages';

	/**
	 * Field blocks whose values participate in field-level validation.
	 *
	 * @since 1.1.0
	 */
	public const VALIDATABLE_BLOCKS = [
		'suredonation/input',
		'suredonation/email',
		'suredonation/number',
		'suredonation/dropdown',
		'suredonation/phone',
		'suredonation/url',
	];

	/**
	 * Add block configuration for form fields.
	 *
	 * This function processes blocks in a form and stores their configuration as post meta.
	 * It extracts payment block settings (amount type, fixed amount, minimum amount, etc.)
	 * which are used for server-side validation to prevent payment manipulation.
	 *
	 * @param array<mixed> $blocks  Array of blocks to process.
	 * @param int          $form_id Form post ID.
	 * @return void
	 * @since 0.0.1
	 */
	public static function add_block_config( $blocks, $form_id ) {
		// Initialize array to store processed block configurations.
		$block_config = [];

		// Process blocks recursively.
		self::process_blocks_recursive( $blocks, $block_config );

		// Only update meta if we have processed configurations.
		if ( ! empty( $block_config ) ) {
			update_post_meta( $form_id, self::BLOCK_CONFIG_META_KEY, $block_config );
		}
	}

	/**
	 * Retrieve or migrate the block configuration for legacy forms.
	 *
	 * This function checks if the _suredonation_block_config post meta exists for the given form ID.
	 * If not found, it attempts to parse the form's post content and generate the block config.
	 *
	 * @param int $form_id The ID of the form post.
	 * @since 0.0.1
	 * @return array<string, array<string, mixed>>|null The block configuration array, or null if not found or invalid.
	 */
	public static function get_or_migrate_block_config_for_legacy_form( $form_id ) {
		// Validate that $form_id is a positive integer.
		if ( ! is_int( $form_id ) || $form_id <= 0 ) {
			return null;
		}

		// Retrieve the block config from post meta.
		$block_config = get_post_meta( $form_id, self::BLOCK_CONFIG_META_KEY, true );
		if ( ! empty( $block_config ) && is_array( $block_config ) ) {
			// If it exists and is an array, return it directly (no migration needed).
			return $block_config;
		}

		// Get the post by ID and validate.
		$post = get_post( $form_id );
		if ( ! ( $post instanceof \WP_Post ) || empty( $post->post_content ) ) {
			return null;
		}

		// Parse the blocks from the post content and attempt migration.
		if ( function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $post->post_content );
			if ( is_array( $blocks ) && ! empty( $blocks ) ) {
				self::add_block_config( $blocks, $form_id );
			}
		}

		// Retrieve the block config again after migration attempt.
		$block_config = get_post_meta( $form_id, self::BLOCK_CONFIG_META_KEY, true );

		return ! empty( $block_config ) && is_array( $block_config ) ? $block_config : null;
	}

	/**
	 * Process blocks recursively to extract configuration.
	 *
	 * @param array<mixed> $blocks       Array of blocks to process.
	 * @param array<mixed> $block_config Reference to block config array.
	 * @return void
	 * @since 0.0.1
	 */
	private static function process_blocks_recursive( $blocks, &$block_config ) {
		foreach ( $blocks as $block ) {
			// Ensure $block is an array and has the required structure.
			if ( ! is_array( $block ) ) {
				continue;
			}

			// Process inner blocks recursively (for columns, groups, etc.).
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::process_blocks_recursive( $block['innerBlocks'], $block_config );
			}

			if ( ! isset( $block['blockName'] ) || ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
				continue;
			}

			// Validate block_id exists.
			if ( ! array_key_exists( 'block_id', $block['attrs'] ) || empty( $block['attrs']['block_id'] ) || ! is_string( $block['attrs']['block_id'] ) ) {
				continue;
			}

			$block_id   = sanitize_text_field( $block['attrs']['block_id'] );
			$block_name = $block['blockName'];

			// Process specific block types.
			$processed_config = null;

			switch ( $block_name ) {
				case 'suredonation/payment':
					$processed_config = self::process_payment_block( $block['attrs'], $blocks );
					break;
				case 'suredonation/donation-amount':
					$processed_config = self::process_donation_amount_block( $block['attrs'] );
					break;
				case 'suredonation/number':
					$processed_config = self::process_number_block( $block['attrs'] );
					break;
				case 'suredonation/cover-fees':
					$processed_config = self::process_cover_fees_block( $block['attrs'] );
					break;
				case 'suredonation/input':
					$processed_config = self::process_input_block( $block['attrs'] );
					break;
				case 'suredonation/email':
					$processed_config = self::process_email_block( $block['attrs'] );
					break;
				case 'suredonation/dropdown':
					$processed_config = self::process_dropdown_block( $block['attrs'] );
					break;
				case 'suredonation/phone':
					$processed_config = self::process_phone_block( $block['attrs'] );
					break;
				case 'suredonation/url':
					$processed_config = self::process_url_block( $block['attrs'] );
					break;
			}

			/**
			 * Filter the stored validation config for a field block.
			 *
			 * Lets extensions contribute configuration for field blocks the
			 * core does not handle (e.g. phone, address, url) so their rules are
			 * persisted on save and picked up by validate_form_data(). Return a
			 * non-empty array (including at least a 'required' flag plus any rule
			 * values the validator needs) to store it under the block id.
			 *
			 * @since 1.1.0
			 * @param array<string, mixed>|null $processed_config Config from core (null when unhandled).
			 * @param string                    $block_name       Block name.
			 * @param array<string, mixed>      $attrs            Block attributes.
			 * @param array<mixed>              $blocks           All blocks in the form.
			 */
			$processed_config = apply_filters( 'suredonation_field_block_config', $processed_config, $block_name, $block['attrs'], $blocks );

			// If block was processed, store its configuration.
			if ( null !== $processed_config && ! empty( $processed_config ) ) {
				$processed_config['block_name'] = $block_name;

				// Add the slug to the configuration.
				if ( isset( $block['attrs']['slug'] ) && ! empty( $block['attrs']['slug'] ) ) {
					$processed_config['slug'] = sanitize_text_field( $block['attrs']['slug'] );
				}

				$block_config[ $block_id ] = $processed_config;
			}
		}
	}

	/**
	 * Process payment block configuration.
	 *
	 * Extracts payment-related settings that are needed for server-side validation:
	 * - amount_type: 'fixed' or 'variable'
	 * - fixed_amount: The configured fixed amount
	 * - minimum_amount: The minimum allowed amount for variable amounts
	 * - variable_amount_field: The slug of the field providing the variable amount
	 *
	 * @param array<mixed> $attrs  Block attributes.
	 * @param array<mixed> $blocks All blocks in the form.
	 * @return array<string, mixed> Processed payment configuration.
	 * @since 0.0.1
	 */
	private static function process_payment_block( $attrs, $blocks ) {
		$payment_config = [];

		// Extract payment type (one-time or subscription).
		// Default to 'one-time' if not set (Gutenberg may not save default values).
		$payment_config['payment_type'] = isset( $attrs['paymentType'] ) && is_string( $attrs['paymentType'] )
			? sanitize_text_field( $attrs['paymentType'] )
			: 'one-time';

		// Extract amount type (fixed or variable).
		// IMPORTANT: Always store this - Gutenberg may not save attributes that match defaults.
		// Default to 'fixed' which is the block.json default.
		$payment_config['amount_type'] = isset( $attrs['amountType'] ) && is_string( $attrs['amountType'] )
			? sanitize_text_field( $attrs['amountType'] )
			: 'fixed';

		// Extract configured fixed amount.
		// Default to 10.00 to match block.json default.
		$payment_config['fixed_amount'] = isset( $attrs['fixedAmount'] )
			? floatval( $attrs['fixedAmount'] )
			: 10.00;

		// Extract minimum amount for variable amounts.
		// Defaults to 0 (no minimum) — only enforced if the block setting specifies one.
		$payment_config['minimum_amount'] = isset( $attrs['minimumAmount'] )
			? floatval( $attrs['minimumAmount'] )
			: 0.0;

		// Extract variable amount field reference.
		if ( isset( $attrs['variableAmountField'] ) ) {
			$variable_amount_slug                    = sanitize_text_field( $attrs['variableAmountField'] );
			$payment_config['variable_amount_field'] = $variable_amount_slug;

			// Find and add the block name from which the variable amount field comes from.
			if ( ! empty( $variable_amount_slug ) && is_array( $blocks ) ) {
				$block_name = self::find_block_name_by_slug( $blocks, $variable_amount_slug );
				if ( $block_name ) {
					$payment_config['variable_amount_field_block_name'] = $block_name;
				}
			}
		}

		return $payment_config;
	}

	/**
	 * Find block name by slug recursively.
	 *
	 * @param array<mixed> $blocks Array of blocks.
	 * @param string       $slug   Slug to find.
	 * @return string|null Block name if found, null otherwise.
	 * @since 0.0.1
	 */
	private static function find_block_name_by_slug( $blocks, $slug ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			// Check inner blocks first.
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = self::find_block_name_by_slug( $block['innerBlocks'], $slug );
				if ( $found ) {
					return $found;
				}
			}

			if ( isset( $block['attrs']['slug'] ) && $block['attrs']['slug'] === $slug ) {
				return $block['blockName'];
			}
		}
		return null;
	}

	/**
	 * Resolve the slugs of the core donor fields (name, email, variable amount
	 * and the optional mapped phone) from a form's saved payment block.
	 *
	 * These fields are surfaced as first-class donation data and persisted in
	 * their own columns, so the stored "additional" field set omits them.
	 * Deriving the slugs from the saved form here (instead of trusting a
	 * client-supplied list) keeps the exclusion authoritative — a tampered
	 * submission cannot smuggle a core field into the additional set.
	 *
	 * @since 1.1.1
	 * @param int $form_id The donation form post ID.
	 * @return array<int, string> List of core field slugs (empty when none/invalid).
	 */
	public static function get_core_field_slugs( $form_id ) {
		if ( ! is_int( $form_id ) || $form_id <= 0 || ! function_exists( 'parse_blocks' ) ) {
			return [];
		}

		$post = get_post( $form_id );
		if ( ! ( $post instanceof \WP_Post ) || empty( $post->post_content ) ) {
			return [];
		}

		$payment_attrs = self::find_payment_block_attrs( parse_blocks( $post->post_content ) );
		if ( empty( $payment_attrs ) ) {
			return [];
		}

		$slugs = [];
		foreach ( [ 'customerNameField', 'customerEmailField', 'customerPhoneField', 'variableAmountField' ] as $attr ) {
			if ( isset( $payment_attrs[ $attr ] ) && is_string( $payment_attrs[ $attr ] ) ) {
				$slug = sanitize_text_field( $payment_attrs[ $attr ] );
				if ( '' !== $slug ) {
					$slugs[] = $slug;
				}
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Find the suredonation/payment block's attributes recursively.
	 *
	 * @since 1.1.1
	 * @param array<mixed> $blocks Array of parsed blocks.
	 * @return array<string, mixed>|null The payment block attributes, or null when absent.
	 */
	private static function find_payment_block_attrs( $blocks ) {
		if ( ! is_array( $blocks ) ) {
			return null;
		}

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( isset( $block['blockName'] ) && 'suredonation/payment' === $block['blockName'] && isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				return $block['attrs'];
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = self::find_payment_block_attrs( $block['innerBlocks'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Resolve the slug of the field mapped to the donor phone on the payment block.
	 *
	 * The mapping is optional: when an author maps a Phone field via the payment
	 * block's "Customer Phone Field" picker, its value is stored in the dedicated
	 * donor_phone column. Returning the slug lets the submission handlers read the
	 * already-validated value from the submitted fields rather than trusting a
	 * separate client-supplied donor_phone field.
	 *
	 * @since 1.1.1
	 * @param int $form_id The donation form post ID.
	 * @return string The mapped phone field slug, or '' when unset/invalid.
	 */
	public static function get_mapped_phone_slug( $form_id ) {
		if ( ! is_int( $form_id ) || $form_id <= 0 || ! function_exists( 'parse_blocks' ) ) {
			return '';
		}

		$post = get_post( $form_id );
		if ( ! ( $post instanceof \WP_Post ) || empty( $post->post_content ) ) {
			return '';
		}

		$payment_attrs = self::find_payment_block_attrs( parse_blocks( $post->post_content ) );
		if ( empty( $payment_attrs ) || ! isset( $payment_attrs['customerPhoneField'] ) || ! is_string( $payment_attrs['customerPhoneField'] ) ) {
			return '';
		}

		return sanitize_text_field( $payment_attrs['customerPhoneField'] );
	}

	/**
	 * Build a map of field slug => label from a form's saved blocks.
	 *
	 * The label persisted with each submitted field is resolved from the saved
	 * form (the authoritative source) rather than scraped from the rendered
	 * page and trusted from the request — mirroring how SureForms recovers a
	 * field's label server-side instead of from client-supplied text. Gutenberg
	 * omits attributes left at their default, so a slug missing from this map
	 * simply has no customized label and the caller falls back to the label
	 * sent with the submission.
	 *
	 * @since 1.1.1
	 * @param int $form_id The donation form post ID.
	 * @return array<string, string> Map of field slug => label (empty when none/invalid).
	 */
	public static function get_field_labels_map( $form_id ) {
		if ( ! is_int( $form_id ) || $form_id <= 0 || ! function_exists( 'parse_blocks' ) ) {
			return [];
		}

		$post = get_post( $form_id );
		if ( ! ( $post instanceof \WP_Post ) || empty( $post->post_content ) ) {
			return [];
		}

		$labels = [];
		self::collect_field_labels( parse_blocks( $post->post_content ), $labels );

		return $labels;
	}

	/**
	 * Recursively collect slug => label pairs from parsed blocks.
	 *
	 * Inner blocks are walked first so nested sub-fields are captured before
	 * their container; the first label seen for a slug wins.
	 *
	 * @since 1.1.1
	 * @param array<mixed>          $blocks Parsed blocks.
	 * @param array<string, string> $labels Accumulator passed by reference.
	 * @return void
	 */
	private static function collect_field_labels( $blocks, &$labels ) {
		if ( ! is_array( $blocks ) ) {
			return;
		}

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_field_labels( $block['innerBlocks'], $labels );
			}

			if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
				continue;
			}

			$slug = isset( $block['attrs']['slug'] ) && is_string( $block['attrs']['slug'] )
				? sanitize_text_field( $block['attrs']['slug'] )
				: '';
			if ( '' === $slug || isset( $labels[ $slug ] ) ) {
				continue;
			}

			if ( isset( $block['attrs']['label'] ) && is_string( $block['attrs']['label'] ) ) {
				$labels[ $slug ] = sanitize_text_field( $block['attrs']['label'] );
			}
		}
	}

	/**
	 * Process donation-amount block configuration.
	 *
	 * @param array<mixed> $attrs Block attributes.
	 * @return array<string, mixed> Processed donation-amount configuration.
	 * @since 0.0.1
	 */
	private static function process_donation_amount_block( $attrs ) {
		$donation_amount_config = [];

		// Extract required field.
		if ( isset( $attrs['required'] ) ) {
			$donation_amount_config['required'] = ! empty( $attrs['required'] );
		}

		// Extract choice type (radio or checkbox).
		if ( isset( $attrs['choiceType'] ) ) {
			$donation_amount_config['choice_type'] = sanitize_text_field( $attrs['choiceType'] );
		}

		// Extract options with their full structure (label, value).
		if ( isset( $attrs['options'] ) && is_array( $attrs['options'] ) ) {
			$sanitized_options = [];
			foreach ( $attrs['options'] as $option ) {
				if ( is_array( $option ) ) {
					$sanitized_options[] = [
						'label' => isset( $option['label'] ) ? sanitize_text_field( $option['label'] ) : '',
						'value' => isset( $option['value'] ) ? sanitize_text_field( $option['value'] ) : '',
					];
				}
			}
			$donation_amount_config['options'] = $sanitized_options;
		}

		// Custom amount settings (radio-mode only).
		$donation_amount_config['allow_custom_amount'] = ! isset( $attrs['allowCustomAmount'] ) || ! empty( $attrs['allowCustomAmount'] );
		$donation_amount_config['custom_amount_min']   = isset( $attrs['customAmountMin'] ) ? (float) $attrs['customAmountMin'] : 0.0;
		$donation_amount_config['custom_amount_max']   = isset( $attrs['customAmountMax'] ) ? (float) $attrs['customAmountMax'] : 0.0;

		return $donation_amount_config;
	}

	/**
	 * Process cover-fees block configuration.
	 *
	 * Resolves global vs block-level fee rates and stores them for server-side validation.
	 *
	 * @param array<mixed> $attrs Block attributes.
	 * @return array<string, mixed> Processed cover fees configuration.
	 * @since 1.0.0
	 */
	private static function process_cover_fees_block( $attrs ) {
		$use_global = $attrs['useGlobalDefaults'] ?? true;

		if ( $use_global ) {
			$fee_config = \SureDonation\Inc\Payments\Payment_Helper::get_fee_recovery_settings();
		} else {
			$fee_config = [
				'fee_percentage' => isset( $attrs['feePercentage'] ) ? floatval( $attrs['feePercentage'] ) : 2.9,
				'fee_fixed'      => isset( $attrs['feeFixed'] ) ? floatval( $attrs['feeFixed'] ) : 0.30,
				'fee_mode'       => $attrs['feeMode'] ?? 'all_gateways',
				'gateways'       => $attrs['gatewayFees'] ?? [],
			];
		}

		return [
			'use_global_defaults' => $use_global,
			'fee_percentage'      => (float) ( $fee_config['fee_percentage'] ?? 2.9 ),
			'fee_fixed'           => (float) ( $fee_config['fee_fixed'] ?? 0.30 ),
			'fee_mode'            => $fee_config['fee_mode'] ?? 'all_gateways',
			'gateway_fees'        => $fee_config['gateways'] ?? [],
		];
	}

	/**
	 * Process number block configuration.
	 *
	 * @param array<mixed> $attrs Block attributes.
	 * @return array<string, mixed> Processed number block configuration.
	 * @since 0.0.1
	 */
	private static function process_number_block( $attrs ) {
		$number_config = [];

		// Extract required field.
		if ( isset( $attrs['required'] ) ) {
			$number_config['required'] = ! empty( $attrs['required'] );
		}

		// Extract min value.
		if ( isset( $attrs['min'] ) ) {
			$number_config['min'] = floatval( $attrs['min'] );
		}

		// Extract max value.
		if ( isset( $attrs['max'] ) ) {
			$number_config['max'] = floatval( $attrs['max'] );
		}

		// Field-level min/max value rules for client + server validation.
		//
		// These are stored under dedicated keys (read from the block's real
		// `minValue`/`maxValue` attributes) and are deliberately kept separate
		// from the amount-path `min`/`max` keys above, which are consumed by
		// Payment_Helper::validate_number_field_amount(). Coerced with absint to
		// match Number_Markup, which renders integer min/max — keeping the
		// rendered HTML constraints and server validation in sync. The markup
		// mirrors these exact defaults: min is always present (default 1) and
		// max only applies when greater than zero.
		$number_config['validation_min'] = isset( $attrs['minValue'] ) ? absint( Helper::get_string_value( $attrs['minValue'] ) ) : 1;
		$number_config['validation_max'] = isset( $attrs['maxValue'] ) ? absint( Helper::get_string_value( $attrs['maxValue'] ) ) : 0;

		// Per-field custom required message.
		$error_msg = isset( $attrs['errorMsg'] ) ? sanitize_text_field( Helper::get_string_value( $attrs['errorMsg'] ) ) : '';
		if ( '' !== $error_msg ) {
			$number_config['error_msg'] = $error_msg;
		}

		return $number_config;
	}

	/**
	 * Process input (text) block configuration.
	 *
	 * Extracts the field-level validation rules — required, max length and the
	 * optional per-field custom required message — for server-side enforcement.
	 *
	 * @param array<mixed> $attrs Block attributes.
	 * @return array<string, mixed> Processed input block configuration.
	 * @since 1.1.0
	 */
	private static function process_input_block( $attrs ) {
		$input_config = [
			'required'   => ! empty( $attrs['required'] ),
			'max_length' => isset( $attrs['maxLength'] ) ? absint( Helper::get_string_value( $attrs['maxLength'] ) ) : 100,
		];

		$error_msg = isset( $attrs['errorMsg'] ) ? sanitize_text_field( Helper::get_string_value( $attrs['errorMsg'] ) ) : '';
		if ( '' !== $error_msg ) {
			$input_config['error_msg'] = $error_msg;
		}

		return $input_config;
	}

	/**
	 * Process email block configuration.
	 *
	 * Extracts required state, the optional per-field custom required message
	 * and the per-field invalid-email message for server-side enforcement.
	 *
	 * @param array<mixed> $attrs Block attributes.
	 * @return array<string, mixed> Processed email block configuration.
	 * @since 1.1.0
	 */
	private static function process_email_block( $attrs ) {
		$email_config = [
			'required' => ! empty( $attrs['required'] ),
		];

		$error_msg = isset( $attrs['errorMsg'] ) ? sanitize_text_field( Helper::get_string_value( $attrs['errorMsg'] ) ) : '';
		if ( '' !== $error_msg ) {
			$email_config['error_msg'] = $error_msg;
		}

		$invalid_email_msg = isset( $attrs['invalidEmailMsg'] ) ? sanitize_text_field( Helper::get_string_value( $attrs['invalidEmailMsg'] ) ) : '';
		if ( '' !== $invalid_email_msg ) {
			$email_config['invalid_email_msg'] = $invalid_email_msg;
		}

		return $email_config;
	}

	/**
	 * Process dropdown block configuration.
	 *
	 * Stores required state, multi-select bounds and the allowed option labels so
	 * the server can enforce required/min/max selections and reject tampered values.
	 *
	 * @param array<mixed> $attrs Block attributes.
	 * @return array<string, mixed> Processed dropdown block configuration.
	 * @since 1.1.1
	 */
	private static function process_dropdown_block( $attrs ) {
		$dropdown_config = [
			'required'      => ! empty( $attrs['required'] ),
			'multi_select'  => ! empty( $attrs['multiSelect'] ),
			'min_selection' => isset( $attrs['minSelection'] ) ? absint( Helper::get_string_value( $attrs['minSelection'] ) ) : 0,
			'max_selection' => isset( $attrs['maxSelection'] ) ? absint( Helper::get_string_value( $attrs['maxSelection'] ) ) : 0,
		];

		// Allowed option labels (the submitted value(s) must match one of these).
		$options = [];
		if ( isset( $attrs['options'] ) && is_array( $attrs['options'] ) ) {
			foreach ( $attrs['options'] as $option ) {
				if ( is_array( $option ) && isset( $option['label'] ) && '' !== $option['label'] ) {
					$options[] = sanitize_text_field( Helper::get_string_value( $option['label'] ) );
				}
			}
		}
		$dropdown_config['options'] = $options;

		$error_msg = isset( $attrs['errorMsg'] ) ? sanitize_text_field( Helper::get_string_value( $attrs['errorMsg'] ) ) : '';
		if ( '' !== $error_msg ) {
			$dropdown_config['error_msg'] = $error_msg;
		}

		return $dropdown_config;
	}

	/**
	 * Process phone block configuration.
	 *
	 * Stores required state and the optional per-field custom required message for
	 * server-side enforcement. Phone-number format is validated loosely (see
	 * validate_field_value) because the submitted value is the E.164-style number
	 * produced by intl-tel-input.
	 *
	 * @param array<mixed> $attrs Block attributes.
	 * @return array<string, mixed> Processed phone block configuration.
	 * @since 1.1.1
	 */
	private static function process_phone_block( $attrs ) {
		$phone_config = [
			'required' => ! empty( $attrs['required'] ),
		];

		$error_msg = isset( $attrs['errorMsg'] ) ? sanitize_text_field( Helper::get_string_value( $attrs['errorMsg'] ) ) : '';
		if ( '' !== $error_msg ) {
			$phone_config['error_msg'] = $error_msg;
		}

		return $phone_config;
	}

	/**
	 * Process url block configuration.
	 *
	 * Stores required state, the optional per-field custom required message and
	 * the per-field invalid-URL message for server-side enforcement.
	 *
	 * @param array<mixed> $attrs Block attributes.
	 * @return array<string, mixed> Processed url block configuration.
	 * @since 1.1.1
	 */
	private static function process_url_block( $attrs ) {
		$url_config = [
			'required' => ! empty( $attrs['required'] ),
		];

		$error_msg = isset( $attrs['errorMsg'] ) ? sanitize_text_field( Helper::get_string_value( $attrs['errorMsg'] ) ) : '';
		if ( '' !== $error_msg ) {
			$url_config['error_msg'] = $error_msg;
		}

		$invalid_url_msg = isset( $attrs['invalidUrlMsg'] ) ? sanitize_text_field( Helper::get_string_value( $attrs['invalidUrlMsg'] ) ) : '';
		if ( '' !== $invalid_url_msg ) {
			$url_config['invalid_url_msg'] = $invalid_url_msg;
		}

		return $url_config;
	}

	/**
	 * Get the block types that participate in field validation.
	 *
	 * Extensions register new validatable field blocks (e.g. phone, address,
	 * url) via the filter so their values run through validate_form_data().
	 * Pair this with the suredonation_field_block_config filter (to store the
	 * block's rules on save) and suredonation_validate_field (to apply them).
	 *
	 * @return array<int, string>
	 * @since 1.1.0
	 */
	public static function get_validatable_blocks() {
		/**
		 * Filter the block types that participate in field validation.
		 *
		 * @since 1.1.0
		 * @param array<int, string> $blocks Validatable block names.
		 */
		$blocks = apply_filters( 'suredonation_validatable_blocks', self::VALIDATABLE_BLOCKS );

		return is_array( $blocks ) ? $blocks : self::VALIDATABLE_BLOCKS;
	}

	/**
	 * Validate submitted donation form field values server-side.
	 *
	 * This is the authoritative validation pass: it reads the immutable block
	 * configuration stored on form save and enforces each field's rules
	 * (required, max length, email format, number range). Per-field custom
	 * messages take precedence over the global defaults configured under
	 * Global Settings → Form Validation.
	 *
	 * @param array<string, mixed> $fields  Submitted field values keyed by field slug.
	 * @param int                  $form_id Donation form post ID.
	 * @return array<string, string> Map of field slug => error message. Empty when valid.
	 * @since 1.1.0
	 */
	public static function validate_form_data( $fields, $form_id ) {
		$errors = [];

		if ( ! is_array( $fields ) ) {
			$fields = [];
		}

		$form_id = absint( $form_id );
		if ( $form_id <= 0 ) {
			return $errors;
		}

		$block_config = self::get_or_migrate_block_config_for_legacy_form( $form_id );
		if ( empty( $block_config ) || ! is_array( $block_config ) ) {
			return $errors;
		}

		$validatable = self::get_validatable_blocks();

		foreach ( $block_config as $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			$block_name = isset( $config['block_name'] ) && is_string( $config['block_name'] ) ? $config['block_name'] : '';
			$slug       = isset( $config['slug'] ) && is_string( $config['slug'] ) ? $config['slug'] : '';

			if ( '' === $slug || ! in_array( $block_name, $validatable, true ) ) {
				continue;
			}

			$raw_value = array_key_exists( $slug, $fields ) ? $fields[ $slug ] : '';
			$value     = is_scalar( $raw_value ) ? trim( (string) $raw_value ) : '';

			$error = self::validate_field_value( $block_name, $config, $value );

			/**
			 * Filter the validation error for a single donation form field.
			 *
			 * Lets extensions (e.g. SureDonation Pro) add custom validators for
			 * their own field types or rules. Return a non-empty string to flag
			 * the field as invalid; return an empty string to pass.
			 *
			 * @since 1.1.0
			 * @param string               $error      Current error message ('' when valid).
			 * @param string               $value      Submitted, trimmed field value.
			 * @param array<string, mixed> $config     Stored block configuration for the field.
			 * @param int                  $form_id    Donation form ID.
			 * @param string               $block_name Block name (e.g. 'suredonation/input').
			 */
			$error = apply_filters( 'suredonation_validate_field', $error, $value, $config, $form_id, $block_name );

			if ( is_string( $error ) && '' !== $error ) {
				$errors[ $slug ] = $error;
			}
		}

		return $errors;
	}

	/**
	 * Get a validation message by key, preferring the admin override.
	 *
	 * @param string $key Message key.
	 * @return string
	 * @since 1.1.0
	 */
	public static function get_validation_message( $key ) {
		$defaults = self::default_validation_messages();
		$stored   = Helper::get_suredonation_option( self::VALIDATION_MESSAGES_OPTION_KEY, [] );

		if ( is_array( $stored ) && ! empty( $stored[ $key ] ) && is_string( $stored[ $key ] ) ) {
			return $stored[ $key ];
		}

		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	/**
	 * Default (fallback) validation messages, keyed by message key.
	 *
	 * Messages containing %s use sprintf substitution for the configured bound.
	 *
	 * @return array<string, string>
	 * @since 1.1.0
	 */
	public static function default_validation_messages() {
		$messages = [
			'suredonation_input_block_required_text'    => __( 'This field is required.', 'suredonation' ),
			'suredonation_email_block_required_text'    => __( 'This field is required.', 'suredonation' ),
			'suredonation_number_block_required_text'   => __( 'This field is required.', 'suredonation' ),
			'suredonation_dropdown_block_required_text' => __( 'This field is required.', 'suredonation' ),
			'suredonation_phone_block_required_text'    => __( 'This field is required.', 'suredonation' ),
			'suredonation_url_block_required_text'      => __( 'This field is required.', 'suredonation' ),
			'suredonation_valid_email'                  => __( 'Please enter a valid email address.', 'suredonation' ),
			'suredonation_valid_number'                 => __( 'Please enter a valid number.', 'suredonation' ),
			'suredonation_valid_phone'                  => __( 'Please enter a valid phone number.', 'suredonation' ),
			'suredonation_valid_url'                    => __( 'Please enter a valid URL.', 'suredonation' ),
			'suredonation_dropdown_invalid_option'      => __( 'Please select a valid option.', 'suredonation' ),
			/* translators: %s: minimum number of selections required. */
			'suredonation_dropdown_min_selection'       => __( 'Please select at least %s option(s).', 'suredonation' ),
			/* translators: %s: maximum number of selections allowed. */
			'suredonation_dropdown_max_selection'       => __( 'Please select no more than %s option(s).', 'suredonation' ),
			/* translators: %s: maximum number of characters allowed. */
			'suredonation_input_max_length'             => __( 'Maximum length is %s characters.', 'suredonation' ),
			/* translators: %s: maximum characters allowed before the @ symbol. */
			'suredonation_email_local_max_length'       => __( 'The part before @ may not exceed %s characters.', 'suredonation' ),
			/* translators: %s: maximum characters allowed after the @ symbol. */
			'suredonation_email_domain_max_length'      => __( 'The part after @ may not exceed %s characters.', 'suredonation' ),
			/* translators: %s: maximum total characters allowed in an email address. */
			'suredonation_email_max_length'             => __( 'The email address may not exceed %s characters.', 'suredonation' ),
			/* translators: %s: minimum allowed value. */
			'suredonation_input_min_value'              => __( 'Minimum value is %s.', 'suredonation' ),
			/* translators: %s: maximum allowed value. */
			'suredonation_input_max_value'              => __( 'Maximum value is %s.', 'suredonation' ),
		];

		/**
		 * Filter the default validation messages.
		 *
		 * Extensions add message keys for their own field types here so the
		 * messages resolve, localize and surface in the Form Validation tab
		 * alongside the core ones. Keys containing %s use sprintf substitution.
		 *
		 * @since 1.1.0
		 * @param array<string, string> $messages Default messages keyed by message key.
		 */
		return apply_filters( 'suredonation_default_validation_messages', $messages );
	}

	/**
	 * Get the fully resolved validation messages (admin overrides over defaults).
	 *
	 * Used to localize the messages to the frontend so client-side validation
	 * mirrors exactly what the server enforces.
	 *
	 * @return array<string, string>
	 * @since 1.1.0
	 */
	public static function get_resolved_validation_messages() {
		$defaults = self::default_validation_messages();
		$stored   = Helper::get_suredonation_option( self::VALIDATION_MESSAGES_OPTION_KEY, [] );

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$resolved = $defaults;
		foreach ( $defaults as $key => $default ) {
			if ( ! empty( $stored[ $key ] ) && is_string( $stored[ $key ] ) ) {
				$resolved[ $key ] = $stored[ $key ];
			}
		}

		return $resolved;
	}

	/**
	 * Apply the core validation rules for a single field value.
	 *
	 * @param string               $block_name Block name.
	 * @param array<string, mixed> $config     Stored block configuration for the field.
	 * @param string               $value      Submitted, trimmed field value.
	 * @return string Error message, or '' when the value passes.
	 * @since 1.1.0
	 */
	private static function validate_field_value( $block_name, $config, $value ) {
		// Required check applies to every field type.
		if ( ! empty( $config['required'] ) && '' === $value ) {
			return self::resolve_required_message( $block_name, $config );
		}

		// Format/range checks are skipped for empty optional values.
		if ( '' === $value ) {
			return '';
		}

		switch ( $block_name ) {
			case 'suredonation/input':
				$max_length = isset( $config['max_length'] ) && is_numeric( $config['max_length'] ) ? (int) $config['max_length'] : 0;
				$length     = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
				if ( $max_length > 0 && $length > $max_length ) {
					// str_replace (not sprintf) because the message is admin/translator
					// editable; a stray literal % would make sprintf throw on PHP 8.
					return str_replace( '%s', number_format_i18n( $max_length ), self::get_validation_message( 'suredonation_input_max_length' ) );
				}
				break;

			case 'suredonation/email':
				if ( ! is_email( $value ) ) {
					if ( ! empty( $config['invalid_email_msg'] ) && is_string( $config['invalid_email_msg'] ) ) {
						return $config['invalid_email_msg'];
					}
					return self::get_validation_message( 'suredonation_valid_email' );
				}

				$email_length_error = self::validate_email_length( $value );
				if ( '' !== $email_length_error ) {
					return $email_length_error;
				}
				break;

			case 'suredonation/url':
				// Intentional dotted-host-only restriction (same as SureForms): the
				// value must be a domain with a TLD or an IPv4 host, with an optional
				// scheme, port, path, query and fragment. Bare single-label hosts
				// (localhost, intranet names, typos like "abcdef") are deliberately
				// rejected for a public "website" field. The 2048-byte cap
				// short-circuits before the regex on overlong, public, unauthenticated
				// input so its host-label sub-pattern cannot backtrack (ReDoS guard).
				// Kept in sync with the client check in src/form-frontend/validation.js.
				if ( strlen( $value ) > 2048 || ! preg_match( '#^(https?://)?((([a-z\d]([a-z\d-]*[a-z\d])*)\.)+[a-z]{2,}|((\d{1,3}\.){3}\d{1,3}))(:\d+)?(/[-a-z\d%_.~+]*)*(\?[;&a-z\d%_.~+=-]*)?(\#[-a-z\d_]*)?$#i', $value ) ) {
					if ( ! empty( $config['invalid_url_msg'] ) && is_string( $config['invalid_url_msg'] ) ) {
						return $config['invalid_url_msg'];
					}
					return self::get_validation_message( 'suredonation_valid_url' );
				}
				break;

			case 'suredonation/phone':
				// Loose format check: digits plus the common phone punctuation,
				// 6–20 characters. The strict country-aware check happens client-side
				// via intl-tel-input; this guards against obviously bad submissions.
				if ( ! preg_match( '/^[\d\s()+.\-]{6,20}$/', $value ) ) {
					return self::get_validation_message( 'suredonation_valid_phone' );
				}
				break;

			case 'suredonation/number':
				if ( ! is_numeric( $value ) ) {
					return self::get_validation_message( 'suredonation_valid_number' );
				}

				$number = (float) $value;

				if ( isset( $config['validation_min'] ) && is_numeric( $config['validation_min'] ) && $number < (float) $config['validation_min'] ) {
					return str_replace( '%s', self::format_number( (float) $config['validation_min'] ), self::get_validation_message( 'suredonation_input_min_value' ) );
				}

				$validation_max = isset( $config['validation_max'] ) && is_numeric( $config['validation_max'] ) ? (float) $config['validation_max'] : 0.0;
				if ( $validation_max > 0 && $number > $validation_max ) {
					return str_replace( '%s', self::format_number( $validation_max ), self::get_validation_message( 'suredonation_input_max_value' ) );
				}
				break;

			case 'suredonation/dropdown':
				$multi_select = ! empty( $config['multi_select'] );
				// Deduplicate before the min/max count check — the server is the
				// trust boundary, and a crafted "A|A|A" must not pass max_selection
				// (or min_selection) with a single distinct value.
				$selections = $multi_select
					? array_values( array_unique( array_filter( array_map( 'trim', explode( '|', $value ) ), 'strlen' ) ) )
					: [ $value ];

				// Reject values that are not among the configured options.
				$allowed = isset( $config['options'] ) && is_array( $config['options'] ) ? $config['options'] : [];
				if ( ! empty( $allowed ) ) {
					foreach ( $selections as $selection ) {
						if ( ! in_array( $selection, $allowed, true ) ) {
							return self::get_validation_message( 'suredonation_dropdown_invalid_option' );
						}
					}
				}

				// Min/max apply to multi-select only.
				if ( $multi_select ) {
					$count = count( $selections );
					$min   = isset( $config['min_selection'] ) ? (int) $config['min_selection'] : 0;
					$max   = isset( $config['max_selection'] ) ? (int) $config['max_selection'] : 0;

					if ( $min > 0 && $count < $min ) {
						return str_replace( '%s', number_format_i18n( $min ), self::get_validation_message( 'suredonation_dropdown_min_selection' ) );
					}
					if ( $max > 0 && $count > $max ) {
						return str_replace( '%s', number_format_i18n( $max ), self::get_validation_message( 'suredonation_dropdown_max_selection' ) );
					}
				}
				break;
		}

		return '';
	}

	/**
	 * Enforce RFC 5321 length limits on an email value.
	 *
	 * The value is split on the last @ so the local part (before @, max 64) and
	 * domain part (after @, max 255) are bounded separately. Limits are
	 * overridable via the suredonation_email_field_char_limits filter.
	 *
	 * Public so the payment layer can length-cap the persisted donor_email
	 * (which is separate from the validation-only fields[] copy this class
	 * normally inspects). A value with no @ — possible when the caller has not
	 * already run is_email() — is bounded by the local-part limit so oversized
	 * junk still cannot be stored.
	 *
	 * @param string $value Submitted, trimmed email value.
	 * @return string Error message, or '' when the value passes.
	 * @since 1.1.1
	 */
	public static function validate_email_length( $value ) {
		$defaults = [
			'local'  => 64,
			'domain' => 255,
		];

		/**
		 * Filter the RFC 5321 character limits enforced on the Email field.
		 *
		 * @since 1.1.1
		 * @param array{local:int,domain:int} $limits Max characters for the local and domain parts.
		 */
		$limits = apply_filters( 'suredonation_email_field_char_limits', $defaults );

		// Fall back to defaults if the filter returns junk or non-positive values.
		$local_limit  = is_array( $limits ) && isset( $limits['local'] ) && (int) $limits['local'] > 0 ? (int) $limits['local'] : $defaults['local'];
		$domain_limit = is_array( $limits ) && isset( $limits['domain'] ) && (int) $limits['domain'] > 0 ? (int) $limits['domain'] : $defaults['domain'];

		$at = strrpos( $value, '@' );
		if ( false === $at ) {
			// No @ (caller did not run is_email first): bound the whole value by
			// the local-part limit so oversized junk cannot be persisted.
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
			if ( $length > $local_limit ) {
				return str_replace( '%s', number_format_i18n( $local_limit ), self::get_validation_message( 'suredonation_email_local_max_length' ) );
			}
			// Defensive total cap (see below): only reachable if a filter raised the
			// local limit past 254; keeps a no-@ value within the VARCHAR(255) column.
			if ( $length > 254 ) {
				return str_replace( '%s', number_format_i18n( 254 ), self::get_validation_message( 'suredonation_email_max_length' ) );
			}
			return '';
		}

		$local_part  = substr( $value, 0, $at );
		$domain_part = substr( $value, $at + 1 );

		$local_length  = function_exists( 'mb_strlen' ) ? mb_strlen( $local_part ) : strlen( $local_part );
		$domain_length = function_exists( 'mb_strlen' ) ? mb_strlen( $domain_part ) : strlen( $domain_part );

		// str_replace (not sprintf) because the message is admin/translator
		// editable; a stray literal % would make sprintf throw on PHP 8.
		if ( $local_length > $local_limit ) {
			return str_replace( '%s', number_format_i18n( $local_limit ), self::get_validation_message( 'suredonation_email_local_max_length' ) );
		}

		if ( $domain_length > $domain_limit ) {
			return str_replace( '%s', number_format_i18n( $domain_limit ), self::get_validation_message( 'suredonation_email_domain_max_length' ) );
		}

		// RFC 5321 §4.5.3.1.3: the whole address may not exceed 254 chars. This is a
		// fixed cap (independent of the per-part filter) because it also guarantees
		// the value fits the VARCHAR(255) donor_email/email columns, which the
		// per-part caps alone do not — they sum to 320.
		if ( ( $local_length + 1 + $domain_length ) > 254 ) {
			return str_replace( '%s', number_format_i18n( 254 ), self::get_validation_message( 'suredonation_email_max_length' ) );
		}

		return '';
	}

	/**
	 * Resolve the required-error message for a field.
	 *
	 * Resolution order: per-field custom message → global default for the field
	 * type (Global Settings → Form Validation) → generic fallback. The message
	 * key is derived from the block name by convention, so new field blocks need
	 * no code change here — they only register their default message and tab
	 * field (e.g. 'suredonation/phone' → 'suredonation_phone_block_required_text').
	 *
	 * @param string               $block_name Block name.
	 * @param array<string, mixed> $config     Stored block configuration for the field.
	 * @return string
	 * @since 1.1.0
	 */
	private static function resolve_required_message( $block_name, $config ) {
		if ( ! empty( $config['error_msg'] ) && is_string( $config['error_msg'] ) ) {
			return $config['error_msg'];
		}

		$message = self::get_validation_message( self::required_message_key( $block_name ) );

		return '' !== $message ? $message : __( 'This field is required.', 'suredonation' );
	}

	/**
	 * Derive the required-message key for a block name.
	 *
	 * 'suredonation/input' => 'suredonation_input_block_required_text'.
	 *
	 * @param string $block_name Block name.
	 * @return string
	 * @since 1.1.0
	 */
	public static function required_message_key( $block_name ) {
		$short = str_replace( 'suredonation/', '', (string) $block_name );
		$short = (string) preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $short ) );

		return 'suredonation_' . $short . '_block_required_text';
	}

	/**
	 * Format a numeric bound for display in a validation message.
	 *
	 * Drops the decimal portion for whole numbers (e.g. 10.0 → "10").
	 *
	 * @param float $number Number to format.
	 * @return string
	 * @since 1.1.0
	 */
	private static function format_number( $number ) {
		if ( floor( $number ) === $number ) {
			return number_format_i18n( $number );
		}

		return number_format_i18n( $number, 2 );
	}
}
