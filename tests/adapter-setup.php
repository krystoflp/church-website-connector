<?php
/** Portable access and package-identity tests; no network or native installation. */
declare(strict_types=1);
namespace ChurchWebsiteConnector {
    const VERSION='1.7.0';
    function current_identity(){return $GLOBALS['identity'];}
    function settings(){return ['setup_pending'=>$GLOBALS['pending']];}
    function is_bound(){return $GLOBALS['bound'];}
}
namespace ChurchWebsiteConnector\RestTools {
    function conditional_delete($key,$value){if(get_option($key)!==$value){return false;}unset($GLOBALS['options'][$key]);return true;}
}
namespace {
    define('ABSPATH',__DIR__.'/unavailable/');define('DAY_IN_SECONDS',86400);define('MINUTE_IN_SECONDS',60);
    $GLOBALS['options']=[];$GLOBALS['identity']=['home'=>'https://connector.example','wordpress'=>'https://connector.example'];$GLOBALS['pending']=true;$GLOBALS['bound']=true;$GLOBALS['logged_in']=true;$GLOBALS['file_mods']=true;$GLOBALS['caps']=['manage_options'=>true,'install_plugins'=>true,'activate_plugins'=>true];$GLOBALS['actions']=[];
    class WP_Error {function __construct(public $code,public $message){}function get_error_message(){return $this->message;}}
    function is_wp_error($r){return $r instanceof WP_Error;}
    function __($s,$domain=''){return $s;}
    function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
    function update_option($k,$v,$autoload=null){$GLOBALS['options'][$k]=$v;return true;}
    function add_option($k,$v,$deprecated='',$autoload=null){if(isset($GLOBALS['options'][$k])){return false;}$GLOBALS['options'][$k]=$v;return true;}
    function wp_strip_all_tags($s){return strip_tags($s);}
    function wp_generate_uuid4(){return 'fixture-uuid';}
    function is_user_logged_in(){return $GLOBALS['logged_in'];}
    function current_user_can($cap){return $GLOBALS['caps'][$cap]??false;}
    function wp_is_file_mod_allowed($context){return $GLOBALS['file_mods'];}
    function add_action($hook,$callback){$GLOBALS['actions'][$hook]=$callback;}
    function check_ajax_referer($action,$param){if(!($GLOBALS['nonce_ok']??false)){throw new RuntimeException('Nonce rejected');}}
    function wp_send_json_error($data,$status){throw new RuntimeException('Denied '.$status);}
    $source=__DIR__.'/../includes/adapter-setup.php';if(!is_file($source)){$source=__DIR__.'/../church-website-connector/includes/adapter-setup.php';}require $source;
    use ChurchWebsiteConnector\AdapterSetup as A;
    class AdapterVersionFixture {}
    $checks=0;function check($ok,$label){global $checks;$checks++;if(!$ok){throw new RuntimeException('FAILED '.$label);}}
    if ( isset( $argv[1] ) ) {
        $version = $argv[1];
        if ( 'unknown' !== $version ) { define( 'WP_MCP_VERSION', $version ); }
        class_alias( AdapterVersionFixture::class, 'WP\MCP\Core\McpAdapter' );
        $status = A\status();
        $expected = [ '0.5.0' => 'red', '0.7.1' => 'yellow', '0.7.2' => 'green', '0.7.3' => 'green', 'unknown' => 'green' ][$version];
        check( $status['level'] === $expected && $status['compatible'] === ( 'red' !== $expected ) && $status['update_recommended'] === ( 'yellow' === $expected ), 'Adapter version policy ' . $version );
        echo 'Adapter version policy passed: ' . $version . PHP_EOL;
        return;
    }
    check(!A\status()['available']&&!A\status()['compatible'],'Missing adapter is unavailable without loading native classes');
    check(isset($GLOBALS['actions']['wp_ajax_church_connector_setup_adapter'])&&!isset($GLOBALS['actions']['wp_ajax_nopriv_church_connector_setup_adapter']),'Installer endpoint is authenticated administrator AJAX only');
    $before=$GLOBALS['options'];$_SERVER['REQUEST_METHOD']='GET';try{A\ajax();}catch(RuntimeException $e){check($e->getMessage()==='Denied 405'&&$GLOBALS['options']===$before,'GET requests cannot mutate installation');}$_SERVER['REQUEST_METHOD']='POST';try{A\ajax();throw new RuntimeException('Nonce bypass');}catch(RuntimeException $e){check($e->getMessage()==='Nonce rejected'&&$GLOBALS['options']===$before,'Missing nonce causes no mutation');}
    foreach(['manage_options','install_plugins','activate_plugins'] as $cap){$GLOBALS['caps'][$cap]=false;check(is_wp_error(A\run()),'Native permission '.$cap);check($GLOBALS['options']===$before,'Permission denial does not save or install');$GLOBALS['caps'][$cap]=true;}
    foreach(['bound','logged_in','file_mods'] as $flag){$GLOBALS[$flag]=false;check(is_wp_error(A\run()),'Access gate '.$flag);check($GLOBALS['options']===$before,'Access denial does not save or install');$GLOBALS[$flag]=true;}
    A\schedule();check(A\automatic_allowed(),'First activation schedules initial setup');
    check(get_option(A\STATE)['status']==='pending'&&get_option(A\STATE)['site']===$GLOBALS['identity'],'Scheduling retains website binding without installing');
    $saved=get_option(A\STATE);$GLOBALS['identity']['home']='https://clone.example';check(!A\automatic_allowed()&&is_wp_error(A\run(true)),'Cloned website cannot reuse setup approval');$GLOBALS['identity']=$saved['site'];
    $GLOBALS['pending']=false;check(A\automatic_allowed(),'Saving settings or restoring defaults does not cancel the independent pending adapter attempt');$GLOBALS['pending']=true;
    $pagenow='plugins.php';$_GET=['activate'=>'true'];check(A\activation_screen(),'Single activation confirmation exposes pending setup');$_GET=['activate-multi'=>'true'];check(A\activation_screen(),'Bulk activation confirmation exposes pending setup');$_GET=[];check(!A\activation_screen(),'Ordinary plugin visits never start setup');$_GET=['activate'=>'true'];$pagenow='index.php';check(!A\activation_screen(),'Other administrator screens never start adapter setup');$pagenow='plugins.php';$GLOBALS['caps']['activate_plugins']=false;check(!A\activation_screen(),'Activation panel respects native permissions');$GLOBALS['caps']['activate_plugins']=true;$_GET=[];

