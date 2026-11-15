<?php
/** Portable traffic-light and selected-primary checks. No network or credentials. */
namespace ChurchWebsiteConnector {
 function settings(){return $GLOBALS['flags'];}
 function is_bound(){return $GLOBALS['bound'];}
 function feature_enabled($flag){return is_bound()&&true===($GLOBALS['flags'][$flag]??false);}
}
namespace ChurchWebsiteConnector\GeneratePressBridge {
 function environment_ready(){return $GLOBALS['provider_ready'];}
 function supported_version($v){return version_compare($v,'1.0.0','>=')&&version_compare($v,'1.1.0','<');}
}
namespace ChurchWebsiteConnector\AdapterSetup {
 function status(){return $GLOBALS['adapter'];}
 function presentation(){return ['message'=>'Native adapter status'];}
}
namespace WPMedia\MCP\OAuth { class Bootstrap {} }
namespace {
define('ABSPATH',__DIR__);$flags=['generatepress_bridge'=>true,'codex'=>false];$bound=true;$provider_ready=true;$adapter=['compatible'=>true];$checks=0;define('WP_ROCKET_VERSION','3.24');
function add_action(...$args){}function add_filter(...$args){}function __($s,$domain=''){return $s;}
function esc_attr($s){return htmlspecialchars((string)$s,ENT_QUOTES);}function esc_html($s){return esc_attr($s);}
function esc_html__($s,$domain=''){return esc_html($s);}
function check($ok,$message){++$GLOBALS['checks'];if(!$ok)throw new \RuntimeException('STATUS: '.$message);}
$root=is_file(__DIR__.'/../includes/status.php')?dirname(__DIR__):dirname(__DIR__).'/church-website-connector';
require $root.'/includes/maintenance.php';require $root.'/includes/status.php';
use ChurchWebsiteConnector\Maintenance as M;use ChurchWebsiteConnector\Status as S;
foreach([false,true] as $bound){foreach([false,true] as $provider_ready){foreach([false,true] as $bridge){foreach([false,true] as $codex){
 $flags=['generatepress_bridge'=>$bridge,'codex'=>$codex];$before=$flags;$p=M\primary_connection();
 check($p['selected']===($bridge?'generatepress':($codex?'separate_connections':'none')),'MCP route reflects both saved connection choices');
 $expected_state=!$bound?'website_review_required':($bridge?($provider_ready?'ready':'setup_required'):($codex?'separate_connections':'connections_disabled'));
 check($p['state']===$expected_state,'MCP state reflects the selected route and local readiness');
 check($p['level']===(!$bound||($bridge&&!$provider_ready)?'red':(!$bridge&&!$codex?'yellow':'green')),'Both disabled is yellow and unavailable selected connections are red');
 check($p['bridge_ready']===($bound&&$provider_ready&&$bridge),'Readiness matches effective bridge conditions');
 check(($p['state']==='website_review_required')===!$bound,'Moved or disabled websites report paused state');
 check(!$bridge||str_starts_with($p['label'],'GeneratePress'),'Unavailable selected GeneratePress is not relabelled as separate connections');
 check($flags===$before,'Readiness is read-only');
}}}}
$bound=true;$provider_ready=true;$flags=['generatepress_bridge'=>true,'codex'=>false];$adapter=['compatible'=>true];
$base=['dependencies'=>['wordpress_abilities'=>true,'generatepress_ai_version'=>'1.0.0','generatepress_license_cached_active'=>true,'generateblocks_version'=>'2.3.0','wp_rocket_version'=>'3.24','wp_rocket_oauth'=>true],
 'primary_connection'=>M\primary_connection(),'pdf_upload'=>['enabled'=>true,'current_user_can_upload'=>true,'max_bytes'=>10485760],
 'imagify_pdf_protection'=>['enabled'=>true,'state'=>'protected','message'=>'PDFs protected'],
 'redirection'=>['enabled'=>true,'compatible'=>true,'plugin_version'=>'5.10.1','message'=>'Ready'],
 'used_css'=>['enabled'=>true,'ready'=>true,'current_user_can_clear'=>true,'legacy_helpers_active'=>[],'message'=>'Ready'],
 'integrations'=>[['integration'=>'ws-form','label'=>'WS Form Pro','enabled'=>true,'compatible'=>true,'installed_version'=>'1.12.20','supported_versions'=>'>=1.12.20 <1.13']]];
check(count(array_filter(S\rows($base),fn($r)=>$r['level']!=='green'))===0,'All ready requirements and helpers are green');
foreach(['wordpress_abilities','generatepress_ai_version','generatepress_license_cached_active','generateblocks_version','wp_rocket_version','wp_rocket_oauth'] as $key){$s=$base;$s['dependencies'][$key]=null;check(count(array_filter(S\rows($s),fn($r)=>$r['level']==='red'))>0,'A missing required dependency is red: '.$key);}
$s=$base;$s['integrations'][0]['installed_version']=null;$s['integrations'][0]['compatible']=false;check(S\rows($s)['ws-form']['level']==='yellow','Enabled optional helper without its plugin is yellow');
$s['integrations'][0]['enabled']=false;check(S\rows($s)['ws-form']['level']==='green'&&S\rows($s)['ws-form']['off'],'An intentional optional off choice is accurately labelled');
$s=$base;$s['integrations'][0]['compatible']=false;check(S\rows($s)['ws-form']['level']==='red','Unsupported installed optional integration is an important issue');
foreach(['imagify_inactive'=>'yellow','overridden'=>'red','hook_unavailable'=>'red','binding_paused'=>'red','protected'=>'green','disabled'=>'green'] as $state=>$level){$s=$base;$s['imagify_pdf_protection']['state']=$state;check(S\rows($s)['pdf_protection']['level']===$level,'PDF protection status has the correct light for '.$state);}
$s=$base;$s['redirection']['plugin_version']=null;$s['redirection']['compatible']=false;check(S\rows($s)['redirection']['level']==='yellow','Missing optional Redirection is yellow');
$adapter['compatible']=false;check(S\rows($base)['adapter']['level']==='red','Missing or incompatible required adapter is red');$adapter['compatible']=true;
$s=$base;$s['pdf_upload']['current_user_can_upload']=false;check(S\rows($s)['uploads']['level']==='red','Missing upload permission is an important issue');
foreach(['green'=>'Ready','yellow'=>'Waiting','red'=>'Action needed'] as $level=>$label){$badge=S\badge($level);check(str_contains($badge,$label)&&str_contains($badge,'aria-hidden="true"'),'Every traffic light has visible text and a decorative dot');}
// WP Rocket's optional CSS optimisation setting can deliberately leave clearing dormant.
$s=$base;$s['used_css']['ready']=false;$s['used_css']['current_user_can_clear']=false;$s['used_css']['waiting_for_configuration']=true;
$before=$s;$row=S\rows($s)['used_css'];
check($row['level']==='yellow'&&!$row['off'],'WP Rocket Remove Unused CSS off waits in amber while the connector helper remains enabled');
check(str_contains($row['message'],'switched off for this website')&&str_contains($row['message'],'if you choose to enable'),'Waiting message explains the website configuration choice without demanding a change');
check($s===$before,'Waiting presentation never changes the website configuration');
ob_start();S\render($s);$waiting_html=ob_get_clean();preg_match('~<tr data-cwc-status="used_css">.*?</tr>~s',$waiting_html,$waiting_row);
check(str_contains($waiting_row[0],'cwc-status-yellow')&&str_contains($waiting_row[0],'Waiting')&&!str_contains($waiting_row[0],'Action needed'),'Rendered Used CSS row has an amber badge and visible Waiting label');
$s['used_css']['enabled']=false;check(S\rows($s)['used_css']['level']==='green'&&S\rows($s)['used_css']['off'],'Switching off the connector helper remains a green optional off state');
foreach(['Unsupported version','Missing WP Rocket interface','Wrong website'] as $problem){$s=$base;$s['used_css']['ready']=false;$s['used_css']['current_user_can_clear']=false;$s['used_css']['message']=$problem;$row=S\rows($s)['used_css'];check($row['level']==='red'&&$row['message']===$problem,'Important Used CSS problem stays red: '.$problem);}
$s=$base;$s['used_css']['current_user_can_clear']=false;check(S\rows($s)['used_css']['level']==='red','Ready services without clearing permission remain red');
$s['used_css']['ready']=false;$s['used_css']['legacy_helpers_active']=['earlier-helper'];check(S\rows($s)['used_css']['level']==='yellow','Existing legacy-helper waiting state is preserved');
// A configured helper is not advertised as ready while its MCP route is disabled.
$flags=['generatepress_bridge'=>false,'codex'=>false];$s=$base;$s['primary_connection']=M\primary_connection();$r=S\rows($s);
check($r['primary']['level']==='yellow'&&str_contains($r['primary']['message'],'No MCP route enabled'),'Both-off row warns about MCP tool access');
foreach(['uploads','redirection','used_css','ws-form'] as $id)check($r[$id]['level']==='yellow'&&str_contains($r[$id]['message'],'MCP access needs connection setup'),'Enabled '.$id.' waits for an MCP connection');
check($r['pdf_protection']['level']==='green'&&$r['generatepress']['level']==='green','PDF configuration and native plugin presence are independent of the MCP route');
check(str_contains($s['primary_connection']['message'],'Existing authorised connections are not revoked'),'Both-off warning does not claim to revoke existing sessions');
$s['integrations'][0]['enabled']=false;check(S\rows($s)['ws-form']['level']==='green'&&S\rows($s)['ws-form']['off'],'An intentionally disabled helper remains off, not waiting');
$s['dependencies']['generateblocks_version']=null;check(S\rows($s)['generateblocks']['level']==='red','A missing requirement remains red when both routes are disabled');
$flags=['generatepress_bridge'=>false,'codex'=>true];$adapter['compatible']=false;check(M\primary_connection()['state']==='setup_required'&&M\primary_connection()['level']==='red','Alternative sign-in selected with a missing adapter needs setup');
$adapter['compatible']=true;check(M\primary_connection()['state']==='separate_connections'&&M\primary_connection()['level']==='green','A locally ready alternative route is green');
$flags=['generatepress_bridge'=>true,'codex'=>false];
$before=$base;ob_start();S\render($base);$html=ob_get_clean();check(str_contains($html,'scope="row"')&&str_contains($html,'data-cwc-status="pdf_protection"')&&$base===$before,'Semantic Status table is read-only and contains the PDF result');
echo 'Status and primary connection checks passed ('.$checks.' checks).'.PHP_EOL;
}
