<?php
/** GitHub releases integrated with WordPress's native, administrator-controlled updater. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Updates;

defined( 'ABSPATH' ) || exit;

const REPOSITORY = 'https://github.com/krystoflp/church-website-connector';
const API = 'https://api.github.com/repos/krystoflp/church-website-connector/releases/';
const SLUG = 'church-website-connector';
const CACHE = 'church_connector_release_';

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
    $response = wp_safe_remote_get( $url, [
        'timeout' => 10,
        'redirection' => $api ? 0 : 3,
        'limit_response_size' => 262144,
        'user-agent' => 'ChurchWebsiteConnector/' . \ChurchWebsiteConnector\VERSION,
        'headers' => [ 'Accept' => $api ? 'application/vnd.github+json' : 'application/json' ],
    ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return false;
    }
    $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
    return is_array( $decoded ) ? $decoded : false;
}

function release( string $version = '' ): array {
    if ( $version !== '' && ! stable_version( $version ) ) {
        return [];
    }
    $key = CACHE . ( $version === '' ? 'latest' : $version );
    $cached = get_transient( $key );
    if ( is_array( $cached ) ) {
        return $cached;
    }
    // Cache failures briefly too; a GitHub outage must not repeatedly delay requests.
    set_transient( $key, [], 15 * MINUTE_IN_SECONDS );
    $data = fetch_json( API . ( $version === '' ? 'latest' : 'tags/v' . $version ), true );
    if ( ! is_array( $data ) || ( $data['draft'] ?? true ) !== false || ( $data['prerelease'] ?? true ) !== false
        || ! is_string( $data['tag_name'] ?? null ) || ! str_starts_with( $data['tag_name'], 'v' )
        || ! is_string( $data['published_at'] ?? null ) || strtotime( $data['published_at'] ) === false ) {
        return [];
    }
    $found_version = substr( $data['tag_name'], 1 );
    if ( ! stable_version( $found_version ) || ( $version !== '' && $version !== $found_version )
        || ( $data['html_url'] ?? '' ) !== REPOSITORY . '/releases/tag/v' . $found_version ) {
        return [];
    }
    $assets = [];
    foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
        if ( ! is_array( $asset ) ) {
            continue;
        }
        foreach ( [ 'zip', 'json' ] as $extension ) {
            if ( ( $asset['name'] ?? '' ) === SLUG . '-' . $found_version . '.' . $extension ) {
                // Duplicate, incomplete or redirected-to-another-repository assets are rejected.
                if ( isset( $assets[ $extension ] ) || ( $asset['state'] ?? '' ) !== 'uploaded'
                    || ( $asset['browser_download_url'] ?? '' ) !== asset_url( $found_version, $extension ) ) {
                    return [];
                }
                $assets[ $extension ] = $asset;
            }
        }
    }
    if ( ! isset( $assets['zip'], $assets['json'] ) ) {
        return [];
    }
    $metadata = fetch_json( asset_url( $found_version, 'json' ) );
    if ( ! is_array( $metadata ) || ( $metadata['version'] ?? '' ) !== $found_version
        || ! is_string( $metadata['sha256'] ?? null ) || preg_match( '/\A[a-f0-9]{64}\z/', $metadata['sha256'] ) !== 1 ) {
        return [];
    }
    foreach ( [ 'requires', 'requires_php', 'tested' ] as $field ) {
        if ( ! is_string( $metadata[ $field ] ?? null ) || preg_match( '/\A[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/', $metadata[ $field ] ) !== 1 ) {
            return [];
        }
    }
    // Recent GitHub uploads also supply a digest; require agreement when supplied.
    $github_digest = $assets['zip']['digest'] ?? null;
    if ( $github_digest !== null && $github_digest !== 'sha256:' . $metadata['sha256'] ) {
        return [];
    }
    $result = [
        'version' => $found_version,
        'requires' => $metadata['requires'],
        'requires_php' => $metadata['requires_php'],
        'tested' => $metadata['tested'],
        'sha256' => $metadata['sha256'],
        'package' => asset_url( $found_version, 'zip' ),
        'url' => $data['html_url'],
        'published_at' => $data['published_at'],
        'notes' => is_string( $data['body'] ?? null ) ? substr( $data['body'], 0, 60000 ) : '',
    ];
    set_transient( CACHE . $found_version, $result, 12 * HOUR_IN_SECONDS );
    set_transient( $key, $result, 12 * HOUR_IN_SECONDS );
    return $result;
}

function compatible( array $release ): bool {
    return version_compare( PHP_VERSION, $release['requires_php'], '>=' )
        && version_compare( (string) ( $GLOBALS['wp_version'] ?? '0' ), $release['requires'], '>=' );
}

/** Core chooses update/no_update and honours the site's automatic update opt-in. */
function update_offer( $update, array $plugin_data, string $file, array $locales ) {
    if ( $file !== plugin_file() || ( $plugin_data['UpdateURI'] ?? '' ) !== REPOSITORY ) {
        return $update;
    }
    $latest = release();
    if ( ! $latest ) {
        return false;
    }
    return [
        'id' => REPOSITORY,
        'slug' => SLUG,
        'version' => $latest['version'],
        'url' => $latest['url'],
        'package' => $latest['package'],
        'requires' => $latest['requires'],
        'requires_php' => $latest['requires_php'],
        'tested' => $latest['tested'],
        'disable_autoupdate' => ! compatible( $latest ),
    ];
}

