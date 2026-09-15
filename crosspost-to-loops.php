<?php
/**
 * Plugin Name:       Crosspost to Loops
 * Plugin URI:        https://wordpress.org/plugins/crosspost-to-loops
 * Description:       Automatically crossposts video posts from your WordPress blog to Loops.video (joinloops.org).
 * Version:           1.7.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            evecodes
 * Author URI:        https://github.com/evecodesx
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       crosspost-to-loops
 *
 * @package Crosspost_To_Loops
 */

defined( 'ABSPATH' ) || exit;

define( 'CTL_VERSION', '1.7.0' );
define( 'CTL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CTL_PLUGIN_DIR . 'includes/class-rabbit-cast.php';

/**
 * Main plugin class for Crosspost to Loops.
 *
 * Handles settings, OAuth flow, video detection, and uploading to Loops.video.
 *
 * @since 1.0.0
 */
final class Crosspost_To_Loops {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Option key used for all settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_loops_settings';

	/**
	 * Post-meta key storing the Loops video ID after a successful upload.
	 *
	 * @var string
	 */
	const META_VIDEO_ID = '_loops_video_id';

	/**
	 * Post-meta key storing the public URL on Loops.
	 *
	 * @var string
	 */
	const META_VIDEO_URL = '_loops_video_url';

	/**
	 * Post-meta key recording when the post was crossposted.
	 *
	 * @var string
	 */
	const META_CROSSPOSTED_AT = '_loops_crossposted_at';

	/**
	 * Post-meta key preventing duplicate auto-posts.
	 *
	 * @var string
	 */
	const META_CROSSPOST_LOCK = '_loops_crosspost_lock';

	/**
	 * Post-meta key storing the last error message.
	 *
	 * @var string
	 */
	const META_ERROR = '_loops_error';

	/**
	 * Option key for the debug log.
	 *
	 * @var string
	 */
	const LOG_OPTION = 'ctl_debug_log';

	/**
	 * Maximum number of log entries to keep.
	 *
	 * @var int
	 */
	const LOG_MAX = 100;

	/**
	 * Option key for OAuth client credentials.
	 *
	 * @var string
	 */
	const OAUTH_CLIENT_KEY = 'ctl_oauth_client';

	/**
	 * Transient key for OAuth state verification.
	 *
	 * @var string
	 */
	const OAUTH_STATE_KEY = 'ctl_oauth_state';

	// -----------------------------------------------------------------------
	// Bootstrap.
	// -----------------------------------------------------------------------

	/**
	 * Returns the singleton instance, creating it if necessary.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers all WordPress action and filter hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'transition_post_status', array( $this, 'maybe_auto_crosspost' ), 10, 3 );
		add_action( 'wp_ajax_loops_manual_crosspost', array( $this, 'ajax_manual_crosspost' ) );
		add_action( 'wp_ajax_loops_verify_token', array( $this, 'ajax_verify_token' ) );
		add_action( 'wp_ajax_loops_clear_log', array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_loops_test_crosspost', array( $this, 'ajax_test_crosspost' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_start' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( 'wp_ajax_loops_disconnect', array( $this, 'ajax_disconnect' ) );

		new Crosspost_To_Loops_Rabbit_Cast(
			$this,
			function ( string $file_path, array $fields, string $token, string $instance_url ) {
				return $this->api_upload_video( $file_path, $fields, $token, $instance_url );
			}
		);
	}

	/**
	 * Text domain loader (no-op since WordPress 4.6 auto-loads translations).
	 */
	public function load_textdomain(): void {
		// WordPress 4.6+ auto-loads translations for plugins hosted on WordPress.org.
		// No manual load_plugin_textdomain() call needed.
	}

	// -----------------------------------------------------------------------
	// OAuth connect flow.
	// -----------------------------------------------------------------------

	/**
	 * Step 1 of OAuth: registers the app with Loops then redirects to the authorization URL.
	 */
	public function handle_oauth_start(): void {
		if (
			! isset( $_GET['ctl_oauth_start'] ) ||
			! current_user_can( 'manage_options' ) ||
			! check_admin_referer( 'ctl_oauth_start' )
		) {
			return;
		}

		$s            = $this->get_settings();
		$instance_url = rtrim( $s['instance_url'], '/' );
		$redirect_uri = $this->oauth_redirect_uri();

		// Register the app with Loops to get fresh client credentials.
		$response = wp_remote_post(
			$instance_url . '/api/v1/apps',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'client_name'   => get_bloginfo( 'name' ) . ' (Crosspost to Loops)',
						'redirect_uris' => array( $redirect_uri ),
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( add_query_arg( 'ctl_oauth_error', rawurlencode( $response->get_error_message() ), $this->settings_url() ) );
			exit;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['client_id'] ) || empty( $data['client_secret'] ) ) {
			wp_safe_redirect( add_query_arg( 'ctl_oauth_error', rawurlencode( __( 'Could not register app with Loops.video. Check the Instance URL.', 'crosspost-to-loops' ) ), $this->settings_url() ) );
			exit;
		}

		// Save client credentials.
		update_option(
			self::OAUTH_CLIENT_KEY,
			array(
				'client_id'     => $data['client_id'],
				'client_secret' => $data['client_secret'],
				'redirect_uri'  => $redirect_uri,
			),
			false
		);

		// Generate and store a random state for CSRF protection.
		$state = wp_generate_password( 32, false );
		set_transient( self::OAUTH_STATE_KEY, $state, 10 * MINUTE_IN_SECONDS );

		// Redirect user to Loops authorize page (external — allow the instance host).
		$authorize_url = add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => $data['client_id'],
				'redirect_uri'  => $redirect_uri,
				'state'         => $state,
			),
			$instance_url . '/oauth/authorize'
		);

		$instance_host = wp_parse_url( $instance_url, PHP_URL_HOST );
		add_filter(
			'allowed_redirect_hosts',
			function ( array $hosts ) use ( $instance_host ): array {
				if ( $instance_host ) {
					$hosts[] = $instance_host;
				}
				return $hosts;
			}
		);

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Step 2 of OAuth: exchanges the authorization code for an access token.
	 */
	public function handle_oauth_callback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback from external provider; CSRF protection uses state parameter below.
		if ( ! isset( $_GET['ctl_oauth_callback'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Verify state parameter to prevent CSRF (replaces WordPress nonce for OAuth flows).
		$saved_state = get_transient( self::OAUTH_STATE_KEY );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$received_state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		if ( ! $saved_state || ! hash_equals( $saved_state, $received_state ) ) {
			wp_safe_redirect( add_query_arg( 'ctl_oauth_error', rawurlencode( __( 'Invalid OAuth state. Please try connecting again.', 'crosspost-to-loops' ) ), $this->settings_url() ) );
			exit;
		}
		delete_transient( self::OAUTH_STATE_KEY );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$error_msg = sanitize_text_field( wp_unslash( $_GET['error_description'] ?? $_GET['error'] ?? '' ) );
			wp_safe_redirect( add_query_arg( 'ctl_oauth_error', rawurlencode( $error_msg ), $this->settings_url() ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$code   = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		$client = get_option( self::OAUTH_CLIENT_KEY, array() );
		$s      = $this->get_settings();

		if ( empty( $code ) || empty( $client['client_id'] ) ) {
			wp_safe_redirect( add_query_arg( 'ctl_oauth_error', rawurlencode( __( 'Missing code or client credentials.', 'crosspost-to-loops' ) ), $this->settings_url() ) );
			exit;
		}

		// Exchange code for access token using standard form-encoded OAuth fields.
		$response = wp_remote_post(
			rtrim( $s['instance_url'], '/' ) . '/oauth/token',
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $client['client_id'],
					'client_secret' => $client['client_secret'],
					'redirect_uri'  => $client['redirect_uri'],
					'code'          => $code,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( add_query_arg( 'ctl_oauth_error', rawurlencode( $response->get_error_message() ), $this->settings_url() ) );
			exit;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			$msg = sanitize_text_field( $data['error_description'] ?? $data['message'] ?? __( 'Failed to retrieve access token.', 'crosspost-to-loops' ) );
			wp_safe_redirect( add_query_arg( 'ctl_oauth_error', rawurlencode( $msg ), $this->settings_url() ) );
			exit;
		}

		// Save the token into plugin settings.
		$settings                 = $this->get_settings();
		$settings['access_token'] = sanitize_text_field( $data['access_token'] );
		update_option( self::OPTION_KEY, $settings );

		$this->log( 'success', 'OAuth connect completed successfully.' );

		wp_safe_redirect( add_query_arg( 'ctl_oauth_success', '1', $this->settings_url() ) );
		exit;
	}

	/**
	 * AJAX handler: disconnects the account by deleting the token and client credentials.
	 */
	public function ajax_disconnect(): void {
		check_ajax_referer( 'loops_disconnect', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$settings                 = $this->get_settings();
		$settings['access_token'] = '';
		update_option( self::OPTION_KEY, $settings );
		delete_option( self::OAUTH_CLIENT_KEY );
		$this->log( 'info', 'Account disconnected.' );
		wp_send_json_success();
	}

	/**
	 * Returns the OAuth redirect URI for this plugin.
	 *
	 * @return string
	 */
	private function oauth_redirect_uri(): string {
		return admin_url( 'options-general.php?page=crosspost-to-loops&ctl_oauth_callback=1' );
	}

	/**
	 * Returns the URL to the plugin settings page.
	 *
	 * @return string
	 */
	private function settings_url(): string {
		return admin_url( 'options-general.php?page=crosspost-to-loops' );
	}

	/**
	 * Adds Settings and Debug Log links to the plugin row on the Plugins page.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_plugin_action_links( array $links ): array {
		$debug_link    = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=crosspost-to-loops&tab=debug' ) ),
			esc_html__( 'Debug Log', 'crosspost-to-loops' )
		);
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=crosspost-to-loops' ) ),
			esc_html__( 'Settings', 'crosspost-to-loops' )
		);
		array_unshift( $links, $debug_link );
		array_unshift( $links, $settings_link );
		return $links;
	}

	// Remaining plugin source unchanged from canonical main at commit 5f411604b23d3a67a7950bf8f7ad3fcd2500f374.
}

Crosspost_To_Loops::instance();
