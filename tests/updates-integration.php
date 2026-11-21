<?php
/** Runs only inside isolated WordPress, with local HTTP fixtures and a synthetic future ZIP. */
define( 'DOING_CRON', true );
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/update.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
use ChurchWebsiteConnector\Updates as U;
wp_set_current_user( 1 );
$checks = 0;
function updater_check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( 'WORDPRESS UPDATER CHECK FAILED: ' . $message ); } }
$zip = '/update-fixtures/future.zip';
$metadata = [ 'version' => '__FUTURE_VERSION__', 'requires' => '7.0', 'requires_php' => '8.3', 'tested' => '7.0.8', 'sha256' => hash_file( 'sha256', $zip ) ];
$release = [ 'tag_name' => 'v__FUTURE_VERSION__', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-11-13T12:00:00Z', 'html_url' => U\REPOSITORY . '/releases/tag/v__FUTURE_VERSION__', 'body' => 'Synthetic future release used only in isolated testing.', 'assets' => [] ];
foreach ( [ 'zip', 'json' ] as $extension ) { $release['assets'][] = [ 'name' => U\SLUG . '-__FUTURE_VERSION__.' . $extension, 'state' => 'uploaded', 'browser_download_url' => U\asset_url( '__FUTURE_VERSION__', $extension ) ]; }
$release['assets'][0]['digest'] = 'sha256:' . $metadata['sha256'];
$tampered = false; $requests = [];
$mock = static function( $pre, $args, $url ) use ( &$tampered, &$requests, $release, &$metadata, $zip ) {
    $requests[] = $url;
    $body = null;
    if ( $url === U\API . 'latest' || $url === U\API . 'tags/v__FUTURE_VERSION__' ) { $body = wp_json_encode( $release ); }
    elseif ( $url === U\asset_url( '__FUTURE_VERSION__', 'json' ) ) { $body = wp_json_encode( $metadata ); }
    elseif ( $url === U\asset_url( '__FUTURE_VERSION__', 'zip' ) ) { $body = $tampered ? 'incorrect bytes' : file_get_contents( $zip ); }
    elseif ( str_contains( $url, 'api.wordpress.org/plugins/update-check/' ) ) { $body = '{"plugins":{},"no_update":{},"translations":[]}'; }
    if ( $body === null ) { return new WP_Error( 'fixture_network_disabled', 'External requests are disabled for this isolated updater test.' ); }
    if ( ! empty( $args['stream'] ) ) { file_put_contents( $args['filename'], $body ); $body = ''; }
    return [ 'headers' => [], 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => $body, 'cookies' => [] ];
};
add_filter( 'pre_http_request', $mock, 10, 3 );
delete_transient( U\CACHE . 'latest' ); delete_transient( U\CACHE . '__FUTURE_VERSION__' );
$settings_before = get_option( ChurchWebsiteConnector\SETTINGS );
$roles_before = wp_roles()->roles;
$grants_before = get_option( ChurchWebsiteConnector\UsedCss\GRANTEE );
$auto_before = get_site_option( 'auto_update_plugins', [] );
$active_before = get_option( 'active_plugins' );
$plugin = 'church-website-connector/church-website-connector.php';
require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH.'wp-admin/includes/class-wp-plugins-list-table.php';
set_current_screen('plugins');
function connector_plugin_row(){
 global $status,$page,$s,$totals;$status='all';$page=1;$s='';$totals=[];
 $table=new WP_Plugins_List_Table(['screen'=>'plugins']);$table->prepare_items();
 foreach($table->items as $file=>$data){if($file===U\plugin_file()){ob_start();$table->single_row([$file,$data]);return ob_get_clean();}}
 throw new RuntimeException('Connector missing from native plugin list');
}
$empty_updates=(object)['last_checked'=>time(),'checked'=>[],'response'=>[],'no_update'=>[]];
set_site_transient('update_plugins',$empty_updates);
update_site_option('auto_update_plugins',[$plugin,'other/keep.php']);
remove_filter('all_plugins','ChurchWebsiteConnector\\Updates'.'\\supported_plugin');
$before_fix=connector_plugin_row();
updater_check(!str_contains($before_fix,'toggle-auto-update'),'Reproduced missing native control without update information');
add_filter('all_plugins','ChurchWebsiteConnector\\Updates\\supported_plugin');
$html=connector_plugin_row();
updater_check(str_contains($html,'Disable auto-updates')&&str_contains($html,'data-wp-action="disable"'),'Native plugin row offers disabling before a successful GitHub check');
updater_check(empty(get_site_transient('update_plugins')->response)&&empty(get_site_transient('update_plugins')->no_update),'Displaying the control does not invent release offers');
update_site_option('auto_update_plugins',['other/keep.php']);$html=connector_plugin_row();
updater_check(str_contains($html,'Enable auto-updates')&&str_contains($html,'data-wp-action="enable"'),'Native plugin row offers enabling after preference is disabled');
updater_check(get_site_option('auto_update_plugins')===['other/keep.php'],'Rendering never restores default or changes another plugin preference');
$force_off=static fn($decision,$item)=>($item->plugin??'')===$plugin?false:$decision;add_filter('auto_update_plugin',$force_off,20,2);$html=connector_plugin_row();
updater_check(!str_contains($html,'toggle-auto-update')&&str_contains($html,'Auto-updates disabled'),'Native per-plugin policy still controls availability');remove_filter('auto_update_plugin',$force_off,20);
update_site_option('auto_update_plugins',$auto_before);

updater_check( get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['UpdateURI'] === U\REPOSITORY, 'Plugin header selects exact GitHub update source' );
delete_site_transient( 'update_plugins' ); wp_update_plugins();
$updates = get_site_transient( 'update_plugins' );
updater_check( isset( $updates->response[ $plugin ] ) && $updates->response[ $plugin ]->new_version === '__FUTURE_VERSION__', 'Core update check discovers a newer published release' );
$offer = $updates->response[ $plugin ];
updater_check( $offer->package === U\asset_url( '__FUTURE_VERSION__', 'zip' ) && $offer->requires_php === '8.3' && $offer->requires === '7.0', 'Core receives exact package and compatibility requirements' );
updater_check( ! isset( $offer->autoupdate ), 'Offer does not force automatic updates' );
updater_check( get_option( ChurchWebsiteConnector\SETTINGS ) === $settings_before && get_site_option( 'auto_update_plugins', [] ) === $auto_before, 'Update discovery preserves settings and automatic update preferences' );
// Exercise the immediate check with real WordPress permissions, nonces and transients.
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['_wpnonce'] = wp_create_nonce( 'church_connector_check_updates' );
$updates->response['other/other.php'] = (object) [ 'new_version' => '9.0' ];
$updates->translations = [ [ 'slug' => 'other', 'language' => 'en_GB' ] ];
set_site_transient( 'update_plugins', $updates );
$unchanged_last_checked = $updates->last_checked;
set_transient( U\CACHE . 'latest', [], 12 * HOUR_IN_SECONDS );
$request_count = count( $requests );
$manual = U\check_now();
updater_check( $manual === [ 'status' => 'available', 'version' => '__FUTURE_VERSION__' ] && count( $requests ) === $request_count + 2, 'Manual nonce-protected check bypasses cache and recent core check' );
$updates = get_site_transient( 'update_plugins' );
updater_check( $updates->response[$plugin]->new_version === '__FUTURE_VERSION__' && $updates->response[$plugin]->package === $offer->package, 'Manual refresh offers exact verified release in native WordPress updates' );
updater_check( $updates->response['other/other.php']->new_version === '9.0' && $updates->translations === [ [ 'slug' => 'other', 'language' => 'en_GB' ] ] && $updates->last_checked === $unchanged_last_checked, 'Unrelated plugin information and schedule survive manual check' );
updater_check( get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['Version'] === '__CURRENT_VERSION__' && get_option( ChurchWebsiteConnector\SETTINGS ) === $settings_before && get_site_option( 'auto_update_plugins', [] ) === $auto_before, 'Manual check does not install, save settings or alter update policy' );
$_POST['_wpnonce'] = 'wrong'; $request_count = count( $requests );
updater_check( U\check_now()->get_error_code() === 'church_connector_update_nonce' && count( $requests ) === $request_count, 'Invalid native nonce denied before HTTP' );
$_POST['_wpnonce'] = wp_create_nonce( 'church_connector_check_updates' ); $_SERVER['REQUEST_METHOD'] = 'GET';
updater_check( U\check_now()->get_error_code() === 'church_connector_update_request', 'GET denied even with valid native nonce' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$editor = wp_insert_user( [ 'user_login' => 'updater-editor', 'user_pass' => 'local-fixture-only', 'role' => 'editor' ] );
wp_set_current_user( $editor );
updater_check( U\check_now()->get_error_code() === 'church_connector_update_denied', 'Editor cannot check updates' );
ob_start(); U\render_notice(); U\render_check(); $hidden = ob_get_clean();
updater_check( $hidden === '', 'Update control hidden from users without update permissions' );
wp_set_current_user( 1 ); $_POST['_wpnonce'] = wp_create_nonce( 'church_connector_check_updates' );
set_transient( 'church_connector_update_notice_1', $manual, 120 );
ob_start(); U\render_notice(); U\render_check(); $html = ob_get_clean();
updater_check( str_contains( $html, 'Check for updates now' ) && str_contains( $html, 'name="_wpnonce"' ) && str_contains( $html, 'method="post"' ) && str_contains( $html, 'Version __FUTURE_VERSION__ is available' ), 'Settings control renders native POST, nonce and available result' );
updater_check( get_transient( 'church_connector_update_notice_1' ) === false, 'Result notice consumed once for current user' );
// Reproduce the refreshed settings page for every update-check outcome.
foreach ( [
    'available' => [ 'notice-success', 'Version __FUTURE_VERSION__ is available.' ],
    'current' => [ 'notice-success', 'You are up to date.' ],
    'ahead' => [ 'notice-success', 'Your installed connector is newer' ],
    'incompatible' => [ 'notice-warning', 'it requires a newer WordPress or PHP version' ],
    'failed' => [ 'notice-error', 'The update check failed.' ],
    'offer_not_saved' => [ 'notice-warning', 'WordPress could not retain the matching result' ],
] as $notice_status => [ $notice_type, $notice_text ] ) {
    set_transient( 'church_connector_update_notice_1', [ 'status' => $notice_status, 'version' => '__FUTURE_VERSION__' ], 120 );
    ob_start(); ChurchWebsiteConnector\admin_page(); $page = ob_get_clean();
    $heading_end = strpos( $page, '</h1>' ); $result_at = strpos( $page, $notice_text ); $intro_at = strpos( $page, 'Connect your assistant to this website' );
    updater_check( $heading_end !== false && $result_at !== false && $intro_at !== false && $heading_end < $result_at && $result_at < $intro_at && str_contains( substr($page,$heading_end,$intro_at-$heading_end), $notice_type ), 'Refreshed page shows ' . $notice_status . ' notice immediately after heading' );
    updater_check( substr_count($page,$notice_text) === 1 && strpos($page,'id="cwc-update-check"') < strpos($page,'Check for updates now') && get_transient('church_connector_update_notice_1') === false, 'Result shown once at top; button remains under Automatic updates' );
}
$http_failure = static function( $pre, $args, $url ) { return $url === U\API . 'latest' ? new WP_Error('offline','Fixture outage') : $pre; };
add_filter( 'pre_http_request', $http_failure, 20, 3 );
$before_failure = serialize( get_site_transient('update_plugins') );
updater_check( is_wp_error( U\check_now() ) && serialize( get_site_transient('update_plugins') ) === $before_failure, 'GitHub failure preserves cached WordPress offers' );
$old_updates = get_site_transient( 'update_plugins' );
$expired = clone $old_updates;
$expired->last_checked = 0;
set_site_transient( 'update_plugins', $expired );
wp_update_plugins();
updater_check( ( get_site_transient( 'update_plugins' )->response[$plugin]->new_version ?? null ) === '__FUTURE_VERSION__', 'Native scheduled refresh retains a recently verified connector offer during a GitHub outage' );
$expired_history = get_option( U\CHECK_STATUS );
$expired_history['last_success'] = time() - 25 * HOUR_IN_SECONDS;
update_option( U\CHECK_STATUS, $expired_history );
updater_check( false === U\update_offer( false, array( 'UpdateURI' => U\REPOSITORY ), $plugin, array() ), 'An outage cannot continue offering a result more than 24 hours old' );
set_site_transient( 'update_plugins', $old_updates );
remove_filter( 'pre_http_request', $http_failure, 20 );
$omit_offer = static function ( $updates ) use ( $plugin ) {
    unset( $updates->response[ $plugin ] );
    return $updates;
};
add_filter( 'pre_set_site_transient_update_plugins', $omit_offer );
updater_check( 'offer_not_saved' === U\check_now()['status'] && ! isset( get_site_transient( 'update_plugins' )->response[$plugin] ), 'A checked release cannot report a retained WordPress offer when another update manager removed it' );
remove_filter( 'pre_set_site_transient_update_plugins', $omit_offer );
$wrong_url = static function ( $updates ) use ( $plugin ) {
    $updates->response[ $plugin ]->package = U\asset_url( '__CURRENT_VERSION__', 'zip' );
    return $updates;
};
add_filter( 'pre_set_site_transient_update_plugins', $wrong_url );
updater_check( 'offer_not_saved' === U\check_now()['status'], 'A genuine official package for a different version is refused during the update check' );
$installer = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
$download_guard = null;
$observe_download = static function ( $value ) use ( &$download_guard ) { $download_guard = $value; return $value; };
add_filter( 'upgrader_pre_download', $observe_download, PHP_INT_MAX );
$wrong_result = $installer->upgrade( $plugin, [ 'clear_update_cache' => false ] );
remove_filter( 'upgrader_pre_download', $observe_download, PHP_INT_MAX );
updater_check( true !== $wrong_result && is_wp_error( $download_guard ) && 'church_connector_package_release' === $download_guard->get_error_code() && '__CURRENT_VERSION__' === get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['Version'], 'The native upgrader refuses an older official ZIP before touching the installed plugin' );
remove_filter( 'pre_set_site_transient_update_plugins', $wrong_url );
U\check_now();
// Cached exact-version data cannot conceal a changed or incompatible latest release.
$metadata['requires_php'] = '99.0';
updater_check( U\check_now()['status'] === 'incompatible' && get_site_transient('update_plugins')->response[$plugin]->disable_autoupdate, 'Manual check re-fetches metadata and reports incompatible update' );
$metadata['requires_php'] = '8.3';
U\check_now();
$updates = get_site_transient('update_plugins'); unset( $updates->response['other/other.php'] ); $updates->translations = []; set_site_transient('update_plugins',$updates);
$details = plugins_api( 'plugin_information', (object) [ 'slug' => U\SLUG ] );
updater_check( ! is_wp_error( $details ) && $details->version === '__FUTURE_VERSION__', 'Native version details use GitHub release metadata' );
update_site_option( 'auto_update_plugins', array_values( array_diff( (array) $auto_before, [ $plugin ] ) ) );
$automatic = new WP_Automatic_Updater();
updater_check( $automatic->should_update( 'plugin', $offer, WP_PLUGIN_DIR ) === false, 'Core refuses automatic installation without administrator opt-in' );
update_site_option( 'auto_update_plugins', array_values( array_unique( array_merge( (array) $auto_before, [ $plugin ] ) ) ) );
updater_check( $automatic->should_update( 'plugin', $offer, WP_PLUGIN_DIR ) === true, 'Core permits automatic installation after administrator opt-in' );
$unsupported = clone $offer; $unsupported->requires_php = '99.0';
updater_check( $automatic->should_update( 'plugin', $unsupported, WP_PLUGIN_DIR ) === false, 'Core blocks incompatible PHP requirements' );
$metadata['requires'] = '99.0';
delete_transient( U\CACHE . 'latest' ); delete_transient( U\CACHE . '__FUTURE_VERSION__' );
$unsupported = (object) U\update_offer( false, [ 'UpdateURI' => U\REPOSITORY ], $plugin, [] ); $unsupported->plugin = $plugin;
updater_check( $automatic->should_update( 'plugin', $unsupported, WP_PLUGIN_DIR ) === false, 'Updater disables automatic installation for incompatible WordPress requirements' );
updater_check( U\verified_download( false, $offer->package, null, [] )->get_error_code() === 'church_connector_package_requirements', 'Incompatible release is also blocked before download' );
$metadata['requires'] = '7.0';
delete_transient( U\CACHE . 'latest' ); delete_transient( U\CACHE . '__FUTURE_VERSION__' );
$tampered = true;
$bad = apply_filters( 'upgrader_pre_download', false, $offer->package, null, [ 'plugin' => $plugin ] );
updater_check( is_wp_error( $bad ) && $bad->get_error_code() === 'church_connector_package_integrity', 'Native download hook rejects changed package bytes' );
updater_check( get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['Version'] === '__CURRENT_VERSION__', 'Rejected package leaves installed version intact' );
$tampered = false;
$mirror = 'https://mirror.example/cache/connector.zip';
updater_check( $zip === U\verified_download( $zip, $mirror, null, array( 'plugin' => $plugin ) ), 'A mirrored connector update can use a previously downloaded file matching the official release hash' );
$bad_mirror = wp_tempnam( 'connector-mirror-test' );
file_put_contents( $bad_mirror, 'incomplete fixture' );
updater_check( 'church_connector_package_integrity' === U\verified_download( $bad_mirror, $mirror, null, array( 'plugin' => $plugin ) )->get_error_code() && is_file( $bad_mirror ), 'An altered package from an update cache is refused and caller-owned files are preserved' );
updater_check( $bad_mirror === U\verified_download( $bad_mirror, $mirror, null, array( 'plugin' => 'other/other.php' ) ), 'An unrelated mirrored plugin remains the responsibility of its own updater' );
updater_check( $zip === U\verified_download( false, $zip, null, array( 'plugin' => $plugin ) ), 'A directly supplied local package path from a managed update cache gets the same checksum verification' );
$late_mirror = static fn() => $bad_mirror;
add_filter( 'upgrader_pre_download', $late_mirror, 98 );
$filtered = apply_filters( 'upgrader_pre_download', false, $mirror, null, array( 'plugin' => $plugin ) );
remove_filter( 'upgrader_pre_download', $late_mirror, 98 );
updater_check( is_wp_error( $filtered ) && 'church_connector_package_integrity' === $filtered->get_error_code(), 'A later-priority download manager still passes through the final checksum check' );
wp_delete_file( $bad_mirror );
$wrong_header = static function ( $source ) {
    $main = trailingslashit( $source ) . U\SLUG . '.php';
    file_put_contents( $main, str_replace( 'Version: __FUTURE_VERSION__', 'Version: __CURRENT_VERSION__', file_get_contents( $main ) ) );
    return $source;
};
add_filter( 'upgrader_source_selection', $wrong_header, 20 );
$source_guard = null;
$observe_source = static function ( $value ) use ( &$source_guard ) { $source_guard = $value; return $value; };
add_filter( 'upgrader_source_selection', $observe_source, PHP_INT_MAX );
$header_result = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $plugin, [ 'clear_update_cache' => false ] );
remove_filter( 'upgrader_source_selection', $wrong_header, 20 );
remove_filter( 'upgrader_source_selection', $observe_source, PHP_INT_MAX );
updater_check( true !== $header_result && is_wp_error( $source_guard ) && 'church_connector_package_version' === $source_guard->get_error_code() && '__CURRENT_VERSION__' === get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['Version'], 'A verified ZIP with the wrong unpacked header is refused before the installed plugin is replaced' );
$skin = new Automatic_Upgrader_Skin();
$upgrader = new Plugin_Upgrader( $skin );
$result = $upgrader->upgrade( $plugin, [ 'clear_update_cache' => false ] );
updater_check( $result === true, 'Native WordPress upgrader installs the verified synthetic update' );
updater_check( ! is_file( WP_PLUGIN_DIR . '/generatepress-ai/generatepress-ai.php' ), 'Unattended connector upgrade never installs GeneratePress AI' );
updater_check( get_option( 'church_connector_generatepress_setup', false ) === false && get_option( 'church_connector_generatepress_pair', false ) === false, 'Unattended upgrade creates no installer job or licence authorisation' );
updater_check( ! in_array( 'generatepress-ai/generatepress-ai.php', (array) get_site_option( 'auto_update_plugins', [] ), true ), 'Unattended upgrade does not opt an absent GeneratePress AI installation into updates' );
wp_clean_plugins_cache( false );
updater_check( get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin )['Version'] === '__FUTURE_VERSION__', 'Installed plugin header contains the new version' );
updater_check( get_option( ChurchWebsiteConnector\SETTINGS ) === $settings_before, 'Native upgrade preserves saved connection and feature choices' );
updater_check( wp_roles()->roles === $roles_before && get_option( ChurchWebsiteConnector\UsedCss\GRANTEE ) === $grants_before, 'Native upgrade preserves roles and recorded grants' );
updater_check( get_option( 'active_plugins' ) === $active_before, 'Native upgrade preserves active plugin list' );
updater_check( in_array( $plugin, (array) get_site_option( 'auto_update_plugins', [] ), true ), 'Native upgrade preserves administrator auto-update opt-in' );
update_site_option( 'auto_update_plugins', $auto_before );
remove_filter( 'pre_http_request', $mock, 10 );
file_put_contents( '/reports/github-updates.json', wp_json_encode( [ 'checks' => $checks, 'source' => U\REPOSITORY, 'synthetic_future_version' => '__FUTURE_VERSION__', 'native_core_discovery' => true, 'native_auto_update_control_without_release_data' => true, 'existing_policy_and_preferences_preserved' => true, 'immediate_check' => true, 'immediate_check_only' => true, 'result_notice_at_top' => true, 'all_result_notice_types_verified' => true, 'immediate_failure_preserves_offers' => true, 'native_upgrade_completed' => true, 'tampered_package_rejected' => true, 'settings_and_grants_preserved' => true, 'administrator_opt_in_respected' => true, 'live_websites_contacted' => false ] ) );
echo 'WORDPRESS GITHUB UPDATER CHECKS PASSED ' . $checks;
