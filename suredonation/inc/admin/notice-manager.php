<?php
/**
 * Admin Notice Manager Class.
 *
 * Bridges PHP-registered admin notices into the SureDonation React admin app.
 * PHP code registers notices via Notice_Manager::register_notice(); this class
 * injects them into the localized `suredonation_admin` global (through the
 * `suredonation_admin_app_data` filter) so the React AdminNotice component can
 * render them on the SPA pages.
 *
 * @package SureDonation
 * @since 1.3.0
 */

namespace SureDonation\Inc\Admin;

use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notice Manager class.
 *
 * Handles collection and distribution of admin notices to the React admin app.
 *
 * @since 1.3.0
 */
class Notice_Manager {
	use Get_Instance;

	/**
	 * Registered notices, keyed by notice id.
	 *
	 * @var array<string, array<string, mixed>>
	 * @since 1.3.0
	 */
	private static $notices = [];

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function __construct() {
		// Inject registered notices into the React app's localized data.
		add_filter( 'suredonation_admin_app_data', [ $this, 'add_notices_to_localized_data' ] );
	}

	/**
	 * Register a notice for display in the React admin pages.
	 *
	 * @param array<string, mixed> $notice_args {
	 *     Notice configuration arguments.
	 *
	 *     @type string $id          Required. Unique notice identifier.
	 *     @type string $variant     Notice type: 'error', 'warning', 'info', 'success'. Default 'info'.
	 *     @type string $message     Required. Notice message. May contain a single <a>…</a> marker
	 *                               whose text/position is used for the inline link (see $link).
	 *     @type string $title       Optional. Notice title.
	 *     @type array  $link        Optional. Inline link for the <a> marker: [ 'url' => string, 'target' => '_self'|'_blank' ].
	 *     @type string $event       Optional. Analytics event name fired when the notice's inline link is clicked.
	 *     @type array  $actions     Optional. Trailing action buttons, used only when $link is absent.
	 *     @type bool   $dismissible Optional. Reserved; dismissal is not yet wired in the React renderer. Default true.
	 * }
	 *
	 * Action button structure (trailing fallback):
	 * {
	 *     @type string $label  Required. Button text.
	 *     @type string $url    Optional. URL to navigate to on click.
	 *     @type string $target Optional. Link target: '_blank' or '_self'. Default '_self'.
	 * }
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public static function register_notice( $notice_args ) {
		// Validate required fields.
		if ( empty( $notice_args['id'] ) || empty( $notice_args['message'] ) ) {
			return;
		}

		// Set defaults.
		$notice = wp_parse_args(
			$notice_args,
			[
				'id'          => '',
				'variant'     => 'info',
				'message'     => '',
				'title'       => '',
				'link'        => null,
				'event'       => '',
				'actions'     => [],
				'dismissible' => true,
			]
		);

		// Store the notice.
		self::$notices[ $notice['id'] ] = $notice;
	}

	/**
	 * Get all registered notices.
	 *
	 * @since 1.3.0
	 * @return array<int, array<string, mixed>> Array of notice configurations.
	 */
	public static function get_notices() {
		return array_values( self::$notices );
	}

	/**
	 * Remove a registered notice.
	 *
	 * @param string $notice_id The notice ID to remove.
	 * @since 1.3.0
	 * @return void
	 */
	public static function remove_notice( $notice_id ) {
		if ( isset( self::$notices[ $notice_id ] ) ) {
			unset( self::$notices[ $notice_id ] );
		}
	}

	/**
	 * Clear all registered notices.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public static function clear_notices() {
		self::$notices = [];
	}

	/**
	 * Add the registered notices to the React app's localized data.
	 *
	 * Hooked - suredonation_admin_app_data
	 *
	 * @param array<string, mixed> $localization_data Existing localization data.
	 * @since 1.3.0
	 * @return array<string, mixed> Modified localization data with notices.
	 */
	public function add_notices_to_localized_data( $localization_data ) {
		if ( ! is_array( $localization_data ) ) {
			return $localization_data;
		}
		$localization_data['notices'] = self::get_notices();
		return $localization_data;
	}
}
