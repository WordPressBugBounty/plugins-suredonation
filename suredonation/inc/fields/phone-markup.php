<?php
/**
 * SureDonation Phone Number Markup Class.
 *
 * @package SureDonation
 * @since 1.1.1
 */

namespace SureDonation\Inc\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureDonation\Inc\Helper;

/**
 * Phone Number Markup Class.
 *
 * Renders a visible `<input type="tel">` (UI only, no name) enhanced on the front
 * end by intl-tel-input, paired with a hidden `.sd-input-common` input that carries
 * the submitted value (kept in sync by the frontend script via iti.getNumber()).
 * The hidden input is what the shared scalar validator reads, mirroring the
 * dropdown's hidden-input approach. See assets/build/blocks/phone.
 *
 * @since 1.1.1
 */
class Phone_Markup extends Base {
	/**
	 * Whether the visitor's country should be auto-detected.
	 *
	 * @var bool
	 * @since 1.1.1
	 */
	protected $auto_country = true;

	/**
	 * The default (initial) country code, lowercase 2-letter ISO.
	 *
	 * @var string
	 * @since 1.1.1
	 */
	protected $default_country = '';

	/**
	 * Whether the country filter is enabled.
	 *
	 * @var bool
	 * @since 1.1.1
	 */
	protected $enable_country_filter = false;

	/**
	 * Country filter type ('include' or 'exclude').
	 *
	 * @var string
	 * @since 1.1.1
	 */
	protected $country_filter_type = 'include';

	/**
	 * Country codes to include.
	 *
	 * @var array<int, string>
	 * @since 1.1.1
	 */
	protected $include_countries = [];

	/**
	 * Country codes to exclude.
	 *
	 * @var array<int, string>
	 * @since 1.1.1
	 */
	protected $exclude_countries = [];

	/**
	 * Initialize the properties based on block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @since 1.1.1
	 */
	public function __construct( $attributes ) {
		$this->slug                  = 'phone';
		$this->auto_country          = ! isset( $attributes['autoCountry'] ) || ! empty( $attributes['autoCountry'] );
		$this->default_country       = isset( $attributes['defaultCountry'] ) ? strtolower( sanitize_text_field( Helper::get_string_value( $attributes['defaultCountry'] ) ) ) : '';
		$this->enable_country_filter = ! empty( $attributes['enableCountryFilter'] );
		$this->country_filter_type   = isset( $attributes['countryFilterType'] ) && 'exclude' === $attributes['countryFilterType'] ? 'exclude' : 'include';
		$this->include_countries     = isset( $attributes['includeCountries'] ) && is_array( $attributes['includeCountries'] )
			? array_map( 'strtolower', array_map( 'sanitize_text_field', $attributes['includeCountries'] ) )
			: [];
		$this->exclude_countries     = isset( $attributes['excludeCountries'] ) && is_array( $attributes['excludeCountries'] )
			? array_map( 'strtolower', array_map( 'sanitize_text_field', $attributes['excludeCountries'] ) )
			: [];

		// When auto country is enabled and no explicit default is set, detect the
		// visitor's country via server-side IP geolocation (ipapi.co). See
		// get_geo_country() for the why and the caching/rate-limit details.
		if ( $this->auto_country && '' === $this->default_country ) {
			$this->default_country = $this->get_geo_country();
		}

		$this->set_properties( $attributes );
		$this->set_unique_slug();
		$this->set_markup_properties();
	}

