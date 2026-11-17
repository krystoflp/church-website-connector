<?php
/** Dismiss only the missing-Rocket warning for the current account and website. */
namespace ChurchWebsiteConnector {
 function current_identity(){return $GLOBALS['identity'];}
 function feature_enabled($name){return false;}
}
namespace ChurchWebsiteConnector\AdapterSetup { function status(){return ['compatible'=>true];} }
namespace {
 define('ABSPATH',__DIR__);$checks=0;$admin=true;$user=1;$identity=['home'=>'https://one.example','wordpress'=>'https://one.example'];$meta=[];$hooks=[];$save=true;$nonce=true;
 function add_action($hook,$callback){$GLOBALS['hooks'][$hook]=$callback;}
 function current_user_can($cap){return $GLOBALS['admin'];}
 function get_current_user_id(){return $GLOBALS['user'];}
 function get_user_meta($id,$key,$single){return $GLOBALS['meta'][$id][$key]??'';}
 function update_user_meta($id,$key,$value){if($GLOBALS['save'])$GLOBALS['meta'][$id][$key]=$value;}
 function admin_url($path){return 'https://one.example/wp-admin/'.$path;}
 function __($s,$domain=''){return $s;}
 function esc_html__($s,$domain=''){return esc_html($s);}
 function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
 function esc_attr($s){return esc_html($s);}function esc_url($s){return esc_html($s);}
 function wp_create_nonce($action){return 'fixture-nonce';}
 function check_ajax_referer($action,$field){if(!$GLOBALS['nonce'])throw new \RuntimeException('nonce rejected');}
 function wp_send_json_error($data,$status){throw new \RuntimeException('denied '.$status);}
 function wp_send_json_success(){throw new \RuntimeException('saved');}
 function check($ok,$label){++$GLOBALS['checks'];if(!$ok)throw new \RuntimeException('ROCKET NOTICE: '.$label);}
 $source=is_file(__DIR__.'/../includes/admin.php')?__DIR__.'/../includes/admin.php':__DIR__.'/../church-website-connector/includes/admin.php';require $source;
 use ChurchWebsiteConnector as C;
 check(isset($hooks['wp_ajax_church_connector_dismiss_rocket_notice'])&&!isset($hooks['wp_ajax_nopriv_church_connector_dismiss_rocket_notice']),'Only authenticated dismissal endpoint exists');
 ob_start();C\dependency_notice();$html=ob_get_clean();check(str_contains($html,'id="cwc-rocket-notice"')&&str_contains($html,'is-dismissible')&&str_contains($html,'fixture-nonce'),'Missing Rocket warning is visible and dismissible');
 check(str_contains($html,'GeneratePress AI'),'Other dependency warning is initially visible');$before=$meta;
 foreach(['GET','PUT'] as $method){$_SERVER['REQUEST_METHOD']=$method;try{C\dismiss_rocket_notice();}catch(\RuntimeException $e){check($e->getMessage()==='denied 405'&&$meta===$before,'Only POST can dismiss');}}
 $_SERVER['REQUEST_METHOD']='POST';$nonce=false;try{C\dismiss_rocket_notice();}catch(\RuntimeException $e){check($e->getMessage()==='nonce rejected'&&$meta===$before,'Forged dismissal cannot save');}$nonce=true;
 $admin=false;try{C\dismiss_rocket_notice();}catch(\RuntimeException $e){check($e->getMessage()==='denied 403'&&$meta===$before,'Non-administrator cannot save');}$admin=true;
 $save=false;try{C\dismiss_rocket_notice();}catch(\RuntimeException $e){check($e->getMessage()==='denied 500'&&$meta===$before,'Storage failure is reported');}$save=true;
 try{C\dismiss_rocket_notice();}catch(\RuntimeException $e){check($e->getMessage()==='saved','Valid administrator dismissal saved');}
 check(C\rocket_notice_dismissed(),'Current account remembers its choice');ob_start();C\dependency_notice();$html=ob_get_clean();check(!str_contains($html,'id="cwc-rocket-notice"')&&str_contains($html,'GeneratePress AI'),'Dismissal hides only Rocket, never unrelated warnings');
 check(isset(C\dependency_issues()['wp_rocket']),'Readiness still reports missing Rocket');
 $user=2;check(!C\rocket_notice_dismissed(),'Other administrators keep their warning');$user=1;$identity['home']='https://two.example';check(!C\rocket_notice_dismissed(),'Copied or moved websites do not inherit dismissal');
 echo 'Rocket notice checks passed ('.$checks.' checks)'.PHP_EOL;
}
