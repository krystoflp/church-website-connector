<?php
declare(strict_types=1);

namespace ChurchWebsiteConnector\RedirectionTools;

defined( 'ABSPATH' ) || exit;

const PREFIX = 'church_connector_redirect_preview_';
const LOCK = 'church_connector_redirect_lock';
const AUDIT = 'church_connector_redirect_audit';
const EXPIRE_HOOK = 'church_connector_expire_redirect_preview';
const TTL = 900;
const FIELDS = [ 'source', 'target', 'title', 'http_code', 'group_id', 'position', 'ignore_case', 'ignore_trailing_slash', 'query_parameters' ];

require_once __DIR__ . '/redirection-safety.php';

function error( string $code, string $message, int $status = 400 ): \WP_Error {
	return \ChurchWebsiteConnector\RestTools\workflow_error( 'church_connector_redirect_', $code, $message, $status );
}

function readiness(): array {
    $version = defined( 'REDIRECTION_VERSION' ) ? (string) REDIRECTION_VERSION : '';
    if ( $version !== '' && function_exists( 'rest_get_server' ) && ! did_action( 'rest_api_init' ) ) { rest_get_server(); }
    $compatible = supported_version( $version ) && required_interfaces();
    return [ 'enabled' => \ChurchWebsiteConnector\feature_enabled( 'redirection_tools' ), 'plugin_version' => $version ?: null,
        'compatible' => $compatible, 'supported_versions' => '>=5.10.1 <5.11', 'tested_version' => '5.10.1',
        'message' => $compatible ? 'Redirection 5.10.x REST interfaces are available (minimum 5.10.1; tested on 5.10.1).'
            : 'Redirection tools require version 5.10.1 or a compatible 5.10.x patch and its native REST/matching interfaces. Other versions are paused pending review.' ];
}

function permission( $input = null ) {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! \ChurchWebsiteConnector\feature_enabled( 'redirection_tools' ) ) {
        return error( 'forbidden', 'An administrator must sign in through this website’s MCP connection and enable Redirection tools.', 403 );
    }
    if ( ! readiness()['compatible'] || ! function_exists( 'rest_do_request' ) ) { return error( 'unavailable', readiness()['message'], 503 ); }
    if ( ! \Redirection_Capabilities::has_access( \Redirection_Capabilities::CAP_REDIRECT_MANAGE ) ) { return error( 'forbidden', 'Redirection does not permit this account to manage redirects.', 403 ); }
    return true;
}

function check( mixed $input, array $keys ) {
    $allowed = permission();
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    if ( ! is_array( $input ) || array_diff( array_keys( $input ), $keys ) ) { return error( 'input', 'Only the documented parameters are accepted.' ); }
    if ( ( $input['site_url'] ?? null ) !== \ChurchWebsiteConnector\bound_site() ) { return error( 'site', 'Identify this installation’s exact website address.', 403 ); }
    return true;
}

/**
 * Run the normal authorized provider callback inside a protected write.
 * Bind the wrapper to this exact request so nested REST reads keep their own callbacks.
 */
function guarded_request( \WP_REST_Request $request, callable $write ) {
	$guarded = null;
	$invoked = false;
	$guard   = static function ( $result, $target, $path, $handler ) use ( $request, $write, &$guarded, &$invoked ) {
		if ( $request !== $target || null !== $result ) {
			return $result;
		}
		$invoked = true;
		$guarded = $write( $handler['callback'], $target );
		return $guarded;
	};
	add_filter( 'rest_dispatch_request', $guard, PHP_INT_MAX, 4 );
	try {
		$response = rest_do_request( $request );
		if ( ! $invoked && ! is_wp_error( $response ) && $response->get_status() < 400 ) {
			return error( 'verification', 'Redirection did not run the reviewed native callback. Read the current state before retrying.', 409 );
		}
		return is_wp_error( $guarded ) ? $guarded : $response;
	} finally {
		remove_filter( 'rest_dispatch_request', $guard, PHP_INT_MAX );
	}
}

/** Redirection uses separate statements for group changes and for counting and creating rules. */
function rule_transaction( callable $operation ) {
	global $wpdb;
	$failed = error( 'write_atomic', 'The database could not protect the reviewed redirect change. Read the current state before retrying or use the native editor.', 409 );
	if ( ! ( $wpdb instanceof \WP_SQLite_DB ) ) {
		$engines = $wpdb->get_col( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s)', $wpdb->prefix . 'redirection_groups', $wpdb->prefix . 'redirection_items', $wpdb->options ) );
		// InnoDB next-key locks cover new rows, including inserts into an empty group.
		// SET TRANSACTION fails when the caller already owns a running transaction.
		if ( 3 !== count( $engines ) || array_diff( $engines, array( 'InnoDB', 'XtraDB' ) )
			|| false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) ) {
			return $failed;
		}
	}
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		return $failed;
	}
	$committed = false;
	try {
		if ( false === $wpdb->query( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name = %s FOR UPDATE', $wpdb->options, \Red_Options::OPTION_KEY ) ) ) {
			return $failed;
		}
		reset_native_options();
		$response = $operation();
		if ( is_wp_error( $response ) || ( $response instanceof \WP_HTTP_Response && $response->get_status() >= 400 ) ) {
			return $response;
		}
		$committed = false !== $wpdb->query( 'COMMIT' );
		return $committed ? $response : $failed;
	} finally {
		if ( ! $committed ) {
			$wpdb->query( 'ROLLBACK' );
			reset_native_options();
		}
	}
}

