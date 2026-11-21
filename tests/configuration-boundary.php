<?php
/** Portable configuration hook checks; no WordPress or Imagify service required. */
namespace ChurchWebsiteConnector {
    function settings(): array { return $GLOBALS['settings']; }
    function current_identity(): array { return ['home'=>'https://connector.example','wordpress'=>'https://connector.example']; }
    function valid_identity(array $identity): bool { return $identity===current_identity(); }
    function settings_match($settings,array $reviewed): bool { return !array_diff_assoc($reviewed,$settings); }
    function is_bound(): bool { return $GLOBALS['bound']; }
    function feature_enabled(string $feature): bool { return is_bound() && true === (settings()[$feature] ?? true); }
}
namespace {
    define('ABSPATH', __DIR__);
    $GLOBALS['settings'] = ['imagify_pdf_protection'=>true];$GLOBALS['bound']=true;$hooks=[];$checks=0;
    function is_multisite(){return false;}
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
    $none=C\imagify_mime_types([]);verify($none&&!preg_match('!\.('.array_key_first($none).')$!i','document.pdf')&&C\imagify_mime_types($expected)===$expected,'Fully excluded list matches no PDF and image-only list is unchanged');
    verify(C\imagify_mime_types(['pdf|xpdf'=>'application/pdf','avif|PDF'=>'image/avif','png'=>'image/png'])===['avif'=>'image/avif','png'=>'image/png'],'PDF-valued aliases and mixed-case PDF suffixes removed without changing images');
    $GLOBALS['settings']['imagify_pdf_protection']=false;verify(C\imagify_mime_types($mimes)===$mimes,'Disabled protection leaves full input unchanged');
    unset($GLOBALS['settings']['imagify_pdf_protection']);verify(C\imagify_mime_types($mimes)===$expected,'Legacy missing flag receives the upgrade default even before plugins_loaded');
    $GLOBALS['settings']['imagify_pdf_protection']=true;$GLOBALS['bound']=false;verify(C\imagify_mime_types($mimes)===$mimes,'Moved website stays paused');
    $before=$GLOBALS['settings'];verify(C\pdf_protection_status()['state']==='binding_paused','Saved enabled state distinguished from effective state');
    $legacy=['schema'=>1,'enabled'=>true]+\ChurchWebsiteConnector\current_identity();
    $GLOBALS['settings']=$legacy;
    verify(C\imagify_mime_types($mimes)===$expected,'An older same-address installation continues excluding PDFs before review');
    foreach (['binding_hash'=>'', 'reviewed_addresses'=>'', 'grant_cleanup_pending'=>true, 'home'=>'https://other.example','wordpress'=>'https://other.example','enabled'=>false,'schema'=>2,'imagify_pdf_protection'=>false] as $key=>$moved) {
        $GLOBALS['settings']=array_merge($legacy,[$key=>$moved]);
        verify(C\imagify_mime_types($mimes)===$mimes,'Does not resume a disabled, changed, or partially reviewed configuration: '.$key);
    }
    $GLOBALS['settings']=$before;
    $GLOBALS['bound']=true;verify(C\pdf_protection_status()['state']==='imagify_inactive'&&!C\pdf_protection_status()['effective'],'Imagify absent is harmless and reported');
    verify($GLOBALS['settings']===$before,'Read-only status preserves settings');
    define('IMAGIFY_VERSION','2.3.0');if(!function_exists('imagify_get_mime_types')){function imagify_get_mime_types(){return ['pdf'=>'application/pdf'];}}
    verify(C\pdf_protection_status()['state']==='hook_unavailable'&&!C\pdf_protection_status()['effective'],'Older Imagify without the documented hook is reported ineffective');
    echo 'Configuration boundary checks passed ('.$checks.' checks).'.PHP_EOL;
}
