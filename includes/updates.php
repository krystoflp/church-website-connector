<?php
/** GitHub releases integrated with WordPress's native, administrator-controlled updater. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Updates;

defined( 'ABSPATH' ) || exit;

const REPOSITORY    = 'https://github.com/krystoflp/church-website-connector';
const API           = 'https://api.github.com/repos/krystoflp/church-website-connector/releases/';
const SLUG          = 'church-website-connector';
const CACHE         = 'church_connector_release_';
const CHECK_STATUS  = 'church_connector_update_status';
const CACHE_HOURS   = 12;
const RETRY_MINUTES = 15;

function plugin_file(): string {
	return plugin_basename( dirname( __DIR__ ) . '/church-website-connector.php' );
}

function stable_version( $version ): bool {
	return is_string( $version ) && preg_match( '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $version ) === 1 && strlen( $version ) <= 32;
}

function asset_url( string $version, string $extension ): string {
	return REPOSITORY . '/releases/download/v' . $version . '/' . SLUG . '-' . $version . '.' . $extension;
}

/** Only these fixed GitHub endpoints are contacted; no site identity or credentials are sent. */
function fetch_json( string $url, bool $api = false ) {
	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => 10,
			'redirection'         => $api ? 0 : 3,
			'limit_response_size' => 262144,
			'user-agent'          => 'ChurchWebsiteConnector/' . \ChurchWebsiteConnector\VERSION,
			'headers'             => array( 'Accept' => $api ? 'application/vnd.github+json' : 'application/json' ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( 429 === $status || $status >= 500 ) {
		return new \WP_Error( 'church_connector_release_transport', 'The release service is temporarily unavailable.' );
	}
	if ( 200 !== $status ) {
		return false;
	}
	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $decoded ) ? $decoded : false;
}

function release_failure( string $version, $cause = false ): array {
	if ( '' === $version ) {
		if ( is_wp_error( $cause ) ) {
			set_transient( CACHE . 'latest_unavailable', true, RETRY_MINUTES * MINUTE_IN_SECONDS );
		} else {
			delete_transient( CACHE . 'last_verified' );
		}
	}
	return array();
}

function release( string $version = '' ): array {
	if ( '' !== $version && ! stable_version( $version ) ) {
		return array();
	}
	$key    = CACHE . ( '' === $version ? 'latest' : $version );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	if ( '' === $version ) {
		record_check( false );
		delete_transient( CACHE . 'latest_unavailable' );
	}
	// Cache failures briefly too; a GitHub outage must not repeatedly delay requests.
	set_transient( $key, array(), RETRY_MINUTES * MINUTE_IN_SECONDS );
	$data = fetch_json( API . ( '' === $version ? 'latest' : 'tags/v' . $version ), true );
	if ( ! is_array( $data ) || ( $data['draft'] ?? true ) !== false || ( $data['prerelease'] ?? true ) !== false
		|| ! is_string( $data['tag_name'] ?? null ) || ! str_starts_with( $data['tag_name'], 'v' )
		|| ! is_string( $data['published_at'] ?? null ) || strtotime( $data['published_at'] ) === false ) {
		return release_failure( $version, $data );
	}
	$found_version = substr( $data['tag_name'], 1 );
	if ( ! stable_version( $found_version ) || ( '' !== $version && $version !== $found_version )
		|| ( $data['html_url'] ?? '' ) !== REPOSITORY . '/releases/tag/v' . $found_version ) {
		return release_failure( $version );
	}
	$assets = array();
	foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
		if ( ! is_array( $asset ) ) {
			continue;
		}
		foreach ( array( 'zip', 'json' ) as $extension ) {
			if ( ( $asset['name'] ?? '' ) === SLUG . '-' . $found_version . '.' . $extension ) {
				// Duplicate, incomplete or redirected-to-another-repository assets are rejected.
				if ( isset( $assets[ $extension ] ) || ( $asset['state'] ?? '' ) !== 'uploaded'
					|| ( $asset['browser_download_url'] ?? '' ) !== asset_url( $found_version, $extension ) ) {
					return release_failure( $version );
				}
				$assets[ $extension ] = $asset;
			}
		}
	}
	if ( ! isset( $assets['zip'], $assets['json'] ) ) {
		return release_failure( $version );
	}
	$metadata = fetch_json( asset_url( $found_version, 'json' ) );
	if ( ! is_array( $metadata ) || ( $metadata['version'] ?? '' ) !== $found_version
		|| ! is_string( $metadata['sha256'] ?? null ) || preg_match( '/\A[a-f0-9]{64}\z/', $metadata['sha256'] ) !== 1 ) {
		return release_failure( $version, $metadata );
	}
	foreach ( array( 'requires', 'requires_php', 'tested' ) as $field ) {
		if ( ! is_string( $metadata[ $field ] ?? null ) || preg_match( '/\A[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/', $metadata[ $field ] ) !== 1 ) {
			return release_failure( $version );
		}
	}
	// Recent GitHub uploads also supply a digest; require agreement when supplied.
	$github_digest = $assets['zip']['digest'] ?? null;
	if ( null !== $github_digest && 'sha256:' . $metadata['sha256'] !== $github_digest ) {
		return release_failure( $version );
	}
	$result = array(
		'version'      => $found_version,
		'requires'     => $metadata['requires'],
		'requires_php' => $metadata['requires_php'],
		'tested'       => $metadata['tested'],
		'sha256'       => $metadata['sha256'],
		'package'      => asset_url( $found_version, 'zip' ),
		'url'          => $data['html_url'],
		'published_at' => $data['published_at'],
		'notes'        => is_string( $data['body'] ?? null ) ? substr( $data['body'], 0, 60000 ) : '',
	);
	if ( '' === $version ) {
		record_check( true, $found_version );
		set_transient( CACHE . 'last_verified', $result, 24 * HOUR_IN_SECONDS );
	}
	set_transient( CACHE . $found_version, $result, CACHE_HOURS * HOUR_IN_SECONDS );
	set_transient( $key, $result, CACHE_HOURS * HOUR_IN_SECONDS );
	return $result;
}