function reset_native_options(): void {
	wp_cache_delete( \Red_Options::OPTION_KEY, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	\Red_Options::reset();
	\Redirection\Settings\Settings::reset();
}

/** Check IDs under the same range lock used by the native writer. */
function locked_rule_ids() {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id LIMIT %d FOR UPDATE', $wpdb->prefix . 'redirection_items', MAX_RULES + 1 ) );
	return $wpdb->last_error || count( $ids ) > MAX_RULES
		? error( 'analysis_limit', 'The current redirect rules cannot be checked within the review limit.', 409 ) : array_map( 'intval', $ids );
}

function rule_matches_review( array $saved, array $before, array $stored ): bool {
	$op = $saved['operation'];
	if ( in_array( $op, array( 'enable', 'disable' ), true ) ) {
		$before['enabled'] = 'enable' === $op;
		return configuration( $stored ) === configuration( $before );
	}
	$enabled  = 'create' === $op ? ( $saved['enabled'] ?? true ) : $before['enabled'];
	$exclude  = 'create' === $op ? ( $saved['log_exclude'] ?? false ) : ! empty( $before['match_data']['options']['log_exclude'] );
	$expected = ( new \Red_Item_Sanitize() )->get( payload( $saved['fields'], $before ) );
	return plain_rule( $stored ) && $enabled === $stored['enabled'] && rule_fields( $stored ) === $saved['fields']
		&& ! empty( $stored['match_data']['options']['log_exclude'] ) === $exclude
		&& ! is_wp_error( $expected ) && $expected['match_url'] === $stored['match_url'];
}

function reviewed_rule_write( array $saved, callable $callback, \WP_REST_Request $request ) {
	return rule_transaction(
		static function () use ( $saved, $callback, $request ) {
			global $wpdb;
			$op     = $saved['operation'];
			$id     = $saved['id'];
			$fields = $saved['fields'];
			$stale  = error( 'stale', 'The redirect, matching defaults or group changed during the save. Review it again.', 409 );
			$failed = error( 'verification', 'The native redirect changed outside the approved review. The database change was rolled back.', 409 );
			$before = array();
			if ( 'create' === $op ) {
				$ids = locked_rule_ids();
				if ( is_wp_error( $ids ) || count( $ids ) >= MAX_RULES ) {
					return error( 'analysis_limit', 'Redirection could not reserve room within the 1000-rule review limit.', 409 );
				}
				if ( isset( $saved['request']['changes'] ) && array_diff_assoc( array_diff_key( creation_defaults(), $saved['request']['changes'] ), $fields ) ) {
					return $stale;
				}
			} else {
				if ( 1 !== $wpdb->query( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $wpdb->prefix . 'redirection_items', $id ) ) ) {
					return $stale;
				}
				$before = native_read( $id );
				$item   = is_wp_error( $before ) ? $before : snapshot( $before );
				if ( is_wp_error( $item ) || ! hash_equals( $saved['state_hash'], $item['state_hash'] ) ) {
					return $stale;
				}
			}
			$group = group( $fields['group_id'] );
			if ( is_wp_error( $group ) || ! hash_equals( $saved['group_hash'], hash( 'sha256', wp_json_encode( group_configuration( $group ) ) ) ) ) {
				return $stale;
			}
			if ( in_array( $op, array( 'create', 'update', 'enable' ), true ) ) {
				$safety = redirect_safety( $fields, $id );
				if ( is_wp_error( $safety ) ) {
					return $safety;
				}
			}
			$response = $callback( $request );
			if ( is_wp_error( $response ) || ( $response instanceof \WP_HTTP_Response && $response->get_status() >= 400 ) ) {
				return $response;
			}
			if ( 'create' === $op ) {
				$after_ids = locked_rule_ids();
				$new       = is_wp_error( $after_ids ) ? array() : array_values( array_diff( $after_ids, $ids ) );
				if ( 1 !== count( $new ) || count( $after_ids ) !== count( $ids ) + 1 ) {
					return $failed;
				}
				$id = $new[0];
			}
			$stored = native_read( $id );
			if ( 'delete' === $op ) {
				return is_wp_error( $stored ) && 'church_connector_redirect_missing' === $stored->get_error_code() ? $response : $failed;
			}
			if ( is_wp_error( $stored ) ) {
				return $failed;
			}
			if ( ! rule_matches_review( $saved, $before, $stored ) ) {
				return $failed;
			}
			if ( 'disable' !== $op ) {
				$safety = redirect_safety( $fields, $id );
				if ( is_wp_error( $safety ) ) {
					return $safety;
				}
			}
			if ( 'create' === $op ) {
				// The new ID came from the locked before/after set, with its full content verified.
				$response = rest_ensure_response( $response );
				$response->set_data( array( 'created_id' => $id ) + $response->get_data() );
			}
			return $response;
		}
	);
}

/** Fixed internal routes retain the current user, native permissions and REST policy filters. */
function dispatch( string $method, string $path, array $params = array(), ?array $review = null ) {
    $read = $method === 'GET' && in_array( $path, [ '/redirection/v1/redirect', '/redirection/v1/group' ], true )
        && ! array_diff( array_keys( $params ), [ 'filterBy', 'per_page', 'page', 'orderby', 'direction' ] );
    $write = $method === 'POST' && preg_match( '~\A/redirection/v1/redirect(?:/[1-9][0-9]*)?\z~', $path )
        && ! array_diff( array_keys( $params ), [ 'url', 'title', 'group_id', 'position', 'regex', 'match_type', 'match_data', 'action_type', 'action_code', 'action_data', 'status' ] );
    $bulk = $method === 'POST' && preg_match( '~\A/redirection/v1/bulk/redirect/(?:enable|disable|delete)\z~', $path )
        && array_keys( $params ) === [ 'items', 'global' ] && $params['global'] === false
        && is_array( $params['items'] ) && count( $params['items'] ) === 1 && is_int( $params['items'][0] ) && $params['items'][0] > 0;
    if ( ! $read && ! $write && ! $bulk ) { return error( 'route', 'This operation is outside the fixed Redirection route allowlist.', 403 ); }
    $request = new \WP_REST_Request( $method, $path );
    if ( $read ) { $request->set_query_params( $params ); } else { $request->set_body_params( $params ); }
	if ( ! $read && null === $review ) {
		return error( 'review', 'A native redirect write requires its reviewed state.', 409 );
	}
	$response = $read ? rest_do_request( $request ) : guarded_request(
		$request,
		static fn( $callback, $target ) => reviewed_rule_write( $review, $callback, $target )
	);
    if ( is_wp_error( $response ) ) { return $response; }
    $data = $response->get_data();
    if ( $response->get_status() >= 400 ) {
        return error( 'denied', is_array( $data ) && is_string( $data['message'] ?? null ) ? wp_strip_all_tags( $data['message'] ) : 'Redirection refused this REST operation.', $response->get_status() );
    }
    return is_array( $data ) ? $data : error( 'response', 'Redirection returned an unexpected response.', 502 );
}

