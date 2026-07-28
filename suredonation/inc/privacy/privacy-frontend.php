<?php
/**
 * Privacy Frontend
 *
 * Renders the donation-form privacy fields (contact-consent checkbox, privacy-policy
 * blurb, terms blurb) that the Privacy settings enable, expanding the [privacy_policy]
 * / [terms] tokens within those blurbs (scoped — not registered as site-wide
 * shortcodes). Also exposes the server-side consent validation used at the shared
 * payment validation choke point.
 *
 * @package SureDonation
 * @since 1.2.0
 */

namespace SureDonation\Inc\Privacy;

use SureDonation\Inc\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Privacy_Frontend class.
 *
 * @since 1.2.0
 */
class Privacy_Frontend {
	/**
	 * POST field name for the contact-consent checkbox.
	 *
	 * @since 1.2.0
	 */
	public const CONSENT_FIELD = 'suredonation_privacy_consent';

	/**
	 * Render the donation-form privacy fields enabled in the Privacy settings.
	 *
	 * Output order matches Charitable: contact-consent checkbox, then the privacy
	 * policy statement, then the terms statement. Returns '' when none are enabled.
	 *
	 * @since 1.2.0
	 * @return string Escaped markup safe to echo before the donate button.
	 */
	public static function render_form_fields() {
		$settings = Privacy_Settings::get_settings();
		$html     = '';

		if ( ! empty( $settings['contact_consent_field'] ) ) {
			$required      = ! empty( $settings['contact_consent_required'] );
			$label         = isset( $settings['contact_consent_label'] ) ? Helper::get_string_value( $settings['contact_consent_label'] ) : '';
			$required_mark = $required ? ' <span class="sd-required" aria-hidden="true">*</span>' : '';

			// Show the required error the same way the form-builder checkbox does: an
			// inline .sd-error message (not the browser's native required popup). It is
			// pre-rendered here (hidden) so client validation (validation.js validates
			// .sd-privacy-consent on submit) can display it immediately, and the server
			// also enforces consent (Payment_Helper::validate_submission keys it into
			// field_errors by this input's data-slug) so showServerFieldErrors targets
			// the same .sd-error. Note: no .sd-input-common on the checkbox (that class
			// is text-input styling and would stretch the box); the form-builder checkbox
			// omits it too. The .sd-error-wrap uses height:auto (not the design system's
			// fixed error-line height) so it collapses when there is no error, keeping
			// the gap to the privacy blurb equal to the other fields.
			$error_slot = $required ? sprintf(
				'<div class="sd-error-wrap" style="height:auto"><p class="sd-error" role="alert" style="display:none">%s</p></div>',
				esc_html( self::consent_required_message() )
			) : '';

			$html .= sprintf(
				'<div class="sd-block-single sd-block sd-checkbox-block sd-consent-field">
					<div class="sd-block-wrap sd-checkbox-wrap">
						<label class="sd-checkbox-label">
							<input type="checkbox" class="sd-input-checkbox sd-privacy-consent" name="%1$s" value="yes" data-slug="%1$s" data-required="%2$s" aria-required="%2$s" />
							<span class="sd-checkbox-text">%3$s%4$s</span>
						</label>
					</div>
					%5$s
				</div>',
				esc_attr( self::CONSENT_FIELD ),
				esc_attr( $required ? 'true' : 'false' ),
				esc_html( $label ),
				$required_mark,
				$error_slot
			);
		}

		// Rendered as full-width paragraphs (sd-block-width-100 → flex-basis 100% so each
		// sits on its own line) rather than sd-block-single, which is a flex column and would
		// stack the inline [privacy_policy]/[terms] link onto its own line.
		// margin:0 so the blurbs rely solely on the form's row-gap for spacing (the
		// browser's default <p> margin would otherwise stack on top of it and make the
		// gaps around these blurbs larger than between the other fields).
		if ( ! empty( $settings['privacy_policy_field'] ) && ! empty( $settings['privacy_policy_text'] ) ) {
			$html .= sprintf(
				'<p class="sd-block-width-100 sd-privacy-policy" style="margin:0">%s</p>',
				self::expand_tokens( wp_kses_post( Helper::get_string_value( $settings['privacy_policy_text'] ) ) )
			);
		}

		if ( ! empty( $settings['terms_conditions_field'] ) && ! empty( $settings['terms_conditions_text'] ) ) {
			$html .= sprintf(
				'<p class="sd-block-width-100 sd-terms-conditions" style="margin:0">%s</p>',
				self::expand_tokens( wp_kses_post( Helper::get_string_value( $settings['terms_conditions_text'] ) ) )
			);
		}

		return $html;
	}

