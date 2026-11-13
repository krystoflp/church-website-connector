<?php
declare(strict_types=1);

// Protected Used CSS operations derived from the tested Church Website Toolkit.
namespace ChurchWebsiteConnector\UsedCss;

defined( 'ABSPATH' ) || exit;

function site(): string { return \ChurchWebsiteConnector\bound_site(); }

const CAP = 'church_connector_clear_used_css';
const LOG = 'church_connector_used_css_log';
const LOCK = 'church_connector_used_css_lock';
const LAST = 'church_connector_used_css_last_clear';
const GRANTEE = 'church_connector_used_css_grantee';
const COOLDOWN = 60;

function error( string $code, string $message, int $status = 400 ): \WP_Error {
    return new \WP_Error( 'church_connector_' . $code, $message, [ 'status' => $status ] );
}

function permission( $input = null ) {
    if ( ! is_user_logged_in() || ! current_user_can( CAP ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'rocket_remove_unused_css' ) ) {
        return error( 'forbidden', 'This account is not authorised to clear Church Website Used CSS.', 403 );
    }
    return true;
}

function environment() {
    if ( ! \ChurchWebsiteConnector\is_bound() ) {
        return error( 'wrong_site', 'Enable the connection helpers for this exact website in Settings → Website Connector.', 403 );
    }
    if ( ! \ChurchWebsiteConnector\feature_enabled( 'used_css' ) ) {
        return error( 'disabled', 'Used CSS clearing is switched off in Settings → Website Connector.', 503 );
    }
    if ( \ChurchWebsiteConnector\legacy_used_css() ) {
        return error( 'legacy_helper', 'An earlier Used CSS helper is active. Keep an administrator session open, deactivate that helper, then save Website Connector settings.', 503 );
    }
    if ( ! defined( 'WP_ROCKET_VERSION' ) || version_compare( WP_ROCKET_VERSION, '3.24', '<' ) || version_compare( WP_ROCKET_VERSION, '3.25', '>=' ) ) {
        return error( 'unsupported_version', 'This release requires WP Rocket 3.24.x. Recheck compatibility after a major/minor update.', 503 );
    }
    if ( ! function_exists( 'get_rocket_option' ) || ! get_rocket_option( 'remove_unused_css', 0 ) ) {
        return error( 'disabled', 'WP Rocket Remove Unused CSS is not enabled.', 503 );
    }
    if ( ! function_exists( 'rocket_clean_domain' ) || ! function_exists( 'rocket_clean_files' ) ) {
        return error( 'unavailable', 'The WP Rocket cache functions are unavailable.', 503 );
    }
    try {
        $container = apply_filters( 'rocket_container', null );
        if ( ! is_object( $container ) || ! is_callable( [ $container, 'get' ] ) ) {
            return error( 'unavailable', 'The WP Rocket service container is unavailable.', 503 );
        }
        $services = [
            'subscriber' => $container->get( 'rucss_admin_subscriber' ),
            'controller' => $container->get( 'rucss_used_css_controller' ),
            'query' => $container->get( 'rucss_used_css_query' ),
        ];
        foreach ( [ [ 'subscriber', 'truncate' ], [ 'controller', 'clear_url_usedcss' ], [ 'query', 'get_rows_by_url' ], [ 'query', 'get_completed_count' ] ] as $required ) {
            if ( ! is_callable( [ $services[ $required[0] ], $required[1] ] ) ) {
                return error( 'unavailable', 'A verified WP Rocket Used CSS interface is unavailable.', 503 );
            }
        }
        return $services;
    } catch ( \Throwable $e ) {
        return error( 'unavailable', 'The WP Rocket Used CSS services could not be loaded.', 503 );
    }
}

function activate(): void {
    $ready = environment();
    if ( is_wp_error( $ready ) ) {
        wp_die( esc_html( $ready->get_error_message() ) );
    }
    if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'rocket_remove_unused_css' ) ) {
        wp_die( 'Only an administrator with WP Rocket Used CSS permission can activate this helper.' );
    }
    $user = wp_get_current_user();
    if ( ! $user->has_cap( CAP ) ) {
        $user->add_cap( CAP );
        $grantees = (array) get_option( GRANTEE, [] );
        $grantees[] = $user->ID;
        update_option( GRANTEE, array_values( array_unique( $grantees ) ), false );
    }
}