function positive_id( mixed $id ): bool { return is_int( $id ) && $id > 0 && $id <= 2147483647; }

function group( int $id ) {
    $data = dispatch( 'GET', '/redirection/v1/group', [ 'filterBy' => [ 'module' => 1 ], 'per_page' => 200, 'page' => 0 ] );
    if ( is_wp_error( $data ) ) { return $data; }
    if ( ( $data['total'] ?? 201 ) > 200 ) { return error( 'groups', 'Too many groups for a bounded compatibility check.', 409 ); }
    foreach ( $data['items'] ?? [] as $item ) {
        if ( (int) ( $item['id'] ?? 0 ) === $id && (int) ( $item['module_id'] ?? 0 ) === 1 ) { return $item; }
    }
    return error( 'group', 'Choose an existing WordPress-module redirect group.', 400 );
}

function native_read( int $id ) {
    $data = dispatch( 'GET', '/redirection/v1/redirect', [ 'filterBy' => [ 'id' => $id ], 'per_page' => 5, 'page' => 0 ] );
    if ( is_wp_error( $data ) ) { return $data; }
    foreach ( $data['items'] ?? [] as $row ) { if ( (int) ( $row['id'] ?? 0 ) === $id ) { return $row; } }
    return error( 'missing', 'The redirect no longer exists.', 404 );
}

function configuration( array $row ): array {
    return array_diff_key( $row, array_flip( [ 'hits', 'last_access' ] ) );
}

function group_configuration( array $group ): array {
    // Last-selected/default and translated labels are UI state, not rule settings.
    return array_diff_key( $group, array_flip( [ 'default', 'moduleName' ] ) );
}

function plain_rule( array $row ): bool {
    $flags = $row['match_data']['source'] ?? [];
    return ( $row['match_type'] ?? '' ) === 'url' && ( $row['action_type'] ?? '' ) === 'url'
        && ( $row['regex'] ?? true ) === false && ( $flags['flag_regex'] ?? true ) === false
        && is_array( $row['action_data'] ?? null ) && ! array_diff( array_keys( $row['action_data'] ), [ 'url' ] )
        && ! array_diff( array_keys( (array) ( $row['match_data'] ?? [] ) ), [ 'source', 'options' ] )
        && ! array_diff( array_keys( $flags ), [ 'flag_query', 'flag_case', 'flag_trailing', 'flag_regex' ] )
        && ! array_diff( array_keys( (array) ( $row['match_data']['options'] ?? [] ) ), [ 'log_exclude' ] );
}

function rule_fields( array $row ): array {
    $flags = $row['match_data']['source'] ?? [];
    return [ 'source' => (string) ( $row['url'] ?? '' ), 'target' => plain_rule( $row ) ? (string) ( $row['action_data']['url'] ?? '' ) : null,
        'title' => (string) ( $row['title'] ?? '' ), 'http_code' => (int) ( $row['action_code'] ?? 0 ), 'group_id' => (int) $row['group_id'],
        'position' => (int) ( $row['position'] ?? 0 ), 'ignore_case' => (bool) ( $flags['flag_case'] ?? false ),
        'ignore_trailing_slash' => (bool) ( $flags['flag_trailing'] ?? false ), 'query_parameters' => (string) ( $flags['flag_query'] ?? 'exact' ) ];
}

function snapshot( array $row ) {
    $g = group( (int) ( $row['group_id'] ?? 0 ) );
    if ( is_wp_error( $g ) ) { return $g; }
    $supported = plain_rule( $row ); $fields = rule_fields( $row );
    return [ 'id' => (int) $row['id'], 'enabled' => (bool) ( $row['enabled'] ?? false ), 'editable' => $supported,
        'fields' => $fields, 'group' => $g, 'state_hash' => hash( 'sha256', wp_json_encode( [ configuration( $row ), group_configuration( $g ) ] ) ),
        'limitation' => $supported ? null : 'Only plain URL-to-URL redirects in WordPress groups can be changed. Use the native editor for other match/action types or regular expressions.' ];
}

