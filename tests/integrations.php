<?php
/** Portable access-boundary tests: no native plugins, network or live websites. */
declare(strict_types=1);
namespace ChurchWebsiteConnector {
    function bound_site(){return 'https://connector.example';}
    function is_bound(){return $GLOBALS['bound'];}
    function feature_enabled($key){return is_bound()&&($GLOBALS['features'][$key]??false);}
}
namespace {
    define('ABSPATH',__DIR__.'/');
    define('THE_SEO_FRAMEWORK_VERSION','6.0.0');
    define('RANK_MATH_VERSION','2.0.0');
    define('CODE_SNIPPETS_VERSION','5.0.0');
    define('WS_FORM_VERSION','2.0.0');
    define('WS_FORM_EDITION','pro');
    $GLOBALS['bound']=true;$GLOBALS['admin']=true;$GLOBALS['signed_in']=true;$GLOBALS['features']=[];$GLOBALS['abilities']=[];$GLOBALS['routes']=[];
    class WP_Error{public function __construct(public $code,public $message,public $data=[]){} }
    function is_wp_error($value){return $value instanceof WP_Error;}
    function is_user_logged_in(){return $GLOBALS['signed_in'];}
    function current_user_can($cap){return $GLOBALS['admin'];}
    function add_action(...$args){}
    function add_filter(...$args){}
    function wp_json_encode($value){return json_encode($value);}
    function wp_register_ability($name,$args){$GLOBALS['abilities'][$name]=$args;}
    function register_rest_route($namespace,$name,$args){$GLOBALS['routes'][$namespace.$name]=$args;}
    $path=__DIR__.'/../includes/integration-tools.php';
    if(!is_file($path)){$path=__DIR__.'/../church-website-connector/includes/integration-tools.php';}
    require_once dirname($path).'/rest-tools.php';
    require $path;
    $checks=0;
    function check($value,$message){global $checks;$checks++;if(!$value){throw new \RuntimeException($message);}}
    $site=['site_url'=>'https://connector.example'];
    foreach(\ChurchWebsiteConnector\Integrations\FLAGS as $provider=>$flag){
        $s=\ChurchWebsiteConnector\Integrations\status($provider);
        check(!$s['enabled']&&!$s['compatible'],'Unconfigured and unsupported integration must be paused');
        $target=$site+['integration'=>$provider,'kind'=>match($provider){'code-snippets'=>'snippet','ws-form'=>'form',default=>'post'},'id'=>1];
        check(is_wp_error(\ChurchWebsiteConnector\Integrations\get_item($target)),'Disabled provider must reject reads');
        check(is_wp_error(\ChurchWebsiteConnector\Integrations\preview($target+['operation'=>'update','changes'=>[]])),'Disabled provider must reject previews');
        $GLOBALS['features'][$flag]=true;
        check(is_wp_error(\ChurchWebsiteConnector\Integrations\get_item($target)),'Unsupported version must reject reads even when enabled');
        unset($GLOBALS['features'][$flag]);
    }
    \ChurchWebsiteConnector\Integrations\register_abilities();check(!$GLOBALS['abilities'],'Unconfigured integration abilities are unavailable');
    $GLOBALS['features']['snippets_tools']=true;\ChurchWebsiteConnector\Integrations\register_abilities();check(count($GLOBALS['abilities'])===6,'Bounded integration abilities registered');
    foreach($GLOBALS['abilities'] as $ability){check($ability['meta']['show_in_rest']===false&&$ability['input_schema']['additionalProperties']===false,'Raw ability REST execution hidden and unknown inputs rejected');}
    \ChurchWebsiteConnector\Integrations\register_routes();check(count($GLOBALS['routes'])===6,'Only six fixed REST endpoints');
    foreach($GLOBALS['routes'] as $route){check(is_callable($route['permission_callback']),'Every REST endpoint checks permissions');}
    check(is_wp_error(\ChurchWebsiteConnector\Integrations\native_rest('GET','/wp/v2/users')),'Arbitrary native routes rejected before dispatch');
    check(is_wp_error(\ChurchWebsiteConnector\Integrations\apply($site+['approved'=>false,'preview_token'=>str_repeat('a',64)])),'Approval required before token lookup');
    check(is_wp_error(\ChurchWebsiteConnector\Integrations\get_item($site+['route'=>'/wp/v2/users'])),'Unknown input cannot turn tools into a proxy');
    foreach(['signed_in','admin','bound'] as $flag){$GLOBALS[$flag]=false;check(is_wp_error(\ChurchWebsiteConnector\Integrations\capabilities($site)),'Access gate '.$flag);$GLOBALS[$flag]=true;}
    check(is_wp_error(\ChurchWebsiteConnector\Integrations\capabilities(['site_url'=>'https://another.example'])),'Exact saved address required');
    check(is_wp_error(\ChurchWebsiteConnector\Integrations\load_preview('bad')),'Malformed token rejected');
    check(!\ChurchWebsiteConnector\Integrations\bounded_json(str_repeat('x',262145)),'Oversized input rejected');
    check(\ChurchWebsiteConnector\Integrations\fingerprint(['b'=>2,'a'=>1])===\ChurchWebsiteConnector\Integrations\fingerprint(['a'=>1,'b'=>2]),'Fingerprints independent of object-key order');
    echo 'Integration boundary checks passed ('.$checks.' checks)'.PHP_EOL;
}