function register_abilities(): void {
    if ( ! function_exists( 'wp_register_ability' ) || ! \ChurchWebsiteConnector\feature_enabled( 'used_css' ) || \ChurchWebsiteConnector\legacy_used_css() ) {
        return;
    }
    $meta = [ 'show_in_rest' => false, 'mcp' => [ 'public' => true ] ];
    wp_register_ability( 'church-connector/used-css-status', [
        'label' => 'Church Website Used CSS status',
        'description' => 'Read-only readiness, site identity and cooldown check for Church Website Used CSS clearing.',
        'category' => 'church-connector',
        'input_schema' => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ],
        'permission_callback' => __NAMESPACE__ . '\\permission',
        'execute_callback' => __NAMESPACE__ . '\\status',
        'meta' => array_merge( $meta, [ 'annotations' => [ 'readonly' => true, 'destructive' => false ] ] ),
    ] );
    wp_register_ability( 'church-connector/clear-used-css', [
        'label' => 'Clear Church Website Used CSS',
        'description' => 'WRITE ACTION: clear generated Used CSS and associated page cache on the configured website. Ask for client approval before execution. Prefer scope page with a published page/post ID. Whole-site scope requires confirm_site=true. This does not wait for regeneration.',
        'category' => 'church-connector',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'site_url' => [ 'type' => 'string', 'enum' => [ site() ] ],
                'scope' => [ 'type' => 'string', 'enum' => [ 'page', 'site' ] ],
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'confirm_site' => [ 'type' => 'boolean', 'description' => 'Explicit acknowledgement of whole-site scope; this is not authentication or proof of human approval.' ],
            ],
            'required' => [ 'site_url', 'scope' ],
            'additionalProperties' => false,
        ],
        'permission_callback' => __NAMESPACE__ . '\\permission',
        'execute_callback' => __NAMESPACE__ . '\\clear',
        'meta' => array_merge( $meta, [ 'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ] ] ),
    ] );
}

function status( $input = [] ) {
    $allowed = permission();
    if ( is_wp_error( $allowed ) ) {
        return $allowed;
    }
    $ready = environment();
    return [
        'site_url' => site(),
        'ready' => ! is_wp_error( $ready ),
        'message' => is_wp_error( $ready ) ? $ready->get_error_message() : 'Ready. Client approval is required before a clear.',
        'wp_rocket_version' => defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : null,
        'cooldown_seconds_remaining' => max( 0, COOLDOWN - ( time() - (int) get_option( LAST, 0 ) ) ),
        'regeneration' => 'Separate asynchronous WP Rocket process; a clear does not prove regeneration completed.',
    ];
}

function target( $input ) {
    if ( ! is_array( $input ) || array_diff( array_keys( $input ), [ 'site_url', 'scope', 'post_id', 'confirm_site' ] ) ) {
        return error( 'invalid_input', 'Only site_url, scope, post_id and confirm_site are accepted.' );
    }
    if ( ( $input['site_url'] ?? null ) !== site() ) {
        return error( 'wrong_site', 'The request must explicitly identify the configured website.', 403 );
    }
    if ( ( $input['scope'] ?? null ) === 'site' ) {
        if ( ( $input['confirm_site'] ?? false ) !== true || isset( $input['post_id'] ) ) {
            return error( 'confirmation_required', 'Whole-site scope requires confirm_site=true and no post_id.' );
        }
        return [ 'scope' => 'site', 'post_id' => null, 'url' => site() ];
    }
    if ( ( $input['scope'] ?? null ) !== 'page' || ! isset( $input['post_id'] ) || ! is_int( $input['post_id'] ) || $input['post_id'] < 1 ) {
        return error( 'invalid_input', 'Page scope requires a positive integer post_id.' );
    }
    $post = get_post( $input['post_id'] );
    if ( ! $post || ! in_array( $post->post_type, [ 'page', 'post' ], true ) || $post->post_status !== 'publish' || ! empty( $post->post_password ) ) {
        return error( 'invalid_page', 'Choose an existing, published, non-password-protected page or post.' );
    }
    $url = get_permalink( $post->ID );
    $parts = $url ? wp_parse_url( $url ) : false;
    if ( ! $parts || ( $parts['scheme'] ?? '' ) !== 'https' || ( $parts['host'] ?? '' ) !== wp_parse_url( site(), PHP_URL_HOST ) || isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ! canonical_path_allowed( $parts['path'] ?? '' ) ) {
        return error( 'invalid_page', 'The canonical page address must belong to the configured website.' );
    }
    return [ 'scope' => 'page', 'post_id' => $post->ID, 'url' => untrailingslashit( $url ) ];
}

