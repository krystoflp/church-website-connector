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
const GRANT_LOCK = 'church_connector_used_css_grant_lock';
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
        return error( 'wrong_site', 'Enable the connection helpers for this exact website in Settings → ChurchWeb Connector.', 403 );
    }
    if ( ! \ChurchWebsiteConnector\feature_enabled( 'used_css' ) ) {
        return error( 'disabled', 'Used CSS clearing is switched off in Settings → ChurchWeb Connector.', 503 );
    }
    if ( \ChurchWebsiteConnector\legacy_used_css() ) {
        return error( 'legacy_helper', 'An earlier Used CSS helper is active. Keep an administrator session open, deactivate that helper, then save ChurchWeb Connector settings.', 503 );
    }
    if ( ! defined( 'WP_ROCKET_VERSION' ) || version_compare( WP_ROCKET_VERSION, '3.24', '<' ) || version_compare( WP_ROCKET_VERSION, '3.25', '>=' ) ) {
        return error( 'unsupported_version', 'This release requires WP Rocket 3.24.x. Recheck compatibility after a major/minor update.', 503 );
    }
    if ( ! function_exists( 'get_rocket_option' ) || ! get_rocket_option( 'remove_unused_css', 0 ) ) {
        return error( 'disabled', 'WP Rocket Remove Unused CSS is not enabled.', 503 );
    }
	if ( ! function_exists( 'rocket_clean_domain' ) ) {
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

/** Read the single physical capabilities row. WordPress normally stores one per user and site. */
function direct_capabilities( \WP_User $user ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT umeta_id, meta_value FROM %i WHERE user_id = %d AND meta_key = %s LIMIT 2', $wpdb->usermeta, $user->ID, $user->cap_key ) );
	if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 1 ) {
		return error( 'grant_storage', 'The account permissions could not be read unambiguously. Review the account and try again.', 500 );
	}
	if ( ! $rows ) {
		return null;
	}
	$row       = $rows[0];
	$row->caps = maybe_unserialize( $row->meta_value );
	return is_array( $row->caps ) ? $row : error( 'grant_storage', 'WordPress has no usable capability list for this account.', 500 );
}

/** Merge one direct permission using the exact stored bytes, including on case-insensitive databases. */
function change_direct_capability( \WP_User $user, bool $grant ): true|\WP_Error {
	global $wpdb;
	$failure = error( 'grant_storage', 'WordPress could not save the Used CSS permission change. Review the account and try again.', 500 );
	try {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			wp_cache_delete( $user->ID, 'user_meta' );
			$user->for_site();
			$row = direct_capabilities( $user );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			// The permission check and the conditional write must see the same account state.
			if ( null !== $row && $user->caps !== $row->caps ) {
				continue;
			}
			if ( $grant && ( ! $user->has_cap( 'manage_options' ) || ! $user->has_cap( 'rocket_remove_unused_css' ) ) ) {
				return error( 'forbidden', 'The account no longer has permission to activate Used CSS clearing.', 403 );
			}
			if ( null === $row ) {
				return $grant ? $failure : true;
			}
			if ( ! empty( $row->caps[ CAP ] ) === $grant || ( $grant && $user->has_cap( CAP ) ) ) {
				return true;
			}
			$next = $row->caps;
			if ( $grant ) {
				$next[ CAP ] = true;
			} else {
				unset( $next[ CAP ] );
			}
			if ( sanitize_meta( $user->cap_key, $next, 'user', get_object_subtype( 'user', $user->ID ) ) !== $next ) {
				return $failure;
			}
			// Preserve the native user-metadata veto and notifications for this conditional write.
			$check = apply_filters( 'update_user_metadata', null, $user->ID, $user->cap_key, $next, $row->caps );
			if ( null === $check ) {
				do_action( 'update_user_meta', (int) $row->umeta_id, $user->ID, $user->cap_key, $next );
				$written = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET meta_value = %s WHERE umeta_id = %d AND user_id = %d AND meta_key = %s AND HEX(meta_value) = %s',
						$wpdb->usermeta,
						maybe_serialize( $next ),
						$row->umeta_id,
						$user->ID,
						$user->cap_key,
						strtoupper( bin2hex( $row->meta_value ) )
					)
				);
				if ( false === $written ) {
					return $failure;
				}
				if ( 0 === $written ) {
					continue;
				}
				wp_cache_delete( $user->ID, 'user_meta' );
				do_action( 'updated_user_meta', (int) $row->umeta_id, $user->ID, $user->cap_key, $next );
			} elseif ( ! $check ) {
				return $failure;
			}
			$saved = direct_capabilities( $user );
			return ! is_wp_error( $saved ) && ! empty( $saved->caps[ CAP ] ) === $grant ? true : $failure;
		}
		return $failure;
	} finally {
		wp_cache_delete( $user->ID, 'user_meta' );
		$user->for_site();
	}
}