	/**
	 * Render phone number markup.
	 *
	 * @since 1.1.1
	 * @return string
	 */
	public function markup() {
		$classes   = $this->get_field_classes( [ 'sd-phone-wrap-block' ] );
		$aria_desc = $this->get_aria_describedby();
		$data_slug = $this->block_slug ? $this->block_slug : $this->unique_slug;

		ob_start();
		?>
		<div data-block-id="<?php echo esc_attr( $this->block_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
			<?php echo wp_kses_post( $this->label_markup ); ?>
			<?php echo wp_kses_post( $this->help_markup ); ?>
			<div class="sd-block-wrap">
				<input
					type="tel"
					class="sd-input-phone"
					id="<?php echo esc_attr( $this->unique_slug ); ?>"
					<?php if ( ! empty( $aria_desc ) ) { ?>
						aria-describedby="<?php echo esc_attr( $aria_desc ); ?>"
					<?php } ?>
					data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					aria-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
					data-default-country="<?php echo esc_attr( $this->default_country ); ?>"
					data-auto-country="<?php echo $this->auto_country ? 'true' : 'false'; ?>"
					data-enable-country-filter="<?php echo $this->enable_country_filter ? 'true' : 'false'; ?>"
					data-country-filter-type="<?php echo esc_attr( $this->country_filter_type ); ?>"
					<?php if ( $this->enable_country_filter && 'include' === $this->country_filter_type && ! empty( $this->include_countries ) ) { ?>
						data-include-countries='<?php echo esc_attr( Helper::get_string_value( wp_json_encode( array_values( $this->include_countries ) ) ) ); ?>'
					<?php } ?>
					<?php if ( $this->enable_country_filter && 'exclude' === $this->country_filter_type && ! empty( $this->exclude_countries ) ) { ?>
						data-exclude-countries='<?php echo esc_attr( Helper::get_string_value( wp_json_encode( array_values( $this->exclude_countries ) ) ) ); ?>'
					<?php } ?>
					<?php echo wp_kses_post( $this->placeholder_attr ); ?>
					autocomplete="tel"
					inputmode="tel"
				/>
				<input
					type="hidden"
					class="sd-input-common sd-phone-hidden"
					name="<?php echo esc_attr( $this->field_name ); ?>"
					data-slug="<?php echo esc_attr( $data_slug ); ?>"
					data-required="<?php echo esc_attr( $this->data_require_attr ); ?>"
				/>
			</div>
			<div class="sd-error-wrap"><?php echo wp_kses_post( $this->error_msg_markup ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Detect the visitor's 2-letter country code via server-side IP geolocation.
	 *
	 * Calls ipapi.co once per visitor IP and caches the result in a transient for
	 * 24 hours so subsequent page loads resolve instantly without any API call.
	 *
	 * Failure responses (network error, non-200, malformed body, invalid country
	 * code) are cached as 'us' for 1 hour to prevent a retry storm if ipapi.co goes
	 * down or rate-limits us. Private/reserved IPs are rejected up front because
	 * ipapi.co cannot geolocate them. A site-wide hourly cap (default 40, filterable
	 * via `suredonation_phone_geo_api_hourly_cap`) bounds outbound calls so the
	 * ipapi free-tier quota cannot be exhausted by rotating spoofed IPs.
	 *
	 * @since 1.1.1
	 * @return string Lowercase 2-letter country code, defaults to 'us'.
	 */
	private function get_geo_country() {
		/**
		 * Global kill-switch for the third-party IP geolocation lookup.
		 *
		 * Complements the per-field "Auto-detect country" toggle: returning
		 * false here disables the ipapi.co transfer for every phone field at
		 * once (e.g. for privacy/GDPR compliance) without editing each form.
		 *
		 * @since 1.1.1
		 * @param bool $enabled Whether automatic IP-based country detection may run.
		 */
		if ( ! apply_filters( 'suredonation_phone_geo_enabled', true ) ) {
			return 'us';
		}

		// Never geolocate in the editor/REST context. The block is server-rendered,
		// so ServerSideRender would otherwise geolocate the editor's IP — polluting
		// the per-IP cache and burning the hourly quota on every form edit. The
		// frontend script re-detects for real visitors, so the preview loses nothing.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return 'us';
		}

		$ip = Helper::get_client_ip();
		if ( empty( $ip ) ) {
			return 'us';
		}

		// Reject private/reserved IPs: ipapi.co cannot geolocate them.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return 'us';
		}

		// Key the cache by a site-salted HMAC of the IP, not a bare md5 — md5 of an
		// IPv4 address is trivially reversible via rainbow tables, and the key is
		// stored in plaintext in the options table.
		$cache_key = 'suredonation_phone_country_' . hash_hmac( 'sha256', $ip, wp_salt() );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		// Site-wide hourly cap on outbound ipapi calls. The counter rolls over every
		// hour (key includes YmdH) so it never needs an explicit reset. Default 40
		// stays well under ipapi's 1,000/day free tier; raise via the filter.
		$quota_key = 'suredonation_phone_geo_quota_' . gmdate( 'YmdH' );
		$quota_cap = Helper::get_integer_value( apply_filters( 'suredonation_phone_geo_api_hourly_cap', 40 ) );
		$count     = Helper::get_integer_value( get_transient( $quota_key ) );
		if ( $count >= $quota_cap ) {
			set_transient( $cache_key, 'us', HOUR_IN_SECONDS );
			return 'us';
		}
		set_transient( $quota_key, $count + 1, HOUR_IN_SECONDS );

		// ipapi.co geolocates the *caller's* IP. Since this request originates from
		// the WordPress server (not the visitor's browser), pass the visitor's IP
		// explicitly via /{ip}/json/ — otherwise it returns the datacenter's country.
		$url      = 'https://ipapi.co/' . rawurlencode( $ip ) . '/json/';
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 3,
				'user-agent' => 'SureDonation/' . SUREDONATION_VER . ' (+https://suredonations.com)',
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, 'us', HOUR_IN_SECONDS );
			return 'us';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['country_code'] ) || ! is_string( $body['country_code'] ) ) {
			set_transient( $cache_key, 'us', HOUR_IN_SECONDS );
			return 'us';
		}

		$country = strtolower( $body['country_code'] );

		// Validate the external API response is a valid 2-letter country code.
		if ( ! preg_match( '/^[a-z]{2}$/', $country ) ) {
			set_transient( $cache_key, 'us', HOUR_IN_SECONDS );
			return 'us';
		}

		set_transient( $cache_key, $country, DAY_IN_SECONDS );

		return $country;
	}
}