function plugin_information( $result, string $action, $args ) {
    if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== SLUG ) {
        return $result;
    }
    $latest = release();
    if ( ! $latest ) {
        return new \WP_Error( 'church_connector_release_unavailable', 'Connector release information is temporarily unavailable. Try again later or visit its GitHub releases.' );
    }
    return (object) [
        'name' => 'Church Website Connector',
        'slug' => SLUG,
        'version' => $latest['version'],
        'author' => 'Church Website Toolkit',
        'homepage' => REPOSITORY,
        'requires' => $latest['requires'],
        'requires_php' => $latest['requires_php'],
        'tested' => $latest['tested'],
        'last_updated' => $latest['published_at'],
        'download_link' => $latest['package'],
        'sections' => [
            'description' => 'Connection helpers and protected website tools for GeneratePress AI and WP Rocket. Updates are supplied through published GitHub releases.',
            'changelog' => '<p>' . nl2br( esc_html( $latest['notes'] ) ) . '</p>',
        ],
    ];
}

/** Check the exact release's SHA-256 before giving WordPress a package to install. */
function verified_download( $reply, string $package, $upgrader, array $extra ) {
    $prefix = REPOSITORY . '/releases/download/';
    if ( ! str_starts_with( $package, $prefix ) || is_wp_error( $reply ) ) {
        return $reply;
    }
    $pattern = '~\A' . preg_quote( $prefix, '~' ) . 'v([0-9]+\.[0-9]+\.[0-9]+)/' . SLUG . '-\1\.zip\z~';
    if ( preg_match( $pattern, $package, $match ) !== 1 || ! stable_version( $match[1] ) ) {
        return new \WP_Error( 'church_connector_package_url', 'The connector update package address is invalid.' );
    }
    $release = release( $match[1] );
    if ( ! $release || $release['package'] !== $package ) {
        return new \WP_Error( 'church_connector_package_release', 'The connector update does not have a complete published release.' );
    }
    if ( ! compatible( $release ) ) {
        return new \WP_Error( 'church_connector_package_requirements', 'This connector update requires a newer WordPress or PHP version.' );
    }
    if ( ! function_exists( 'download_url' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $downloaded_here = $reply === false;
    $file = $downloaded_here ? download_url( $package ) : $reply;
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

add_filter( 'update_plugins_github.com', __NAMESPACE__ . '\\update_offer', 10, 4 );
add_filter( 'plugins_api', __NAMESPACE__ . '\\plugin_information', 10, 3 );
add_filter( 'upgrader_pre_download', __NAMESPACE__ . '\\verified_download', 10, 4 );
