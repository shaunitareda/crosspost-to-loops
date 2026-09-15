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

		// Exchange code for access token.
		$response = wp_remote_post(
			rtrim( $s['instance_url'], '/' ) . '/oauth/token',
			array(
				'headers' => array(
					'Accept' => 'application/json',
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

	// -----------------------------------------------------------------------
	// Settings.
	// -----------------------------------------------------------------------

	/**
	 * Registers the plugin settings page in the WordPress admin menu.
	 */
	public function register_admin_menu(): void {
		add_options_page(
			__( 'Crosspost to Loops', 'crosspost-to-loops' ),
			__( 'Crosspost to Loops', 'crosspost-to-loops' ),
			'manage_options',
			'crosspost-to-loops',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers plugin settings, sections, and fields with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			'wp_loops_settings_group',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);

		/* ---- Connection section ---- */
		add_settings_section(
			'loops_connection',
			__( 'Loops.video Connection', 'crosspost-to-loops' ),
			fn() => printf( '<p>%s</p>', esc_html__( 'Enter your Loops.video instance URL and API access token.', 'crosspost-to-loops' ) ),
			'crosspost-to-loops'
		);

		add_settings_field( 'instance_url', __( 'Instance URL', 'crosspost-to-loops' ), array( $this, 'field_instance_url' ), 'crosspost-to-loops', 'loops_connection' );
		add_settings_field( 'access_token', __( 'Access Token', 'crosspost-to-loops' ), array( $this, 'field_access_token' ), 'crosspost-to-loops', 'loops_connection' );

		/* ---- Crosspost behaviour section ---- */
		add_settings_section(
			'loops_behaviour',
			__( 'Crosspost Behaviour', 'crosspost-to-loops' ),
			fn() => printf( '<p>%s</p>', esc_html__( 'Control which posts get sent to Loops automatically.', 'crosspost-to-loops' ) ),
			'crosspost-to-loops'
		);

		add_settings_field( 'auto_crosspost', __( 'Auto-crosspost on publish', 'crosspost-to-loops' ), array( $this, 'field_auto_crosspost' ), 'crosspost-to-loops', 'loops_behaviour' );
		add_settings_field( 'enabled_post_types', __( 'Enabled post types', 'crosspost-to-loops' ), array( $this, 'field_post_types' ), 'crosspost-to-loops', 'loops_behaviour' );
		add_settings_field( 'video_source', __( 'Video source', 'crosspost-to-loops' ), array( $this, 'field_video_source' ), 'crosspost-to-loops', 'loops_behaviour' );
		add_settings_field( 'custom_field_name', __( 'Custom field name', 'crosspost-to-loops' ), array( $this, 'field_custom_field_name' ), 'crosspost-to-loops', 'loops_behaviour' );

		/* ---- Defaults section ---- */
		add_settings_section(
			'loops_defaults',
			__( 'Upload Defaults', 'crosspost-to-loops' ),
			fn() => printf( '<p>%s</p>', esc_html__( 'Default metadata applied to every upload.', 'crosspost-to-loops' ) ),
			'crosspost-to-loops'
		);

		add_settings_field( 'default_lang', __( 'Default language', 'crosspost-to-loops' ), array( $this, 'field_default_lang' ), 'crosspost-to-loops', 'loops_defaults' );
		add_settings_field( 'can_download', __( 'Allow downloads', 'crosspost-to-loops' ), array( $this, 'field_can_download' ), 'crosspost-to-loops', 'loops_defaults' );
		add_settings_field( 'can_comment', __( 'Allow comments', 'crosspost-to-loops' ), array( $this, 'field_can_comment' ), 'crosspost-to-loops', 'loops_defaults' );
		add_settings_field( 'can_duet', __( 'Allow duets', 'crosspost-to-loops' ), array( $this, 'field_can_duet' ), 'crosspost-to-loops', 'loops_defaults' );
		add_settings_field( 'can_stitch', __( 'Allow stitches', 'crosspost-to-loops' ), array( $this, 'field_can_stitch' ), 'crosspost-to-loops', 'loops_defaults' );
		add_settings_field( 'include_post_url', __( 'Append post URL to caption', 'crosspost-to-loops' ), array( $this, 'field_include_post_url' ), 'crosspost-to-loops', 'loops_defaults' );

		/* ---- Debug section ---- */
		add_settings_section(
			'loops_debug',
			__( 'Debug Log', 'crosspost-to-loops' ),
			fn() => printf( '<p>%s</p>', esc_html__( 'A log of all crosspost attempts. Useful for diagnosing errors.', 'crosspost-to-loops' ) ),
			'crosspost-to-loops'
		);

		add_settings_field( 'enable_debug', __( 'Enable logging', 'crosspost-to-loops' ), array( $this, 'field_enable_debug' ), 'crosspost-to-loops', 'loops_debug' );
	}

	/**
	 * Sanitizes and validates plugin settings before saving.
	 *
	 * @param array $input Raw input from the settings form.
	 * @return array
	 */
	public function sanitize_settings( array $input ): array {
		$clean = array();

		$clean['instance_url']       = esc_url_raw( trim( $input['instance_url'] ?? 'https://loops.video' ) );
		$existing                    = $this->get_settings();
		$submitted_token             = sanitize_text_field( trim( $input['access_token'] ?? '' ) );
		$clean['access_token']       = '' !== $submitted_token ? $submitted_token : (string) ( $existing['access_token'] ?? '' );
		$clean['auto_crosspost']     = ! empty( $input['auto_crosspost'] );
		$clean['enabled_post_types'] = array_map( 'sanitize_key', (array) ( $input['enabled_post_types'] ?? array( 'post' ) ) );
		$clean['video_source']       = sanitize_key( $input['video_source'] ?? 'attachment' );
		$clean['custom_field_name']  = sanitize_text_field( $input['custom_field_name'] ?? '' );
		$clean['default_lang']       = sanitize_key( $input['default_lang'] ?? 'en' );
		$clean['can_download']       = ! empty( $input['can_download'] );
		$clean['can_comment']        = ! empty( $input['can_comment'] );
		$clean['can_duet']           = ! empty( $input['can_duet'] );
		$clean['can_stitch']         = ! empty( $input['can_stitch'] );
		$clean['include_post_url']   = ! empty( $input['include_post_url'] );
		$clean['enable_debug']       = ! empty( $input['enable_debug'] );

		return $clean;
	}

	/**
	 * Returns the current plugin settings merged with defaults.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'instance_url'       => 'https://loops.video',
				'access_token'       => '',
				'auto_crosspost'     => false,
				'enabled_post_types' => array( 'post' ),
				'video_source'       => 'attachment',
				'custom_field_name'  => '',
				'default_lang'       => 'en',
				'can_download'       => true,
				'can_comment'        => true,
				'can_duet'           => false,
				'can_stitch'         => false,
				'include_post_url'   => true,
				'enable_debug'       => false,
			)
		);
	}

	// -----------------------------------------------------------------------
	// Settings field renderers.
	// -----------------------------------------------------------------------

	/**
	 * Renders the Instance URL settings field.
	 */
	public function field_instance_url(): void {
		$s = $this->get_settings();
		printf(
			'<input type="url" name="%s[instance_url]" value="%s" class="regular-text" placeholder="https://loops.video">
			 <p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $s['instance_url'] ),
			esc_html__( 'The base URL of your Loops instance. Default: https://loops.video', 'crosspost-to-loops' )
		);
	}

	/**
	 * Renders the Access Token settings field with eye-toggle and verify button.
	 */
	public function field_access_token(): void {
		$s = $this->get_settings();
		printf(
			'<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
			   <div style="position:relative;display:inline-flex;align-items:center">
			     <input type="password" id="loops_access_token" name="%s[access_token]" value="%s" class="regular-text" autocomplete="new-password" style="padding-right:36px">
			     <button type="button" id="loops_token_eye" aria-label="%s" style="position:absolute;right:6px;background:none;border:none;cursor:pointer;padding:0;color:#666;line-height:1">
			       <svg id="loops_eye_icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
			       <svg id="loops_eye_off_icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
			     </button>
			   </div>
			   <button type="button" id="loops_verify_token" class="button button-secondary">%s</button>
			   <span id="loops_token_status" style="font-weight:600"></span>
			 </div>
			 <p class="description">%s <a href="https://loops.video/settings/developer" target="_blank">%s</a>.</p>',
			esc_attr( self::OPTION_KEY ),
			'',
			esc_html__( 'Toggle token visibility', 'crosspost-to-loops' ),
			esc_html__( 'Verify token', 'crosspost-to-loops' ),
			esc_html__( 'Your Loops.video personal access token. Generate one in your account', 'crosspost-to-loops' ),
			esc_html__( 'developer settings', 'crosspost-to-loops' )
		);
	}

	/**
	 * Renders the auto-crosspost toggle field.
	 */
	public function field_auto_crosspost(): void {
		$s = $this->get_settings();
		printf(
			'<label><input type="checkbox" name="%s[auto_crosspost]" value="1" %s> %s</label>
			 <p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			checked( $s['auto_crosspost'], true, false ),
			esc_html__( 'Automatically crosspost when a post is published', 'crosspost-to-loops' ),
			esc_html__( 'Each post is only auto-crossposted once. You can still manually trigger a crosspost from the post editor.', 'crosspost-to-loops' )
		);
	}

	/**
	 * Renders the enabled post types checkboxes.
	 */
	public function field_post_types(): void {
		$s          = $this->get_settings();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( $post_types as $pt ) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%s[enabled_post_types][]" value="%s" %s> %s (<code>%s</code>)</label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $pt->name ),
				checked( in_array( $pt->name, $s['enabled_post_types'], true ), true, false ),
				esc_html( $pt->label ),
				esc_html( $pt->name )
			);
		}
	}

	/**
	 * Renders the video source radio buttons.
	 */
	public function field_video_source(): void {
		$s       = $this->get_settings();
		$options = array(
			'attachment'   => __( 'Attached video file (WordPress media library)', 'crosspost-to-loops' ),
			'content'      => __( 'First video URL found in post content (wp:video block)', 'crosspost-to-loops' ),
			'custom_field' => __( 'Custom field / post meta (specify the field name below)', 'crosspost-to-loops' ),
		);
		foreach ( $options as $val => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="radio" name="%s[video_source]" value="%s" %s> %s</label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $val ),
				checked( $s['video_source'], $val, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Renders the custom field name input.
	 */
	public function field_custom_field_name(): void {
		$s = $this->get_settings();
		printf(
			'<input type="text" name="%s[custom_field_name]" value="%s" class="regular-text" placeholder="video_url">
			 <p class="description">%s</p>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $s['custom_field_name'] ),
			esc_html__( 'Only used when "Custom field" is selected as the video source above.', 'crosspost-to-loops' )
		);
	}

	/**
	 * Renders the default language select.
	 */
	public function field_default_lang(): void {
		$s     = $this->get_settings();
		$langs = $this->supported_languages();
		echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[default_lang]">';
		foreach ( $langs as $code => $name ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $code ), selected( $s['default_lang'], $code, false ), esc_html( $name ) );
		}
		echo '</select>';
	}

	/**
	 * Renders a generic boolean checkbox field.
	 *
	 * @param string $key   The settings key.
	 * @param string $label The checkbox label.
	 */
	private function bool_field( string $key, string $label ): void {
		$s = $this->get_settings();
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			checked( $s[ $key ], true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Renders the allow-downloads field.
	 */
	public function field_can_download(): void {
		$this->bool_field( 'can_download', __( 'Yes', 'crosspost-to-loops' ) ); }
	/**
	 * Renders the allow-comments field.
	 */
	public function field_can_comment(): void {
		$this->bool_field( 'can_comment', __( 'Yes', 'crosspost-to-loops' ) ); }
	/**
	 * Renders the allow-duets field.
	 */
	public function field_can_duet(): void {
		$this->bool_field( 'can_duet', __( 'Yes', 'crosspost-to-loops' ) ); }
	/**
	 * Renders the allow-stitches field.
	 */
	public function field_can_stitch(): void {
		$this->bool_field( 'can_stitch', __( 'Yes', 'crosspost-to-loops' ) ); }
	/**
	 * Renders the append-post-URL field.
	 */
	public function field_include_post_url(): void {
		$this->bool_field( 'include_post_url', __( 'Yes (adds " – yoursite.com/post" to the description)', 'crosspost-to-loops' ) ); }

	// -----------------------------------------------------------------------
	// Settings page.
	// -----------------------------------------------------------------------

	/**
	 * Renders the main plugin settings page with tabs.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display page, tab param is sanitized via sanitize_key().
		$current_tab = sanitize_key( $_GET['tab'] ?? 'settings' );
		$tabs        = array(
			'settings' => __( 'Settings', 'crosspost-to-loops' ),
			'test'     => __( 'Test Crosspost', 'crosspost-to-loops' ),
			'debug'    => __( 'Debug Log', 'crosspost-to-loops' ),
		);
		?>
		<div class="wrap">
			<h1><span style="vertical-align:middle">📹</span> <?php esc_html_e( 'Crosspost to Loops', 'crosspost-to-loops' ); ?></h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:20px">
				<?php
				foreach ( $tabs as $slug => $label ) :
					$url    = admin_url( 'options-general.php?page=crosspost-to-loops&tab=' . $slug );
					$active = ( $slug === $current_tab ) ? ' nav-tab-active' : '';
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="nav-tab<?php echo esc_attr( $active ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'settings' === $current_tab ) : ?>

				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- settings-updated is set by WordPress options API, not user input. ?>
				<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'crosspost-to-loops' ); ?></p></div>
				<?php endif; ?>

				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ctl_oauth_success is an internal redirect indicator set by this plugin. ?>
				<?php if ( isset( $_GET['ctl_oauth_success'] ) ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '🎉 Successfully connected to Loops.video!', 'crosspost-to-loops' ); ?></p></div>
				<?php endif; ?>

				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ctl_oauth_error is an internal redirect indicator set by this plugin.
				$oauth_error = isset( $_GET['ctl_oauth_error'] ) ? sanitize_text_field( wp_unslash( $_GET['ctl_oauth_error'] ) ) : '';
				if ( $oauth_error ) :
					?>
					<div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( $oauth_error ) ); ?></p></div>
				<?php endif; ?>

				<?php $this->render_connected_account(); ?>

				<form method="post" action="options.php">
					<?php
					settings_fields( 'wp_loops_settings_group' );
					do_settings_sections( 'crosspost-to-loops' );
					submit_button();
					?>
				</form>

				<hr>
				<h2><?php esc_html_e( 'Video source notes', 'crosspost-to-loops' ); ?></h2>
				<ul style="list-style:disc;margin-left:20px">
					<li><strong><?php esc_html_e( 'Attached video file:', 'crosspost-to-loops' ); ?></strong> <?php esc_html_e( 'The plugin looks for the first video file (mp4, mov, webm…) directly attached to the post in the WordPress Media Library.', 'crosspost-to-loops' ); ?></li>
					<li><strong><?php esc_html_e( 'Post content:', 'crosspost-to-loops' ); ?></strong> <?php esc_html_e( 'Parses the post body for a WordPress Video block and extracts its URL.', 'crosspost-to-loops' ); ?></li>
					<li><strong><?php esc_html_e( 'Custom field:', 'crosspost-to-loops' ); ?></strong> <?php esc_html_e( 'Reads a URL from the post meta key you specify. Useful for ACF or other custom fields.', 'crosspost-to-loops' ); ?></li>
				</ul>

			<?php elseif ( 'test' === $current_tab ) : ?>

				<?php $this->render_test_crosspost(); ?>

			<?php elseif ( 'debug' === $current_tab ) : ?>

				<?php $this->render_debug_log(); ?>

			<?php endif; ?>

		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Metabox.
	// -----------------------------------------------------------------------

	/**
	 * Registers the Loops.video metabox on enabled post type edit screens.
	 */
	public function add_metabox(): void {
		$s          = $this->get_settings();
		$post_types = ! empty( $s['enabled_post_types'] ) ? $s['enabled_post_types'] : array( 'post' );

		foreach ( $post_types as $pt ) {
			add_meta_box(
				'wp_loops_crosspost',
				__( '📹 Loops.video', 'crosspost-to-loops' ),
				array( $this, 'render_metabox' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the Loops.video crosspost metabox.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function render_metabox( WP_Post $post ): void {
		$video_id       = get_post_meta( $post->ID, self::META_VIDEO_ID, true );
		$video_url      = get_post_meta( $post->ID, self::META_VIDEO_URL, true );
		$crossposted_at = get_post_meta( $post->ID, self::META_CROSSPOSTED_AT, true );
		$error          = get_post_meta( $post->ID, self::META_ERROR, true );
		$s              = $this->get_settings();
		$has_token      = ! empty( $s['access_token'] );

		wp_nonce_field( 'loops_crosspost_nonce', 'loops_nonce' );
		?>
		<div id="loops-metabox-wrap" style="font-size:13px">

			<?php if ( $video_id ) : ?>
				<div style="background:#f0fff4;border:1px solid #68d391;border-radius:4px;padding:8px 10px;margin-bottom:10px">
					<strong style="color:#276749">✅ <?php esc_html_e( 'Crossposted!', 'crosspost-to-loops' ); ?></strong><br>
					<?php if ( $crossposted_at ) : ?>
						<span style="color:#555"><?php echo esc_html( human_time_diff( strtotime( $crossposted_at ) ) . ' ' . __( 'ago', 'crosspost-to-loops' ) ); ?></span><br>
					<?php endif; ?>
					<?php if ( $video_url ) : ?>
						<a href="<?php echo esc_url( $video_url ); ?>" target="_blank" style="word-break:break-all"><?php echo esc_url( $video_url ); ?></a>
					<?php endif; ?>
				</div>
			<?php elseif ( $error ) : ?>
				<div style="background:#fff5f5;border:1px solid #fc8181;border-radius:4px;padding:8px 10px;margin-bottom:10px">
					<strong style="color:#c53030">❌ <?php esc_html_e( 'Last attempt failed:', 'crosspost-to-loops' ); ?></strong><br>
					<span style="color:#555;word-break:break-all"><?php echo esc_html( $error ); ?></span>
				</div>
			<?php else : ?>
				<p style="color:#666;margin-bottom:10px"><?php esc_html_e( 'This post has not been crossposted yet.', 'crosspost-to-loops' ); ?></p>
			<?php endif; ?>

			<?php if ( ! $has_token ) : ?>
				<p style="color:#c53030">
				<?php
					printf(
						wp_kses(
							/* translators: %s: URL to settings page */
							__( '⚠️ No API token configured. <a href="%s">Go to settings</a>.', 'crosspost-to-loops' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_url( admin_url( 'options-general.php?page=crosspost-to-loops' ) )
					);
				?>
				</p>
			<?php else : ?>
				<button type="button"
					id="loops-crosspost-btn"
					class="button button-secondary"
					data-post-id="<?php echo esc_attr( $post->ID ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'loops_manual_crosspost_' . $post->ID ) ); ?>"
					style="width:100%">
					<?php echo $video_id ? esc_html__( '🔄 Re-crosspost to Loops.video', 'crosspost-to-loops' ) : esc_html__( '🚀 Crosspost to Loops.video', 'crosspost-to-loops' ); ?>
				</button>
				<div id="loops-crosspost-result" style="margin-top:8px"></div>
			<?php endif; ?>

			<p style="color:#888;margin-top:8px;font-size:11px">
				<?php
				$source_label = array(
					'attachment'   => esc_html__( 'attached video file', 'crosspost-to-loops' ),
					'content'      => esc_html__( 'video block in post content', 'crosspost-to-loops' ),
					'custom_field' => sprintf(
						/* translators: %s: custom field name */
						esc_html__( 'custom field: %s', 'crosspost-to-loops' ),
						'<code>' . esc_html( $s['custom_field_name'] ) . '</code>'
					),
				)[ $s['video_source'] ] ?? '';
				printf(
					/* translators: %s: video source description */
					esc_html__( 'Looking for %s.', 'crosspost-to-loops' ),
					wp_kses( $source_label, array( 'code' => array() ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Auto-crosspost hook.
	// -----------------------------------------------------------------------

	/**
	 * Auto-crossposts a post when it transitions to published.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       The post object.
	 */
	public function maybe_auto_crosspost( string $new_status, string $old_status, WP_Post $post ): void {
		$s = $this->get_settings();

		// Only when auto is enabled and post transitions to "publish".
		if ( ! $s['auto_crosspost'] || 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		// Only for configured post types.
		if ( ! in_array( $post->post_type, $s['enabled_post_types'], true ) ) {
			return;
		}

		// Prevent double-posting (e.g. on "quick edit" re-saves).
		if ( get_post_meta( $post->ID, self::META_CROSSPOST_LOCK, true ) ) {
			return;
		}

		update_post_meta( $post->ID, self::META_CROSSPOST_LOCK, '1' );

		$this->crosspost_post( $post->ID );
	}

	// -----------------------------------------------------------------------
	// AJAX handlers.
	// -----------------------------------------------------------------------

	/**
	 * AJAX handler: manually triggers a crosspost for a given post.
	 */
	public function ajax_manual_crosspost(): void {
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		check_ajax_referer( 'loops_manual_crosspost_' . $post_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crosspost-to-loops' ) ) );
		}

		// Allow re-posting — clear the lock.
		delete_post_meta( $post_id, self::META_CROSSPOST_LOCK );

		$result = $this->crosspost_post( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'   => __( 'Successfully crossposted to Loops.video!', 'crosspost-to-loops' ),
				'video_url' => get_post_meta( $post_id, self::META_VIDEO_URL, true ),
			)
		);
	}

	/**
	 * AJAX handler: verifies the stored API token against the Loops API.
	 */
	public function ajax_verify_token(): void {
		check_ajax_referer( 'loops_verify_token', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crosspost-to-loops' ) ) );
		}

		$token        = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$instance_url = esc_url_raw( wp_unslash( $_POST['instance_url'] ?? 'https://loops.video' ) );

		$result = $this->api_get( '/v1/account/info/self', $token, $instance_url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$data = $result['data'] ?? array();
		wp_send_json_success(
			array(
				/* translators: %s: Loops.video username */
				'message'        => sprintf( __( 'Connected as @%s', 'crosspost-to-loops' ), $data['username'] ?? '' ),
				'username'       => $data['username'] ?? '',
				'name'           => $data['name'] ?? '',
				'avatar'         => $data['avatar'] ?? '',
				'post_count'     => $data['post_count'] ?? 0,
				'follower_count' => $data['follower_count'] ?? 0,
				'url'            => $data['url'] ?? '',
			)
		);
	}

	// -----------------------------------------------------------------------
	// Core crosspost logic.
	// -----------------------------------------------------------------------

	/**
	 * Finds the video for a post and uploads it to Loops.
	 *
	 * @param int $post_id The WordPress post ID to crosspost.
	 * @return true|WP_Error
	 */
	public function crosspost_post( int $post_id ): bool|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->log( 'error', "Post #{$post_id} not found." );
			return new WP_Error( 'no_post', __( 'Post not found.', 'crosspost-to-loops' ) );
		}

		$s = $this->get_settings();

		if ( empty( $s['access_token'] ) ) {
			$error = __( 'No Loops.video access token configured.', 'crosspost-to-loops' );
			$this->log( 'error', $error );
			update_post_meta( $post_id, self::META_ERROR, $error );
			return new WP_Error( 'no_token', $error );
		}

		$this->log( 'info', "Starting crosspost for post #{$post_id} \"{$post->post_title}\".", array( 'source' => $s['video_source'] ) );

		// Find the video file for this post.
		$video_path = null;
		$temp_file  = false;

		switch ( $s['video_source'] ) {
			case 'attachment':
				[ $video_path, $temp_file ] = $this->get_video_from_attachment( $post_id );
				break;

			case 'content':
				[ $video_path, $temp_file ] = $this->get_video_from_content( $post );
				break;

			case 'custom_field':
				[ $video_path, $temp_file ] = $this->get_video_from_custom_field( $post_id, $s['custom_field_name'] );
				break;
		}

		if ( ! $video_path ) {
			$error = __( 'No video found for this post. Check the "Video source" setting.', 'crosspost-to-loops' );
			$this->log(
				'error',
				$error,
				array(
					'post_id' => $post_id,
					'source'  => $s['video_source'],
				)
			);
			update_post_meta( $post_id, self::META_ERROR, $error );
			return new WP_Error( 'no_video', $error );
		}

		$this->log(
			'info',
			'Video file located.',
			array(
				'path' => basename( $video_path ),
				'temp' => $temp_file,
			)
		);

		// Build the caption.
		$caption = wp_strip_all_tags( ! empty( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( $post->post_content, 30, '' ) );
		if ( $s['include_post_url'] ) {
			$url     = get_permalink( $post_id );
			$caption = trim( $caption . ' – ' . $url );
		}
		// Loops enforces a 200-char limit on description.
		$caption = mb_substr( $caption, 0, 200 );

		$this->log( 'info', 'Uploading to Loops.video…', array( 'caption_length' => mb_strlen( $caption ) ) );

		// Upload to Loops.
		$result = $this->api_upload_video(
			$video_path,
			array(
				'description'  => $caption,
				'lang'         => $s['default_lang'],
				'can_download' => $s['can_download'],
				'can_comment'  => $s['can_comment'],
				'can_duet'     => $s['can_duet'],
				'can_stitch'   => $s['can_stitch'],
			),
			$s['access_token'],
			$s['instance_url']
		);

		if ( $temp_file && file_exists( $video_path ) ) {
			wp_delete_file( $video_path );
		}

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, self::META_ERROR, $result->get_error_message() );
			return $result;
		}

		// Store the result.
		$loops_id  = $result['data']['id'] ?? ( $result['id'] ?? '' );
		$loops_url = $result['data']['url'] ?? ( $result['url'] ?? '' );

		$this->log(
			'success',
			"Upload successful for post #{$post_id}.",
			array(
				'loops_id' => $loops_id,
				'url'      => $loops_url,
			)
		);

		delete_post_meta( $post_id, self::META_ERROR );
		update_post_meta( $post_id, self::META_VIDEO_ID, $loops_id );
		update_post_meta( $post_id, self::META_VIDEO_URL, $loops_url );
		update_post_meta( $post_id, self::META_CROSSPOSTED_AT, current_time( 'mysql' ) );

		return true;
	}

	// -----------------------------------------------------------------------
	// Video resolvers.
	// -----------------------------------------------------------------------

	/**
	 * Returns [ local_path, is_temp ] for the first video attachment of a post.
	 */
	private function get_video_from_attachment( int $post_id ): array {
		$attachments = get_posts(
			array(
				'post_parent'    => $post_id,
				'post_type'      => 'attachment',
				'post_mime_type' => 'video',
				'posts_per_page' => 1,
				'post_status'    => 'inherit',
				'fields'         => 'ids',
			)
		);

		if ( empty( $attachments ) ) {
			return array( null, false );
		}

		$path = get_attached_file( $attachments[0] );
		return $path && file_exists( $path ) ? array( $path, false ) : array( null, false );
	}

	/**
	 * Parses the post body for a WordPress video block and downloads the URL.
	 */
	private function get_video_from_content( WP_Post $post ): array {
		// Match wp:video blocks.
		if ( preg_match( '/<source[^>]+src=["\']([^"\']+\.(mp4|mov|webm|ogg|avi|mkv))["\']/', $post->post_content, $m ) ) {
			return $this->download_video_to_tmp( $m[1] );
		}

		// Fallback: any naked video URL in post content.
		if ( preg_match( '/https?:\/\/[^\s"\'<>]+\.(mp4|mov|webm|ogg)/i', $post->post_content, $m ) ) {
			return $this->download_video_to_tmp( $m[0] );
		}

		return array( null, false );
	}

	/**
	 * Reads a video URL from a custom post meta field and downloads it.
	 */
	private function get_video_from_custom_field( int $post_id, string $field_name ): array {
		if ( empty( $field_name ) ) {
			return array( null, false );
		}

		$url = get_post_meta( $post_id, $field_name, true );
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array( null, false );
		}

		// Could be a local attachment URL — try to convert to a path first.
		$local_path = $this->url_to_local_path( $url );
		if ( $local_path ) {
			return array( $local_path, false );
		}

		return $this->download_video_to_tmp( $url );
	}

	/**
	 * Attempts to convert a local WordPress upload URL to a filesystem path.
	 */
	private function url_to_local_path( string $url ): ?string {
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];
		$base_dir   = $upload_dir['basedir'];

		if ( strpos( $url, $base_url ) === 0 ) {
			$path = str_replace( $base_url, $base_dir, $url );
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Downloads a remote video to a temp file. Returns [ path, true ].
	 */
	private function download_video_to_tmp( string $url ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return array( null, false );
		}

		// Add a video extension so the API accepts it.
		$raw_ext  = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
		$ext      = $raw_ext ? $raw_ext : 'mp4';
		$new_path = $tmp . '.' . $ext;

		$fs = new WP_Filesystem_Direct( array() );
		if ( ! $fs->move( $tmp, $new_path, true ) ) {
			wp_delete_file( $tmp );
			return array( null, false );
		}

		return array( $new_path, true );
	}

	// -----------------------------------------------------------------------
	// API helpers.
	// -----------------------------------------------------------------------

	/**
	 * Performs a GET request against the Loops API.
	 *
	 * @return array|WP_Error
	 */
	private function api_get( string $endpoint, string $token, string $instance_url ): array|WP_Error {
		$url      = rtrim( $instance_url, '/' ) . '/api' . $endpoint;
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		return $this->parse_api_response( $response );
	}

	/**
	 * Uploads a video file using multipart/form-data.
	 *
	 * @return array|WP_Error
	 */
	private function api_upload_video( string $file_path, array $fields, string $token, string $instance_url ): array|WP_Error {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_missing', __( 'Video file not found on disk.', 'crosspost-to-loops' ) );
		}

		$url      = rtrim( $instance_url, '/' ) . '/api/v1/studio/upload';
		$boundary = wp_generate_password( 24, false );
		$body     = '';

		// Append scalar fields.
		$scalar_fields = array(
			'description'  => $fields['description'] ?? '',
			'lang'         => $fields['lang'] ?? 'en',
			'can_download' => $fields['can_download'] ? '1' : '0',
			'can_comment'  => $fields['can_comment'] ? '1' : '0',
			'can_duet'     => $fields['can_duet'] ? '1' : '0',
			'can_stitch'   => $fields['can_stitch'] ? '1' : '0',
		);

		foreach ( $scalar_fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
			$body .= $value . "\r\n";
		}

		// Append video file.
		global $wp_filesystem;
		WP_Filesystem();
		$file_data = $wp_filesystem->get_contents( $file_path );
		$file_name = basename( $file_path );
		$mime_type = mime_content_type( $file_path ) ? mime_content_type( $file_path ) : 'video/mp4';

		$body .= "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"video\"; filename=\"{$file_name}\"\r\n";
		$body .= "Content-Type: {$mime_type}\r\n\r\n";
		$body .= $file_data . "\r\n";
		$body .= "--{$boundary}--\r\n";

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					'Accept'        => 'application/json',
				),
				'body'    => $body,
				'timeout' => 300, // Large files can take a while.
			)
		);

		return $this->parse_api_response( $response );
	}

	/**
	 * Parses a WP_HTTP response into an array or WP_Error.
	 *
	 * @param array|WP_Error $response
	 * @return array|WP_Error
	 */
	private function parse_api_response( $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 200 && $code < 300 ) {
			return $data ?? array();
		}

		/* translators: %d: HTTP status code */
		$message = $data['message'] ?? $data['error']['message'] ?? sprintf( __( 'API error (HTTP %d)', 'crosspost-to-loops' ), $code );

		// Surface validation errors.
		if ( ! empty( $data['errors'] ) ) {
			$flat = array();
			array_walk_recursive(
				$data['errors'],
				function ( $v ) use ( &$flat ) {
					$flat[] = $v;
				}
			);
			$message .= ': ' . implode( ', ', $flat );
		}

		return new WP_Error(
			'loops_api_error',
			$message,
			array(
				'status' => $code,
				'body'   => $body,
			)
		);
	}

	// -----------------------------------------------------------------------
	// Admin assets.
	// -----------------------------------------------------------------------

	/**
	 * Enqueues admin scripts and styles on relevant pages.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		$is_settings  = ( 'settings_page_crosspost-to-loops' === $hook );
		$is_post_edit = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_settings && ! $is_post_edit ) {
			return;
		}

		wp_enqueue_script(
			'crosspost-to-loops-admin',
			CTL_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			CTL_VERSION,
			true
		);

		wp_localize_script(
			'crosspost-to-loops-admin',
			'crosspostToLoops',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'verifyTokenNonce' => wp_create_nonce( 'loops_verify_token' ),
				'disconnectNonce'  => wp_create_nonce( 'loops_disconnect' ),
				'i18n'             => array(
					'verifying'         => __( 'Verifying…', 'crosspost-to-loops' ),
					'crossposting'      => __( 'Uploading to Loops.video…', 'crosspost-to-loops' ),
					'disconnecting'     => __( 'Disconnecting…', 'crosspost-to-loops' ),
					'confirmDisconnect' => __( 'Disconnect your Loops.video account? You can reconnect at any time.', 'crosspost-to-loops' ),
				),
			)
		);

		wp_add_inline_style(
			'common',
			'
			#loops-metabox-wrap a { text-decoration:none; }
			#loops-crosspost-result.success { color:#276749; }
			#loops-crosspost-result.error   { color:#c53030; }
		'
		);
	}

	/**
	 * Renders the enable-debug-log field.
	 */
	public function field_enable_debug(): void {
		$this->bool_field( 'enable_debug', __( 'Yes — record all crosspost attempts in the log below', 'crosspost-to-loops' ) ); }

	/**
	 * Renders the connected account card or the connect button.
	 */
	private function render_connected_account(): void {
		$s            = $this->get_settings();
		$connect_url  = wp_nonce_url(
			admin_url( 'options-general.php?page=crosspost-to-loops&ctl_oauth_start=1' ),
			'ctl_oauth_start'
		);
		$instance_url = rtrim( $s['instance_url'], '/' );

		// Not connected — show big connect button.
		if ( empty( $s['access_token'] ) ) {
			?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px 28px;margin-bottom:24px;max-width:520px;box-shadow:0 1px 3px rgba(0,0,0,.06)">
				<h3 style="margin:0 0 8px;font-size:16px">
					<?php esc_html_e( 'Connect your Loops.video account', 'crosspost-to-loops' ); ?>
				</h3>
				<p style="color:#666;margin:0 0 16px;font-size:13px">
					<?php esc_html_e( 'Click the button below to log in to Loops.video and authorise this plugin. You\'ll be redirected back here automatically.', 'crosspost-to-loops' ); ?>
				</p>
				<a href="<?php echo esc_url( $connect_url ); ?>"
					class="button button-primary"
					style="font-size:14px;height:36px;line-height:34px;padding:0 18px">
					🔗 <?php esc_html_e( 'Connect to Loops.video', 'crosspost-to-loops' ); ?>
				</a>
				<p style="color:#999;font-size:12px;margin:12px 0 0">
					<?php
					printf(
						wp_kses(
							/* translators: %s: Loops instance URL */
							__( 'Connecting to: <strong>%s</strong>. Change this in the Instance URL setting below if needed.', 'crosspost-to-loops' ),
							array( 'strong' => array() )
						),
						esc_html( $instance_url )
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Connected — show account card.
		$account = $this->api_get( '/v1/account/info/self', $s['access_token'], $s['instance_url'] );
		$data    = ! is_wp_error( $account ) ? ( $account['data'] ?? null ) : null;
		?>
		<div id="loops-connected-account" style="margin-bottom:24px">
		<?php if ( $data ) : ?>
			<div style="display:inline-flex;align-items:center;gap:14px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;box-shadow:0 1px 3px rgba(0,0,0,.06)">
				<?php if ( ! empty( $data['avatar'] ) ) : ?>
					<img src="<?php echo esc_url( $data['avatar'] ); ?>" alt=""
						style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:1px solid #eee">
				<?php else : ?>
					<div style="width:48px;height:48px;border-radius:50%;background:#6c63ff;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:600">
						<?php echo esc_html( strtoupper( substr( $data['username'] ?? '?', 0, 1 ) ) ); ?>
					</div>
				<?php endif; ?>
				<div>
					<div style="font-weight:600;font-size:15px;color:#1a1a1a">
						<?php echo esc_html( $data['name'] ?? $data['username'] ); ?>
					</div>
					<div style="color:#666;font-size:13px">
						@<?php echo esc_html( $data['username'] ); ?>
						&nbsp;·&nbsp;
						<?php
						$video_count = (int) ( $data['post_count'] ?? 0 );
						printf(
							/* translators: %s: number of videos */
							esc_html( _n( '%s video', '%s videos', $video_count, 'crosspost-to-loops' ) ),
							esc_html( number_format_i18n( $video_count ) )
						);
						?>
						&nbsp;·&nbsp;
						<?php
						$follower_count = (int) ( $data['follower_count'] ?? 0 );
						printf(
							/* translators: %s: number of followers */
							esc_html( _n( '%s follower', '%s followers', $follower_count, 'crosspost-to-loops' ) ),
							esc_html( number_format_i18n( $follower_count ) )
						);
						?>
					</div>
					<div style="margin-top:4px">
						<span style="display:inline-block;background:#e6f4ea;color:#1e7e34;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px">
							✓ <?php esc_html_e( 'Connected', 'crosspost-to-loops' ); ?>
						</span>
					</div>
				</div>
				<div style="margin-left:8px;display:flex;flex-direction:column;gap:6px">
					<a href="<?php echo esc_url( $data['url'] ?? $instance_url ); ?>" target="_blank"
						class="button button-secondary" style="font-size:12px;text-align:center">
						<?php esc_html_e( 'View profile →', 'crosspost-to-loops' ); ?>
					</a>
					<button type="button" id="loops-disconnect-btn"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'loops_disconnect' ) ); ?>"
						data-connect-url="<?php echo esc_attr( $connect_url ); ?>"
						class="button" style="font-size:12px;color:#c53030;border-color:#c53030">
						<?php esc_html_e( 'Disconnect', 'crosspost-to-loops' ); ?>
					</button>
				</div>
			</div>
		<?php else : ?>
			<div style="display:inline-flex;align-items:center;gap:12px;background:#fff8f8;border:1px solid #f5c6c6;border-radius:8px;padding:12px 16px">
				<span style="color:#c53030;font-size:18px">⚠</span>
				<div>
					<div style="color:#c53030;font-size:13px;margin-bottom:6px">
						<?php esc_html_e( 'Could not verify your Loops.video connection. Your token may have expired.', 'crosspost-to-loops' ); ?>
					</div>
					<a href="<?php echo esc_url( $connect_url ); ?>" class="button button-primary" style="font-size:12px">
						🔗 <?php esc_html_e( 'Reconnect', 'crosspost-to-loops' ); ?>
					</a>
				</div>
			</div>
		<?php endif; ?>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Test crosspost.
	// -----------------------------------------------------------------------

	/**
	 * Renders the Test Crosspost tab UI.
	 */
	private function render_test_crosspost(): void {
		$s = $this->get_settings();

		// Fetch posts for the test dropdown.
		$posts = get_posts(
			array(
				'post_type'      => ! empty( $s['enabled_post_types'] ) ? $s['enabled_post_types'] : array( 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		?>
		<div style="max-width:640px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Test Crosspost', 'crosspost-to-loops' ); ?></h2>
			<p style="color:#555">
				<?php esc_html_e( 'Pick any published post and run a test crosspost. This will actually upload the video to Loops.video so you can verify everything is working — but it will be clearly marked as a test and you can delete it from Loops afterwards.', 'crosspost-to-loops' ); ?>
			</p>

			<?php if ( empty( $s['access_token'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL to settings page */
							__( '⚠️ You need to <a href="%s">connect your Loops.video account</a> first.', 'crosspost-to-loops' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_url( admin_url( 'options-general.php?page=crosspost-to-loops' ) )
					);
					?>
				</p></div>
			<?php else : ?>

				<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px">

					<table class="form-table" style="margin:0">
						<tr>
							<th style="width:160px;padding-left:0">
								<label for="ctl_test_post_id"><?php esc_html_e( 'Post to test', 'crosspost-to-loops' ); ?></label>
							</th>
							<td style="padding-left:0">
								<select id="ctl_test_post_id" style="min-width:300px">
									<option value=""><?php esc_html_e( '— Select a post —', 'crosspost-to-loops' ); ?></option>
									<?php
									foreach ( $posts as $p ) :
										$crossposted = get_post_meta( $p->ID, self::META_VIDEO_ID, true ) ? ' ✓' : '';
										?>
										<option value="<?php echo esc_attr( $p->ID ); ?>">
											<?php echo esc_html( $p->post_title . $crossposted ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description" style="margin-top:4px">
									<?php esc_html_e( 'Posts marked ✓ have already been crossposted.', 'crosspost-to-loops' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th style="padding-left:0">
								<label for="ctl_test_caption"><?php esc_html_e( 'Test caption', 'crosspost-to-loops' ); ?></label>
							</th>
							<td style="padding-left:0">
								<input type="text" id="ctl_test_caption" class="regular-text"
									value="<?php esc_attr_e( '[TEST] Crosspost test from WordPress', 'crosspost-to-loops' ); ?>"
									maxlength="200">
								<p class="description" style="margin-top:4px">
									<?php esc_html_e( 'This caption will be used instead of the post excerpt so you can identify the test upload on Loops.', 'crosspost-to-loops' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<div style="margin-top:16px">
						<button type="button" id="ctl-run-test" class="button button-primary"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'loops_test_crosspost' ) ); ?>">
							▶ <?php esc_html_e( 'Run test', 'crosspost-to-loops' ); ?>
						</button>
					</div>

					<!-- Results panel -->
					<div id="ctl-test-results" style="display:none;margin-top:20px;border-top:1px solid #eee;padding-top:16px">
						<h4 style="margin:0 0 12px;font-size:13px;text-transform:uppercase;color:#888;letter-spacing:.05em">
							<?php esc_html_e( 'Test results', 'crosspost-to-loops' ); ?>
						</h4>
						<div id="ctl-test-steps"></div>
						<div id="ctl-test-final" style="margin-top:12px;font-size:14px;font-weight:600"></div>
					</div>

				</div>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler: runs a full test crosspost and returns step-by-step results.
	 */
	public function ajax_test_crosspost(): void {
		check_ajax_referer( 'loops_test_crosspost', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'steps'   => array(),
					'message' => __( 'Permission denied.', 'crosspost-to-loops' ),
				)
			);
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$caption = mb_substr( sanitize_text_field( wp_unslash( $_POST['caption'] ?? '' ) ), 0, 200 );
		$steps   = array();

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error(
				array(
					'steps'   => $steps,
					'message' => __( 'Post not found.', 'crosspost-to-loops' ),
				)
			);
		}
		$steps[] = array(
			'ok'   => true,
			/* translators: %s: post title */
			'text' => sprintf( __( 'Found post: "%s"', 'crosspost-to-loops' ), $post->post_title ),
		);

		$s = $this->get_settings();
		if ( empty( $s['access_token'] ) ) {
			$steps[] = array(
				'ok'   => false,
				'text' => __( 'No access token — not connected.', 'crosspost-to-loops' ),
			);
			wp_send_json_error(
				array(
					'steps'   => $steps,
					'message' => __( 'Not connected to Loops.video.', 'crosspost-to-loops' ),
				)
			);
		}
		$steps[] = array(
			'ok'   => true,
			'text' => __( 'Access token found.', 'crosspost-to-loops' ),
		);

		// Verify the token.
		$account = $this->api_get( '/v1/account/info/self', $s['access_token'], $s['instance_url'] );
		if ( is_wp_error( $account ) ) {
			$steps[] = array(
				'ok'   => false,
				'text' => __( 'Token verification failed: ', 'crosspost-to-loops' ) . $account->get_error_message(),
			);
			wp_send_json_error(
				array(
					'steps'   => $steps,
					'message' => __( 'Could not connect to Loops.video.', 'crosspost-to-loops' ),
				)
			);
		}
		$username = $account['data']['username'] ?? '?';
		$steps[]  = array(
			'ok'   => true,
			/* translators: %s: Loops.video username */
			'text' => sprintf( __( 'Connected as @%s.', 'crosspost-to-loops' ), $username ),
		);

		// Find the video file.
		$video_path = null;
		$temp_file  = false;

		switch ( $s['video_source'] ) {
			case 'attachment':
				[ $video_path, $temp_file ] = $this->get_video_from_attachment( $post_id );
				break;
			case 'content':
				[ $video_path, $temp_file ] = $this->get_video_from_content( $post );
				break;
			case 'custom_field':
				[ $video_path, $temp_file ] = $this->get_video_from_custom_field( $post_id, $s['custom_field_name'] );
				break;
		}

		if ( ! $video_path ) {
			$steps[] = array(
				'ok'   => false,
				/* translators: %s: video source setting value */
				'text' => sprintf( __( 'No video found (source: %s). Check the Video source setting.', 'crosspost-to-loops' ), $s['video_source'] ),
			);
			wp_send_json_error(
				array(
					'steps'   => $steps,
					'message' => __( 'No video found for this post.', 'crosspost-to-loops' ),
				)
			);
		}

		$file_size = size_format( filesize( $video_path ) );
		$steps[]   = array(
			'ok'   => true,
			/* translators: %1$s: filename, %2$s: file size */
			'text' => sprintf( __( 'Video found: %1$s (%2$s).', 'crosspost-to-loops' ), basename( $video_path ), $file_size ),
		);
		$steps[]   = array(
			'ok'   => true,
			/* translators: %s: caption text */
			'text' => sprintf( __( 'Caption: "%s"', 'crosspost-to-loops' ), $caption ),
		);
		$steps[]   = array(
			'ok'   => true,
			'text' => __( 'Uploading to Loops.video…', 'crosspost-to-loops' ),
		);

		// Upload to Loops.video.
		$result = $this->api_upload_video(
			$video_path,
			array(
				'description'  => $caption,
				'lang'         => $s['default_lang'],
				'can_download' => $s['can_download'],
				'can_comment'  => $s['can_comment'],
				'can_duet'     => $s['can_duet'],
				'can_stitch'   => $s['can_stitch'],
			),
			$s['access_token'],
			$s['instance_url']
		);

		if ( $temp_file && file_exists( $video_path ) ) {
			wp_delete_file( $video_path );
		}

		if ( is_wp_error( $result ) ) {
			$steps[] = array(
				'ok'   => false,
				'text' => __( 'Upload failed: ', 'crosspost-to-loops' ) . $result->get_error_message(),
			);
			$this->log( 'error', '[TEST] Upload failed: ' . $result->get_error_message(), array( 'post_id' => $post_id ) );
			wp_send_json_error(
				array(
					'steps'   => $steps,
					'message' => $result->get_error_message(),
				)
			);
		}

		$loops_url = $result['data']['url'] ?? ( $result['url'] ?? '' );
		$steps[]   = array(
			'ok'   => true,
			'text' => __( 'Upload successful!', 'crosspost-to-loops' ),
		);

		$this->log(
			'success',
			'[TEST] Test crosspost succeeded.',
			array(
				'post_id' => $post_id,
				'url'     => $loops_url,
			)
		);

		wp_send_json_success(
			array(
				'steps'   => $steps,
				'message' => __( 'Test passed! Video uploaded successfully.', 'crosspost-to-loops' ),
				'url'     => $loops_url,
			)
		);
	}

	// -----------------------------------------------------------------------
	// Debug log.
	// -----------------------------------------------------------------------

	/**
	 * Appends an entry to the debug log if logging is enabled.
	 *
	 * @param string $level   Severity level: info, success, or error.
	 * @param string $message Human-readable log message.
	 * @param array  $context Optional context data.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		$s = $this->get_settings();
		if ( empty( $s['enable_debug'] ) ) {
			return;
		}

		$message = $this->redact_sensitive_string( $message );
		$context = $this->redact_sensitive_value( $context );

		$entries   = get_option( self::LOG_OPTION, array() );
		$entries[] = array(
			'time'    => current_time( 'Y-m-d H:i:s' ),
			'level'   => $level, // Severity: info, error, or success.
			'message' => $message,
			'context' => $context,
		);

		// Keep only the most recent entries.
		if ( count( $entries ) > self::LOG_MAX ) {
			$entries = array_slice( $entries, -self::LOG_MAX );
		}

		update_option( self::LOG_OPTION, $entries, false );
	}


	/**
	 * Recursively redact secrets before writing log context.
	 *
	 * @param mixed  $value Value to sanitize.
	 * @param string $key   Context key, when available.
	 * @return mixed
	 */
	private function redact_sensitive_value( $value, string $key = '' ) {
		$normalized = strtolower( str_replace( array( '-', '_' ), '', $key ) );
		$sensitive  = array( 'password', 'apppassword', 'token', 'accesstoken', 'refreshtoken', 'clientsecret', 'authorization', 'bearer' );
		if ( $key && in_array( $normalized, $sensitive, true ) ) {
			return '[REDACTED]';
		}
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $child_key => $child_value ) {
				$clean[ $child_key ] = $this->redact_sensitive_value( $child_value, (string) $child_key );
			}
			return $clean;
		}
		return is_string( $value ) ? $this->redact_sensitive_string( $value ) : $value;
	}

	/**
	 * Redact common credential patterns from a log message.
	 */
	private function redact_sensitive_string( string $value ): string {
		$value = preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value );
		$value = preg_replace(
			'/(["\']?(?:client_secret|access_token|refresh_token|token|password|authorization)["\']?\s*[:=]\s*["\']?)[^"\'\s,&}]+/i',
			'$1[REDACTED]',
			$value
		);
		return $value;
	}

	/**
	 * AJAX handler: clears all debug log entries.
	 */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'loops_clear_log', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		delete_option( self::LOG_OPTION );
		wp_send_json_success();
	}

	/**
	 * Renders the debug log tab UI.
	 */
	private function render_debug_log(): void {
		$entries = get_option( self::LOG_OPTION, array() );
		$nonce   = wp_create_nonce( 'loops_clear_log' );
		?>
		<h2><?php esc_html_e( 'Debug Log', 'crosspost-to-loops' ); ?></h2>
		<p>
			<button type="button" id="loops_clear_log" class="button button-secondary"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Clear log', 'crosspost-to-loops' ); ?>
			</button>
		</p>

		<?php if ( empty( $entries ) ) : ?>
			<p style="color:#666"><?php esc_html_e( 'No log entries yet.', 'crosspost-to-loops' ); ?></p>
		<?php else : ?>
			<div id="loops-log-wrap" style="background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;line-height:1.6;border-radius:4px;padding:12px 16px;max-height:400px;overflow-y:auto">
				<?php
				foreach ( array_reverse( $entries ) as $e ) :
					$color = match ( $e['level'] ) {
						'success' => '#4ec9b0',
						'error'   => '#f48771',
						default   => '#9cdcfe',
					};
					$ctx = ! empty( $e['context'] ) ? ' ' . wp_json_encode( $e['context'] ) : '';
					?>
					<div style="border-bottom:1px solid #333;padding:4px 0">
						<span style="color:#858585"><?php echo esc_html( $e['time'] ); ?></span>
						<span style="color:<?php echo esc_attr( $color ); ?>;margin:0 6px;text-transform:uppercase;font-size:10px">[<?php echo esc_html( $e['level'] ); ?>]</span>
						<span><?php echo esc_html( $e['message'] . $ctx ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Returns the list of language codes supported by the Loops API.
	 *
	 * @return array<string, string>
	 */
	private function supported_languages(): array {
		return array(
			'af'    => 'Afrikaans',
			'sq'    => 'Albanian',
			'am'    => 'Amharic',
			'ar'    => 'Arabic',
			'hy'    => 'Armenian',
			'az'    => 'Azerbaijani',
			'eu'    => 'Basque',
			'be'    => 'Belarusian',
			'bn'    => 'Bengali',
			'bs'    => 'Bosnian',
			'bg'    => 'Bulgarian',
			'my'    => 'Burmese',
			'ca'    => 'Catalan',
			'zh'    => 'Chinese (Simplified)',
			'zh-TW' => 'Chinese (Traditional)',
			'hr'    => 'Croatian',
			'cs'    => 'Czech',
			'da'    => 'Danish',
			'nl'    => 'Dutch',
			'en'    => 'English',
			'et'    => 'Estonian',
			'fi'    => 'Finnish',
			'fr'    => 'French',
			'ka'    => 'Georgian',
			'de'    => 'German',
			'el'    => 'Greek',
			'ha'    => 'Hausa',
			'he'    => 'Hebrew',
			'hi'    => 'Hindi',
			'hu'    => 'Hungarian',
			'is'    => 'Icelandic',
			'id'    => 'Indonesian',
			'ga'    => 'Irish',
			'it'    => 'Italian',
			'ja'    => 'Japanese',
			'kn'    => 'Kannada',
			'kk'    => 'Kazakh',
			'km'    => 'Khmer',
			'ko'    => 'Korean',
			'lv'    => 'Latvian',
			'lt'    => 'Lithuanian',
			'ms'    => 'Malay',
			'ml'    => 'Malayalam',
			'mr'    => 'Marathi',
			'mn'    => 'Mongolian',
			'ne'    => 'Nepali',
			'no'    => 'Norwegian',
			'fa'    => 'Persian',
			'pl'    => 'Polish',
			'pt'    => 'Portuguese',
			'pa'    => 'Punjabi',
			'ro'    => 'Romanian',
			'ru'    => 'Russian',
			'sr'    => 'Serbian',
			'sk'    => 'Slovak',
			'sl'    => 'Slovenian',
			'so'    => 'Somali',
			'es'    => 'Spanish',
			'sw'    => 'Swahili',
			'sv'    => 'Swedish',
			'ta'    => 'Tamil',
			'te'    => 'Telugu',
			'th'    => 'Thai',
			'tr'    => 'Turkish',
			'uk'    => 'Ukrainian',
			'ur'    => 'Urdu',
			'uz'    => 'Uzbek',
			'vi'    => 'Vietnamese',
			'cy'    => 'Welsh',
		);
	}
}

// Boot the plugin.
add_action(
	'plugins_loaded',
	function () {
		Crosspost_To_Loops::instance();
	}
);
