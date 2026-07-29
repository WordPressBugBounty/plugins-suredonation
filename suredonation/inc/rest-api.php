<?php
/**
 * REST API Endpoints
 *
 * @package SureDonation
 */

namespace SureDonation\Inc;

use SureDonation\Inc\API\Campaigns_API;
use SureDonation\Inc\API\Dashboard_API;
use SureDonation\Inc\API\Donations_API;
use SureDonation\Inc\API\Donors_API;
use SureDonation\Inc\API\Forms_API;
use SureDonation\Inc\API\Import_Export_API;
use SureDonation\Inc\API\Import_Givewp_API;
use SureDonation\Inc\API\Onboarding_API;
use SureDonation\Inc\API\Settings_API;
use SureDonation\Inc\Traits\Get_Instance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rest_Api class.
 *
 * @since 0.0.1
 */
class Rest_Api {
	use Get_Instance;

	/**
	 * REST API namespace.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	private $namespace = 'suredonation';

	/**
	 * REST API version.
	 *
	 * @var string
	 * @since 0.0.1
	 */
	private $version = 'v1';

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Get REST API namespace with version.
	 *
	 * @return string
	 * @since 0.0.1
	 */
	public function get_namespace() {
		return $this->namespace . '/' . $this->version;
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_routes() {
		$endpoints = $this->get_endpoints();

		foreach ( $endpoints as $endpoint => $args ) {
			if ( ! is_array( $args ) ) {
				continue;
			}
			register_rest_route(
				$this->get_namespace(),
				$endpoint,
				$args
			);
		}
	}

	/**
	 * Get REST API endpoints.
	 *
	 * @return array<string, mixed>
	 * @since 0.0.1
	 */
	private function get_endpoints() {
		$campaigns_api     = new Campaigns_API();
		$donations_api     = new Donations_API();
		$donors_api        = new Donors_API();
		$dashboard_api     = new Dashboard_API();
		$forms_api         = new Forms_API();
		$settings_api      = new Settings_API();
		$import_givewp_api = Import_Givewp_API::get_instance();
		$import_export_api = new Import_Export_API();
		$onboarding_api    = new Onboarding_API();

		// Merge endpoints from all APIs.
		$endpoints = array_merge(
			$campaigns_api->get_endpoints(),
			$donations_api->get_endpoints(),
			$donors_api->get_endpoints(),
			$dashboard_api->get_endpoints(),
			$forms_api->get_endpoints(),
			$settings_api->get_endpoints(),
			$import_givewp_api->get_endpoints(),
			$import_export_api->get_endpoints(),
			$onboarding_api->get_endpoints()
		);

		/**
		 * Filter REST API endpoints.
		 *
		 * @param array $endpoints Array of REST API endpoints.
		 */
		return apply_filters(
			'suredonation_rest_api_endpoints',
			$endpoints
		);
	}
}
