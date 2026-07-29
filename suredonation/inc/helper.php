<?php
/**
 * Helper Class - Utility functions for SureDonation
 *
 * @package SureDonation
 */

namespace SureDonation\Inc;

use SureDonation\Inc\API\Settings_API;
use SureDonation\Inc\Database\Tables\Donations;
use SureDonation\Inc\Emails\Email_Handler;
use SureDonation\Inc\Payments\Payment_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class.
 * Provides utility functions for the plugin.
 *
 * @since 0.0.1
 */
class Helper {
	/**
	 * Option name for all SureDonation settings.
	 *
	 * @since 0.0.1
	 */
	public const OPTION_NAME = 'suredonation_options';

	/**
	 * Campaign meta key name.
	 *
	 * @since 0.0.1
	 */
	public const SUREDONATION_CAMPAIGN_META_KEY = '_suredonation_campaign_meta';

	/**
	 * Default campaign meta values.
	 *
	 * @since 0.0.1
	 * @var array<string, mixed>
	 */
	private static $campaign_meta_defaults = [
		'goal_type'         => 'raised_amount',
		'goal_amount'       => 0,
		'campaign_status'   => 'active',
		'email_settings'    => [],
		'require_terms'     => false,
		'terms_text'        => '',
		'thank_you_message' => '',
	];

	/**
	 * Get a value from the suredonation_options array.
	 *
	 * @param string $key           The key to retrieve.
	 * @param mixed  $default_value Default value if key doesn't exist.
	 * @return mixed
	 * @since 0.0.1
	 */
	public static function get_suredonation_option( $key, $default_value = null ) {
		$options = get_option( self::OPTION_NAME, [] );

		if ( ! is_array( $options ) ) {
			$options = [];
		}

		return array_key_exists( $key, $options ) ? $options[ $key ] : $default_value;
	}

	/**
	 * Update a value in the suredonation_options array.
	 *
	 * @param string $key   The key to update.
	 * @param mixed  $value The value to set.
	 * @return bool True on success, false on failure.
	 * @since 0.0.1
	 */
	public static function update_suredonation_option( $key, $value ) {
		$options = get_option( self::OPTION_NAME, [] );

		if ( ! is_array( $options ) ) {
			$options = [];
		}

		$options[ $key ] = $value;

		return update_option( self::OPTION_NAME, $options );
	}

	/**
	 * Whether honeypot spam protection is enabled in the global settings.
	 *
	 * @return bool True when the honeypot is enabled.
	 * @since 1.1.0
	 */
	public static function is_honeypot_enabled() {
		$spam_settings = self::get_suredonation_option( Settings_API::SPAM_OPTION_KEY, [] );

		return is_array( $spam_settings ) && ! empty( $spam_settings['honeypot'] );
	}

	/**
	 * Output the hidden honeypot field when spam protection is enabled.
	 *
	 * Genuine visitors never see or fill this hidden field, so it is submitted
	 * with an empty value. A filled value (a bot that auto-fills every input) or
	 * a missing field (a bot that strips unknown inputs) is flagged as spam at
	 * submission time.
	 *
	 * @return void
	 * @see Helper::is_honeypot_spam()
	 * @since 1.1.0
	 */
	public static function render_honeypot_field() {
		if ( ! self::is_honeypot_enabled() ) {
			return;
		}

		echo '<input type="hidden" name="suredonation_honeypot" value="" />';
	}