function capabilities( $input ) {
	$checked = check( $input, array( 'site_url' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked; }
	return array(
		'site_url'                 => \ChurchWebsiteConnector\bound_site(),
		'redirection'              => readiness(),
		'operations'               => array( 'list', 'get', 'list_groups', 'create', 'update', 'enable', 'disable', 'delete' ),
		'editable_fields'          => FIELDS,
		'administration'           => 'Separate Redirection admin tools offer richer search, group changes, selected batches, plain rule imports and exports, matching defaults, and counts of retained log entries for a specified path.',
		'preview_lifetime_seconds' => TTL,
		'workflow'                 => 'Read the current rule, preview one change using its state_hash, review the diff, then apply its owner-bound token after approval. For create, omit id and state_hash. Delete is permanent and requires explicit confirmation. One outstanding Redirection preview per account.',
		'safety_limits'            => array(
			'rules'           => MAX_RULES,
			'groups'          => 200,
			'chain_hops'      => MAX_HOPS,
			'matching_checks' => MAX_MATCHES,
		),
		'limitations'              => 'Checks equivalent sources and bounded local chains among plain WordPress URL rules. Regex/conditional rules, external aliases, other plugins and server redirects are not modelled. Single-rule tools accept plain rules only. The administration tools have separate fixed operations and do not return individual visitor records. Reviewed rule writes recheck current native configuration inside a database transaction. Approval flags acknowledge client approval; they are not independent proof of human consent.',
	);
}

function listing( $input ) {
    $checked = check( $input, [ 'site_url', 'search', 'page', 'per_page' ] ); if ( is_wp_error( $checked ) ) { return $checked; }
    $page = $input['page'] ?? 0; $limit = $input['per_page'] ?? 25; $search = $input['search'] ?? '';
    if ( ! is_int( $page ) || $page < 0 || $page > 10000 || ! is_int( $limit ) || $limit < 5 || $limit > 100 || ! is_string( $search ) || strlen( $search ) > 2000 ) { return error( 'input', 'Use page 0–10000, per_page 5–100 and a search string of at most 2000 bytes.' ); }
    $data = dispatch( 'GET', '/redirection/v1/redirect', [ 'filterBy' => $search === '' ? [] : [ 'url' => $search ], 'page' => $page, 'per_page' => $limit, 'orderby' => 'id', 'direction' => 'asc' ] );
    if ( is_wp_error( $data ) ) { return $data; }
    $items = [];
    foreach ( $data['items'] ?? [] as $row ) {
        // List summaries do not expose visitor logs or conditional matching secrets.
        $items[] = [ 'id' => (int) $row['id'], 'source' => (string) $row['url'], 'title' => (string) ( $row['title'] ?? '' ),
            'target' => ( $row['match_type'] ?? '' ) === 'url' && ( $row['action_type'] ?? '' ) === 'url' ? ( $row['action_data']['url'] ?? null ) : null,
            'enabled' => (bool) ( $row['enabled'] ?? false ), 'http_code' => (int) $row['action_code'], 'group_id' => (int) $row['group_id'] ];
    }
    return [ 'site_url' => \ChurchWebsiteConnector\bound_site(), 'items' => $items, 'total' => (int) ( $data['total'] ?? 0 ), 'page' => $page, 'per_page' => $limit ];
}

function groups( $input ) {
    $checked = check( $input, [ 'site_url', 'page' ] ); if ( is_wp_error( $checked ) ) { return $checked; }
    $page = $input['page'] ?? 0;
    if ( ! is_int( $page ) || $page < 0 || $page > 10000 ) { return error( 'input', 'Use a nonnegative page offset.' ); }
    $data = dispatch( 'GET', '/redirection/v1/group', [ 'filterBy' => [ 'module' => 1 ], 'page' => $page, 'per_page' => 100 ] );
    return is_wp_error( $data ) ? $data : [ 'site_url' => \ChurchWebsiteConnector\bound_site(), 'groups' => $data, 'page' => $page ];
}

function get_rule( $input ) {
    $checked = check( $input, [ 'site_url', 'id' ] ); if ( is_wp_error( $checked ) ) { return $checked; }
    if ( ! positive_id( $input['id'] ?? null ) ) { return error( 'id', 'Choose a positive integer redirect id.' ); }
    $row = native_read( $input['id'] ); if ( is_wp_error( $row ) ) { return $row; }
    $item = snapshot( $row );
    return is_wp_error( $item ) ? $item : [ 'site_url' => \ChurchWebsiteConnector\bound_site(), 'redirect' => $item ];
}

function valid_url( string $url, bool $source ): bool {
    if ( $url === '' || strlen( $url ) > 2000 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { return false; }
    $decoded = $url;
    for ( $i = 0; $i < 3; $i++ ) { $decoded = rawurldecode( $decoded ); }
    if ( preg_match( '/[\x00-\x1f\x7f\\\\]/', $decoded ) || str_starts_with( $url, '//' ) ) { return false; }
    $parts = wp_parse_url( $url );
    if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return false; }
    if ( str_starts_with( $url, '/' ) ) {
        if ( isset( $parts['host'] ) || isset( $parts['scheme'] ) || ( $source && isset( $parts['fragment'] ) ) ) { return false; }
    } elseif ( $source || ! in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) || empty( $parts['host'] ) || esc_url_raw( $url, [ 'http', 'https' ] ) !== $url ) { return false; }
    if ( $source ) {
        $path = rawurldecode( $parts['path'] ?? '/' );
        $home_path = wp_parse_url( \ChurchWebsiteConnector\bound_site(), PHP_URL_PATH ) ?: '';
        if ( $home_path !== '' && $path !== $home_path && ! str_starts_with( $path, $home_path . '/' ) ) { return false; }
        $relative = substr( $path, strlen( $home_path ) );
        if ( preg_match( '~\A/(?:wp-admin(?:/|$)|wp-json(?:/|$)|wp-login\.php(?:/|$)|xmlrpc\.php(?:/|$))~i', $relative ) || preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', $path ) ) { return false; }
    }
    return true;
}

function payload( array $fields, array $row = [] ): array {
    $match_data = (array) ( $row['match_data'] ?? [] );
    $match_data['source'] = [ 'flag_regex' => false, 'flag_query' => $fields['query_parameters'], 'flag_case' => $fields['ignore_case'], 'flag_trailing' => $fields['ignore_trailing_slash'] ];
    return [ 'url' => $fields['source'], 'title' => $fields['title'], 'group_id' => $fields['group_id'], 'position' => $fields['position'],
        'regex' => false, 'match_type' => 'url', 'match_data' => $match_data, 'action_type' => 'url', 'action_code' => $fields['http_code'], 'action_data' => [ 'url' => $fields['target'] ] ];
}

function creation_defaults(): array {
	$native = \Red_Options::get();
	return array(
		'ignore_case'           => $native['flag_case'],
		'ignore_trailing_slash' => $native['flag_trailing'],
		'query_parameters'      => $native['flag_query'],
	);
}

function normalise( array $base, mixed $changes, array $row = [] ) {
    if ( ! is_array( $changes ) || ! $changes || array_diff( array_keys( $changes ), FIELDS ) ) { return error( 'fields', 'Use only the documented URL redirect fields.' ); }
    $values = array_replace( $base, $changes );
    foreach ( FIELDS as $field ) { if ( ! array_key_exists( $field, $values ) ) { return error( 'fields', 'The redirect details are incomplete.' ); } }
    if ( ! is_string( $values['source'] ) || ! valid_url( $values['source'], true ) || ! is_string( $values['target'] ) || ! valid_url( $values['target'], false ) ) { return error( 'url', 'Use a local source path and a relative or HTTP(S) target URL, without credentials, control characters or administrative source paths.' ); }
    if ( ! is_string( $values['title'] ) || strlen( $values['title'] ) > 500 ) { return error( 'value', 'Use a title of at most 500 bytes.' ); }
    $values['title'] = sanitize_text_field( $values['title'] );
    if ( ! in_array( $values['http_code'], [ 301, 302, 303, 307, 308 ], true ) || ! positive_id( $values['group_id'] ) || ! is_int( $values['position'] ) || $values['position'] < 0 || $values['position'] > 2147483647
        || ! is_bool( $values['ignore_case'] ) || ! is_bool( $values['ignore_trailing_slash'] ) || ! in_array( $values['query_parameters'], [ 'exact', 'exactorder', 'ignore', 'pass' ], true ) ) { return error( 'value', 'Use supported HTTP codes, group/position integers, booleans and query-parameter modes.' ); }
    $g = group( $values['group_id'] ); if ( is_wp_error( $g ) ) { return $g; }
    if ( ! $row && $values['position'] === 0 ) { $values['position'] = (int) ( $g['redirects'] ?? 0 ); }
    $target_request = next_request( $values, (string) local_request( $values['source'] ) );
    if ( $target_request !== null && matches_request( $values, $target_request ) ) { return error( 'loop', 'The target would redirect this source back to itself.', 409 ); }
    $validated = ( new \Red_Item_Sanitize() )->get( payload( $values, $row ) );
    if ( is_wp_error( $validated ) ) { return error( 'validation', wp_strip_all_tags( $validated->get_error_message() ) ); }
    // Native validation is authoritative; never silently accept a different preview.
    if ( ( $validated['url'] ?? null ) !== $values['source'] || (int) ( $validated['action_code'] ?? 0 ) !== $values['http_code'] || (int) ( $validated['group_id'] ?? 0 ) !== $values['group_id'] ) { return error( 'normalisation', 'Redirection would change the source, HTTP code or group. Review these values in its native editor.' ); }
    return array_replace( array_fill_keys( FIELDS, null ), $values );
}

function write_permission( string $operation ) {
    $cap = $operation === 'delete' ? \Redirection_Capabilities::CAP_REDIRECT_DELETE : \Redirection_Capabilities::CAP_REDIRECT_ADD;
    return \Redirection_Capabilities::has_access( $cap ) ? true : error( 'forbidden', 'Redirection does not permit this account to perform that change.', 403 );
}

function stage_name( int $id ): string { return PREFIX . $id; }
function remove_exact( string $name, mixed $value ): bool { return \ChurchWebsiteConnector\RestTools\conditional_delete( $name, $value ); }
function expire_preview( int $owner, string $hash ): void {
    $saved = get_option( stage_name( $owner ), false );
    if ( is_array( $saved ) && ( $saved['token_hash'] ?? '' ) === $hash && ( $saved['expires'] ?? 0 ) <= time() ) { remove_exact( stage_name( $owner ), $saved ); }
}

function preview( $input, ?array $replaces = null ) {
    $checked = check( $input, [ 'site_url', 'operation', 'id', 'state_hash', 'changes' ] ); if ( is_wp_error( $checked ) ) { return $checked; }
    $op = $input['operation'] ?? '';
    if ( ! in_array( $op, [ 'create', 'update', 'enable', 'disable', 'delete' ], true ) ) { return error( 'operation', 'Choose create, update, enable, disable or delete.' ); }
    $allowed = write_permission( $op ); if ( is_wp_error( $allowed ) ) { return $allowed; }
    $row = []; $item = null; $id = $input['id'] ?? 0;
    if ( $op === 'create' ) {
        if ( isset( $input['id'] ) || isset( $input['state_hash'] ) ) { return error( 'input', 'Create must omit id and state_hash.' ); }
		$base = array(
			'title'     => '',
			'http_code' => 301,
			'position'  => 0,
		) + creation_defaults();
    } else {
        if ( ! positive_id( $id ) ) { return error( 'id', 'Choose a positive integer redirect id.' ); }
        $row = native_read( $id ); if ( is_wp_error( $row ) ) { return $row; }
        $item = snapshot( $row ); if ( is_wp_error( $item ) ) { return $item; }
        if ( ! $item['editable'] ) { return error( 'unsupported', $item['limitation'] ); }
        if ( ! is_string( $input['state_hash'] ?? null ) || ! hash_equals( $item['state_hash'], $input['state_hash'] ) ) { return error( 'stale', 'The redirect or group changed. Read it again before previewing.', 409 ); }
        $base = $item['fields'];
    }
    if ( in_array( $op, [ 'create', 'update' ], true ) ) {
        $fields = normalise( $base, $input['changes'] ?? null, $row ); if ( is_wp_error( $fields ) ) { return $fields; }
        $conflicts = redirect_safety( $fields, $id ); if ( is_wp_error( $conflicts ) ) { return $conflicts; }
        $diff = [];
        foreach ( $fields as $key => $value ) { if ( $op === 'create' || $base[ $key ] !== $value ) { $diff[ $key ] = [ 'before' => $op === 'create' ? null : $base[ $key ], 'after' => $value ]; } }
        if ( $op === 'create' ) { $diff['enabled'] = [ 'before' => null, 'after' => true ]; }
        if ( ! $diff ) { return error( 'unchanged', 'The proposed rule is already saved.', 409 ); }
    } else {
        if ( array_key_exists( 'changes', $input ) ) { return error( 'input', 'Only create and update accept changes.' ); }
        $fields = $base;
        if ( $op === 'enable' ) {
            $validated = normalise( $base, $base, $row ); if ( is_wp_error( $validated ) ) { return $validated; }
            $conflicts = redirect_safety( $validated, $id ); if ( is_wp_error( $conflicts ) ) { return $conflicts; }
        }
        if ( $op !== 'delete' && $item['enabled'] === ( $op === 'enable' ) ) { return error( 'unchanged', 'The rule is already in that state.', 409 ); }
        $diff = $op === 'delete' ? [ 'redirect' => [ 'before' => $item, 'after' => null ] ] : [ 'enabled' => [ 'before' => $item['enabled'], 'after' => $op === 'enable' ] ];
    }
    $g = group( $fields['group_id'] ); if ( is_wp_error( $g ) ) { return $g; }
	$saved = save_preview(
		array(
			'operation'      => $op,
			'id'             => $id,
			'state_hash'     => $item['state_hash'] ?? null,
			'fields'         => $fields,
			'group_hash'     => hash( 'sha256', wp_json_encode( group_configuration( $g ) ) ),
			'changed_fields' => array_keys( $diff ),
			'request'        => array_intersect_key( $input, array_flip( array( 'operation', 'changes', 'id', 'site_url' ) ) ),
		),
		$replaces
	);
    if ( is_wp_error( $saved ) ) { return $saved; }
    return [ 'site_url' => \ChurchWebsiteConnector\bound_site(), 'operation' => $op, 'id' => $id, 'diff' => $diff, 'group' => $g,
        'message' => $op === 'delete' ? 'Preview only. Deletion is permanent with no undo. Obtain explicit confirmation before applying.' : 'Preview only; no redirect changed. Review and approve before applying. A new preview replaces your previous Redirection preview.' ] + $saved;
}

function save_preview( array $change, ?array $replaces = null ) {
	return \ChurchWebsiteConnector\RestTools\stage_review( $change, $replaces, PREFIX, EXPIRE_HOOK, TTL, 'church_connector_redirect_' );
}

function load_preview( mixed $token ) {
    if ( ! is_string( $token ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $token ) ) { return error( 'token', 'Use a valid preview token.' ); }
    $saved = get_option( stage_name( get_current_user_id() ), false );
    if ( ! is_array( $saved ) || ( $saved['owner'] ?? 0 ) !== get_current_user_id() || ! hash_equals( (string) ( $saved['token_hash'] ?? '' ), hash( 'sha256', $token ) ) ) { return error( 'token', 'This preview is unavailable for the signed-in account.', 410 ); }
    if ( $saved['expires'] <= time() ) { expire_preview( get_current_user_id(), $saved['token_hash'] ); return error( 'expired', 'The preview expired. Prepare a new one.', 410 ); }
	if ( ! \ChurchWebsiteConnector\RestTools\preview_site_matches( $saved ) ) {
		return error( 'site', 'This review belongs to an earlier or different website configuration. Prepare a fresh preview.', 403 );
	}
    return $saved;
}

function audit( array $saved, string $result ): void {
	\ChurchWebsiteConnector\RestTools\append_audit(
		AUDIT,
		array(
			'time_utc'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'user_id'   => get_current_user_id(),
			'operation' => $saved['operation'],
			'id'        => $saved['id'],
			'fields'    => $saved['changed_fields'],
			'result'    => $result,
		)
	);
}

function acquire_lock() {
	global $wpdb;
	$lock = wp_json_encode(
		array(
			'expires' => time() + 120,
			'token'   => wp_generate_uuid4(),
		)
	);
	if ( \ChurchWebsiteConnector\RestTools\insert_lock( LOCK, $lock ) ) {
		return $lock;
	}
	$old     = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", LOCK ) );
	$decoded = is_string( $old ) ? json_decode( $old, true ) : null;
	if ( is_array( $decoded ) && ( $decoded['expires'] ?? PHP_INT_MAX ) < time() ) {
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND HEX(option_value) = HEX(%s)", $lock, LOCK, $old ) );
		if ( 1 === $claimed ) {
			wp_cache_delete( LOCK, 'options' );
			return $lock;
		}
	}
	return error( 'busy', 'Another connector redirect change is running.', 409 );
}

function consume( array $saved ): bool {
    if ( ! remove_exact( stage_name( get_current_user_id() ), $saved ) ) { return false; }
    wp_clear_scheduled_hook( EXPIRE_HOOK, [ get_current_user_id(), $saved['token_hash'] ] );
    return true;
}

function refresh_rule( array $saved, ?string $hash ): \WP_Error {
	$error = error( 'stale', 'The redirect or its group changed after preview.', 409 );
	if ( ! isset( $saved['request'] ) ) {
		\ChurchWebsiteConnector\RestTools\retire_preview( $saved, PREFIX, EXPIRE_HOOK );
		return $error;
	}
	$request = $saved['request'];
	if ( null !== $hash ) {
		$request['state_hash'] = $hash;
	}
	return \ChurchWebsiteConnector\RestTools\refreshed_error( $error, preview( $request, $saved ), $saved, PREFIX, EXPIRE_HOOK );
}

function apply( $input ) {
    $checked = check( $input, [ 'site_url', 'preview_token', 'approved' ] ); if ( is_wp_error( $checked ) ) { return $checked; }
    if ( ( $input['approved'] ?? false ) !== true ) { return error( 'approval', 'Apply only after approval, with approved=true.' ); }
    $saved = load_preview( $input['preview_token'] ?? null ); if ( is_wp_error( $saved ) ) { return $saved; }
    if ( ! in_array( $saved['operation'] ?? null, [ 'create', 'update', 'enable', 'disable', 'delete' ], true ) ) { return error( 'token', 'Use the apply tool named by the preview.', 409 ); }
    $allowed = write_permission( $saved['operation'] ); if ( is_wp_error( $allowed ) ) { return $allowed; }
    $lock = acquire_lock(); if ( is_wp_error( $lock ) ) { return $lock; }
    try {
        $op = $saved['operation']; $id = $saved['id']; $row = [];
		if ( 'create' === $op && isset( $saved['request']['changes'] ) ) {
			$defaults = array_diff_key( creation_defaults(), $saved['request']['changes'] );
			if ( array_diff_assoc( $defaults, $saved['fields'] ) ) {
				return refresh_rule( $saved, null );
			}
		}
        if ( $op !== 'create' ) {
            $row = native_read( $id ); if ( is_wp_error( $row ) ) { return $row; }
            $current = snapshot( $row ); if ( is_wp_error( $current ) ) { return $current; }
			if ( ! $current['editable'] || ! hash_equals( $saved['state_hash'], $current['state_hash'] ) ) {
				return refresh_rule( $saved, $current['state_hash'] ?? null );
			}
        }
        $g = group( $saved['fields']['group_id'] ); if ( is_wp_error( $g ) ) { return $g; }
		if ( ! hash_equals( $saved['group_hash'], hash( 'sha256', wp_json_encode( group_configuration( $g ) ) ) ) ) {
			return refresh_rule( $saved, $current['state_hash'] ?? null );
		}
        if ( in_array( $op, [ 'create', 'update', 'enable' ], true ) ) {
            $fields = normalise( $saved['fields'], $saved['fields'], $row ); if ( is_wp_error( $fields ) ) { return $fields; }
			if ( $fields !== $saved['fields'] ) {
				return refresh_rule( $saved, $current['state_hash'] ?? null );
			}
            $conflicts = redirect_safety( $fields, $id ); if ( is_wp_error( $conflicts ) ) { return $conflicts; }
        }
        if ( ! consume( $saved ) ) { return error( 'token', 'The preview was replaced or consumed.', 409 ); }
        if ( $op === 'create' || $op === 'update' ) {
            $body = payload( $saved['fields'], $row );
            if ( $op === 'create' ) { $body['status'] = 'enabled'; }
			$result = dispatch( 'POST', '/redirection/v1/redirect' . ( 'update' === $op ? '/' . $id : '' ), $body, $saved );
        } else {
			$result = dispatch(
				'POST',
				'/redirection/v1/bulk/redirect/' . $op,
				array(
					'items'  => array( $id ),
					'global' => false,
				),
				$saved
			);
        }
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'church_connector_redirect_analysis_limit', 'church_connector_redirect_stale', 'church_connector_redirect_duplicate', 'church_connector_redirect_loop', 'church_connector_redirect_verification' ), true ) ) {
			audit( $saved, 'error' );
			return $result;
		}
        if ( is_wp_error( $result ) ) { audit( $saved, 'error' ); return error( 'failed', 'Redirection did not complete cleanly: ' . $result->get_error_message() . ' Inspect the rule before retrying; the preview is consumed.', 500 ); }
		if ( 'create' === $op ) {
			$id = $result['created_id'] ?? 0;
			if ( ! positive_id( $id ) ) {
				audit( $saved, 'verification_unavailable' );
				return error( 'verification', 'Redirection did not return the verified new rule ID. Inspect the group before retrying.', 502 );
			}
		}
        $after = native_read( $id );
        if ( $op === 'delete' ) {
            if ( ! is_wp_error( $after ) || $after->get_error_code() !== 'church_connector_redirect_missing' ) { audit( $saved, 'verification_unavailable' ); return error( 'verification', 'Deletion was processed but could not be verified. Inspect before retrying.', 502 ); }
            $item = null;
        } else {
            if ( is_wp_error( $after ) ) { audit( $saved, 'verification_unavailable' ); return error( 'verification', 'The write was processed but reading it back failed. Inspect before retrying.', 502 ); }
            $item = snapshot( $after );
            if ( is_wp_error( $item ) ) { audit( $saved, 'verification_unavailable' ); return $item; }
			$matches = rule_matches_review( $saved, $row, $after );
            if ( ! $matches ) { audit( $saved, 'normalised' ); return error( 'verification', 'Native filters stored different values. Inspect the rule before retrying; the preview is consumed.', 409 ); }
        }
        $saved['id'] = $id; audit( $saved, 'verified' );
        return [ 'site_url' => \ChurchWebsiteConnector\bound_site(), 'operation' => $op, 'id' => $id, 'verified' => true, 'redirect' => $item, 'message' => 'The native REST operation completed and was read back. The preview token is consumed. No page or Used CSS cache was cleared.' ];
    } catch ( \Throwable $e ) {
        audit( $saved, 'error' ); return error( 'failed', 'The change did not complete cleanly. Inspect the rule before retrying; it may already have changed.', 500 );
    } finally { remove_exact( LOCK, $lock ); }
}

