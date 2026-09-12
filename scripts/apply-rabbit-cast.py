from pathlib import Path

root = Path(__file__).resolve().parents[1]
main = root / 'crosspost-to-loops.php'
text = main.read_text()

repls = [
    ('Version:           1.0.1', 'Version:           1.7.0'),
    ("define( 'CTL_VERSION', '1.0.1' );", "define( 'CTL_VERSION', '1.7.0' );"),
    ("define( 'CTL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );\n", "define( 'CTL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );\n\nrequire_once CTL_PLUGIN_DIR . 'includes/class-rabbit-cast.php';\n"),
    ("\t\tadd_action( 'wp_ajax_loops_disconnect', array( $this, 'ajax_disconnect' ) );\n\t}\n", "\t\tadd_action( 'wp_ajax_loops_disconnect', array( $this, 'ajax_disconnect' ) );\n\n\t\tnew Crosspost_To_Loops_Rabbit_Cast(\n\t\t\t$this,\n\t\t\tfunction ( string $file_path, array $fields, string $token, string $instance_url ) {\n\t\t\t\treturn $this->api_upload_video( $file_path, $fields, $token, $instance_url );\n\t\t\t}\n\t\t);\n\t}\n"),
]
for old, new in repls:
    if text.count(old) != 1:
        raise SystemExit(f'Expected exactly one anchor, got {text.count(old)}: {old[:80]}')
    text = text.replace(old, new, 1)
main.write_text(text)

includes = root / 'includes'
includes.mkdir(exist_ok=True)
(includes / 'class-rabbit-cast.php').write_text(r'''<?php
/**
 * Rabbit Cast REST bridge for Eboni.
 *
 * @package Crosspost_To_Loops
 */

defined( 'ABSPATH' ) || exit;

final class Crosspost_To_Loops_Rabbit_Cast {
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
''')

(root / 'tests').mkdir(exist_ok=True)
(root / 'tests' / 'rabbit-cast-test.php').write_text(r'''<?php
$opts = array();
function add_action() {}
function register_rest_route() {}
function register_setting() {}
function add_settings_section() {}
function add_settings_field() {}
function get_option( $k, $d = '' ) { global $opts; return $opts[$k] ?? $d; }
function wp_unslash( $v ) { return $v; }
function esc_url_raw( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( strip_tags( $v ) ); }
function wp_parse_url( $u, $part = -1 ) { return parse_url( $u, $part ); }
function wp_http_validate_url( $u ) { return true; }
function wp_delete_file( $p ) { @unlink( $p ); }
function __( $v ) { return $v; }
function esc_attr( $v ) { return $v; }
function esc_html__( $v ) { return $v; }
function esc_html( $v ) { return $v; }
class WP_Error { public function __construct(public $code, public $message, public $data=array()){} public function get_error_message(){return $this->message;} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_REST_Response { public function __construct(public $data, public $status=200){} public function get_data(){return $this->data;} public function get_status(){return $this->status;} }
class Req { public array $headers=[]; public array $params=[]; function get_header($k){return $this->headers[$k]??'';} function get_param($k){return $this->params[$k]??'';} }
class FakePlugin { public array $logs=[]; function get_settings(){return array('access_token'=>'loops-token','instance_url'=>'https://loops.video','default_lang'=>'en','can_download'=>true,'can_comment'=>true,'can_duet'=>false,'can_stitch'=>false);} function log($l,$m,$c=array()){$this->logs[]=array($l,$m,$c);} }

define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/includes/class-rabbit-cast.php';

class TestBridge extends Crosspost_To_Loops_Rabbit_Cast {
    public string $mime='video/mp4'; public int $bytes=100; public bool $safe=true;
    protected function is_safe_https_url(string $url): bool { return $this->safe && str_starts_with($url,'https://'); }
    protected function download_video(string $url) { $p=tempnam(sys_get_temp_dir(),'rc'); file_put_contents($p,str_repeat('x',$this->bytes)); return $p; }
    protected function detect_mime(string $path): string { return $this->mime; }
}
function ok($cond,$msg){ if(!$cond){fwrite(STDERR,"FAIL: $msg\n"); exit(1);} echo "ok - $msg\n"; }

$secret='super-secret-rabbit'; $opts[Crosspost_To_Loops_Rabbit_Cast::SECRET_OPTION]=hash('sha256',$secret);
$plugin=new FakePlugin(); $uploads=[];
$bridge=new TestBridge($plugin,function($path,$fields,$token,$instance) use (&$uploads){$uploads[]=compact('fields','token','instance'); return array('data'=>array('id'=>'123','url'=>'https://loops.video/v/123'));});

$r=new Req();
ok(is_wp_error($bridge->authorize_request($r)),'missing auth rejected');
$r->headers['X-Eboni-Rabbit-Key']='wrong'; ok(is_wp_error($bridge->authorize_request($r)),'wrong auth rejected');
$r->headers['X-Eboni-Rabbit-Key']=$secret; ok(true === $bridge->authorize_request($r),'successful auth accepted');

$r->params=array('video_url'=>'http://example.com/a.mp4','caption'=>'hi');
$res=$bridge->handle_request($r); ok(400===$res->get_status(),'non-HTTPS URL rejected');
$bridge->safe=true; $r->params['video_url']='https://example.com/a.mp4';
$bridge->mime='text/plain'; $res=$bridge->handle_request($r); ok(415===$res->get_status(),'invalid MIME rejected');
$bridge->mime='video/mp4'; $bridge->bytes=Crosspost_To_Loops_Rabbit_Cast::MAX_FILE_BYTES+1; $res=$bridge->handle_request($r); ok(413===$res->get_status(),'oversize file rejected');
$bridge->bytes=100; $r->params['caption']=str_repeat('a',250); $res=$bridge->handle_request($r); ok(200===$res->get_status() && true===$res->get_data()['ok'],'successful Loops handoff');
ok(200===strlen($uploads[0]['fields']['description']),'caption capped at 200 chars');
$blob=json_encode(array($plugin->logs,$res->get_data(),$opts)); ok(false===strpos($blob,$secret),'secret absent from logs and responses');
ok(hash('sha256',$secret)===$opts[Crosspost_To_Loops_Rabbit_Cast::SECRET_OPTION],'only secret hash stored');
echo "All Rabbit Cast tests passed.\n";
''')

(root / 'tests' / 'run.sh').write_text("#!/usr/bin/env bash\nset -euo pipefail\nphp -l crosspost-to-loops.php\nphp -l includes/class-rabbit-cast.php\nphp tests/rabbit-cast-test.php\n")

readme = root / 'README.md'
r = readme.read_text().replace('**Stable Tag:** 1.6.0', '**Stable Tag:** 1.7.0')
if '### 1.7.0' not in r:
    r = r.replace('## Changelog\n', '## Changelog\n\n### 1.7.0\n- Added authenticated Eboni Rabbit Cast REST publishing endpoint with safe remote MP4 validation and reuse of the existing Loops upload path.\n- Added hashed shared-secret configuration and a minimal executable Rabbit Cast test/lint harness.\n')
readme.write_text(r)

wpreadme = root / 'readme.txt'
r = wpreadme.read_text().replace('Stable tag:        1.0.0', 'Stable tag:        1.7.0')
wpreadme.write_text(r)