/** Retain only timestamps, outcome and a validated version, never remote content. */
function record_check( bool $success, string $version = '' ): void {
	$old = get_option( CHECK_STATUS, array() );
	$old = is_array( $old ) ? $old : array();
	update_option(
		CHECK_STATUS,
		array(
			'last_attempt' => time(),
			'last_success' => $success ? time() : (int) ( $old['last_success'] ?? 0 ),
			'latest'       => $success ? $version : ( stable_version( $old['latest'] ?? null ) ? $old['latest'] : '' ),
			'result'       => $success ? 'success' : 'failed',
		),
		false
	);
}

function compatible( array $release ): bool {
	return version_compare( PHP_VERSION, $release['requires_php'], '>=' )
		&& version_compare( (string) ( $GLOBALS['wp_version'] ?? '0' ), $release['requires'], '>=' );
}

/** Keep the native preference control available before a successful release lookup. */
function supported_plugin( array $plugins ): array {
	$file = plugin_file();
	if ( isset( $plugins[ $file ] ) && ( $plugins[ $file ]['UpdateURI'] ?? '' ) === REPOSITORY ) {
		$plugins[ $file ]['update-supported'] = true;
	}
	return $plugins;
}

/** Core chooses update/no_update and honours the site's automatic update opt-in. */
function update_offer( $update, array $plugin_data, string $file, array $locales ) {
	if ( plugin_file() !== $file || ( $plugin_data['UpdateURI'] ?? '' ) !== REPOSITORY ) {
		return $update;
	}
	$latest = release();
	if ( ! $latest && get_transient( CACHE . 'latest_unavailable' ) ) {
		$previous = get_transient( CACHE . 'last_verified' );
		$history  = get_option( CHECK_STATUS, array() );
		if ( is_array( $previous ) && ( $history['last_success'] ?? 0 ) > time() - 24 * HOUR_IN_SECONDS
			&& ( $history['latest'] ?? null ) === $previous['version'] ) {
			$latest = $previous;
		}
	}
	if ( ! $latest ) {
		return false;
	}
	return release_offer( $latest );
}

/** Use one validated release snapshot for both scheduled and immediate offers. */
function release_offer( array $latest ): array {
	return array(
		'id'                 => REPOSITORY,
		'slug'               => SLUG,
		'version'            => $latest['version'],
		'url'                => $latest['url'],
		'package'            => $latest['package'],
		'requires'           => $latest['requires'],
		'requires_php'       => $latest['requires_php'],
		'tested'             => $latest['tested'],
		'disable_autoupdate' => ! compatible( $latest ),
	);
}

