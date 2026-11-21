<?php
/** Real WordPress administration, privacy and first-install preference regression checks. */
require '/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/template.php';
use ChurchWebsiteConnector as C;
use ChurchWebsiteConnector\Maintenance as M;
use ChurchWebsiteConnector\Updates as U;
wp_set_current_user(1);$checks=0;
function maintenance_check($ok,$message){global $checks;++$checks;if(!$ok){throw new RuntimeException('MAINTENANCE: '.$message);}}
$file=U\plugin_file();$marker='church_connector_auto_default_connector';$original_marker=get_option($marker,false);$auto=get_site_option('auto_update_plugins',[]);
$allow=static fn()=>true;add_filter('plugins_auto_update_enabled',$allow);
delete_option($marker);update_site_option('auto_update_plugins',['other/keep.php']);M\initial_auto_update($file);
maintenance_check(in_array($file,get_site_option('auto_update_plugins'),true)&&in_array('other/keep.php',get_site_option('auto_update_plugins'),true),'First default enables native checkbox while preserving other preferences');
update_site_option('auto_update_plugins',['other/keep.php']);M\initial_auto_update($file);
maintenance_check(get_site_option('auto_update_plugins')===['other/keep.php'],'Default is never restored after administrator disables it');
$before=get_site_option('auto_update_plugins');M\initial_auto_update('other/other.php');maintenance_check(get_site_option('auto_update_plugins')===$before,'Other plugins cannot receive defaults');
delete_option($marker);wp_get_current_user()->add_cap('update_plugins',false);M\initial_auto_update($file);maintenance_check(get_option($marker)==='blocked'&&get_site_option('auto_update_plugins')===$before,'Missing update permission leaves preferences unchanged');wp_get_current_user()->remove_cap('update_plugins');M\initial_auto_update($file);maintenance_check(get_site_option('auto_update_plugins')===$before,'Permission failure does not cause a later silent opt-in');
delete_option($marker);$deny=static fn()=>false;add_filter('plugins_auto_update_enabled',$deny,20);M\initial_auto_update($file);maintenance_check(get_option($marker)==='blocked'&&get_site_option('auto_update_plugins')===$before,'Website update policy is respected');maintenance_check(M\auto_status($file)['message']==='Blocked by website update policy','Blocked policy is explained');remove_filter('plugins_auto_update_enabled',$deny,20);
$force=static fn($decision,$item)=>$item->plugin===$file?false:$decision;add_filter('auto_update_plugin',$force,20,2);delete_option($marker);M\initial_auto_update($file);maintenance_check(get_option($marker)==='blocked'&&get_site_option('auto_update_plugins')===$before,'Per-plugin policy is respected');remove_filter('auto_update_plugin',$force,20);
if($original_marker===false){delete_option($marker);}else{update_option($marker,$original_marker,false);}update_site_option('auto_update_plugins',$auto);remove_filter('plugins_auto_update_enabled',$allow);