function discard( $input ) {
    $checked = check( $input, [ 'site_url', 'preview_token' ] ); if ( is_wp_error( $checked ) ) { return $checked; }
    $saved = load_preview( $input['preview_token'] ?? null ); if ( is_wp_error( $saved ) ) { return $saved; }
    if ( ! remove_exact( stage_name( get_current_user_id() ), $saved ) ) { return error( 'token', 'The preview was replaced or consumed.', 409 ); }
    wp_clear_scheduled_hook( EXPIRE_HOOK, [ get_current_user_id(), $saved['token_hash'] ] );
    return [ 'site_url' => \ChurchWebsiteConnector\bound_site(), 'discarded' => true ];
}

function register_abilities(): void {
    if ( ! function_exists( 'wp_register_ability' ) || ! \ChurchWebsiteConnector\feature_enabled( 'redirection_tools' ) ) { return; }
    $site = [ 'site_url' => [ 'type' => 'string', 'enum' => [ \ChurchWebsiteConnector\bound_site() ] ] ];
    $id = [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647 ];
    $token = [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ];
    $fields = [ 'source' => [ 'type' => 'string', 'maxLength' => 2000 ], 'target' => [ 'type' => 'string', 'maxLength' => 2000 ], 'title' => [ 'type' => 'string', 'maxLength' => 500 ],
        'http_code' => [ 'type' => 'integer', 'enum' => [ 301, 302, 303, 307, 308 ] ], 'group_id' => $id,
        'position' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647 ], 'ignore_case' => [ 'type' => 'boolean' ], 'ignore_trailing_slash' => [ 'type' => 'boolean' ],
        'query_parameters' => [ 'type' => 'string', 'enum' => [ 'exact', 'exactorder', 'ignore', 'pass' ], 'description' => 'Native query modes: exact matches all query values in any order; exactorder also uses native order-sensitive candidate selection. Ignore/pass permit additional incoming parameters while retaining source query constraints. Pass forwards parameters to the target.' ] ];
    $definitions = [
        'redirection-capabilities' => [ 'Redirection MCP capabilities', 'Read-only website, compatibility, operations and workflow for the installed Redirection plugin. Uses the existing MCP account and native Redirection permissions.', 'capabilities', $site, [ 'site_url' ], true ],
        'list-redirections' => [ 'Find Redirection rules', 'Read bounded redirect summaries, optionally searching source URLs. Zero-based pagination; no visitor logs or arbitrary REST proxy.', 'listing', $site + [ 'search' => [ 'type' => 'string', 'maxLength' => 2000 ], 'page' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000 ], 'per_page' => [ 'type' => 'integer', 'minimum' => 5, 'maximum' => 100 ] ], [ 'site_url' ], true ],
        'get-redirection' => [ 'Read one Redirection rule', 'Read a current redirect, editable URL fields, group and state_hash. Traffic counters do not invalidate previews. Non-URL/regex rules are read-only.', 'get_rule', $site + [ 'id' => $id ], [ 'site_url', 'id' ], true ],
        'list-redirection-groups' => [ 'List WordPress redirect groups', 'Read existing WordPress-module groups for selecting a rule’s group. Group settings are not changed.', 'groups', $site + [ 'page' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000 ] ], [ 'site_url' ], true ],
        'preview-redirection-change' => [ 'Preview a Redirection change', 'Stage one create, update, enable, disable or permanent delete without changing any redirect. For create supply source, target and group_id in changes and omit id/state_hash. Otherwise read the current rule first and supply id/state_hash. Only create/update accept changes. Review the diff before applying; permanent delete needs explicit confirmation. One 15-minute owner-bound Redirection preview per account.', 'preview', $site + [ 'operation' => [ 'type' => 'string', 'enum' => [ 'create', 'update', 'enable', 'disable', 'delete' ] ], 'id' => $id, 'state_hash' => $token, 'changes' => [ 'type' => 'object', 'properties' => $fields, 'minProperties' => 1, 'additionalProperties' => false ] ], [ 'site_url', 'operation' ], false ],
        'apply-redirection-change' => [ 'Apply an approved Redirection change', 'WRITE: apply exactly one retained, reviewed preview after user approval. Permanent deletion has no undo and requires explicit confirmation. Rechecks account, website, native permissions, expiry, current rule/group and source conflicts. approved=true acknowledges approval; it is not authentication or proof of consent. Read-back verifies the result. No global operations or cache clearing.', 'apply', $site + [ 'preview_token' => $token, 'approved' => [ 'type' => 'boolean', 'const' => true ] ], [ 'site_url', 'preview_token', 'approved' ], false ],
        'discard-redirection-change' => [ 'Discard a Redirection preview', 'Discard only the signed-in account’s Redirection preview without changing a rule.', 'discard', $site + [ 'preview_token' => $token ], [ 'site_url', 'preview_token' ], false ],
    ];
    foreach ( $definitions as $name => [ $label, $description, $callback, $properties, $required, $readonly ] ) {
        wp_register_ability( 'church-connector/' . $name, [ 'label' => $label, 'description' => $description, 'category' => 'church-connector',
            'input_schema' => [ 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ],
            'permission_callback' => __NAMESPACE__ . '\\permission', 'execute_callback' => __NAMESPACE__ . '\\' . $callback,
            'meta' => [ 'show_in_rest' => false, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readonly' => $readonly, 'destructive' => $name === 'apply-redirection-change', 'idempotent' => $readonly ] ],
        ] );
    }
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities' );
add_action( EXPIRE_HOOK, __NAMESPACE__ . '\\expire_preview', 10, 2 );