function canonical_path_allowed( string $path ): bool {
    $base = wp_parse_url( site(), PHP_URL_PATH ) ?: '';
    $decoded = rawurldecode( $path );
    if ( preg_match( '~(?:^|/)(?:\.|\.\.)(?:/|$)~', $decoded ) || str_contains( $decoded, '\\' ) || preg_match( '/[\x00-\x20]/', $decoded ) ) {
        return false;
    }
    return $base === '' || $path === $base || str_starts_with( $path, $base . '/' );
}

// Conditional deletion avoids releasing another request's lock during stale-lock recovery.
function release_lock( string $value ): void {
    global $wpdb;
    $wpdb->delete( $wpdb->options, [ 'option_name' => LOCK, 'option_value' => $value ], [ '%s', '%s' ] );
    wp_cache_delete( LOCK, 'options' );
}

function acquire_lock() {
    $value = wp_json_encode( [ 'expires' => time() + 120, 'token' => wp_generate_uuid4() ] );
    if ( add_option( LOCK, $value, '', false ) ) {
        return $value;
    }
    $previous = (string) get_option( LOCK, '' );
    $decoded = json_decode( $previous, true );
    if ( is_array( $decoded ) && isset( $decoded['expires'] ) && $decoded['expires'] < time() ) {
        release_lock( $previous );
        if ( add_option( LOCK, $value, '', false ) ) {
            return $value;
        }
    }
    return error( 'busy', 'Another Used CSS clearing request is running. Try again shortly.', 429 );
}

function audit( array $record ): void {
    $rows = (array) get_option( LOG, [] );
    array_unshift( $rows, array_merge( [ 'time_utc' => gmdate( 'c' ), 'user_id' => get_current_user_id() ], $record ) );
    update_option( LOG, array_slice( $rows, 0, 50 ), false );
}

function clear( $input ) {
    $allowed = permission();
    if ( is_wp_error( $allowed ) ) {
        return $allowed;
    }
    $target = target( $input );
    if ( is_wp_error( $target ) ) {
        return $target;
    }
    $services = environment();
    if ( is_wp_error( $services ) ) {
        return $services;
    }
    $lock = acquire_lock();
    if ( is_wp_error( $lock ) ) {
        return $lock;
    }
    try {
        $remaining = COOLDOWN - ( time() - (int) get_option( LAST, 0 ) );
        if ( $remaining > 0 ) {
            return error( 'cooldown', 'Wait ' . $remaining . ' seconds before another Used CSS clear.', 429 );
        }
        // Reserve the cooldown before any side effect, including partially failing operations.
        update_option( LAST, time(), false );
        if ( $target['scope'] === 'site' ) {
            $result = $services['subscriber']->truncate( [] );
            if ( ! is_array( $result ) || ( $result['status'] ?? null ) !== 'success' || (int) $services['query']->get_completed_count() !== 0 ) {
                throw new \RuntimeException( 'Site-wide Used CSS clearing could not be verified.' );
            }
            rocket_clean_domain();
        } else {
            $services['controller']->clear_url_usedcss( $target['url'] );
            if ( $services['query']->get_rows_by_url( $target['url'] ) ) {
                throw new \RuntimeException( 'Page Used CSS clearing could not be verified.' );
            }
            rocket_clean_files( $target['url'] );
        }
        $result = array_merge( $target, [
            'site_url' => site(),
            'status' => 'cleared',
            'page_cache' => 'clear_requested',
            'regeneration' => 'not_verified',
            'message' => 'Used CSS cleared. WP Rocket regenerates it separately through visits/preloading; regeneration is not yet verified.',
        ] );
        audit( array_merge( $target, [ 'status' => 'cleared' ] ) );
        return $result;
    } catch ( \Throwable $e ) {
        audit( array_merge( $target, [ 'status' => 'error' ] ) );
        return error( 'clear_failed', 'The clear did not complete cleanly. Some cache data may already have been cleared. Check the dashboard before retrying.', 500 );
    } finally {
        release_lock( $lock );
    }
}

