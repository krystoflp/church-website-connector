<?php
/** Portable setup authorisation and vendor-request boundary checks. No real credentials. */
namespace ChurchWebsiteConnector {
    function current_identity(): array { return $GLOBALS['identity']; }
    function is_bound(): bool { return $GLOBALS['bound']; }
}
namespace ChurchWebsiteConnector\RestTools {
    function conditional_delete(string $name, mixed $value): bool {
        if (($GLOBALS['options'][$name] ?? false) !== $value) return false;
        unset($GLOBALS['options'][$name]); return true;
    }
}
namespace {
    define('ABSPATH','/no-real-wordpress/');
    define('MINUTE_IN_SECONDS',60);
    class WP_Error {
        public function __construct(public $code,public $message='',public $data=null) {}
        public function get_error_code(){return $this->code;}
        public function get_error_message(){return $this->message;}
    }
    class WP_REST_Server { const CREATABLE='POST'; }
    class Request {
        public function __construct(public $code='',public $input=[]){}
        public function get_header($name){return $this->code;}
        public function get_json_params(){return $this->input;}
    }
    $options=[];$owner=1;$bound=true;$identity=['home'=>'https://church.example','wordpress'=>'https://church.example'];
    $capabilities=[1=>['manage_options'=>true,'install_plugins'=>true,'activate_plugins'=>true]];
    $file_mod_allowed=true;$requests=[];$response=[];$http_code=200;$checks=0;$routes=[];
    function __($text,$domain=''){return $text;}
    function add_action(...$args){}
    function get_current_user_id(){return $GLOBALS['owner'];}
    function is_user_logged_in(){return $GLOBALS['owner']>0;}
    function user_can($id,$cap){return $GLOBALS['capabilities'][$id][$cap]??false;}
    function wp_is_file_mod_allowed($context){return $GLOBALS['file_mod_allowed'];}
    function get_option($name,$default=false){return $GLOBALS['options'][$name]??$default;}
    function update_option($name,$value,...$args){$GLOBALS['options'][$name]=$value;return true;}
    function wp_schedule_single_event(...$args){return true;}
    function rest_url($path){return 'https://church.example/wp-json/'.$path;}
    function is_wp_error($value){return $value instanceof WP_Error;}
    function wp_parse_url($url){return parse_url($url);}
    function get_bloginfo($field){return '7.1.4';}
    function wp_verify_nonce($nonce,$action){return $nonce==='fixture-nonce';}
    function wp_unslash($value){return stripslashes($value);}
    function untrailingslashit($url){return rtrim($url,'/');}
    function wp_safe_remote_post($url,$args){$GLOBALS['requests'][]=[$url,$args];return ['code'=>$GLOBALS['http_code'],'body'=>json_encode($GLOBALS['response'])];}
    function wp_remote_retrieve_response_code($response){return $response['code'];}
    function wp_remote_retrieve_body($response){return $response['body'];}
    function register_rest_route($namespace,$route,$data){$GLOBALS['routes'][$namespace.$route]=$data;}
    function check($condition,$message){++$GLOBALS['checks'];if(!$condition)throw new \RuntimeException('FAILED: '.$message);}
    $source=dirname(__DIR__).'/church-website-connector/includes/generatepress-setup.php';
    if(!is_file($source))$source=dirname(__DIR__).'/includes/generatepress-setup.php';
    require $source;
    use ChurchWebsiteConnector\GeneratePressSetup as G;
    G\register_route();
    check(count($routes)===1&&$routes[G\ROUTE]['methods']==='POST','Exactly one POST setup route');
    $owner=0;check(is_wp_error(G\create_pairing()),'Anonymous authorisation denied');$owner=1;
    $pair=G\create_pairing();$saved=get_option(G\PAIR);$request=new Request($pair['code']);
    check(strlen($pair['code'])===64&&$saved['hash']===hash('sha256',$pair['code']),'Random code is stored only as a fingerprint');
    check(!str_contains(json_encode($saved),$pair['code']),'Authorisation record does not retain its secret');
    check(G\rest_permission($request)===true,'Scoped authorisation works');
    foreach(['',str_repeat('a',63),str_repeat('A',64),str_repeat('0',64),$pair['code']."\n"] as $bad)check(is_wp_error(G\rest_permission(new Request($bad))),'Malformed or wrong setup code denied');
    $options[G\PAIR]['expires']=time()-1;check(is_wp_error(G\rest_permission($request)),'Expiry checked');$options[G\PAIR]=$saved;
    $options[G\PAIR]['expires']=time()+601;check(is_wp_error(G\rest_permission($request)),'Excess lifetime rejected');$options[G\PAIR]=$saved;
    $identity['home']='https://moved.example';check(is_wp_error(G\rest_permission($request)),'Moved site rejected');$identity['home']='https://church.example';
    $bound=false;check(is_wp_error(G\rest_permission($request)),'Disabled binding rejected');$bound=true;
    foreach(['manage_options','install_plugins','activate_plugins'] as $cap){$capabilities[1][$cap]=false;check(is_wp_error(G\rest_permission($request)),'Owner permission revocation checked');$capabilities[1][$cap]=true;}
    $file_mod_allowed=false;check(is_wp_error(G\rest_permission($request)),'Filesystem policy checked');$file_mod_allowed=true;
    foreach([[],['license_key'=>''],['license_key'=>'bad key'],['license_key'=>[]],['license_key'=>str_repeat('a',257)],['license_key'=>'FAKE','url'=>'https://other.example']] as $input){
        check(is_wp_error(G\rest_setup(new Request($pair['code'],$input)))&&get_option(G\PAIR)===$saved,'Invalid body denied without consuming code');
    }
    check(!$requests,'Rejected requests never contact vendor');
    $next=G\create_pairing();check(is_wp_error(G\rest_permission($request)),'Replacement code revokes previous generation');
    foreach(['http://updates.generatepress.com/a','https://updates.generatepress.com.evil.example/a','https://u@updates.generatepress.com/a','https://updates.generatepress.com:443/a','https://updates.generatepress.com/a#b',"https://updates.generatepress.com/a\n"] as $url)check(!G\package_url($url),'Unsafe origin denied');
    check(G\package_url('https://updates.generatepress.com/?token=FAKE'),'Verified origin accepted');
    foreach(['1.0.0','1.0.1','1.0.999'] as $version)check(G\supported_version($version),'Reviewed version line accepted');
    foreach(['0.9.9','1.1.0','2.0.0','1.0.1-beta','evil'] as $version)check(!G\supported_version($version),'Unreviewed or unstable version rejected');
    $response=['slug'=>'generatepress-ai','new_version'=>'1.0.0','requires'=>'7.0','requires_php'=>'8.1','package'=>'https://updates.generatepress.com/?token=FAKE'];
    check(!is_wp_error(G\release('FAKE-LICENCE')),'Valid current metadata accepted');
    [$url,$args]=end($requests);check($url===G\STORE&&$args['body']['version']==='0.0.0'&&$args['body']['item_id']===2936664,'First-install fixed product request');
    check($args['sslverify']===true&&$args['redirection']===0&&$args['body']['url']==='https://church.example','HTTPS, no redirects and configured site');
    $good=$response;
    foreach([['slug','other'],['new_version','1.1.0'],['requires_php','99.0'],['requires','99.0'],['package','https://other.example/file.zip'],['package','']] as [$field,$value]){
        $response=$good;$response[$field]=$value;check(is_wp_error(G\release('FAKE-LICENCE')),'Invalid release metadata denied');
    }
    $http_code=302;check(is_wp_error(G\vendor_request('get_version','FAKE')),'Service redirects denied');$http_code=200;
    $before=count($requests);check(is_wp_error(G\vendor_request('deactivate_license','FAKE'))&&count($requests)===$before,'Setup cannot deactivate a licence');
    foreach(['invalid','missing','expired','disabled','revoked','no_activations_left','item_name_mismatch','site_inactive','license_unavailable','license_save_failed','SECRET-DO-NOT-ECHO'] as $reason)check(!str_contains(G\licence_failure($reason),'SECRET-DO-NOT-ECHO'),'Only safe fixed licence messages');
    $policy=G\native_request_policy(['redirection'=>5,'sslverify'=>false],rtrim(G\STORE,'/'));check($policy['redirection']===0&&$policy['sslverify']===true&&$policy['reject_unsafe_urls']===true,'Native licensing is constrained to verified HTTPS without redirects');
    check(G\native_request_policy(['keep'=>true],'https://other.example')===['keep'=>true],'Native policy does not change unrelated requests');
    $_SERVER['REQUEST_METHOD']='POST';
    $browser=['action'=>G\ADMIN_ACTION,'_wpnonce'=>'fixture-nonce','license_key'=>'FAKE-LICENCE'];
    $before=count($requests);
    foreach([['license_key',''],['license_key','wrong key!'],['license_key',[]],['_wpnonce','wrong'],['action','other']] as [$field,$value]){$input=$browser;$input[$field]=$value;check(is_wp_error(G\admin_request($input)),'Malformed browser setup denied');}
    $_SERVER['REQUEST_METHOD']='GET';check(is_wp_error(G\admin_request($browser)),'GET browser setup denied');$_SERVER['REQUEST_METHOD']='POST';
    $owner=0;check(is_wp_error(G\admin_request($browser)),'Anonymous browser setup denied');$owner=1;
    check(count($requests)===$before,'Denied browser form does not contact the licence service');
    echo 'GeneratePress setup boundary checks passed ('.$checks.' checks)'.PHP_EOL;
}
