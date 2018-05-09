<?php
/**
 * Handles the license management for each addon.
 *
 * @package     wp-user-manager
 * @copyright   Copyright (c) 2018, Alessandro Tesoro
 * @license     https://opensource.org/licenses/gpl-license GNU Public License
 * @since       1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Carbon_Fields\Container;
use Carbon_Fields\Field;

class WPUM_License {

	/**
	 * Holds the addon file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Store the addon license key.
	 *
	 * @var string
	 */
	private $license;

	/**
	 * Item name from the site.
	 *
	 * @var string
	 */
	private $item_name;

	/**
	 * Item id from the site.
	 *
	 * @var string
	 */
	private $item_id;

	/**
	 * Item shortname.
	 *
	 * @var string
	 */
	private $item_shortname;

	/**
	 * Item version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * The author of the plugin.
	 *
	 * @var string
	 */
	private $author;

	/**
	 * Api url.
	 *
	 * @var string
	 */
	private $api_url = 'http://wpum.test';

	/**
	 * Construction function.
	 *
	 * @param string $file    file path.
	 * @param string $item_name    item name.
	 * @param string $version version of the addon.
	 * @param string $author  author of the addon.
	 */
	public function __construct( $file, $item_name, $item_id, $version, $author, $_api_url = null ) {

		$this->file      = $file;
		$this->item_name = $item_name;
		$this->item_id   = $item_id;
		$this->version   = $version;
		$this->author    = $author;

		if ( ! empty( $_api_url ) ) {
			$this->api_url = $_api_url;
		}

		$this->item_shortname = 'wpum_' . preg_replace( '/[^a-zA-Z0-9_\s]/', '', str_replace( ' ', '_', strtolower( $this->item_name ) ) );
		$this->license        = trim( get_option( '_' . $this->item_shortname . '_license_key', '' ) );

		$this->includes();
		$this->hooks();

	}

	/**
	 * Include the updater library.
	 */
	private function includes() {

		if ( ! class_exists( 'EDD_SL_Plugin_Updater' ) ) {
			require_once WPUM_PLUGIN_DIR . 'includes/wpum-updater/EDD_SL_Plugin_Updater.php';
		}

	}

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	private function hooks() {

		// Register settings.
		add_filter( 'wpum_licenses_register_addon_settings', array( $this, 'settings' ), 1 );

		add_action( 'carbon_fields_theme_options_container_saved', [ $this, 'activate_license' ] );

		// Activate license key on settings save.
		//add_action( 'admin_init', array( $this, 'activate_license' ) );

		// Deactivate license key.
		add_action( 'admin_init', array( $this, 'deactivate_license' ) );

		// Updater.
		add_action( 'admin_init', array( $this, 'auto_updater' ), 0 );

	}

	/**
	 * Register settings for the new addon.
	 *
	 * @param  $settings
	 * @return void
	 */
	public function settings( $settings ) {

		$new_settings[] = Field::make(
			'text',
			$this->item_shortname . '_license_key',
			sprintf( __( '%1$s License Key', 'wpum' ), $this->item_name )
		)->set_help_text(  $this->get_status_message() );

		return array_merge( $settings, $new_settings );

	}

	public function activate_license() {

		// Detect if license submission.
		if( isset( $_POST['_wpum_license_submission'] ) ) {

			if( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( 'valid' === get_option( $this->item_shortname . '_license_active' ) ) {
				return;
			}

			$license = sanitize_text_field( $_POST[ '_' . $this->item_shortname . '_license_key'] );

			if( empty( $license ) ) {
				return;
			}

			$api_params = array(
				'edd_action' => 'activate_license',
				'license'    => $license,
				'item_name'  => urlencode( $this->item_name ), // the name of our product in EDD
				'url'        => home_url()
			);

			$response = wp_remote_post(
				$this->api_url,
				array(
					'timeout'   => 15,
					'sslverify' => false,
					'body'      => $api_params
				)
			);

			// make sure the response came back okay.
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {

				if ( is_wp_error( $response ) ) {
					$message = $response->get_error_message();
				} else {
					$message = __( 'An error occurred, please try again.' );
				}

			} else {

				$license_data = json_decode( wp_remote_retrieve_body( $response ) );

				// Tell WordPress to look for updates.
				set_site_transient( 'update_plugins', null );

				update_option( $this->item_shortname . '_license_active', $license_data->license );

				if( $license_data->success ) {
					update_option( $this->item_shortname . '_license_expires', $license_data->expires );
				}

				if( ! (bool) $license_data->success ) {
					update_option( $this->item_shortname . '_license_active', $license_data->error );
				}

			}

		}

	}

	public function deactivate_license() {

	}

	public function auto_updater() {

		if ( 'valid' !== get_option( $this->item_shortname . '_license_active' ) )
			return;

		$edd_updater = new EDD_SL_Plugin_Updater( $this->api_url, $this->file, array(
			'version'   => $this->version,
			'license'   => $this->license,
			'item_id'   => $this->item_id,
			'item_name' => $this->item_name,
			'author'    => $this->author,
			'url'       => home_url()
    	) );

	}

	public function get_status_message() {

		$message = '';
		$status  = get_option( $this->item_shortname . '_license_active' );

		$status_class = 'notice-error';

		switch( $status ) {
			case 'expired' :
				$message = sprintf(
						__( 'Your license key expired on %s.' ),
						date_i18n( get_option( 'date_format' ), strtotime( get_option( $this->item_shortname . '_license_expires' ), current_time( 'timestamp' ) ) )
					);
				break;
			case 'disabled' :
			case 'revoked' :
				$message = __( 'Your license key has been disabled.' );
				break;
			case 'missing' :
				$message = __( 'Invalid license.' );
				break;
			case 'invalid' :
			case 'site_inactive' :
				$message = __( 'Your license is not active for this URL.' );
				break;
			case 'item_name_mismatch' :
				$message = sprintf( __( 'This appears to be an invalid license key for %s.' ), $this->item_name );
				break;
			case 'no_activations_left':
				$message = __( 'Your license key has reached its activation limit.' );
				break;
		}

		if( $status == 'valid' ) {
			$status_class == 'notice-success';
		}

		if( empty( $message ) && $status !== 'valid' ) {
			return false;
		}

		if( ! empty( $message ) ) {
			$message = '<div class="wpum-license-message is-alt ' . $status_class . ' '. $status .'"><p>' . $message . '</p></div>';
		}

		if( $status == 'valid' ) {
			$inline = sprintf( __( 'License successfully activated. Expires on %s' ), date_i18n( get_option( 'date_format' ), strtotime( get_option( $this->item_shortname . '_license_expires' ), current_time( 'timestamp' ) ) ) );
			$message = '<div class="wpum-license-message is-alt notice-success"><p>' . $inline . '</p></div>';
		}

		return $message;

	}

}