function admin_menu(): void {
    add_management_page( 'Church Website Used CSS', 'Church Website Used CSS', CAP, 'church-connector-used-css', __NAMESPACE__ . '\\admin_page' );
}

function admin_page(): void {
    if ( is_wp_error( permission() ) ) {
        wp_die( 'You are not authorised to use this tool.' );
    }
    $status = status();
    echo '<div class="wrap"><h1>Church Website Used CSS</h1><p>Site: ' . esc_html( site() ) . '</p>';
    echo '<p><strong>' . esc_html( $status['message'] ) . '</strong></p>';
    echo '<p>WP Rocket: ' . esc_html( (string) $status['wp_rocket_version'] ) . '. Cooldown remaining: ' . esc_html( (string) $status['cooldown_seconds_remaining'] ) . ' seconds.</p>';
    echo '<p>MCP abilities: <code>church-connector/used-css-status</code> (read-only) and <code>church-connector/clear-used-css</code> (write). Only an eligible administrator activating or saving Website Connector settings receives the additional clearing permission. Use client approval for the write action.</p>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    wp_nonce_field( 'church_connector_used_css' );
    echo '<input type="hidden" name="action" value="church_connector_used_css"><p><label for="ct-scope">Scope</label> <select id="ct-scope" name="scope"><option value="page">One published page or post</option><option value="site">Whole website</option></select></p>';
    echo '<p><label for="ct-post">Page/post ID (for one-page scope)</label> <input id="ct-post" name="post_id" type="number" min="1" step="1"></p>';
    echo '<p><label><input type="checkbox" name="confirm_site" value="1"> I explicitly request whole-site clearing (required only for whole-site scope).</label></p>';
    submit_button( 'Clear Used CSS' );
    echo '</form><h2>Recent activity</h2><table class="widefat striped"><caption class="screen-reader-text">Last 50 Used CSS clearing requests</caption><thead><tr><th scope="col">Time (UTC)</th><th scope="col">User ID</th><th scope="col">Scope</th><th scope="col">Address</th><th scope="col">Result</th></tr></thead><tbody>';
    foreach ( (array) get_option( LOG, [] ) as $row ) {
        echo '<tr>';
        foreach ( [ 'time_utc', 'user_id', 'scope', 'url', 'status' ] as $key ) {
            echo '<td>' . esc_html( (string) ( $row[ $key ] ?? '' ) ) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function admin_clear(): void {
    if ( is_wp_error( permission() ) ) {
        wp_die( 'You are not authorised to use this tool.', '', [ 'response' => 403 ] );
    }
    check_admin_referer( 'church_connector_used_css' );
    $scope = isset( $_POST['scope'] ) && is_string( $_POST['scope'] ) ? wp_unslash( $_POST['scope'] ) : '';
    $input = [ 'site_url' => site(), 'scope' => $scope ];
    if ( $scope === 'page' ) {
        $raw = isset( $_POST['post_id'] ) && is_string( $_POST['post_id'] ) ? wp_unslash( $_POST['post_id'] ) : '';
        if ( ! ctype_digit( $raw ) || (int) $raw < 1 ) {
            wp_die( 'Enter a valid page/post ID.', '', [ 'response' => 400 ] );
        }
        $input['post_id'] = (int) $raw;
    } else {
        $input['confirm_site'] = isset( $_POST['confirm_site'] ) && $_POST['confirm_site'] === '1';
    }
    $result = clear( $input );
    if ( is_wp_error( $result ) ) {
        wp_die( esc_html( $result->get_error_message() ), 'Used CSS action', [ 'response' => 400, 'back_link' => true ] );
    }
    wp_safe_redirect( admin_url( 'tools.php?page=church-connector-used-css' ) );
    exit;
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities' );
add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu' );
add_action( 'admin_post_church_connector_used_css', __NAMESPACE__ . '\\admin_clear' );
