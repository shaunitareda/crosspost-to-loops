<?php
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