function plugin_information( $result, string $action, $args ) {
	if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== SLUG ) {
		return $result;
	}
	$latest = release();
	if ( ! $latest ) {
		return new \WP_Error( 'church_connector_release_unavailable', 'Connector release information is temporarily unavailable. Try again later or visit its GitHub releases.' );
	}
	return (object) array(
		'name'          => 'Church Website Connector',
		'slug'          => SLUG,
		'version'       => $latest['version'],
		'author'        => 'Church Website Toolkit',
		'homepage'      => REPOSITORY,
		'requires'      => $latest['requires'],
		'requires_php'  => $latest['requires_php'],
		'tested'        => $latest['tested'],
		'last_updated'  => $latest['published_at'],
		'download_link' => $latest['package'],
		'sections'      => array(
			'description' => 'Connection helpers and protected website tools for GeneratePress AI and WP Rocket. Updates are supplied through published GitHub releases.',
			'changelog'   => '<p>' . nl2br( esc_html( $latest['notes'] ) ) . '</p>',
		),
	);
}

/** Return the version currently offered for a managed upgrade of this plugin. */
function offered_version(): string {
	$updates = get_site_transient( 'update_plugins' );
	$offer   = $updates->response[ plugin_file() ] ?? null;
	$version = $offer->new_version ?? '';
	return REPOSITORY === ( $offer->id ?? null ) && stable_version( $version ) && version_compare( $version, \ChurchWebsiteConnector\VERSION, '>' ) ? $version : '';
}

/** Check the exact release's SHA-256 before giving WordPress a package to install. */
function verified_download( $reply, string $package, $upgrader, array $extra ) {
	$target   = $extra['plugin'] ?? null;
	$official = str_starts_with( $package, REPOSITORY . '/releases/download/' );
	if ( is_wp_error( $reply ) || ( null !== $target && plugin_file() !== $target ) || ( ! $official && null === $target ) ) {
		return $reply;
	}
	if ( $official ) {
		$pattern = '~\A' . preg_quote( REPOSITORY . '/releases/download/', '~' ) . 'v([0-9]+\.[0-9]+\.[0-9]+)/' . SLUG . '-\1\.zip\z~';
		if ( 1 !== preg_match( $pattern, $package, $match ) || ! stable_version( $match[1] ) ) {
			return new \WP_Error( 'church_connector_package_url', 'The connector update package address is invalid.' );
		}
		$version = $match[1];
	}
	if ( null !== $target ) {
		$offered = offered_version();
		if ( '' === $offered || ( $official && $version !== $offered ) ) {
			return new \WP_Error( 'church_connector_package_release', 'Refresh connector updates. The package must match the newer version offered by WordPress.' );
		}
		$version = $offered;
	}
	if ( version_compare( $version, \ChurchWebsiteConnector\VERSION, '<' ) ) {
		return new \WP_Error( 'church_connector_package_release', 'The connector updater cannot install an older release.' );
	}
	$release = release( $version );
	if ( ( $release['version'] ?? '' ) !== $version || asset_url( $version, 'zip' ) !== ( $release['package'] ?? '' ) ) {
		return new \WP_Error( 'church_connector_package_release', 'The connector update does not have a complete published release.' );
	}
	if ( ! compatible( $release ) ) {
		return new \WP_Error( 'church_connector_package_requirements', 'This connector update requires a newer WordPress or PHP version.' );
	}
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	// WordPress also accepts a local package path from a managed update cache.
	$local_package   = false === $reply && ! str_contains( $package, '://' ) && is_file( $package );
	$downloaded_here = false === $reply && ! $local_package;
	$file            = $local_package ? $package : $reply;
	if ( $downloaded_here ) {
		$file = download_url( $package );
	}
	if ( is_wp_error( $file ) ) {
		return $file;
	}
	if ( ! is_string( $file ) || ! is_file( $file ) || ! is_readable( $file )
		|| ! hash_equals( $release['sha256'], (string) hash_file( 'sha256', $file ) ) ) {
		if ( $downloaded_here && is_string( $file ) && is_file( $file ) ) {
			wp_delete_file( $file );
		}
		return new \WP_Error( 'church_connector_package_integrity', 'The connector update failed its integrity check and was not installed.' );
	}
	return $file;
}