	/**
	 * The message shown when consent is required but not given.
	 *
	 * Shared by the pre-rendered inline .sd-error (client validation) and the
	 * server-side validate_consent() so both surface identical text.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public static function consent_required_message() {
		return __( 'Please acknowledge consent before submitting your donation.', 'suredonation' );
	}

	/**
	 * Validate the contact-consent requirement against the current request.
	 *
	 * Reads the consent checkbox from the POST body; the caller is responsible for
	 * nonce/token verification (mirrors Payment_Helper::get_submitted_donor_email).
	 * Returns '' when consent is not required or has been given.
	 *
	 * @since 1.2.0
	 * @return string Error message when consent is required but missing, else ''.
	 */
	public static function validate_consent() {
		$settings = Privacy_Settings::get_settings();

		if ( empty( $settings['contact_consent_field'] ) || empty( $settings['contact_consent_required'] ) ) {
			return '';
		}

		if ( 'yes' !== self::submitted_consent_value() ) {
			return self::consent_required_message();
		}

		return '';
	}

	/**
	 * Resolve the [privacy_policy] link — the Privacy Policy page configured in the
	 * Privacy settings, falling back to WordPress core's assigned privacy policy page.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	private static function privacy_policy_link() {
		$settings = Privacy_Settings::get_settings();
		$page_id  = isset( $settings['privacy_page_id'] ) ? absint( Helper::get_string_value( $settings['privacy_page_id'] ) ) : 0;
		if ( 0 === $page_id ) {
			$page_id = absint( Helper::get_string_value( get_option( 'wp_page_for_privacy_policy' ) ) );
		}
		return self::page_link( $page_id, __( 'Privacy Policy', 'suredonation' ) );
	}

	/**
	 * Resolve the [terms] link — the configured Terms & Conditions page.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	private static function terms_link() {
		$settings = Privacy_Settings::get_settings();
		$page_id  = isset( $settings['terms_page_id'] ) ? absint( Helper::get_string_value( $settings['terms_page_id'] ) ) : 0;
		return self::page_link( $page_id, __( 'Terms & Conditions', 'suredonation' ) );
	}

	/**
	 * Expand the scoped [privacy_policy] / [terms] tokens within a blurb string.
	 *
	 * Handled by a local str_replace rather than global shortcodes so the short,
	 * friendly tokens work inside the privacy/terms blurbs without registering
	 * site-wide shortcodes that would collide with other post/theme content
	 * (especially the very common [terms]).
	 *
	 * @since 1.2.0
	 * @param string $text Blurb text (already wp_kses_post'd).
	 * @return string
	 */
	private static function expand_tokens( $text ) {
		return str_replace(
			[ '[privacy_policy]', '[terms]' ],
			[ self::privacy_policy_link(), self::terms_link() ],
			$text
		);
	}

	/**
	 * Build a link to a page, or plain fallback text when the page is unset/invalid.
	 *
	 * @since 1.2.0
	 * @param int    $page_id  Page ID.
	 * @param string $fallback Text used when no page is set.
	 * @return string
	 */
	private static function page_link( $page_id, $fallback ) {
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			return sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( (string) get_permalink( $page_id ) ),
				esc_html( get_the_title( $page_id ) )
			);
		}
		return esc_html( $fallback );
	}

	/**
	 * Read the submitted consent value from the request.
	 *
	 * The AJAX gateways serialize every rendered field as fields[slug][value] (see
	 * gateway-base.js appendFieldValues), so the consent checkbox arrives nested under
	 * its data-slug; the no-JS native form POST sends it as a top-level field. Accept
	 * either location. The caller verifies the nonce/HMAC.
	 *
	 * @since 1.2.0
	 * @return string 'yes' when consent was given, else ''.
	 */
	private static function submitted_consent_value() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/HMAC verified by the calling handler.
		$nested = isset( $_POST['fields'][ self::CONSENT_FIELD ]['value'] ) ? sanitize_text_field( wp_unslash( $_POST['fields'][ self::CONSENT_FIELD ]['value'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce/HMAC verified by the calling handler.
		$top = isset( $_POST[ self::CONSENT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CONSENT_FIELD ] ) ) : '';

		return 'yes' === $nested || 'yes' === $top ? 'yes' : '';
	}
}