	/**
	 * Determine whether the current submission tripped the honeypot.
	 *
	 * Returns false when honeypot protection is disabled. When enabled, a real
	 * submission always carries the hidden field with an empty value; a missing
	 * field or any non-empty value is treated as spam.
	 *
	 * The honeypot field holds no sensitive data and is only inspected for
	 * emptiness. Nonce/referer verification is performed by the calling
	 * submission handler before this method runs.
	 *
	 * @return bool True when the submission should be rejected as spam.
	 * @since 1.1.0
	 */
	public static function is_honeypot_spam() {
		if ( ! self::is_honeypot_enabled() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling submission handler; value only checked for emptiness.
		if ( ! isset( $_POST['suredonation_honeypot'] ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See note above.
		$value = sanitize_text_field( wp_unslash( $_POST['suredonation_honeypot'] ) );

		return '' !== $value;
	}

	/**
	 * Get all campaign meta as an array.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<string, mixed> Campaign meta values.
	 * @since 0.0.1
	 */
	public static function get_campaign_meta( $campaign_id ) {
		$raw = get_post_meta( $campaign_id, self::SUREDONATION_CAMPAIGN_META_KEY, true );

		$meta = ! empty( $raw ) && is_string( $raw ) ? json_decode( $raw, true ) : [];

		if ( ! is_array( $meta ) ) {
			$meta = [];
		}

		return array_merge( self::$campaign_meta_defaults, $meta );
	}

	/**
	 * Get a single campaign meta value.
	 *
	 * @param int    $campaign_id Campaign post ID.
	 * @param string $key         Meta key within the campaign meta array.
	 * @param mixed  $default_value     Default value if not set.
	 * @return mixed
	 * @since 0.0.1
	 */
	public static function get_campaign_meta_value( $campaign_id, $key, $default_value = null ) {
		$meta = self::get_campaign_meta( $campaign_id );

		return $meta[ $key ] ?? $default_value;
	}

	/**
	 * Update campaign meta. Merges provided values with existing meta.
	 *
	 * @param int                  $campaign_id Campaign post ID.
	 * @param array<string, mixed> $values      Key-value pairs to update.
	 * @return bool|int Meta ID on success, false on failure.
	 * @since 0.0.1
	 */
	public static function update_campaign_meta( $campaign_id, $values ) {
		$meta = self::get_campaign_meta( $campaign_id );
		$meta = array_merge( $meta, $values );

		return update_post_meta( $campaign_id, self::SUREDONATION_CAMPAIGN_META_KEY, wp_json_encode( $meta ) );
	}

	/**
	 * Checks if current value is string or else returns default value
	 *
	 * @param mixed $data data which need to be checked if is string.
	 * @return string
	 * @since 0.0.1
	 */
	public static function get_string_value( $data ) {
		if ( is_scalar( $data ) ) {
			return (string) $data;
		}
		if ( is_object( $data ) && method_exists( $data, '__toString' ) ) {
			return $data->__toString();
		}
		if ( is_null( $data ) ) {
			return '';
		}
			return '';
	}

	/**
	 * Checks if current value is number or else returns default value
	 *
	 * @param mixed $value data which need to be checked if is string.
	 * @param int   $base value can be set is $data is not a string, defaults to empty string.
	 * @return int
	 * @since 0.0.1
	 */
	public static function get_integer_value( $value, $base = 10 ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		if ( is_string( $value ) ) {
			$trimmed_value = trim( $value );
			return intval( $trimmed_value, $base );
		}
			return 0;
	}

	/**
	 * Safely converts a mixed value to float
	 *
	 * @param mixed $value         The value to convert.
	 * @param float $default_value Default value if conversion fails.
	 * @return float
	 * @since 0.0.1
	 */
	public static function get_float_value( $value, $default_value = 0.0 ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		return $default_value;
	}

	/**
	 * Safely get array value with type checking
	 *
	 * @param mixed                $value         The value to check.
	 * @param array<string, mixed> $default_value Default value if not an array.
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	public static function get_array_value( $value, $default_value = [] ) {
		return is_array( $value ) ? $value : $default_value;
	}

	/**
	 * Check if current user has required capability.
	 *
	 * @param string       $capability Capability to check (default: 'manage_options').
	 * @param array<mixed> $args Additional arguments for capability check.
	 * @return bool True if user has capability.
	 * @since 0.0.1
	 */
	public static function current_user_can( $capability = '', $args = [] ) {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		if ( ! is_string( $capability ) || empty( $capability ) ) {
			$capability = 'manage_options';
		}

		return ! empty( $args )
			? current_user_can( $capability, ...$args )
			: current_user_can( $capability );
	}

	/**
	 * Join an array of strings into a single string, filtering out empty values.
	 *
	 * @param array<string> $strings Array of strings to join.
	 * @param string        $glue    Separator to use (default: ' ').
	 * @return string Joined string.
	 * @since 0.0.1
	 */
	public static function join_strings( $strings, $glue = ' ' ) {
		if ( ! is_array( $strings ) ) {
			return '';
		}

		$filtered = array_filter(
			$strings,
			static function ( $item ) {
				return is_string( $item ) && '' !== trim( $item );
			}
		);

		return implode( $glue, array_map( 'trim', $filtered ) );
	}

	/**
	 * Process blocks to generate unique slugs for SureDonation blocks.
	 *
	 * Recursively processes all blocks and generates slugs for those that
	 * don't have one set. Ensures all slugs are unique within the form.
	 *
	 * @param array<mixed>  $blocks  The blocks to process.
	 * @param array<string> $slugs   Array of existing slugs (keyed by block_id).
	 * @param bool          $updated Whether any blocks were updated.
	 * @param string        $prefix  Optional prefix for nested blocks.
	 * @return array{0: array<mixed>, 1: array<string>, 2: bool} Processed blocks, slugs, and updated flag.
	 * @since 0.0.1
	 */
	public static function process_blocks( $blocks, $slugs = [], $updated = false, $prefix = '' ) {
		if ( ! is_array( $blocks ) ) {
			return [ [], $slugs, $updated ];
		}
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			// Skip non-SureDonation blocks.
			if ( ! isset( $block['blockName'] ) || ! is_string( $block['blockName'] ) || strpos( $block['blockName'], 'suredonation/' ) !== 0 ) {
				// Process inner blocks if any.
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					[ $blocks[ $index ]['innerBlocks'], $slugs, $updated ] = self::process_blocks( $block['innerBlocks'], $slugs, $updated, $prefix );
				}
				continue;
			}

			// Skip if no attrs or slug is already set and block_id is in slugs array.
			if (
				! isset( $block['attrs'] ) ||
				! is_array( $block['attrs'] ) ||
				(
					! empty( $block['attrs']['slug'] ) &&
					isset( $block['attrs']['block_id'] ) &&
					isset( $slugs[ $block['attrs']['block_id'] ] )
				)
			) {
				// Process inner blocks if any.
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					[ $blocks[ $index ]['innerBlocks'], $slugs, $updated ] = self::process_blocks( $block['innerBlocks'], $slugs, $updated, $prefix );
				}
				continue;
			}

			// Generate slug if empty.
			if ( empty( $block['attrs']['slug'] ) ) {
				$blocks[ $index ]['attrs']['slug'] = self::generate_unique_block_slug( $block, $slugs, $prefix );
				$updated                           = true;
			}

			// Track the slug if block_id is set.
			if ( isset( $block['attrs']['block_id'] ) ) {
				$slugs[ $block['attrs']['block_id'] ] = $blocks[ $index ]['attrs']['slug'];
			}

			// Process inner blocks recursively.
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				[ $blocks[ $index ]['innerBlocks'], $slugs, $updated ] = self::process_blocks(
					$block['innerBlocks'],
					$slugs,
					$updated,
					$blocks[ $index ]['attrs']['slug']
				);
			}
		}