/** Check the unpacked header before core replaces the installed directory. */
function verified_source( $source, $remote_source, $upgrader, array $extra ) {
	if ( is_wp_error( $source ) || ( $extra['plugin'] ?? null ) !== plugin_file() || 'update' !== ( $extra['action'] ?? '' ) ) {
		return $source;
	}
	global $wp_filesystem;
	$version = offered_version();
	$file    = trailingslashit( $source ) . SLUG . '.php';
	$header  = $wp_filesystem->is_file( $file ) ? $wp_filesystem->get_contents( $file ) : false;
	if ( '' === $version || ! is_string( $header ) || ! preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Version:(.*)$/mi', str_replace( "\r", "\n", substr( $header, 0, 8192 ) ), $match )
		|| _cleanup_header_comment( $match[1] ) !== $version ) {
		return new \WP_Error( 'church_connector_package_version', 'The unpacked connector does not match the version offered by WordPress. The installed plugin was preserved.' );
	}
	return $source;
}

/** Refresh release information on the settings screen, even when automatic updates are off. */
function check_on_settings_load(): void {
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	// Transients can be evicted early. Retain the check interval across ordinary page refreshes.
	$history  = \ChurchWebsiteConnector\Maintenance\update_status();
	$age      = time() - $history['last_attempt'];
	$interval = 'success' === $history['result'] ? CACHE_HOURS * HOUR_IN_SECONDS : RETRY_MINUTES * MINUTE_IN_SECONDS;
	if ( 'not_checked' !== $history['result'] && $age >= 0 && $age < $interval ) {
		return;
	}
	release();
}

/** Check immediately from the administrator's nonce-protected form; never install. */
function check_now(): array|\WP_Error {
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) {
		return new \WP_Error( 'church_connector_update_denied', __( 'You are not allowed to check connector updates.', 'church-website-connector' ) );
	}
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
		return new \WP_Error( 'church_connector_update_request', __( 'Use the update-check button on the Website Connector settings page.', 'church-website-connector' ) );
	}
	$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'church_connector_check_updates' ) ) {
		return new \WP_Error( 'church_connector_update_nonce', __( 'The update-check request expired. Reload the settings page and try again.', 'church-website-connector' ) );
	}

	// Only the latest pointer needs invalidating: release() fetches its metadata afresh.
	delete_transient( CACHE . 'latest' );
	$latest = release();
	if ( ! $latest ) {
		return new \WP_Error( 'church_connector_release_unavailable', __( 'The update check failed. GitHub did not return a complete, valid release. Try again later. The previous WordPress update information has been kept.', 'church-website-connector' ) );
	}

	// Match core's Update URI payload, preserving every other plugin and its schedule.
	$file               = plugin_file();
	$offer              = (object) release_offer( $latest );
	$offer->plugin      = $file;
	$offer->new_version = $offer->version;
	$updates            = get_site_transient( 'update_plugins' );
	if ( ! is_object( $updates ) ) {
		$updates = (object) array( 'last_checked' => 0 );
	}
	foreach ( array( 'response', 'no_update', 'checked' ) as $field ) {
		if ( ! isset( $updates->$field ) || ! is_array( $updates->$field ) ) {
			$updates->$field = array();
		}
	}
	$updates->checked[ $file ] = \ChurchWebsiteConnector\VERSION;
	unset( $updates->response[ $file ], $updates->no_update[ $file ] );
	$newer = version_compare( $latest['version'], \ChurchWebsiteConnector\VERSION, '>' );
	if ( $newer ) {
		$updates->response[ $file ] = $offer;
	} else {
		$updates->no_update[ $file ] = $offer;
	}
	set_site_transient( 'update_plugins', $updates );
	$stored   = get_site_transient( 'update_plugins' );
	$expected = $newer ? 'response' : 'no_update';
	$opposite = $newer ? 'no_update' : 'response';
	$actual   = $stored->{$expected}[ $file ] ?? null;
	if ( ( $actual->new_version ?? null ) !== $latest['version'] || ( $actual->id ?? null ) !== REPOSITORY
		|| ( $actual->disable_autoupdate ?? null ) !== ! compatible( $latest ) || ! is_string( $actual->package ?? null ) || '' === $actual->package
		|| ( str_starts_with( $actual->package, REPOSITORY . '/releases/download/' ) && $actual->package !== $latest['package'] )
		|| isset( $stored->{$opposite}[ $file ] ) ) {
		return array(
			'status'  => 'offer_not_saved',
			'version' => $latest['version'],
		);
	}

	$status = $newer ? ( compatible( $latest ) ? 'available' : 'incompatible' ) : 'current';
	if ( version_compare( $latest['version'], \ChurchWebsiteConnector\VERSION, '<' ) ) {
		$status = 'ahead';
	}
	return array(
		'status'  => $status,
		'version' => $latest['version'],
	);
}

