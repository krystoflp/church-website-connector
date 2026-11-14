<?php
/** Portable configuration hook checks; no WordPress or Imagify service required. */
namespace ChurchWebsiteConnector {
    function settings(): array { return $GLOBALS['settings']; }
    function is_bound(): bool { return $GLOBALS['bound']; }
    function feature_enabled(string $feature): bool { return is_bound() && true === (settings()[$feature] ?? true); }
}
namespace {
    define('ABSPATH', __DIR__);
    $GLOBALS['settings'] = ['imagify_pdf_protection'=>true];$GLOBALS['bound']=true;$hooks=[];$checks=0;
    function add_action(...$args){}
    function add_filter($name,$callback,$priority=10){$GLOBALS['hooks'][$name][$priority][]=$callback;}
    function __($text,$domain=''){return $text;}
    function verify($ok,$label){global $checks;++$checks;if(!$ok){throw new \RuntimeException($label);}}
    require (is_file(__DIR__.'/../includes/configuration.php') ? __DIR__.'/../includes/configuration.php' : __DIR__.'/../church-website-connector/includes/configuration.php');
    use ChurchWebsiteConnector\Configuration as C;
    verify(isset($hooks['imagify_get_mime_types'][PHP_INT_MAX]),'Registered on plugin load, after normal filters');
    $mimes=['jpg|jpeg|jpe'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','pdf'=>'application/pdf','custom'=>'image/avif'];
    $expected=$mimes;unset($expected['pdf']);verify(C\imagify_mime_types($mimes)===$expected,'Only pdf removed, all image/custom values and keys preserved');
    $other=$mimes;unset($other['gif']);$result=C\imagify_mime_types($other);verify(!isset($result['pdf'],$result['gif'])&&$result['custom']==='image/avif','Earlier exclusions and additions survive');
    verify(C\imagify_mime_types([])===[]&&C\imagify_mime_types($expected)===$expected,'Empty/image-only lists stay unchanged');
    $GLOBALS['settings']['imagify_pdf_protection']=false;verify(C\imagify_mime_types($mimes)===$mimes,'Disabled protection leaves full input unchanged');
    unset($GLOBALS['settings']['imagify_pdf_protection']);verify(C\imagify_mime_types($mimes)===$expected,'Legacy missing flag receives the upgrade default even before plugins_loaded');
    $GLOBALS['settings']['imagify_pdf_protection']=true;$GLOBALS['bound']=false;verify(C\imagify_mime_types($mimes)===$mimes,'Moved website stays paused');
    $before=$GLOBALS['settings'];verify(C\pdf_protection_status()['state']==='binding_paused','Saved enabled state distinguished from effective state');
    $GLOBALS['bound']=true;verify(C\pdf_protection_status()['state']==='imagify_inactive'&&!C\pdf_protection_status()['effective'],'Imagify absent is harmless and reported');
    verify($GLOBALS['settings']===$before,'Read-only status preserves settings');
    define('IMAGIFY_VERSION','2.3.0');if(!function_exists('imagify_get_mime_types')){function imagify_get_mime_types(){return ['pdf'=>'application/pdf'];}}
    verify(C\pdf_protection_status()['state']==='hook_unavailable'&&!C\pdf_protection_status()['effective'],'Older Imagify without the documented hook is reported ineffective');
    echo 'Configuration boundary checks passed ('.$checks.' checks).'.PHP_EOL;
}