$stored_update=get_option(U\CHECK_STATUS,false);update_option(U\CHECK_STATUS,['last_attempt'=>time(),'last_success'=>time()-60,'latest'=>'1.7.3','result'=>'failed','secret'=>'DO-NOT-COPY-SECRET'],false);
$keys=['church_connector_rest_audit','church_connector_redirect_audit','church_connector_integration_audit','church_connector_used_css_log'];$old=[];foreach($keys as $key){$old[$key]=get_option($key,false);}
// Retained older rows use two UTC spellings. Leave each original timestamp untouched.
$now = time();
foreach ( $keys as $key ) {
	$rows = array();
	$format = in_array( $key, array( C\Integrations\AUDIT, C\UsedCss\LOG ), true ) ? 'c' : 'Y-m-d\TH:i:s\Z';
	for ( $i = 0; $i < 55; $i++ ) {
		$rows[] = array( 'time_utc' => gmdate( $format, $now - 60 - $i ), 'user_id' => 1, 'operation' => 'update', 'integration' => 'ws-form', 'id' => $i + 1, 'result' => 'updated', 'url' => 'https://private.example/DO-NOT-COPY-SECRET', 'code' => 'DO-NOT-COPY-SECRET' );
	}
	update_option( $key, $rows, false );
}
$legacy = M\recent_changes();
maintenance_check( 50 === count( $legacy ) && 4 === count( array_unique( array_column( $legacy, 'source' ) ) ) && $now - 60 === $legacy[0]['timestamp'], 'Historical UTC timestamps from all four stores are merged by instant before applying the display limit' );
$css_history = get_option( C\UsedCss\LOG );
foreach ( array( 'not-a-date', '2026-02-30T01:00:00Z', '2030-01-01T01:00:00+01:00' ) as $invalid ) {
	array_unshift( $css_history, array( 'time_utc' => $invalid, 'id' => 987654, 'status' => 'cleared' ) );
}
update_option( C\UsedCss\LOG, $css_history, false );
C\UsedCss\audit( array( 'scope' => 'page', 'url' => 'https://private.example/DO-NOT-COPY-SECRET', 'status' => 'cleared' ) );
$latest = M\recent_changes();
maintenance_check( 'Used CSS' === $latest[0]['source'] && 'clear' === $latest[0]['operation'] && 'cleared' === $latest[0]['result'] && 'Z' === substr( get_option( C\UsedCss\LOG )[0]['time_utc'], -1 ), 'Real Used CSS audit output appears with its outcome and the common UTC format' );
maintenance_check( ! in_array( 987654, array_column( $latest, 'item_id' ), true ) && ! str_contains( wp_json_encode( $latest ), 'DO-NOT-COPY-SECRET' ), 'Malformed or non-UTC timestamps are excluded and sensitive stored values remain private' );
// Exercise the registered MCP status ability with the same policy sources as Installed Plugins.
$ability = wp_get_ability('church-connector/connection-status');
$recorded = get_option(U\CHECK_STATUS);
$no_http = static function () { throw new RuntimeException('A status read attempted an external request'); };
add_filter('pre_http_request', $no_http, 100);
add_filter('plugins_auto_update_enabled', $allow);
$status = $ability->execute([]);
maintenance_check(!is_wp_error($status) && $status['current_site_url'] === C\bound_site() && $status['bound_site_url'] === C\bound_site(), 'Native status returns the bound website');
maintenance_check($status['connector_auto_updates'] === ['selected' => in_array($file, $auto, true), 'available' => true, 'policy' => 'default'], 'Native status reports the actual saved preference and policy');
maintenance_check($status['github_update_check'] === M\update_status() && $status['github_update_check']['last_success'] === $recorded['last_success'] && $status['github_update_check']['last_attempt'] === $recorded['last_attempt'], 'Failed check preserves the previous successful check time');
maintenance_check(!str_contains(wp_json_encode($status), 'DO-NOT-COPY-SECRET') && in_array('adapter', $status['serious_issues'], true), 'Native diagnostics omit private stored fields and include serious readiness codes');
add_filter('auto_update_plugin', '__return_true');
maintenance_check($ability->execute([])['connector_auto_updates']['policy'] === 'enabled', 'Native status reports a forced opt-in');
remove_filter('auto_update_plugin', '__return_true');
add_filter('auto_update_plugin', '__return_false');
maintenance_check($ability->execute([])['connector_auto_updates']['policy'] === 'disabled', 'Native status reports a forced opt-out');
remove_filter('auto_update_plugin', '__return_false');
add_filter('plugins_auto_update_enabled', $deny, 20);
maintenance_check($ability->execute([])['connector_auto_updates']['available'] === false, 'Global plugin update policy is reported');
remove_filter('plugins_auto_update_enabled', $deny, 20);
remove_filter('plugins_auto_update_enabled', $allow);
$other_site = static fn() => 'https://different.example';
add_filter('option_home', $other_site, 100);
maintenance_check(is_wp_error($ability->execute([])), 'A moved website cannot expose the saved update record');
remove_filter('option_home', $other_site, 100);
maintenance_check(get_option(U\CHECK_STATUS) === $recorded && get_site_option('auto_update_plugins') === $auto, 'The native status read preserves preferences and GitHub check times');
wp_set_current_user(0);
maintenance_check(is_wp_error($ability->execute([])) && is_wp_error(C\mcp_connection_status()), 'Native and direct status deny anonymous access');
wp_set_current_user(1);
remove_filter('pre_http_request', $no_http, 100);
$report=M\diagnostic_report();$json=wp_json_encode($report);
maintenance_check(!is_wp_error($report)&&$report['connector']===C\VERSION&&$report['updates']['result']==='failed','Report includes selected current technical status');
maintenance_check(!str_contains($json,'DO-NOT-COPY-SECRET')&&!str_contains($json,'connector.example')&&!str_contains($json,'user_id')&&!str_contains($json,'display_name'),'Diagnostics exclude unapproved stored fields, URLs, activity and account data');
maintenance_check(M\version('8.3.secret')===null&&M\version('1.7.3')==='1.7.3','Diagnostic versions accept only version numbers');
$health=M\site_health(['existing'=>['keep'=>true]]);maintenance_check(isset($health['church-website-connector'])&&$health['existing']===['keep'=>true]&&!str_contains(wp_json_encode($health),'DO-NOT-COPY-SECRET'),'Site Health uses the same safe report and preserves other sections');
$history=M\recent_changes();maintenance_check(count($history)===50&&$history[0]['timestamp']>=$history[49]['timestamp'],'Recent changes are bounded and newest first');maintenance_check(!str_contains(wp_json_encode($history),'DO-NOT-COPY-SECRET'),'History normalisation excludes stored URLs, values and code');
$network=static function(){throw new RuntimeException('Dashboard must not make an external request');};add_filter('pre_http_request',$network,100);
$settings=C\settings();$roles=wp_roles()->roles;$before_updates=get_site_option('auto_update_plugins');
ob_start();C\admin_page();$html=ob_get_clean();
maintenance_check(strpos($html,'id="cwc-gp-setup"')<strpos($html,'id="cwc-summary"')&&strpos($html,'Install and activate GeneratePress AI')<strpos($html,'id="cwc-status-title"'),'Licence field and installation action appear at the top before the summary and plugin table');
maintenance_check(!str_contains($html,'Authorise setup from my Mac')&&!str_contains($html,'Optional: use the existing Mac helper')&&!str_contains($html,'cwc-gp-helper-submit'),'Only the browser licence form is offered; the helper controls are absent');
preg_match('~<form id="cwc-gp-install-form".*?</form>~s',$html,$browser_form);
maintenance_check(!empty($browser_form)&&str_contains($browser_form[0],'method="post"')&&str_contains($browser_form[0],'name="license_key"')&&str_contains($browser_form[0],'for="cwc-generatepress-license-key"'),'Direct setup has a labelled, stable autofill field in a POST form');
maintenance_check(str_contains($browser_form[0],'type="text"')&&!str_contains($browser_form[0],'autocomplete="current-password"')&&!str_contains($browser_form[0],'data-1p-ignore'),'Licence is an additional text field, not a WordPress password field, and is available to the extension');
maintenance_check(!str_contains($browser_form[0],'name="license_key" value=')&&str_contains($html,'role="status"')&&str_contains($html,'aria-describedby="cwc-gp-licence-help"'),'Licence is never repopulated and instructions and result announcements are labelled');
ob_start();\ChurchWebsiteConnector\GeneratePressSetup\render_callout(['dependencies'=>['generatepress_ai_version'=>'1.0.0','generatepress_license_cached_active'=>true]]);$ready_html=ob_get_clean();maintenance_check($ready_html==='','Working licensed installations need no installation warning');
file_put_contents('/reports/setup-settings.html',$html);
$css='';foreach(['wp-admin/css/common.min.css','wp-admin/css/forms.min.css','wp-includes/css/buttons.min.css','wp-admin/css/colors.min.css'] as $file){if(is_readable(ABSPATH.$file)){$css.=file_get_contents(ABSPATH.$file);}}
file_put_contents('/reports/wordpress-admin.css',$css);