/** Handle the check separately from saving settings and use POST/redirect/GET. */
function handle_check(): void {
	$result = check_now();
	if ( is_wp_error( $result ) && 'church_connector_release_unavailable' !== $result->get_error_code() ) {
		wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
	}
	set_transient( 'church_connector_update_notice_' . get_current_user_id(), is_wp_error( $result ) ? array( 'status' => 'failed' ) : $result, 2 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'options-general.php?page=church-website-connector' ) );
	exit;
}

/** Render the one-use, account-specific result at the top of the settings page. */
function render_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}
	$key    = 'church_connector_update_notice_' . get_current_user_id();
	$result = get_transient( $key );
	delete_transient( $key );
	if ( is_array( $result ) ) {
		$version = is_string( $result['version'] ?? null ) && stable_version( $result['version'] ) ? $result['version'] : '';
		$message = '';
		$type    = 'success';
		switch ( $result['status'] ?? '' ) {
			case 'available':
				/* translators: %s: Latest connector version. */
				$message = sprintf( __( 'Version %s is available. Open Installed Plugins and choose Update now for Church Website Connector.', 'church-website-connector' ), $version );
				$type    = 'warning';
				break;
			case 'incompatible':
				/* translators: %s: Latest connector version. */
				$message = sprintf( __( 'Version %s is available, but it requires a newer WordPress or PHP version. Review the requirements in Installed Plugins.', 'church-website-connector' ), $version );
				$type    = 'warning';
				break;
			case 'current':
				/* translators: %s: Latest connector version. */
				$message = sprintf( __( 'You are up to date. Version %s is the latest published stable release.', 'church-website-connector' ), $version );
				break;
			case 'ahead':
				/* translators: %s: Latest connector version. */
				$message = sprintf( __( 'Your installed connector is newer than the latest published stable release (%s). No downgrade is offered.', 'church-website-connector' ), $version );
				break;
			case 'offer_not_saved':
				/* translators: %s: Latest connector version. */
				$message = sprintf( __( 'GitHub reports version %s. WordPress could not retain the matching result in Installed Plugins. Check again before attempting an update.', 'church-website-connector' ), $version );
				$type    = 'warning';
				break;
			case 'failed':
				$message = __( 'The update check failed. GitHub did not return a complete, valid release. Try again later. The previous WordPress update information has been kept.', 'church-website-connector' );
				$type    = 'error';
				break;
		}
		if ( '' !== $message ) {
			echo '<div class="notice notice-' . esc_attr( $type ) . ' inline" role="status"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
}

/** Render the immediate check control in the Automatic updates section. */
function render_check(): void {
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="church_connector_check_updates">';
	wp_nonce_field( 'church_connector_check_updates' );
	echo '<p>' . esc_html__( 'Check GitHub now without waiting for the scheduled check or the cached result. This checks only; it does not install an update.', 'church-website-connector' ) . '</p>';
	submit_button( __( 'Check for updates now', 'church-website-connector' ), 'secondary', 'submit', false );
	echo ' <a href="' . esc_url( self_admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Installed Plugins', 'church-website-connector' ) . '</a></form>';
}

add_action( 'admin_post_church_connector_check_updates', __NAMESPACE__ . '\\handle_check' );

add_filter( 'all_plugins', __NAMESPACE__ . '\\supported_plugin' );
add_filter( 'update_plugins_github.com', __NAMESPACE__ . '\\update_offer', 10, 4 );
add_filter( 'plugins_api', __NAMESPACE__ . '\\plugin_information', 10, 3 );
add_filter( 'upgrader_pre_download', __NAMESPACE__ . '\\verified_download', PHP_INT_MAX, 4 );
add_filter( 'upgrader_source_selection', __NAMESPACE__ . '\\verified_source', PHP_INT_MAX, 4 );
