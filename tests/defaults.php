<?php
/** Portable restore permission, scope and update-policy checks. No credentials or HTTP. */
namespace ChurchWebsiteConnector {
    function is_bound(){return $GLOBALS['bound'];}
    function settings(){return $GLOBALS['settings'];}
    function configure($flags){if(!$GLOBALS['save_failed'])$GLOBALS['settings']=$flags;return true;}
}
namespace ChurchWebsiteConnector\Updates { function plugin_file(){return 'church-website-connector/church-website-connector.php';} }
namespace ChurchWebsiteConnector\AdapterSetup { const PLUGIN_FILE='mcp-adapter/mcp-adapter.php'; }
namespace ChurchWebsiteConnector\GeneratePressSetup { const PLUGIN_FILE='generatepress-ai/generatepress-ai.php'; }
namespace ChurchWebsiteConnector\Maintenance { function update_api(){} }
namespace {
define('ABSPATH',__DIR__);$checks=0;$hooks=[];$bound=true;$settings=['keep'=>'before'];$save_failed=false;$write_failed=false;
$logged_in=true;$capabilities=['manage_options'=>true,'update_plugins'=>true];$global_policy=true;$files_allowed=true;$forced=[];$site_options=['auto_update_plugins'=>['unrelated/keep.php']];
function add_action($hook,$callback){$GLOBALS['hooks'][$hook]=$callback;}
function __($s,$domain=''){return $s;}
class WP_Error {public function __construct(public $code,public $message='',public $data=[]){} }
function is_wp_error($v){return $v instanceof WP_Error;}
function is_user_logged_in(){return $GLOBALS['logged_in'];}
function current_user_can($cap){return $GLOBALS['capabilities'][$cap]??false;}
function wp_verify_nonce($nonce,$action){return $nonce==='fixture-nonce'&&$action==='church_connector_restore_defaults';}
function get_plugins(){return $GLOBALS['installed'];}
function get_site_option($key,$default=[]){return $GLOBALS['site_options'][$key]??$default;}
function update_site_option($key,$value){if(!$GLOBALS['write_failed'])$GLOBALS['site_options'][$key]=$value;return !$GLOBALS['write_failed'];}
function wp_is_file_mod_allowed($kind){return $GLOBALS['files_allowed'];}
function wp_is_auto_update_enabled_for_type($kind){return $GLOBALS['global_policy'];}
function wp_is_auto_update_forced_for_item($kind,$value,$item){return $GLOBALS['forced'][$item->plugin]??null;}
function check($ok,$label){++$GLOBALS['checks'];if(!$ok)throw new \RuntimeException('DEFAULTS: '.$label);}
require (is_file(__DIR__.'/../includes/defaults.php')?__DIR__.'/../includes/defaults.php':__DIR__.'/../church-website-connector/includes/defaults.php');
use ChurchWebsiteConnector\Defaults as D;
$connector='church-website-connector/church-website-connector.php';$rocket='wp-rocket/wp-rocket.php';$blocks='generateblocks/generateblocks.php';
$installed=[$connector=>[], $rocket=>[], $blocks=>[], 'unrelated/keep.php'=>[], 'unrelated/off.php'=>[]];
$input=['action'=>D\ACTION,'_wpnonce'=>'fixture-nonce'];$_SERVER['REQUEST_METHOD']='POST';
$before=[$settings,$site_options];
check(array_keys($hooks)===['admin_post_church_connector_restore_defaults']&&[$settings,$site_options]===$before,'Loading restoration registers only its administrator form; it never restores automatically');
foreach(['GET','PUT','DELETE'] as $method){$_SERVER['REQUEST_METHOD']=$method;check(is_wp_error(D\restore_request($input))&&[$settings,$site_options]===$before,'Non-POST requests cannot mutate defaults');}$_SERVER['REQUEST_METHOD']='POST';
foreach([[],['action'=>D\ACTION],['action'=>'other','_wpnonce'=>'fixture-nonce'],['action'=>D\ACTION,'_wpnonce'=>[]],['action'=>D\ACTION,'_wpnonce'=>'wrong'], $input+['plugin'=>'unrelated/off.php']] as $bad){check(is_wp_error(D\restore_request($bad))&&[$settings,$site_options]===$before,'Malformed or forged restores cannot change settings or plugin preferences');}
$logged_in=false;check(is_wp_error(D\restore_request($input))&&[$settings,$site_options]===$before,'Anonymous request rejected');$logged_in=true;
foreach(['manage_options','update_plugins'] as $cap){$capabilities[$cap]=false;check(is_wp_error(D\restore_request($input))&&[$settings,$site_options]===$before,'Required administrator capability enforced');$capabilities[$cap]=true;}
$bound=false;check(is_wp_error(D\restore_request($input))&&[$settings,$site_options]===$before,'Moved or disabled site must be reviewed first');$bound=true;
$save_failed=true;check(is_wp_error(D\restore_request($input))&&$site_options===$before[1],'Failed settings save cannot enable updates');$save_failed=false;
$result=D\restore_request($input);
foreach(['enabled','compatibility','used_css','rest_tools','redirection_tools','generatepress_bridge','tsf_tools','rankmath_tools','snippets_tools','wsform_tools','imagify_pdf_protection','disable_comments'] as $flag){check($settings[$flag]===true,'Restore enables '.$flag);}
check($settings['codex']===false&&$settings['setup_pending']===false,'Alternative Codex trust stays disabled and deliberate setup is saved');
check(in_array($connector,$site_options['auto_update_plugins'],true),'Website Connector itself receives automatic updates');
check($site_options['auto_update_plugins']===['unrelated/keep.php',$connector,$blocks,$rocket],'Only listed installed plugins are added, preserving unrelated preferences');
check($result['blocked']===[]&&count($result['enabled'])===3,'Report includes only installed listed plugins');
$again=D\restore_request($input);check($again===$result&&count($site_options['auto_update_plugins'])===4,'Repeated deliberate restore does not duplicate preferences');
$site_options['auto_update_plugins']=['unrelated/keep.php'];$forced[$rocket]=false;
$result=D\restore_request($input);check($result['blocked']===['WP Rocket']&&!in_array($rocket,$site_options['auto_update_plugins'],true),'Per-plugin website policy is respected and reported');$forced=[];
foreach(['global_policy','files_allowed'] as $policy){$GLOBALS[$policy]=false;$site_options['auto_update_plugins']=['unrelated/keep.php'];$result=D\restore_request($input);check(count($result['blocked'])===3&&$site_options['auto_update_plugins']===['unrelated/keep.php'],'Website policy blocks updates without falsely reporting success');$GLOBALS[$policy]=true;}
$write_failed=true;$site_options['auto_update_plugins']=['unrelated/keep.php'];$result=D\restore_request($input);check(count($result['blocked'])===3&&$result['enabled']===[],'Failed preference save is reported by reading back WordPress state');
echo 'Restore defaults boundary checks passed ('.$checks.' checks).'.PHP_EOL;
}