		return [ $blocks, $slugs, $updated ];
	}

	/**
	 * Generates a unique slug based on the provided block and existing slugs.
	 *
	 * @param array<mixed>  $block  The block data.
	 * @param array<string> $slugs  The array of existing slugs.
	 * @param string        $prefix Optional prefix for nested blocks.
	 * @return string The generated unique block slug.
	 * @since 0.0.1
	 */
	public static function generate_unique_block_slug( $block, $slugs, $prefix = '' ) {
		$slug = is_string( $block['blockName'] ?? '' ) ? str_replace( 'suredonation/', '', $block['blockName'] ) : '';

		// Use label if available.
		if ( ! empty( $block['attrs']['label'] ) && is_string( $block['attrs']['label'] ) ) {
			$slug = sanitize_title( $block['attrs']['label'] );
		}

		// Add prefix for nested blocks.
		if ( ! empty( $prefix ) ) {
			$slug = $prefix . '-' . $slug;
		}

		return self::generate_unique_slug( $slug, $slugs );
	}

	/**
	 * Ensures that the slug is unique.
	 *
	 * If the slug is already taken, it appends a number to make it unique.
	 *
	 * @param string        $slug  The slug to make unique.
	 * @param array<string> $slugs Array of existing slugs.
	 * @return string The unique slug.
	 * @since 0.0.1
	 */
	public static function generate_unique_slug( $slug, $slugs ) {
		$slug = sanitize_title( $slug );

		// Check if slug exists in the array values.
		if ( ! in_array( $slug, $slugs, true ) ) {
			return $slug;
		}

		// Append a number to make it unique.
		$index = 1;
		while ( in_array( $slug . '-' . $index, $slugs, true ) ) {
			++$index;
		}

		return $slug . '-' . $index;
	}

	/**
	 * Generate a unique block ID for a server-created block.
	 *
	 * Mirrors the client-side generateBlockId() used in each block's edit.js
	 * (a 7-character base36 string). Blocks created programmatically (e.g. the
	 * default form auto-generated when a campaign is published) never run the
	 * editor, so they would otherwise have no block_id. The server-side payment
	 * validation config is keyed on block_id, so without one no config is stored
	 * and donations fail with "Invalid form configuration." until the form is
	 * opened and saved in the editor.
	 *
	 * @return string A 7-character base36 identifier.
	 * @since 1.1.1
	 */
	public static function generate_block_id() {
		$chars    = '0123456789abcdefghijklmnopqrstuvwxyz';
		$block_id = '';
		for ( $i = 0; $i < 7; $i++ ) {
			$block_id .= $chars[ wp_rand( 0, 35 ) ];
		}
		return $block_id;
	}

	/**
	 * Get client IP address for logging purposes.
	 *
	 * Uses REMOTE_ADDR only — forwarded headers (HTTP_X_FORWARDED_FOR,
	 * HTTP_CLIENT_IP) are deliberately ignored because they are trivially
	 * spoofable. Note: behind a proxy/CDN that does not restore the real client
	 * IP, this returns the proxy's address. Suitable for informational logging
	 * and best-effort geolocation only — do NOT use for security-critical IP
	 * validation.
	 *
	 * @return string Client IP address.
	 * @since 0.0.1
	 */
	public static function get_client_ip() {
		// Only trust REMOTE_ADDR — proxy headers (HTTP_X_FORWARDED_FOR, HTTP_CLIENT_IP)
		// are trivially spoofable and should not be used for logging or security.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		return '';
	}

	/**
	 * Per-IP rate limiter for public (unauthenticated) submission endpoints.
	 *
	 * Uses a short-lived transient bucket keyed by action + client IP to
	 * throttle abuse (card-testing, DB/email flooding) on nopriv AJAX handlers.
	 * When the client IP cannot be determined the request is allowed, so
	 * legitimate donors are never blocked by a missing IP.
	 *
	 * @param string $action Unique action identifier namespacing the bucket.
	 * @param int    $max    Maximum attempts permitted within the window.
	 * @param int    $window Window length in seconds.
	 * @return bool True if the request is within limits; false if the limit is exceeded.
	 * @since 1.1.0
	 */
	public static function check_rate_limit( $action, $max = 15, $window = MINUTE_IN_SECONDS ) {
		$ip = self::get_client_ip();
		if ( '' === $ip ) {
			return true;
		}

		$key   = 'suredonation_rl_' . md5( (string) $action . '|' . $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Get sanitized request metadata (user agent and referer).
	 *
	 * @return array{user_agent: string, referer_url: string} Request metadata.
	 * @since 1.0.0
	 */
	public static function get_request_meta() {
		return [
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'referer_url' => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
		];
	}

	/**
	 * Get allowed HTML tags for form markup.
	 *
	 * The wp_kses_post() doesn't allow form elements, so we need a custom allowed tags array.
	 * This is safe because the markup is generated internally by trusted code that already
	 * escapes user input with esc_attr(), esc_html(), etc.
	 *
	 * @return array<string, array<string, bool>> Allowed HTML tags and attributes.
	 * @since 0.0.1
	 */
	public static function get_allowed_form_html() {
		// Note: data-* wildcard doesn't work in wp_kses, so we list each data attribute explicitly.
		$common_data_attrs = [
			'data-block-id'                    => true,
			'data-form-id'                     => true,
			'data-gateway'                     => true,
			'data-stripe-key'                  => true,
			'data-currency'                    => true,
			'data-payment-mode'                => true,
			'data-amount-type'                 => true,
			'data-fixed-amount'                => true,
			'data-payment-type'                => true,
			'data-customer-name-field'         => true,
			'data-customer-email-field'        => true,
			'data-nonce'                       => true,
			'data-variable-amount-field'       => true,
			'data-minimum-amount'              => true,
			'data-subscription-plan-name'      => true,
			'data-subscription-interval'       => true,
			'data-subscription-billing-cycles' => true,
			'data-currency-symbol'             => true,
			'data-message-format'              => true,
			'data-payment-methods'             => true,
			'data-payment-available'           => true,
			'data-method'                      => true,
			'data-slug'                        => true,
			'data-required'                    => true,
			'data-fee-percentage'              => true,
			'data-fee-fixed'                   => true,
			'data-fee-mode'                    => true,
			'data-gateway-fees'                => true,
			'data-invalid-email-msg'           => true,
			'data-invalid-url-msg'             => true,
			'data-sd-mask'                     => true,
			'data-custom-sd-mask'              => true,
			// Dropdown (tom-select) field.
			'data-multiple'                    => true,
			'data-searchable'                  => true,
			'data-preselected'                 => true,
			'data-min-selection'               => true,
			'data-max-selection'               => true,
			'data-placeholder'                 => true,
			// Phone (intl-tel-input) field.
			'data-default-country'             => true,
			'data-auto-country'                => true,
			'data-enable-country-filter'       => true,
			'data-country-filter-type'         => true,
			'data-include-countries'           => true,
			'data-exclude-countries'           => true,
		];

		$allowed = [
			'div'        => array_merge(
				[
					'id'              => true,
					'class'           => true,
					'style'           => true,
					'role'            => true,
					'tabindex'        => true,
					'aria-live'       => true,
					'aria-atomic'     => true,
					'aria-hidden'     => true,
					'aria-labelledby' => true,
				],
				$common_data_attrs
			),
			'form'       => array_merge(
				[
					'id'     => true,
					'class'  => true,
					'method' => true,
					'action' => true,
				],
				$common_data_attrs
			),
			'fieldset'   => [
				'id'    => true,
				'class' => true,
			],
			'legend'     => [
				'id'    => true,
				'class' => true,
			],
			'label'      => [
				'id'    => true,
				'class' => true,
				'for'   => true,
			],
			'input'      => array_merge(
				[
					'id'               => true,
					'class'            => true,
					'type'             => true,
					'name'             => true,
					'value'            => true,
					'placeholder'      => true,
					'min'              => true,
					'max'              => true,
					'step'             => true,
					'maxlength'        => true,
					'checked'          => true,
					'disabled'         => true,
					'readonly'         => true,
					'required'         => true,
					'tabindex'         => true,
					'autocomplete'     => true,
					'inputmode'        => true,
					'aria-describedby' => true,
					'aria-required'    => true,
					'aria-hidden'      => true,
				],
				$common_data_attrs
			),
			'button'     => array_merge(
				[
					'id'       => true,
					'class'    => true,
					'type'     => true,
					'disabled' => true,
				],
				$common_data_attrs
			),
			'select'     => array_merge(
				[
					'id'               => true,
					'class'            => true,
					'name'             => true,
					'disabled'         => true,
					'required'         => true,
					'multiple'         => true,
					'tabindex'         => true,
					'autocomplete'     => true,
					'aria-describedby' => true,
					'aria-required'    => true,
				],
				$common_data_attrs
			),
			'option'     => [
				'value'    => true,
				'class'    => true,
				'selected' => true,
				'disabled' => true,
			],
			'textarea'   => array_merge(
				[
					'id'               => true,
					'class'            => true,
					'name'             => true,
					'rows'             => true,
					'cols'             => true,
					'placeholder'      => true,
					'maxlength'        => true,
					'disabled'         => true,
					'readonly'         => true,
					'required'         => true,
					'aria-describedby' => true,
					'aria-required'    => true,
				],
				$common_data_attrs
			),
			'span'       => array_merge(
				[
					'id'          => true,
					'class'       => true,
					'style'       => true,
					'aria-hidden' => true,
				],
				$common_data_attrs
			),
			'p'          => [
				'id'    => true,
				'class' => true,
				'style' => true,
				'role'  => true,
			],
			'h1'         => [
				'id'    => true,
				'class' => true,
			],
			'h2'         => [
				'id'    => true,
				'class' => true,
			],
			'h3'         => [
				'id'    => true,
				'class' => true,
			],
			'h4'         => [
				'id'    => true,
				'class' => true,
			],
			'h5'         => [
				'id'    => true,
				'class' => true,
			],
			'h6'         => [
				'id'    => true,
				'class' => true,
			],
			'a'          => [
				'id'     => true,
				'class'  => true,
				'href'   => true,
				'target' => true,
				'rel'    => true,
				'style'  => true,
			],
			'strong'     => [
				'class' => true,
			],
			'em'         => [
				'class' => true,
			],
			'ol'         => [
				'class' => true,
			],
			'ul'         => [
				'class' => true,
			],
			'li'         => [
				'class' => true,
			],
			'br'         => [],
			'hr'         => [
				'class' => true,
			],
			// img/figure/figcaption back the Image block (inc/blocks/image) — the
			// render depends on these entries, so don't drop them in a cleanup.
			'img'        => [
				'src'              => true,
				'fetchpriority'    => true,
				'srcset'           => true,
				'sizes'            => true,
				'alt'              => true,
				'class'            => true,
				'style'            => true,
				'width'            => true,
				'height'           => true,
				'loading'          => true,
				'decoding'         => true,
				'title'            => true,
				// Lazy-load optimizers (WP Rocket, Perfmatters, Optimole, the
				// Bricks theme, …) rewrite wp_get_attachment_image() output into
				// these data-* attributes with a data: placeholder in src; allow
				// them so kses doesn't strip the real URLs the lazy JS swaps back.
				'data-src'         => true,
				'data-srcset'      => true,
				'data-sizes'       => true,
				'data-lazy-src'    => true,
				'data-lazy-srcset' => true,
				'data-lazy-sizes'  => true,
			],
			'figure'     => [
				'class' => true,
			],
			'figcaption' => [
				'class' => true,
			],
			'svg'        => [
				'class'       => true,
				'width'       => true,
				'height'      => true,
				'viewbox'     => true,
				'fill'        => true,
				'xmlns'       => true,
				'aria-hidden' => true,
			],
			'circle'     => [
				'cx'           => true,
				'cy'           => true,
				'r'            => true,
				'stroke'       => true,
				'stroke-width' => true,
				'fill'         => true,
			],
			'rect'       => [
				'x'            => true,
				'y'            => true,
				'width'        => true,
				'height'       => true,
				'rx'           => true,
				'stroke'       => true,
				'stroke-width' => true,
			],
			'path'       => [
				'class'           => true,
				'd'               => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'fill'            => true,
			],
		];

		/**
		 * Filter the allowed HTML tags/attributes for SureDonation form markup.
		 *
		 * Lets extensions (e.g. the SureDonation Pro date/time pickers) permit the
		 * extra tags or data attributes their fields render.
		 *
		 * @since 1.1.1
		 * @param array<string, array<string, bool>> $allowed Allowed tags/attributes.
		 */
		return apply_filters( 'suredonation_allowed_form_html', $allowed );
	}

	/**
	 * Get the nonce action string for a donation form.
	 *
	 * Shared between block render, shortcode render, and donation handler
	 * to ensure the nonce action is always consistent.
	 *
	 * @param int $campaign_id Campaign ID (0 for standalone forms).
	 * @return string Nonce action string.
	 * @since 1.0.0
	 */
	public static function get_donation_nonce_action( $campaign_id ) {
		// Note: This nonce is used by the generic donation-handler.php (form POST flow).
		// Stripe and Offline AJAX handlers use a separate fixed nonce action
		// 'suredonation_donation_form' generated in payment-markup.php — these are
		// intentionally different nonce paths (form POST vs payment AJAX).
		return $campaign_id ? 'suredonation_donation_' . $campaign_id : 'suredonation_donation_standalone';
	}

	/**
	 * Get form payment settings from post meta.
	 *
	 * Shared between the block and shortcode render paths to build
	 * the `window.suredonationPayment` frontend configuration object.
	 *
	 * @param int $form_id Form post ID.
	 * @return array<string, mixed> Payment settings array.
	 * @since 1.0.0
	 */
	public static function get_form_payment_settings( $form_id ) {
		$data = self::get_form_confirmation_settings( $form_id );

		// Map confirmation type to frontend format.
		$confirmation_type = 'message';
		$redirect_url      = '';
		if ( 'custom url' === $data['confirmation_type'] ) {
			$confirmation_type = 'redirect';
			$redirect_url      = $data['custom_url'];
		} elseif ( 'different page' === $data['confirmation_type'] ) {
			$confirmation_type = 'redirect';
			$redirect_url      = $data['page_url'];
		}

		$success_message = ! empty( $data['message'] )
			? $data['message']
			: esc_html__( 'Thank you for your donation!', 'suredonation' );

		return [
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'confirmationType'     => $confirmation_type,
			'successTitle'         => esc_html__( 'Thank You!', 'suredonation' ),
			'successMessage'       => wp_kses_post( self::get_string_value( $success_message ) ),
			// Shown when payment succeeded at the gateway but our server-side
			// finalize did not complete; the webhook will finalize it, so the
			// donor must not be prompted to pay again.
			'processingMessage'    => esc_html__( 'Payment received. We are finalizing your donation and will email you a confirmation shortly. Please do not pay again.', 'suredonation' ),
			'redirectUrl'          => ! empty( $redirect_url ) ? esc_url( self::get_string_value( $redirect_url ) ) : '',
			'submissionAction'     => $data['submission_action'],
			// translators: %s: formatted fee amount with currency symbol.
			'feeIncludesText'      => __( '(includes %s processing fee)', 'suredonation' ),
			'amountPlaceholder'    => __( 'Complete the form to view the amount.', 'suredonation' ),
			// Currency symbol placement for client-side amount/fee formatting.
			'currencySignPosition' => Payment_Helper::get_currency_sign_position(),
		];
	}

	/**
	 * Get form confirmation settings from post meta.
	 *
	 * Reads from consolidated _suredonation_form_confirmation meta key.
	 *
	 * @param int $form_id Form post ID.
	 * @return array<string, string> Confirmation settings with defaults applied.
	 * @since 1.0.0
	 */
	public static function get_form_confirmation_settings( $form_id ) {
		$defaults = [
			'confirmation_type' => 'same page',
			'message'           => '',
			'submission_action' => 'hide form',
			'custom_url'        => '',
			'page_url'          => '',
		];

		$raw = get_post_meta( $form_id, '_suredonation_form_confirmation', true );

		if ( ! empty( $raw ) && is_string( $raw ) ) {
			$data = json_decode( $raw, true );
			if ( is_array( $data ) ) {
				return wp_parse_args( $data, $defaults );
			}
		}

		return $defaults;
	}

	/**
	 * Get smart tags definitions grouped by context.
	 *
	 * Centralized source of truth for all smart tag lists used across
	 * admin UI, form editor, and email settings.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Smart tags grouped by context.
	 * @since 1.0.0
	 */
	public static function get_smart_tags() {
		$confirmation_tags = [
			[
				'tag'   => '{donor_name}',
				'title' => __( 'Donor Name', 'suredonation' ),
			],
			[
				'tag'   => '{donor_email}',
				'title' => __( 'Donor Email', 'suredonation' ),
			],
			[
				'tag'   => '{amount}',
				'title' => __( 'Donation Amount', 'suredonation' ),
			],
			[
				'tag'   => '{campaign_name}',
				'title' => __( 'Campaign Name', 'suredonation' ),
			],
			[
				'tag'   => '{donation_date}',
				'title' => __( 'Donation Date', 'suredonation' ),
			],
			[
				'tag'   => '{transaction_id}',
				'title' => __( 'Transaction ID', 'suredonation' ),
			],
			[
				'tag'   => '{payment_method}',
				'title' => __( 'Payment Method', 'suredonation' ),
			],
			[
				'tag'   => '{site_title}',
				'title' => __( 'Site Title', 'suredonation' ),
			],
			[
				'tag'   => '{donation_total}',
				'title' => __( 'Donation Total', 'suredonation' ),
			],
			[
				'tag'   => '{payment_status}',
				'title' => __( 'Payment Status', 'suredonation' ),
			],
			[
				'tag'   => '{donation_receipt}',
				'title' => __( 'Donation Receipt', 'suredonation' ),
			],
			[
				'tag'   => '{success_badge}',
				'title' => __( 'Success Badge', 'suredonation' ),
			],
		];

		return [
			'confirmation'         => $confirmation_tags,
			'email'                => array_merge(
				$confirmation_tags,
				[
					[
						'tag'   => '{admin_email}',
						'title' => __( 'Admin Email', 'suredonation' ),
					],
					[
						'tag'   => '{site_url}',
						'title' => __( 'Site URL', 'suredonation' ),
					],
					[
						'tag'   => '{admin_url}',
						'title' => __( 'Admin URL', 'suredonation' ),
					],
					[
						'tag'   => '{subscription_id}',
						'title' => __( 'Subscription ID', 'suredonation' ),
					],
					[
						'tag'   => '{subscription_interval}',
						'title' => __( 'Subscription Interval', 'suredonation' ),
					],
					[
						'tag'   => '{offline_instructions}',
						'title' => __( 'Offline Instructions', 'suredonation' ),
					],
				]
			),
			'email_grouped'        => [
				[
					'label' => __( 'Donation Tags', 'suredonation' ),
					'tags'  => [
						[
							'tag'   => '{donor_name}',
							'title' => __( 'Donor Name', 'suredonation' ),
						],
						[
							'tag'   => '{donor_email}',
							'title' => __( 'Donor Email', 'suredonation' ),
						],
						[
							'tag'   => '{amount}',
							'title' => __( 'Donation Amount', 'suredonation' ),
						],
						[
							'tag'   => '{campaign_name}',
							'title' => __( 'Campaign Name', 'suredonation' ),
						],
						[
							'tag'   => '{donation_date}',
							'title' => __( 'Donation Date', 'suredonation' ),
						],
						[
							'tag'   => '{transaction_id}',
							'title' => __( 'Transaction ID', 'suredonation' ),
						],
						[
							'tag'   => '{payment_method}',
							'title' => __( 'Payment Method', 'suredonation' ),
						],
						[
							'tag'   => '{subscription_id}',
							'title' => __( 'Subscription ID', 'suredonation' ),
						],
						[
							'tag'   => '{subscription_interval}',
							'title' => __( 'Subscription Interval', 'suredonation' ),
						],
						[
							'tag'   => '{refund_amount}',
							'title' => __( 'Refund Amount', 'suredonation' ),
						],
					],
				],
				[
					'label' => __( 'General Tags', 'suredonation' ),
					'tags'  => [
						[
							'tag'   => '{site_title}',
							'title' => __( 'Site Title', 'suredonation' ),
						],
						[
							'tag'   => '{admin_email}',
							'title' => __( 'Admin Email', 'suredonation' ),
						],
						[
							'tag'   => '{site_url}',
							'title' => __( 'Site URL', 'suredonation' ),
						],
						[
							'tag'   => '{admin_url}',
							'title' => __( 'Admin URL', 'suredonation' ),
						],
						[
							'tag'   => '{offline_instructions}',
							'title' => __( 'Offline Instructions', 'suredonation' ),
						],
					],
				],
			],
			'offline_instructions' => [
				[
					'tag'   => '{campaign_name}',
					'title' => __( 'Campaign Name', 'suredonation' ),
				],
				[
					'tag'   => '{site_title}',
					'title' => __( 'Site Title', 'suredonation' ),
				],
				[
					'tag'   => '{site_url}',
					'title' => __( 'Site URL', 'suredonation' ),
				],
				[
					'tag'   => '{admin_email}',
					'title' => __( 'Admin Email', 'suredonation' ),
				],
			],
		];
	}

	/**
	 * Map a payment gateway slug to a human-readable label.
	 *
	 * @param string $gateway Gateway slug (e.g. stripe, paypal, manual).
	 * @return string Display label.
	 * @since 1.0.0
	 */
	public static function get_payment_method_label( $gateway ) {
		switch ( $gateway ) {
			case 'paypal':
				return __( 'PayPal', 'suredonation' );
			case 'manual':
			case 'offline':
				return __( 'Offline Donation', 'suredonation' );
			case 'stripe':
				return __( 'Stripe', 'suredonation' );
			default:
				return ucwords( str_replace( [ '_', '-' ], ' ', (string) $gateway ) );
		}
	}

	/**
	 * Render the static "Success" badge used by the {success_badge} smart tag.
	 *
	 * @return string Badge HTML.
	 * @since 1.0.0
	 */
	public static function render_success_badge() {
		return '<span class="sd-success-box__badge">' . esc_html__( 'Success', 'suredonation' ) . '</span>';
	}

	/**
	 * Render a styled payment-status badge for the donation confirmation.
	 *
	 * @param string $status Payment status (e.g. completed, pending, failed).
	 * @return array Badge HTML.
	 * @since 1.0.0
	 */
	public static function get_payment_status_config( $status ) {
		$status = strtolower( trim( (string) $status ) );

		$map = [
			'completed'  => [
				'label'   => __( 'Complete', 'suredonation' ),
				'variant' => 'complete',
			],
			'complete'   => [
				'label'   => __( 'Complete', 'suredonation' ),
				'variant' => 'complete',
			],
			'pending'    => [
				'label'   => __( 'Pending', 'suredonation' ),
				'variant' => 'pending',
			],
			'processing' => [
				'label'   => __( 'Processing', 'suredonation' ),
				'variant' => 'pending',
			],
			'failed'     => [
				'label'   => __( 'Failed', 'suredonation' ),
				'variant' => 'failed',
			],
			'refunded'   => [
				'label'   => __( 'Refunded', 'suredonation' ),
				'variant' => 'refunded',
			],
		];

		return $map[ $status ] ?? [
			'label'   => '' !== $status ? ucfirst( $status ) : __( 'Complete', 'suredonation' ),
			'variant' => 'pending',
		];
	}

	/**
	 * Render a styled payment-status badge for the donation receipt row.
	 *
	 * @param string $status Payment status (e.g. completed, pending, failed).
	 * @return string Badge HTML.
	 * @since 1.0.0
	 */
	public static function render_payment_status_badge( $status ) {
		$config = self::get_payment_status_config( $status );
		return sprintf(
			'<span class="sd-receipt-badge sd-receipt-badge--%1$s">%2$s</span>',
			esc_attr( $config['variant'] ),
			esc_html( $config['label'] )
		);
	}

	/**
	 * Render the donation receipt card used by the {donation_receipt} smart tag.
	 *
	 * @param array<string, mixed> $donation_data Donation data.
	 * @param string               $campaign_name Campaign name ('' for standalone forms).
	 * @return string Receipt card HTML.
	 * @since 1.0.0
	 */
	public static function render_donation_receipt( $donation_data, $campaign_name = '' ) {
		$currency     = isset( $donation_data['currency'] ) && is_string( $donation_data['currency'] ) ? $donation_data['currency'] : 'USD';
		$base_amount  = isset( $donation_data['amount'] ) && is_numeric( $donation_data['amount'] ) ? (float) $donation_data['amount'] : 0.0;
		$fees_covered = isset( $donation_data['fees_covered'] ) && is_numeric( $donation_data['fees_covered'] ) ? (float) $donation_data['fees_covered'] : 0.0;
		$total        = $base_amount + $fees_covered;

		$donor_name  = isset( $donation_data['donor_name'] ) && is_string( $donation_data['donor_name'] ) ? $donation_data['donor_name'] : '';
		$donor_email = isset( $donation_data['donor_email'] ) && is_string( $donation_data['donor_email'] ) ? $donation_data['donor_email'] : '';
		$gateway     = isset( $donation_data['gateway'] ) && is_string( $donation_data['gateway'] ) ? $donation_data['gateway'] : '';
		$status      = isset( $donation_data['payment_status'] ) && is_string( $donation_data['payment_status'] ) ? $donation_data['payment_status'] : '';

		$rows = [
			[
				'label' => __( 'Donor Name', 'suredonation' ),
				'value' => esc_html( $donor_name ),
			],
			[
				'label' => __( 'Donor Email', 'suredonation' ),
				'value' => esc_html( $donor_email ),
			],
		];

		if ( '' !== $campaign_name ) {
			$rows[] = [
				'label' => __( 'Campaign Name', 'suredonation' ),
				'value' => esc_html( $campaign_name ),
			];
		}

		$rows[] = [
			'label' => __( 'Payment Status', 'suredonation' ),
			'value' => self::render_payment_status_badge( $status ),
		];
		$rows[] = [
			'label' => __( 'Payment Method', 'suredonation' ),
			'value' => esc_html( self::get_payment_method_label( $gateway ) ),
		];
		$rows[] = [
			'label' => __( 'Donation Amount', 'suredonation' ),
			'value' => esc_html( Payment_Helper::format_amount( $base_amount, $currency ) ),
		];

		$rows_html = '';
		foreach ( $rows as $row ) {
			$rows_html .= sprintf(
				'<div class="sd-receipt-row"><span class="sd-receipt-row__label">%1$s</span><span class="sd-receipt-row__value">%2$s</span></div>',
				esc_html( $row['label'] ),
				$row['value']
			);
		}

		$rows_html .= sprintf(
			'<div class="sd-receipt-row sd-receipt-row--total"><span class="sd-receipt-row__label">%1$s</span><span class="sd-receipt-row__value">%2$s</span></div>',
			esc_html__( 'Donation Total', 'suredonation' ),
			esc_html( Payment_Helper::format_amount( $total, $currency ) )
		);

		return sprintf(
			'<div class="sd-receipt-card"><h3 class="sd-receipt-card__title">%1$s</h3><div class="sd-receipt-rows">%2$s</div></div>',
			esc_html__( 'Donation Receipt', 'suredonation' ),
			$rows_html
		);
	}

	/**
	 * Default confirmation message template (receipt layout with smart tags).
	 *
	 * @return string Message HTML template.
	 * @since 1.0.0
	 */
	public static function get_default_confirmation_message() {
		return '<p style="text-align: center; margin: 0;">{success_badge}</p>'
			. '<h2 class="sd-receipt-title" style="text-align: center;">'
			/* translators: {donor_name} is a smart tag replaced with the donor's name. */
			. esc_html__( 'Thank you {donor_name} for your Donation', 'suredonation' )
			. '</h2>'
			. '<p class="sd-receipt-subtitle" style="text-align: center;">'
			. esc_html__( 'Your contribution means a lot. We have sent an email to your registered account along with a receipt for your donation.', 'suredonation' )
			. '</p>{donation_receipt}';
	}

	/**
	 * Build the rendered confirmation/thank-you HTML for a donation.
	 *
	 * Resolves the form's confirmation message template against the donation's
	 * real data (smart tags) so the frontend can display the receipt.
	 *
	 * @param int $donation_id Donation ID.
	 * @return string Sanitized confirmation HTML, or '' on failure.
	 * @since 1.0.0
	 */
	public static function render_confirmation_message( $donation_id ) {
		$donation = Donations::get( $donation_id );
		if ( ! is_array( $donation ) ) {
			return '';
		}

		$form_id     = isset( $donation['form_id'] ) ? absint( $donation['form_id'] ) : 0;
		$campaign_id = isset( $donation['campaign_id'] ) ? absint( $donation['campaign_id'] ) : 0;

		$settings = self::get_form_confirmation_settings( $form_id );
		$template = ! empty( $settings['message'] ) ? $settings['message'] : self::get_default_confirmation_message();

		$donation_data = [
			'id'             => $donation_id,
			'donor_name'     => $donation['donor_name'] ?? '',
			'donor_email'    => $donation['donor_email'] ?? '',
			'amount'         => $donation['amount'] ?? 0,
			'fees_covered'   => $donation['fees_covered'] ?? 0,
			'currency'       => $donation['currency'] ?? Payment_Helper::get_currency(),
			'gateway'        => $donation['gateway'] ?? '',
			'payment_status' => $donation['payment_status'] ?? '',
			'transaction_id' => $donation['transaction_id'] ?? '',
			'donation_type'  => $donation['donation_type'] ?? 'one-time',
		];

		$campaign = $campaign_id ? get_post( $campaign_id ) : null;

		$rendered = Email_Handler::process_smart_tags( $template, $donation_data, $campaign );

		return wp_kses_post( $rendered );
	}

	/**
	 * Check whether the OttoKit (formerly SureTriggers) plugin is active and
	 * authenticated with the OttoKit SaaS.
	 *
	 * @return bool True when OttoKit is installed, active and connected.
	 * @since 1.2.0
	 */
	public static function is_suretriggers_ready() {
		if ( ! defined( 'SURE_TRIGGERS_FILE' ) ) {
			// Plugin is deactivated or not installed at all.
			return false;
		}

		$suretriggers_data = get_option( 'suretrigger_options', [] );
		if ( ! is_array( $suretriggers_data ) || empty( $suretriggers_data['secret_key'] ) || ! is_string( $suretriggers_data['secret_key'] ) ) {
			// OttoKit is not authenticated yet.
			return false;
		}

		return true;
	}

	/**
	 * Get OttoKit (formerly SureTriggers) integration metadata.
	 *
	 * Shared by the admin app and the donation form editor so both surface the
	 * same install/activate/connect state.
	 *
	 * @return array<string,mixed> Integration metadata.
	 * @since 1.2.0
	 */
	public static function get_ottokit_integration() {
		$plugin_file = 'suretriggers/suretriggers.php';

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$status = 'Install';
		if ( is_plugin_active( $plugin_file ) ) {
			$status = 'Activated';
		} elseif ( array_key_exists( $plugin_file, get_plugins() ) ) {
			$status = 'Installed';
		}

		return [
			'title'          => 'OttoKit',
			'slug'           => 'suretriggers',
			'path'           => $plugin_file,
			'status'         => $status,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filter is owned by the OttoKit plugin.
			'connected'      => apply_filters( 'suretriggers_is_user_connected', '' ),
			'connection_url' => admin_url( 'admin.php?page=suretriggers' ),
		];
	}
}