function activate(): true|\WP_Error {
	$ready = environment();
	if ( is_wp_error( $ready ) ) {
		return $ready;
	}
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'rocket_remove_unused_css' ) ) {
		return error( 'forbidden', 'Only an administrator with WP Rocket Used CSS permission can activate this helper.', 403 );
	}
	$lock = acquire_lock( GRANT_LOCK );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$user = wp_get_current_user();
		wp_cache_delete( $user->ID, 'user_meta' );
		$user->for_site();
		if ( $user->has_cap( CAP ) && empty( $user->caps[ CAP ] ) ) {
			// A permission supplied by a role or a filter belongs to that provider.
			return true;
		}
		$grantees = array_map( 'intval', (array) get_option( GRANTEE, array() ) );
		if ( ! in_array( $user->ID, $grantees, true ) ) {
			$grantees[] = $user->ID;
			update_option( GRANTEE, $grantees, false );
			if ( get_option( GRANTEE ) !== $grantees ) {
				return error( 'grant_storage', 'WordPress could not record the Used CSS permission. Review settings and try again.', 500 );
			}
		}
		return change_direct_capability( $user, true );
	} finally {
		release_lock( $lock, GRANT_LOCK );
	}
}

/** Include direct connector grants left untracked by older versions. Role grants belong to their provider. */
function revoke_grants(): true|\WP_Error {
	global $wpdb;
	$lock = acquire_lock( GRANT_LOCK );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$direct = get_users(
			array(
				'capability' => CAP,
				'fields'     => 'ID',
			)
		);
		if ( $wpdb->last_error ) {
			return error( 'grant_storage', 'WordPress could not read the Used CSS permissions. Check settings and try again.', 500 );
		}
		$users = array_unique( array_merge( $direct, (array) get_option( GRANTEE, array() ) ) );
		foreach ( $users as $user_id ) {
			$user = get_current_user_id() === (int) $user_id ? wp_get_current_user() : get_user_by( 'id', (int) $user_id );
			if ( ! $user ) {
				continue;
			}
			$removed = change_direct_capability( $user, false );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
		}
		delete_option( GRANTEE );
		return false === get_option( GRANTEE, false ) ? true : error( 'grant_storage', 'WordPress could not remove the Used CSS permission records.', 500 );
	} finally {
		release_lock( $lock, GRANT_LOCK );
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
	// WP Rocket also removes query-variant directories below a URL when purging its disk cache.
	if ( isset( $parts['query'] ) || ( '' === (string) get_option( 'permalink_structure' ) && untrailingslashit( $url ) === site() )
		|| ( function_exists( 'get_rocket_cache_query_string' ) && get_rocket_cache_query_string() ) ) {
		return error( 'shared_cache_path', 'WP Rocket can clear other query-string addresses from this cache path. A single-page clear cannot isolate it. If the whole website should be cleared, choose whole-site scope and confirm it separately.', 409 );
	}
	return array(
		'scope'   => 'page',
		'post_id' => $post->ID,
		'url'     => $url,
	);
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
function release_lock( string $value, string $name = LOCK ): void {
	global $wpdb;
	$wpdb->delete(
		$wpdb->options,
		array(
			'option_name'  => $name,
			'option_value' => $value,
		),
		array( '%s', '%s' )
	);
	wp_cache_delete( $name, 'options' );
}

function acquire_lock( string $name = LOCK ) {
	global $wpdb;
	$value   = wp_json_encode(
		array(
			'expires' => time() + 120,
			'token'   => wp_generate_uuid4(),
		)
	);
	$written = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
	if ( 0 === $written ) {
		$previous = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		$decoded  = is_string( $previous ) ? json_decode( $previous, true ) : null;
		if ( is_array( $decoded ) && isset( $decoded['expires'] ) && $decoded['expires'] < time() ) {
			$written = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND HEX(option_value) = HEX(%s)", $value, $name, $previous ) );
		}
	}
	if ( 1 === $written ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $value;
	}
	return false === $written ? error( 'lock_storage', 'WordPress could not store the Used CSS lock. Check the database and retry.', 503 ) : error( 'busy', 'Another Used CSS operation is running. Try again shortly.', 429 );
}

function audit( array $record ): void {
	$entry = array(
		'time_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'user_id'  => get_current_user_id(),
	);
	\ChurchWebsiteConnector\RestTools\append_audit( LOG, array_merge( $entry, $record ) );
}

/** Keep WP Rocket's Varnish request scoped to the exact canonical URL of this page. */
function with_exact_cache_url( string $canonical, callable $operation ): void {
	$host    = wp_parse_url( $canonical, PHP_URL_HOST );
	$path    = wp_parse_url( $canonical, PHP_URL_PATH ) ?? '/';
	$varnish = trailingslashit( $path );
	$exact   = static function ( $parts ) use ( $host, $path, $varnish ) {
		// WP Rocket 3.24's page subscriber appends "/?regex", which the Varnish sender expands to ".*".
		if ( 'regex' === ( $parts['query'] ?? '' ) && 'https' === ( $parts['scheme'] ?? '' ) && ( $parts['host'] ?? '' ) === $host && ( $parts['path'] ?? '' ) === $varnish ) {
			$parts['query'] = '';
			$parts['path']  = $path;
		}
		return $parts;
	};
	add_filter( 'rocket_parse_url', $exact, PHP_INT_MIN );
	try {
		$operation();
	} finally {
		remove_filter( 'rocket_parse_url', $exact, PHP_INT_MIN );
	}
}

function clear_page( $controller, string $canonical ): void {
	with_exact_cache_url(
		$canonical,
		static function () use ( $controller, $canonical ) {
			$controller->clear_url_usedcss( untrailingslashit( $canonical ) );
		}
	);
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
		$reserved = time();
		update_option( LAST, $reserved, false );
		if ( (int) get_option( LAST, 0 ) !== $reserved ) {
			return error( 'cooldown_storage', 'WordPress could not reserve the Used CSS cooldown. Check the database and retry.', 503 );
		}
        if ( $target['scope'] === 'site' ) {
            $result = $services['subscriber']->truncate( [] );
            if ( ! is_array( $result ) || ( $result['status'] ?? null ) !== 'success' || (int) $services['query']->get_completed_count() !== 0 ) {
                throw new \RuntimeException( 'Site-wide Used CSS clearing could not be verified.' );
            }
            rocket_clean_domain();
        } else {
			$canonical     = $target['url'];
			$target['url'] = untrailingslashit( $canonical );
			clear_page( $services['controller'], $canonical );
            if ( $services['query']->get_rows_by_url( $target['url'] ) ) {
                throw new \RuntimeException( 'Page Used CSS clearing could not be verified.' );
            }
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
    echo '<p>MCP abilities: <code>church-connector/used-css-status</code> (read-only) and <code>church-connector/clear-used-css</code> (write). Only an eligible administrator activating or saving ChurchWeb Connector settings receives the additional clearing permission. Use client approval for the write action.</p>';
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

if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities' );
add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu' );
add_action( 'admin_post_church_connector_used_css', __NAMESPACE__ . '\\admin_clear' );
