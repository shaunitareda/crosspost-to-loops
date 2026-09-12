<?php
/**
 * Rabbit Cast REST bridge for Eboni.
 *
 * @package Crosspost_To_Loops
 */

defined( 'ABSPATH' ) || exit;

class Crosspost_To_Loops_Rabbit_Cast {
	const SECRET_OPTION = 'ctl_rabbit_cast_secret_hash';
	const REST_NAMESPACE = 'crosspost-to-loops/v1';
	const REST_ROUTE = '/rabbit-cast';
	const MAX_FILE_BYTES = 104857600; // 100 MiB.

	private $plugin;
	private $uploader;

	public function __construct( $plugin, callable $uploader ) {
		$this->plugin   = $plugin;
		$this->uploader = $uploader;
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ), 20 );
	}

	public function register_rest_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'authorize_request' ),
				'args'                => array(
					'video_url' => array( 'required' => true, 'type' => 'string' ),
					'caption'   => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);
	}

	public function register_settings(): void {
		register_setting(
			'wp_loops_settings_group',
			self::SECRET_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_secret' ),
				'default'           => '',
			)
		);
		add_settings_section(
			'loops_rabbit_cast',
			__( 'Eboni Rabbit Cast', 'crosspost-to-loops' ),
			function () {
				echo '<p>' . esc_html__( 'Configure the shared secret Eboni uses to publish Rabbit Cast MP4 videos through this plugin. The raw secret is never stored.', 'crosspost-to-loops' ) . '</p>';
			},
			'crosspost-to-loops'
		);
		add_settings_field(
			'rabbit_cast_secret',
			__( 'Rabbit Cast shared secret', 'crosspost-to-loops' ),
			array( $this, 'render_secret_field' ),
			'crosspost-to-loops',
			'loops_rabbit_cast'
		);
	}

	public function sanitize_secret( $value ): string {
		$value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
		if ( '' === $value ) {
			return (string) get_option( self::SECRET_OPTION, '' );
		}
		return hash( 'sha256', $value );
	}

	public function render_secret_field(): void {
		$configured = '' !== (string) get_option( self::SECRET_OPTION, '' );
		printf(
			'<input type="password" name="%1$s" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s"><p class="description">%3$s</p>',
			esc_attr( self::SECRET_OPTION ),
			esc_attr( $configured ? __( 'Configured — enter a new secret to replace it', 'crosspost-to-loops' ) : __( 'Enter a strong shared secret', 'crosspost-to-loops' ) ),
			esc_html__( 'Only the SHA-256 hash is stored. Leaving this blank keeps the current secret.', 'crosspost-to-loops' )
		);
	}

	public function authorize_request( $request ) {
		$stored = (string) get_option( self::SECRET_OPTION, '' );
		$given  = trim( (string) $request->get_header( 'X-Eboni-Rabbit-Key' ) );
		if ( '' === $stored || '' === $given ) {
			return new WP_Error( 'rabbit_cast_unauthorized', __( 'Rabbit Cast authentication required.', 'crosspost-to-loops' ), array( 'status' => 401 ) );
		}
		if ( ! hash_equals( $stored, hash( 'sha256', $given ) ) ) {
			return new WP_Error( 'rabbit_cast_forbidden', __( 'Rabbit Cast authentication failed.', 'crosspost-to-loops' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function handle_request( $request ) {
		$url     = esc_url_raw( trim( (string) $request->get_param( 'video_url' ) ) );
		$caption = mb_substr( sanitize_text_field( (string) $request->get_param( 'caption' ) ), 0, 200 );
		if ( ! $this->is_safe_https_url( $url ) ) {
			return $this->response( false, '', '', __( 'A public HTTPS video URL is required.', 'crosspost-to-loops' ), 400 );
		}

		$s = $this->plugin->get_settings();
		if ( empty( $s['access_token'] ) ) {
			return $this->response( false, '', '', __( 'Loops account is not connected.', 'crosspost-to-loops' ), 503 );
		}

		$tmp = '';
		try {
			$tmp = $this->download_video( $url );
			if ( is_wp_error( $tmp ) ) {
				$this->plugin->log( 'error', 'Rabbit Cast video download failed.' );
				return $this->response( false, '', '', __( 'Video download failed.', 'crosspost-to-loops' ), 400 );
			}
			if ( filesize( $tmp ) > self::MAX_FILE_BYTES ) {
				return $this->response( false, '', '', __( 'Video exceeds the maximum allowed size.', 'crosspost-to-loops' ), 413 );
			}
			if ( 'video/mp4' !== $this->detect_mime( $tmp ) ) {
				return $this->response( false, '', '', __( 'Downloaded file must be an MP4 video.', 'crosspost-to-loops' ), 415 );
			}

			$this->plugin->log( 'info', 'Rabbit Cast video accepted for Loops upload.', array( 'caption_length' => mb_strlen( $caption ) ) );
			$result = call_user_func(
				$this->uploader,
				$tmp,
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
			if ( is_wp_error( $result ) ) {
				$this->plugin->log( 'error', 'Rabbit Cast Loops upload failed: ' . $result->get_error_message() );
				return $this->response( false, '', '', __( 'Loops upload failed.', 'crosspost-to-loops' ), 502 );
			}
			$id  = (string) ( $result['data']['id'] ?? $result['id'] ?? '' );
			$out = esc_url_raw( (string) ( $result['data']['url'] ?? $result['url'] ?? '' ) );
			$this->plugin->log( 'success', 'Rabbit Cast video uploaded to Loops.', array( 'loops_id' => $id, 'url' => $out ) );
			return $this->response( true, $id, $out, __( 'Rabbit Cast published to Loops.', 'crosspost-to-loops' ), 200 );
		} finally {
			if ( is_string( $tmp ) && '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	protected function is_safe_https_url( string $url ): bool {
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return (bool) wp_http_validate_url( $url );
	}

	protected function download_video( string $url ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'rabbit-cast.mp4' );
		if ( ! $tmp ) {
			return new WP_Error( 'rabbit_cast_tmp', __( 'Could not create temporary file.', 'crosspost-to-loops' ) );
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 120,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::MAX_FILE_BYTES + 1,
				'reject_unsafe_urls'  => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'rabbit_cast_http', __( 'Remote video request failed.', 'crosspost-to-loops' ) );
		}
		return $tmp;
	}

	protected function detect_mime( string $path ): string {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = (string) finfo_file( $finfo, $path );
				finfo_close( $finfo );
				return strtolower( trim( $mime ) );
			}
		}
		return function_exists( 'mime_content_type' ) ? strtolower( (string) mime_content_type( $path ) ) : '';
	}

	private function response( bool $ok, string $id, string $url, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array( 'ok' => $ok, 'id' => $id, 'url' => $url, 'message' => $message ),
			$status
		);
	}
}