maintenance_check(str_contains($html,'Unattended connector updates do not install it or access 1Password')&&str_contains($html,'Install and activate GeneratePress AI'),'Missing GeneratePress AI shows an actionable unattended-upgrade warning');
maintenance_check(strpos($html,'id="cwc-summary"')<strpos($html,'Connect your assistant')&&str_contains($html,'Latest known stable release')&&str_contains($html,'Last successful release check'),'Compact status appears before detailed setup and reports update state');
maintenance_check(str_contains($html,'id="cwc-diagnostic-report"')&&str_contains($html,'readonly')&&str_contains($html,'Copy diagnostic report'),'Report has a labelled read-only field and copy control');
ob_start();M\activity_page();$activity=ob_get_clean();maintenance_check(str_contains($activity,'scope="col"')&&str_contains($activity,'Recent connector changes')&&!str_contains($activity,'DO-NOT-COPY-SECRET'),'Activity screen renders semantic rows without sensitive audit values');
maintenance_check( str_contains( $activity, '<td>Used CSS</td><td>clear</td>' ) && str_contains( $activity, '<td>WS Form Pro</td>' ), 'Tools screen renders the new Used CSS operation and retained integration entries' );
maintenance_check(C\settings()===$settings&&wp_roles()->roles===$roles&&get_site_option('auto_update_plugins')===$before_updates,'Administration views do not change settings, roles or update preferences');remove_filter('pre_http_request',$network,100);
wp_set_current_user(0);maintenance_check(is_wp_error(M\diagnostic_report())&&is_wp_error(M\recent_changes())&&M\site_health(['keep'=>true])===['keep'=>true],'Anonymous access is denied, including Site Health');
$editor=wp_insert_user(['user_login'=>'maintenance-editor','user_pass'=>'local-fixture-only','role'=>'editor']);wp_set_current_user($editor);maintenance_check(is_wp_error($ability->execute([])) && is_wp_error(C\mcp_connection_status()), 'Native and direct status deny editors');maintenance_check(is_wp_error(M\diagnostic_report())&&is_wp_error(M\recent_changes()),'Non-administrator cannot read diagnostics or activity');wp_set_current_user(1);
foreach($old as $key=>$value){if($value===false){delete_option($key);}else{update_option($key,$value,false);}}if($stored_update===false){delete_option(U\CHECK_STATUS);}else{update_option(U\CHECK_STATUS,$stored_update,false);}
file_put_contents('/reports/maintenance.json',wp_json_encode(['checks'=>$checks,'php'=>PHP_VERSION,'wordpress'=>$GLOBALS['wp_version'],'first_install_preferences'=>true,'later_choices_preserved'=>true,'diagnostics_allowlist'=>true,'admin_permissions'=>true,'read_only_views'=>true,'bounded_existing_history'=>true]));echo 'MAINTENANCE CHECKS PASSED '.$checks;
