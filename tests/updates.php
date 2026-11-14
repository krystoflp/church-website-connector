<?php
/** Isolated updater boundary tests; no network requests or live websites. */
declare(strict_types=1);
namespace ChurchWebsiteConnector { const VERSION = '1.5.0'; }
namespace {
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['wp_version'] = '7.0.8';
class WP_Error { public function __construct( public $code, public $message ) {} public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function add_action( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['actions'][$name] = [ $callback, $args ]; }
function current_user_can( $cap ) { return $GLOBALS['caps'][$cap] ?? false; }
function __( $message, $domain = '' ) { return $message; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'test-nonce' && $action === 'church_connector_check_updates'; }
function delete_transient( $key ) { unset( $GLOBALS['cache'][$key] ); }
function get_site_transient( $key ) { return isset( $GLOBALS['site_cache'][$key] ) ? clone $GLOBALS['site_cache'][$key] : false; }
function set_site_transient( $key, $value ) { $GLOBALS['site_cache'][$key] = clone $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function plugin_basename( $path ) { return basename( dirname( $path ) ) . '/' . basename( $path ); }
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['filters'][$name] = [ $callback, $args ]; }
function get_transient( $key ) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['cache'][$key] = $value; $GLOBALS['ttls'][$key] = $ttl; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_safe_remote_get( $url, $args ) {
    $GLOBALS['requests'][] = [ $url, $args ];
    return $GLOBALS['responses'][$url] ?? new WP_Error( 'network', 'Unavailable' );
}
function wp_delete_file( $file ) { unlink( $file ); }
function download_url( $url ) {
    $GLOBALS['downloads'][] = $url;
    if ( $GLOBALS['download_error'] ) { return new WP_Error( 'download', 'Unavailable' ); }
    $file = tempnam( sys_get_temp_dir(), 'cwc-test-' );
    file_put_contents( $file, $GLOBALS['download_bytes'] );
    $GLOBALS['temporary'][] = $file;
    return $file;
}
$module = dirname( __DIR__ ) . '/church-website-connector/includes/updates.php';
require is_file( $module ) ? $module : dirname( __DIR__ ) . '/includes/updates.php';
use ChurchWebsiteConnector\Updates as U;
$checks = 0;
function check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( 'UPDATER CHECK FAILED: ' . $message ); } }
function response( $data ) { return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $data ) ]; }
function fixture( $version = '1.5.1' ) {
    $metadata = [ 'version' => $version, 'requires' => '7.0', 'requires_php' => '8.3', 'tested' => '7.0.8', 'sha256' => hash( 'sha256', 'tested-package' ) ];
    $release = [ 'tag_name' => 'v' . $version, 'draft' => false, 'prerelease' => false, 'published_at' => '2026-11-13T12:00:00Z', 'html_url' => U\REPOSITORY . '/releases/tag/v' . $version, 'body' => '<script>unsafe</script> Release notes', 'assets' => [] ];
    foreach ( [ 'zip', 'json' ] as $extension ) {
        $release['assets'][] = [ 'name' => U\SLUG . '-' . $version . '.' . $extension, 'state' => 'uploaded', 'browser_download_url' => U\asset_url( $version, $extension ) ];
    }
    $release['assets'][0]['digest'] = 'sha256:' . $metadata['sha256'];
    $GLOBALS['cache'] = []; $GLOBALS['ttls'] = []; $GLOBALS['requests'] = []; $GLOBALS['downloads'] = []; $GLOBALS['download_error'] = false; $GLOBALS['download_bytes'] = 'tested-package';
    $GLOBALS['responses'] = [ U\API . 'latest' => response( $release ), U\API . 'tags/v' . $version => response( $release ), U\asset_url( $version, 'json' ) => response( $metadata ) ];
    return [ $release, $metadata ];
}
check( ! isset( $GLOBALS['filters']['auto_update_plugin'] ), 'No forced auto-update preference' );
check( $GLOBALS['filters']['update_plugins_github.com'][1] === 4 && $GLOBALS['filters']['upgrader_pre_download'][1] === 4, 'Native update and download hooks registered' );
fixture();
$offer = U\update_offer( false, [ 'UpdateURI' => U\REPOSITORY ], U\plugin_file(), [] );
check( $offer['version'] === '1.5.1' && $offer['package'] === U\asset_url( '1.5.1', 'zip' ), 'Published release becomes a native update offer' );
check( $offer['requires'] === '7.0' && $offer['requires_php'] === '8.3' && $offer['tested'] === '7.0.8', 'Compatibility metadata is preserved' );
check( ! isset( $offer['autoupdate'] ) && $offer['disable_autoupdate'] === false, 'Core opt-in controls compatible automatic installation' );
check( count( $GLOBALS['requests'] ) === 2, 'One release lookup and one metadata lookup' );
U\release(); U\release( '1.5.1' );
check( count( $GLOBALS['requests'] ) === 2, 'Success cache shared by latest and exact release downloads' );
check( $GLOBALS['ttls'][ U\CACHE . 'latest' ] === 43200, 'Twelve-hour success cache' );
foreach ( $GLOBALS['requests'] as [ $url, $args ] ) {
    check( str_starts_with( $url, U\API ) || $url === U\asset_url( '1.5.1', 'json' ), 'Only pinned GitHub addresses requested' );
    check( ! isset( $args['headers']['Authorization'] ) && ! str_contains( json_encode( $args ), 'site_url' ), 'No credentials or website identity in requests' );
    check( $args['timeout'] === 10 && $args['limit_response_size'] === 262144, 'Remote fetch is bounded' );
}
check( ! U\compatible( ['requires'=>'99.0','requires_php'=>'8.3'] ), 'Incompatible WordPress requirement rejected' );
check( ! U\compatible( ['requires'=>'7.0','requires_php'=>'99.0'] ), 'Incompatible PHP requirement rejected' );
$info = U\plugin_information( false, 'plugin_information', (object) [ 'slug' => U\SLUG ] );
check( ! str_contains( $info->sections['changelog'], '<script>' ) && str_contains( $info->sections['changelog'], '&lt;script&gt;' ), 'Release notes escaped before display' );
check( U\plugin_information( 'keep', 'query_plugins', (object) [ 'slug' => U\SLUG ] ) === 'keep', 'Other information queries left alone' );
check( U\update_offer( 'keep', [ 'UpdateURI' => U\REPOSITORY ], 'other/other.php', [] ) === 'keep', 'Other plugins left alone' );
check( U\update_offer( 'keep', [ 'UpdateURI' => 'https://other.example' ], U\plugin_file(), [] ) === 'keep', 'Mismatched update identity left alone' );
foreach ( [ '1.5.1-beta', 'v1.5.1', '1.5', '01.5.1', '../1.5.1', '1.5.1+build', '', 151 ] as $invalid ) { check( ! U\stable_version( $invalid ), 'Non-stable version rejected' ); }
foreach ( [
    'draft' => function( &$r, &$m ) { $r['draft'] = true; },
    'prerelease' => function( &$r, &$m ) { $r['prerelease'] = true; },
    'unpublished' => function( &$r, &$m ) { unset( $r['published_at'] ); },
    'invalid time' => function( &$r, &$m ) { $r['published_at'] = 'not a date'; },
    'beta tag' => function( &$r, &$m ) { $r['tag_name'] = 'v1.5.1-beta'; },
    'other owner' => function( &$r, &$m ) { $r['html_url'] = 'https://github.com/other/church-website-connector/releases/tag/v1.5.1'; },
    'missing zip' => function( &$r, &$m ) { unset( $r['assets'][0] ); },
    'missing metadata' => function( &$r, &$m ) { unset( $r['assets'][1] ); },
    'duplicate asset' => function( &$r, &$m ) { $r['assets'][] = $r['assets'][0]; },
    'incomplete asset' => function( &$r, &$m ) { $r['assets'][0]['state'] = 'new'; },
    'external package' => function( &$r, &$m ) { $r['assets'][0]['browser_download_url'] = 'https://outside.example/update.zip'; },
    'wrong metadata version' => function( &$r, &$m ) { $m['version'] = '1.5.2'; },
    'missing checksum' => function( &$r, &$m ) { unset( $m['sha256'] ); },
    'invalid checksum' => function( &$r, &$m ) { $m['sha256'] = str_repeat( 'z', 64 ); },
    'digest mismatch' => function( &$r, &$m ) { $r['assets'][0]['digest'] = 'sha256:' . str_repeat( '0', 64 ); },
    'invalid PHP requirement' => function( &$r, &$m ) { $m['requires_php'] = '<script>'; },
    'missing WordPress requirement' => function( &$r, &$m ) { unset( $m['requires'] ); },
    'invalid tested version' => function( &$r, &$m ) { $m['tested'] = []; },
] as $name => $mutate ) {
    [ $release, $metadata ] = fixture(); $mutate( $release, $metadata );
    $GLOBALS['responses'][ U\API . 'latest' ] = response( $release );
    $GLOBALS['responses'][ U\asset_url( '1.5.1', 'json' ) ] = response( $metadata );
    check( U\release() === [], $name . ' rejected' );
}
fixture(); $GLOBALS['responses'][ U\API . 'latest' ] = new WP_Error( 'network', 'No connection' );
check( U\release() === [] && U\release() === [] && count( $GLOBALS['requests'] ) === 1, 'Outage fails closed with cached failure' );
check( $GLOBALS['ttls'][ U\CACHE . 'latest' ] === 900, 'Failures retried after fifteen minutes' );
check( is_wp_error( U\plugin_information( false, 'plugin_information', (object) [ 'slug' => U\SLUG ] ) ), 'Unavailable details return a native error' );
fixture(); $GLOBALS['responses'][ U\API . 'latest' ]['body'] = 'invalid JSON'; check( U\release() === [], 'Malformed response rejected' );
fixture(); $GLOBALS['responses'][ U\API . 'latest' ]['response']['code'] = 429; check( U\release() === [], 'Rate limiting handled without an update offer' );
fixture();
$file = U\verified_download( false, U\asset_url( '1.5.1', 'zip' ), null, [] );
check( is_string( $file ) && is_file( $file ), 'Correct downloaded bytes accepted' );
unlink( $file );
$GLOBALS['download_bytes'] = 'tampered';
check( is_wp_error( U\verified_download( false, U\asset_url( '1.5.1', 'zip' ), null, [] ) ), 'Tampered download rejected' );
check( ! is_file( end( $GLOBALS['temporary'] ) ), 'Failed temporary package removed' );
$GLOBALS['download_error'] = true;
check( U\verified_download( false, U\asset_url( '1.5.1', 'zip' ), null, [] )->code === 'download', 'Download errors preserved' );
$prior = new WP_Error( 'prior', 'Existing failure' );
check( U\verified_download( $prior, U\asset_url( '1.5.1', 'zip' ), null, [] ) === $prior, 'Existing updater errors retained' );
check( U\verified_download( 'keep', 'https://another.example/plugin.zip', null, [] ) === 'keep', 'Other package downloads unaffected' );
check( is_wp_error( U\verified_download( false, U\REPOSITORY . '/releases/download/v1.5.1/other.zip', null, [] ) ), 'Unexpected package name rejected' );
fixture();
check( U\release( '../bad' ) === [] && count( $GLOBALS['requests'] ) === 0, 'Invalid requested version cannot generate an endpoint' );
$wrong = fixture( '1.5.2' )[0]; $GLOBALS['responses'][ U\API . 'tags/v1.5.1' ] = response( $wrong );
check( U\release( '1.5.1' ) === [], 'Exact-tag download cannot substitute another release' );
$borrowed = tempnam( sys_get_temp_dir(), 'cwc-borrowed-' ); file_put_contents( $borrowed, 'tampered' ); fixture();
check( is_wp_error( U\verified_download( $borrowed, U\asset_url( '1.5.1', 'zip' ), null, [] ) ), 'Packages returned by another filter are still verified' );
check( is_file( $borrowed ), 'A file supplied by another filter is not deleted' ); unlink( $borrowed );
check( isset( $GLOBALS['actions']['admin_post_church_connector_check_updates'] ) && ! isset( $GLOBALS['actions']['admin_post_nopriv_church_connector_check_updates'] ), 'Update check uses authenticated admin-post only' );
$GLOBALS['caps'] = [ 'manage_options' => true, 'update_plugins' => true ];
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['_wpnonce'] = 'test-nonce';
$other = (object) [ 'new_version' => '9.0' ];
$original_updates = (object) [ 'last_checked' => time(), 'response' => [ 'other/other.php' => $other ], 'no_update' => [ 'current/current.php' => $other ], 'checked' => [ 'other/other.php' => '8.0' ], 'translations' => [ 'keep' ] ];
$GLOBALS['site_cache'] = [ 'update_plugins' => clone $original_updates ];
fixture();
$GLOBALS['cache'][ U\CACHE . 'latest' ] = [ 'version' => '1.5.0' ];
$result = U\check_now();
check( $result === [ 'status' => 'available', 'version' => '1.5.1' ] && count( $GLOBALS['requests'] ) === 2, 'Manual check bypasses a successful twelve-hour cache' );
$updates = get_site_transient( 'update_plugins' ); $file = U\plugin_file();
check( $updates->response[$file]->new_version === '1.5.1' && $updates->response[$file]->plugin === $file, 'Manual check populates native plugin update entry' );
check( $updates->last_checked === $original_updates->last_checked && $updates->response['other/other.php'] === $other && $updates->no_update['current/current.php'] === $other && $updates->translations === ['keep'], 'Manual check preserves unrelated updates, translations and scheduling' );
check( $GLOBALS['downloads'] === [] && ! isset( $updates->response[$file]->autoupdate ), 'Check never downloads or forces automatic installation' );
fixture(); $GLOBALS['cache'][ U\CACHE . 'latest' ] = [];
check( U\check_now()['status'] === 'available' && count( $GLOBALS['requests'] ) === 2, 'Manual check bypasses failed fifteen-minute cache too' );
foreach ( [ '1.5.0' => 'current', '1.4.9' => 'ahead' ] as $version => $status ) {
    fixture( $version );
    check( U\check_now()['status'] === $status, 'Manual check reports ' . $status );
    $updates = get_site_transient( 'update_plugins' );
    check( ! isset( $updates->response[$file] ) && $updates->no_update[$file]->new_version === $version, 'Equal or older release clears stale connector offer without downgrade' );
}
[ $r, $m ] = fixture(); $m['requires_php'] = '99.0'; $GLOBALS['responses'][U\asset_url('1.5.1','json')] = response($m);
check( U\check_now()['status'] === 'incompatible' && get_site_transient('update_plugins')->response[$file]->disable_autoupdate === true, 'Manual check reports and blocks incompatible automatic update' );
$before = serialize( get_site_transient('update_plugins') );
fixture(); $GLOBALS['responses'][U\API.'latest'] = new WP_Error('network','Offline');
check( is_wp_error( U\check_now() ) && serialize( get_site_transient('update_plugins') ) === $before, 'Failed check preserves all previous WordPress update information' );
foreach ( [ 'manage_options', 'update_plugins' ] as $cap ) {
    fixture(); $GLOBALS['caps'][$cap] = false;
    check( U\check_now()->code === 'church_connector_update_denied' && $GLOBALS['requests'] === [], 'Missing ' . $cap . ' denied before network or cache mutation' );
    $GLOBALS['caps'][$cap] = true;
}
foreach ( [ '', 'wrong', ['test-nonce'] ] as $nonce ) {
    fixture(); $_POST['_wpnonce'] = $nonce;
    check( U\check_now()->code === 'church_connector_update_nonce' && $GLOBALS['requests'] === [], 'Missing, invalid or array nonce denied without side effects' );
}
$_POST['_wpnonce'] = 'test-nonce'; $_SERVER['REQUEST_METHOD'] = 'GET'; fixture();
check( U\check_now()->code === 'church_connector_update_request' && $GLOBALS['requests'] === [], 'GET cannot trigger update check' );
$_SERVER['REQUEST_METHOD'] = 'POST'; fixture(); $GLOBALS['site_cache'] = [];
check( U\check_now()['status'] === 'available' && get_site_transient('update_plugins')->last_checked === 0, 'Missing native cache is created without postponing other plugin checks' );
echo 'GitHub updater regression checks passed (' . $checks . ' checks)' . PHP_EOL;
}