    $pagenow='options-general.php';$_GET=['page'=>'church-website-connector'];check(A\automatic_screen(),'Connector settings retain automatic onboarding');$pagenow='index.php';check(!A\automatic_screen(),'Moving the control to dashboard notices does not extend automatic setup');$_GET=[];
    $expired=$saved;$expired['expires']=time()-1;update_option(A\STATE,$expired);check(!A\automatic_allowed()&&is_wp_error(A\run(true)),'Expired setup does not auto-install');update_option(A\STATE,$saved);
    $info=(object)['slug'=>'mcp-adapter','version'=>'0.7.1','download_link'=>'https://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip'];check(A\package_url($info)===$info->download_link,'Exact official adapter package accepted');
    foreach(['https://evil.example/plugin/mcp-adapter.0.7.1.zip','http://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip','https://downloads.wordpress.org/plugin/other.0.7.1.zip','https://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip?redirect=1'] as $url){$bad=clone $info;$bad->download_link=$url;check(is_wp_error(A\package_url($bad)),'Unexpected package URL denied');}
    foreach(['0.1.0','0.7.1-beta','../other','8.3;bad'] as $version){$bad=clone $info;$bad->version=$version;check(is_wp_error(A\package_url($bad)),'Unsupported version metadata denied');}
    $bad=clone $info;$bad->slug='other';check(is_wp_error(A\package_url($bad)),'Other plugin slug denied');
    // Stored completion must not overrule the adapter's current availability.
    define('WP_PLUGIN_DIR',sys_get_temp_dir().'/connector-adapter-state-'.uniqid());
    mkdir(WP_PLUGIN_DIR);mkdir(WP_PLUGIN_DIR.'/mcp-adapter');
    A\finish('complete','Previously completed');$before=$GLOBALS['options'];$p=A\presentation();
    check($p['level']==='warning'&&str_contains($p['message'],'not installed')&&!str_contains($p['message'],'setup completed'),'Removed adapter is not reported as completed');
    check($GLOBALS['options']===$before&&!A\automatic_allowed(),'Reading stale completion never changes state or queues activation');
    file_put_contents(WP_PLUGIN_DIR.'/'.A\PLUGIN_FILE,'<?php // Inactive fixture.');
    foreach([false,true] as $active){
        update_option('active_plugins',$active?[A\PLUGIN_FILE]:[]);$before=$GLOBALS['options'];$p=A\presentation();
        check($p['level']==='warning'&&str_contains($p['message'],$active?'interfaces are unavailable':'installed but inactive'),'Current activation state overrides saved completion');
        check(!str_contains($p['message'],'setup completed')&&$GLOBALS['options']===$before&&!A\automatic_allowed(),'Stale completion reporting is read-only and never reactivates an adapter');
    }
    unlink(WP_PLUGIN_DIR.'/'.A\PLUGIN_FILE);rmdir(WP_PLUGIN_DIR.'/mcp-adapter');rmdir(WP_PLUGIN_DIR);unset($GLOBALS['options']['active_plugins']);update_option(A\STATE,$saved);
    eval('namespace WP\\MCP\\Core { class McpAdapter { const VERSION="0.7.1"; } }');
    $result=A\run(true);check(!is_wp_error($result)&&$result['status']==='complete','Existing native adapter reused without installer files or network');
    check(!A\automatic_allowed()&&is_wp_error(A\run(true)),'Completed setup cannot auto-reactivate later');
    check(get_option(A\LOCK)===false,'Setup lock released');
    check(A\status()['compatible'] && A\status()['update_recommended'] && A\status()['level']==='yellow', 'Compatible 0.7.1 remains usable with an amber advisory');
    check(A\presentation()['level']==='warning' && str_contains(A\presentation()['message'], 'Update recommended. MCP Adapter 0.7.1 is active. Update to 0.7.2 or later.'), 'Advisory identifies installed and recommended versions');
    A\schedule();add_option(A\LOCK,['expires'=>time()+60,'token'=>'other']);$before=$GLOBALS['options'];check(is_wp_error(A\run(true))&&$GLOBALS['options']===$before,'Concurrent setup refused without changing the pending request');
    unset($GLOBALS['options'][A\LOCK]);$r=A\finish('failed',str_repeat('<b>private</b>',500));check(strlen($r['message'])<=1600&&!str_contains($r['message'],'<b>'),'Outcome is bounded plain text');check(!A\automatic_allowed(),'Failure is not retried automatically');
    echo 'Adapter setup boundary checks passed ('.$checks.' checks)'.PHP_EOL;
}
