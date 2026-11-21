<?php
/** Reviewed Redirection administration through its native REST callbacks. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\RedirectionAdmin;

use ChurchWebsiteConnector as Connector;
use ChurchWebsiteConnector\RedirectionTools as Redirection;
use ChurchWebsiteConnector\ScopedAbilities;

defined( 'ABSPATH' ) || exit;

const OPERATIONS = array( 'create_group', 'rename_group', 'enable_group', 'disable_group', 'delete_empty_group', 'bulk_enable', 'bulk_disable', 'bulk_delete', 'update_redirect_defaults', 'import_disabled_rules' );
const DEFAULTS   = array( 'flag_case', 'flag_trailing', 'flag_query' );
const MAX_ITEMS  = 25;

function check( $input, array $keys ): true|\WP_Error {
	return Redirection\check( $input, array_merge( array( 'site_url' ), $keys ) );
}

/** These paths and arguments are selected by typed callbacks inside this module. */
function request( string $method, string $route, array $params = array(), ?callable $write = null ): array|\WP_Error {
	$request = new \WP_REST_Request( $method, '/redirection/v1/' . $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}
	$response = null === $write ? rest_do_request( $request ) : Redirection\guarded_request( $request, $write );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( $response->get_status() >= 400 || ! is_array( $response->get_data() ) ) {
		return Redirection\error( 'native_admin', 'Redirection refused this operation. Read the current state before retrying.', max( 400, $response->get_status() ) );
	}
	return $response->get_data();
}

function log_count( $input ): array|\WP_Error {
	$checked = check( $input, array( 'path', 'log' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$path = $input['path'] ?? null;
	$log  = $input['log'] ?? null;
	if ( ! is_string( $path ) || ! Redirection\valid_url( $path, true ) || str_contains( $path, '?' ) || ! in_array( $log, array( 'redirect', '404' ), true ) ) {
		return Redirection\error( 'log_input', 'Choose redirect or 404 and one exact local source path without a query string.' );
	}
	$result = request(
		'GET',
		'404' === $log ? '404' : 'log',
		array(
			'filterBy' => array( 'url-exact' => $path ),
			'per_page' => 5,
			'page'     => 0,
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return array(
		'site_url' => Connector\bound_site(),
		'path'     => $path,
		'log'      => $log,
		'count'    => (int) ( $result['total'] ?? 0 ),
	);
}

function search( $input ): array|\WP_Error {
	$checked = check( $input, array( 'source', 'target', 'title', 'enabled', 'match_format', 'group_id', 'http_code', 'page' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$filter = array();
	foreach ( array(
		'source' => 'url',
		'target' => 'target',
		'title'  => 'title',
	) as $key => $native ) {
		if ( isset( $input[ $key ] ) ) {
			if ( ! is_string( $input[ $key ] ) || '' === $input[ $key ] || strlen( $input[ $key ] ) > 500 ) {
				return Redirection\error( 'filter', 'Choose filter text up to 500 bytes.' );
			}
			$filter[ $native ] = $input[ $key ];
		}
	}
	$page = $input['page'] ?? 0;
	if ( ! is_int( $page ) || $page < 0 || $page > 1000
		|| ( isset( $input['enabled'] ) && ! is_bool( $input['enabled'] ) )
		|| ( isset( $input['match_format'] ) && ! in_array( $input['match_format'], array( 'plain', 'regular' ), true ) )
		|| ( isset( $input['group_id'] ) && ! Redirection\positive_id( $input['group_id'] ) )
		|| ( isset( $input['http_code'] ) && ( ! is_int( $input['http_code'] ) || $input['http_code'] < 300 || $input['http_code'] > 599 ) ) ) {
		return Redirection\error( 'filter', 'Choose a valid page or supported rule filter.' );
	}
	if ( isset( $input['http_code'] ) ) {
		$validator = new \Red_Item_Sanitize();
		if ( ! $validator->is_valid_redirect_code( $input['http_code'] ) && ! $validator->is_valid_error_code( $input['http_code'] ) ) {
			return Redirection\error( 'filter', 'Redirection does not support that HTTP code as a rule filter.' );
		}
	}
	if ( isset( $input['enabled'] ) ) {
		$filter['status'] = $input['enabled'] ? 'enabled' : 'disabled';
	}
	foreach ( array(
		'match_format' => 'url-match',
		'group_id'     => 'group',
		'http_code'    => 'http',
	) as $key => $native ) {
		if ( isset( $input[ $key ] ) ) {
			$filter[ $native ] = $input[ $key ];
		}
	}
	$result = Redirection\dispatch(
		'GET',
		'/redirection/v1/redirect',
		array(
			'filterBy'  => $filter,
			'page'      => $page,
			'per_page'  => 25,
			'orderby'   => 'id',
			'direction' => 'asc',
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$items = array_map( __NAMESPACE__ . '\\summarize', $result['items'] );
	foreach ( $items as $item ) {
		foreach ( array( 'http_code', 'group_id', 'enabled', 'match_format' ) as $key ) {
			if ( isset( $input[ $key ] ) && $item[ $key ] !== $input[ $key ] ) {
				return Redirection\error( 'filter', 'The native search returned a rule outside the requested filters.', 409 );
			}
		}
		foreach ( array( 'source', 'title', 'target' ) as $key ) {
			if ( isset( $input[ $key ] ) && ( ! is_string( $item[ $key ] ) || ! preg_match( '~' . preg_quote( remove_accents( $input[ $key ] ), '~' ) . '~iu', remove_accents( $item[ $key ] ) ) ) ) {
				return Redirection\error( 'filter', 'The native search returned a rule whose visible fields do not match the requested text.', 409 );
			}
		}
	}
	return array(
		'site_url' => Connector\bound_site(),
		'page'     => $page,
		'total'    => (int) $result['total'],
		'items'    => $items,
	);
}

function summarize( array $row ): array {
	return array(
		'id'           => (int) $row['id'],
		'source'       => (string) $row['url'],
		'title'        => (string) ( $row['title'] ?? '' ),
		'target'       => Redirection\rule_fields( $row )['target'],
		'enabled'      => ! empty( $row['enabled'] ),
		'match_format' => ! empty( $row['regex'] ) ? 'regular' : 'plain',
		'match_type'   => (string) ( $row['match_type'] ?? '' ),
		'action_type'  => (string) ( $row['action_type'] ?? '' ),
		'http_code'    => (int) $row['action_code'],
		'group_id'     => (int) $row['group_id'],
		'state_hash'   => fingerprint( Redirection\configuration( $row ) ),
	);
}

function fingerprint( array $data ): string {
	return hash( 'sha256', wp_json_encode( $data ) );
}

function group_rules( int $id ): array|\WP_Error {
	$result = Redirection\dispatch(
		'GET',
		'/redirection/v1/redirect',
		array(
			'filterBy'  => array( 'group' => $id ),
			'page'      => 0,
			'per_page'  => 200,
			'orderby'   => 'id',
			'direction' => 'asc',
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( count( $result['items'] ) !== (int) $result['total'] ) {
		return Redirection\error( 'group_size', 'Use the native editor for groups with more than 200 rules.', 409 );
	}
	foreach ( $result['items'] as $row ) {
		if ( (int) $row['group_id'] !== $id ) {
			return Redirection\error( 'group_filter', 'The native group filter returned unexpected data.', 409 );
		}
	}
	return $result['items'];
}

function group_state( int $id ): array|\WP_Error {
	$group = Redirection\group( $id );
	if ( is_wp_error( $group ) ) {
		return $group;
	}
	$rows = group_rules( $id );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	return array(
		'group'      => Redirection\group_configuration( $group ),
		'rows'       => $rows,
		'state_hash' => fingerprint( array( Redirection\group_configuration( $group ), array_map( '\\ChurchWebsiteConnector\\RedirectionTools\\configuration', $rows ) ) ),
	);
}

function get_group( $input ): array|\WP_Error {
	$checked = check( $input, array( 'id' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	if ( ! Redirection\positive_id( $input['id'] ?? null ) ) {
		return Redirection\error( 'group', 'Choose a WordPress group ID.' );
	}
	$state = group_state( $input['id'] );
	return is_wp_error( $state ) ? $state : array(
		'site_url'   => Connector\bound_site(),
		'group'      => $state['group'],
		'rules'      => array_map( __NAMESPACE__ . '\\summarize', $state['rows'] ),
		'state_hash' => $state['state_hash'],
	);
}

function defaults_state(): array|\WP_Error {
	$result = request( 'GET', 'setting' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$keys   = array_merge( DEFAULTS, array( 'flag_regex' ) );
	$fields = array_intersect_key( $result['settings'], array_flip( $keys ) );
	if ( count( $keys ) !== count( $fields ) ) {
		return Redirection\error( 'settings_permission', 'Redirection did not grant access to all rule defaults.', 403 );
	}
	return array(
		'fields'     => $fields,
		'state_hash' => fingerprint( $fields ),
	);
}

function get_defaults( $input ): array|\WP_Error {
	$checked = check( $input, array() );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$state = defaults_state();
	return is_wp_error( $state ) ? $state : array( 'site_url' => Connector\bound_site() ) + $state;
}

function capability( string $operation ): true|\WP_Error {
	$cap = match ( $operation ) {
		'delete_empty_group'       => \Redirection_Capabilities::CAP_GROUP_DELETE,
		'update_redirect_defaults' => \Redirection_Capabilities::CAP_OPTION_MANAGE,
		'bulk_delete'              => \Redirection_Capabilities::CAP_REDIRECT_DELETE,
		'bulk_enable', 'bulk_disable', 'import_disabled_rules' => \Redirection_Capabilities::CAP_REDIRECT_ADD,
		default => \Redirection_Capabilities::CAP_GROUP_ADD,
	};
	return \Redirection_Capabilities::has_access( $cap ) ? true : Redirection\error( 'forbidden', 'Redirection did not grant permission for this operation.', 403 );
}

function matching_hash( $expected, string $actual ): bool {
	return is_string( $expected ) && hash_equals( $actual, $expected );
}

function validate_enable( array $rows ): true|\WP_Error {
	$ids = array();
	foreach ( $rows as $row ) {
		if ( ! Redirection\plain_rule( $row ) ) {
			return Redirection\error( 'advanced_enable', 'Use Redirection to activate a rule with a regular expression or conditional match.', 409 );
		}
		$ids[] = (int) $row['id'];
	}
	if ( ! $ids ) {
		return true;
	}
	$rules = Redirection\safety_rules();
	if ( is_wp_error( $rules ) ) {
		return $rules;
	}
	foreach ( $rules as &$rule ) {
		if ( in_array( $rule['id'], $ids, true ) ) {
			$rule['active'] = true;
		}
	}
	unset( $rule );
	foreach ( $rows as $row ) {
		$fields = Redirection\rule_fields( $row );
		$valid  = Redirection\normalise( $fields, $fields, $row );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$result = Redirection\redirect_safety( $fields, (int) $row['id'], $rules );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	return true;
}

function bulk_plan( string $operation, array $parameters ): array|\WP_Error {
	$selection = $parameters['selection'] ?? null;
	if ( ! is_array( $selection ) || ! array_is_list( $selection ) || ! $selection || count( $selection ) > MAX_ITEMS ) {
		return Redirection\error( 'bulk_selection', 'Select from 1 to 25 exact redirect IDs and their current state hashes.' );
	}
	$rows  = array();
	$guard = array();
	foreach ( $selection as $item ) {
		if ( ! is_array( $item ) || count( $item ) !== 2 || ! Redirection\positive_id( $item['id'] ?? null ) || isset( $rows[ $item['id'] ] ) ) {
			return Redirection\error( 'bulk_selection', 'Each selected redirect must have a unique ID and state hash.' );
		}
		$id  = $item['id'];
		$row = Redirection\native_read( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$summary = summarize( $row );
		if ( ! matching_hash( $item['state_hash'] ?? null, $summary['state_hash'] ) ) {
			return Redirection\error( 'stale', 'A selected redirect changed. Search for it again.', 409 );
		}
		$group = Redirection\group( $summary['group_id'] );
		if ( is_wp_error( $group ) ) {
			return $group;
		}
		if ( 'bulk_delete' !== $operation && ( 'bulk_enable' === $operation ) === $summary['enabled'] ) {
			return Redirection\error( 'unchanged', 'A selected rule already has the proposed status. Remove it from the selection.', 409 );
		}
		$rows[ $id ]  = $row;
		$guard[ $id ] = Redirection\group_configuration( $group );
	}
	if ( 'bulk_enable' === $operation ) {
		$checked = validate_enable( $rows );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
	}
	return array(
		'ids'    => array_keys( $rows ),
		'before' => array_values( array_map( __NAMESPACE__ . '\\summarize', $rows ) ),
		'groups' => $guard,
		'after'  => substr( $operation, 5 ),
	);
}

function group_plan( string $operation, array $parameters ): array|\WP_Error {
	$id      = $parameters['id'] ?? 0;
	$current = null;
	if ( 'create_group' !== $operation ) {
		if ( ! Redirection\positive_id( $id ) ) {
			return Redirection\error( 'group', 'Choose an existing group ID.' );
		}
		$current = group_state( $id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! matching_hash( $parameters['state_hash'] ?? null, $current['state_hash'] ) ) {
			return Redirection\error( 'stale', 'The group or its rules changed. Read the group again.', 409 );
		}
	}
	$name = $current['group']['name'] ?? '';
	if ( in_array( $operation, array( 'create_group', 'rename_group' ), true ) ) {
		$name = $parameters['name'] ?? null;
		if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 50 || sanitize_text_field( $name ) !== $name ) {
			return Redirection\error( 'group_name', 'Provide a plain group name up to 50 bytes.' );
		}
		$groups = Redirection\groups( array( 'site_url' => Connector\bound_site() ) );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}
		if ( count( $groups['groups']['items'] ) !== (int) $groups['groups']['total'] ) {
			return Redirection\error( 'group_size', 'Too many groups to verify a unique name.', 409 );
		}
		foreach ( $groups['groups']['items'] as $item ) {
			if ( 0 === strcasecmp( $name, $item['name'] ) ) {
				return Redirection\error( 'group_name', 'A WordPress group already has that name.', 409 );
			}
		}
	}
	$rows = $current['rows'] ?? array();
	if ( 'delete_empty_group' === $operation && $rows ) {
		return Redirection\error( 'group_not_empty', 'Move the redirects before deleting the group.', 409 );
	}
	$enabled = match ( $operation ) {
		'enable_group', 'create_group' => true,
		'disable_group' => false,
		default => $current['group']['enabled'] ?? false,
	};
	if ( in_array( $operation, array( 'enable_group', 'disable_group' ), true ) && $enabled === $current['group']['enabled'] ) {
		return Redirection\error( 'unchanged', 'The group already has the requested status.', 409 );
	}
	if ( 'enable_group' === $operation ) {
		// The native group endpoint enables every member, including disabled imports.
		if ( count( $rows ) > MAX_ITEMS ) {
			return Redirection\error( 'group_size', 'Use the native editor for groups with more than 25 rules that could become active.', 409 );
		}
		$checked = validate_enable( $rows );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
	}
	$rules = array_map( __NAMESPACE__ . '\\summarize', $rows );
	if ( in_array( $operation, array( 'enable_group', 'disable_group' ), true ) ) {
		foreach ( $rules as $index => $rule ) {
			$rules[ $index ]['enabled_after'] = $enabled;
		}
	}
	return array(
		'id'     => $id,
		'before' => $current['group'] ?? null,
		'rules'  => $rules,
		'after'  => 'delete_empty_group' === $operation ? null : array(
			'name'    => $name,
			'enabled' => $enabled,
		),
	);
}

/**
 * Native Redirection stores only source flags that differ from the current defaults.
 * The REST list already expands them and cannot distinguish inherited and explicit choices.
 */
function defaults_impact( array $before, array $after ): array|\WP_Error {
	global $wpdb;
	$table = $wpdb->prefix . 'redirection_items';
	$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id LIMIT %d', $table, Redirection\MAX_RULES + 1 ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) > Redirection\MAX_RULES ) {
		return Redirection\error( 'analysis_limit', 'The rules inheriting these defaults could not all be reviewed. Use the native editor.', 409 );
	}
	$affected = array();
	foreach ( $rows as $row ) {
		$data = empty( $row['match_data'] ) ? array() : json_decode( $row['match_data'], true );
		if ( ! is_array( $data ) || ( isset( $data['source'] ) && ! is_array( $data['source'] ) ) ) {
			return Redirection\error( 'settings_input', 'A native rule has unreadable matching settings. Review the defaults in Redirection.', 409 );
		}
		// Match Red_Item's loading of the legacy regex column and stored flag overrides.
		$overrides = isset( $data['source'] ) ? $data['source'] : array( 'flag_regex' => (bool) $row['regex'] );
		$old       = ( new \Redirection\Url\SourceFlags( array_replace( $before, $overrides ) ) )->get_json();
		$new       = ( new \Redirection\Url\SourceFlags( array_replace( $after, $overrides ) ) )->get_json();
		$changes   = array_diff_assoc( $new, $old );
		if ( ! $changes ) {
			continue;
		}
		if ( count( $affected ) >= MAX_ITEMS ) {
			return Redirection\error( 'analysis_limit', 'More than 25 existing rules would change their matching behaviour. Review this site-wide change in Redirection.', 409 );
		}
		if ( isset( $changes['flag_query'] ) && in_array( 'exactorder', array( $old['flag_query'], $new['flag_query'] ), true ) && ! $row['regex'] ) {
			$matcher = new \Redirection\Url\Matcher( $row['url'] );
			$index   = 'exactorder' === $new['flag_query'] ? $matcher->get_url_with_params() : $matcher->get_url();
			if ( $index !== $row['match_url'] ) {
				return Redirection\error( 'settings_index', 'The new query default needs an existing rule’s native matching index to be rebuilt. Review that change in Redirection.', 409 );
			}
		}
		$native     = new \Red_Item( (object) $row );
		$affected[] = array(
			'id'         => (int) $row['id'],
			'target'     => Redirection\rule_fields( $native->to_json() )['target'],
			'group_id'   => (int) $row['group_id'],
			'source'     => $row['url'],
			'enabled'    => 'enabled' === $row['status'],
			'match_type' => $row['match_type'],
			'regex'      => (bool) $row['regex'],
			'before'     => array_intersect_key( $old, $changes ),
			'after'      => $changes,
			'state_hash' => fingerprint( array_diff_key( $row, array_flip( array( 'last_count', 'last_access' ) ) ) ),
		);
	}
	return $affected;
}

/** Check the projected live plain rules together, using the same loop/overlap model as a rule edit. */
function defaults_safety( array $affected ): true|\WP_Error {
	$changes = array_column( $affected, 'after', 'id' );
	if ( ! $changes ) {
		return true;
	}
	$rules = Redirection\safety_rules();
	if ( is_wp_error( $rules ) ) {
		return $rules;
	}
	$flags = array(
		'flag_case'     => 'ignore_case',
		'flag_trailing' => 'ignore_trailing_slash',
		'flag_query'    => 'query_parameters',
	);
	foreach ( $rules as $index => $rule ) {
		foreach ( $changes[ $rule['id'] ] ?? array() as $key => $value ) {
			$rules[ $index ]['fields'][ $flags[ $key ] ] = $value;
		}
	}
	foreach ( $rules as $rule ) {
		if ( $rule['active'] && isset( $changes[ $rule['id'] ] ) ) {
			$safe = Redirection\redirect_safety( $rule['fields'], $rule['id'], $rules );
			if ( is_wp_error( $safe ) ) {
				return $safe;
			}
		}
	}
	return true;
}

function defaults_plan( array $parameters ): array|\WP_Error {
	$current = defaults_state();
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	if ( ! matching_hash( $parameters['state_hash'] ?? null, $current['state_hash'] ) ) {
		return Redirection\error( 'stale', 'Rule defaults changed. Read them again.', 409 );
	}
	$changes = $parameters['changes'] ?? null;
	if ( ! is_array( $changes ) || ! $changes || array_diff( array_keys( $changes ), DEFAULTS )
		|| ( array_key_exists( 'flag_case', $changes ) && ! is_bool( $changes['flag_case'] ) )
		|| ( array_key_exists( 'flag_trailing', $changes ) && ! is_bool( $changes['flag_trailing'] ) )
		|| ( array_key_exists( 'flag_query', $changes ) && ! in_array( $changes['flag_query'], array( 'exact', 'exactorder', 'ignore', 'pass' ), true ) ) ) {
		return Redirection\error( 'settings_input', 'Choose only the supported defaults for case, slash or query strings.' );
	}
	$after = array_replace( $current['fields'], $changes );
	if ( $after === $current['fields'] ) {
		return Redirection\error( 'unchanged', 'The defaults already have these values.', 409 );
	}
	$affected = defaults_impact( $current['fields'], $after );
	if ( is_wp_error( $affected ) ) {
		return $affected;
	}
	$safe = defaults_safety( $affected );
	return is_wp_error( $safe ) ? $safe : array(
		'before'         => $current['fields'],
		'after'          => $after,
		'affected_rules' => $affected,
	);
}

function export_rules( $input ): array|\WP_Error {
	$checked = check( $input, array( 'ids' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$ids = $input['ids'] ?? null;
	if ( ! is_array( $ids ) || ! array_is_list( $ids ) || ! $ids || count( $ids ) > MAX_ITEMS ) {
		return Redirection\error( 'export_selection', 'Specify up to 25 exact redirect IDs.' );
	}
	$rules = array();
	foreach ( $ids as $id ) {
		if ( ! Redirection\positive_id( $id ) || isset( $rules[ $id ] ) ) {
			return Redirection\error( 'export_selection', 'Every redirect ID must be unique and positive.' );
		}
		$row = Redirection\native_read( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$fields = Redirection\rule_fields( $row );
		if ( ! Redirection\plain_rule( $row ) || ! Redirection\valid_url( $fields['source'], true ) || ! Redirection\valid_url( $fields['target'], false ) ) {
			return Redirection\error( 'export_rule', 'Use the vendor export for unsupported rules.' );
		}
		unset( $fields['group_id'], $fields['position'] );
		if ( ! empty( $row['match_data']['options']['log_exclude'] ) ) {
			$fields['log_exclude'] = true;
		}
		$rules[ $id ] = $fields;
	}
	return array(
		'site_url' => Connector\bound_site(),
		'format'   => 'church-connector/plain-redirects/v1',
		'rules'    => array_values( $rules ),
	);
}

function import_plan( array $parameters ): array|\WP_Error {
	$id   = $parameters['id'] ?? null;
	$list = $parameters['rules'] ?? null;
	if ( ! Redirection\positive_id( $id ) || ! is_array( $list ) || ! array_is_list( $list ) || ! $list || count( $list ) > MAX_ITEMS ) {
		return Redirection\error( 'import_rules', 'Choose an existing WordPress group and up to 25 plain redirect rules.' );
	}
	$group = group_state( $id );
	if ( is_wp_error( $group ) ) {
		return $group;
	}
	if ( ! matching_hash( $parameters['state_hash'] ?? null, $group['state_hash'] ) ) {
		return Redirection\error( 'stale', 'Read the current destination group again.', 409 );
	}
	if ( count( $group['rows'] ) + count( $list ) > 200 ) {
		return Redirection\error( 'group_size', 'The import would exceed the 200-rule read-back limit for this group.', 409 );
	}
	$safety = Redirection\safety_rules( count( $list ) );
	if ( is_wp_error( $safety ) ) {
		return $safety;
	}
	$fields   = array();
	$defaults = Redirection\creation_defaults();
	foreach ( $list as $index => $row ) {
		if ( ! is_array( $row ) || ! isset( $row['source'], $row['target'] ) || isset( $row['group_id'] ) || isset( $row['position'] ) ) {
			return Redirection\error( 'import_rules', 'Each imported rule needs a source and target. The destination group is selected separately.' );
		}
		$exclude_logging = $row['log_exclude'] ?? false;
		if ( array_key_exists( 'log_exclude', $row ) && ! is_bool( $row['log_exclude'] ) ) {
			return Redirection\error( 'import_rules', 'Use a boolean for the rule logging exclusion.' );
		}
		unset( $row['log_exclude'] );
		$base  = array(
			'group_id'  => $id,
			'position'  => count( $group['rows'] ) + $index,
			'title'     => '',
			'http_code' => 301,
		) + $defaults;
		$entry = Redirection\normalise( $base, $row );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		if ( $exclude_logging ) {
			$entry['log_exclude'] = true;
		}
		$fields[] = $entry;
		$safety[] = array(
			'id'     => -1 - $index,
			'active' => true,
			'fields' => $entry,
		);
	}
	foreach ( $fields as $index => $entry ) {
		$checked = Redirection\redirect_safety( $entry, -1 - $index, $safety );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
	}
	return array(
		'id'      => $id,
		'group'   => $group['group'],
		'rules'   => $fields,
		'enabled' => false,
	);
}

function import_rules( array $plan ): array {
	$ids = array();
	foreach ( $plan['rules'] as $index => $fields ) {
		$exclude_logging = ! empty( $fields['log_exclude'] );
		unset( $fields['log_exclude'] );
		$payload = Redirection\payload( $fields ) + array( 'status' => 'disabled' );
		if ( $exclude_logging ) {
			$payload['match_data']['options'] = array( 'log_exclude' => true );
		}
		$group              = $plan['group'];
		$group['redirects'] = (int) $group['redirects'] + $index;
		$review             = array(
			'operation'   => 'create',
			'id'          => 0,
			'group_hash'  => fingerprint( $group ),
			'fields'      => $fields,
			'enabled'     => false,
			'log_exclude' => $exclude_logging,
		);
		$found              = Redirection\dispatch( 'POST', '/redirection/v1/redirect', $payload, $review );
		$rows               = is_wp_error( $found ) ? $found : group_rules( $plan['id'] );
		if ( ! is_wp_error( $rows ) ) {
			$match = array_values(
				array_filter(
					$rows,
					static fn( $row ) => empty( $row['enabled'] ) && Redirection\rule_fields( $row ) === $fields
						&& ! empty( $row['match_data']['options']['log_exclude'] ) === $exclude_logging
				)
			);
			if ( 1 === count( $match ) ) {
				$ids[] = (int) $match[0]['id'];
				continue;
			}
		}
		return array(
			'verified'       => false,
			'ids'            => $ids,
			'stopped_at_row' => $index + 1,
			'message'        => 'Import stopped. Inspect the destination group and import only rules that are still missing.',
		);
	}
	return array(
		'verified' => true,
		'ids'      => $ids,
		'enabled'  => false,
	);
}

function plan( string $operation, array $parameters ): array|\WP_Error {
	$allowed = capability( $operation );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	$keys = match ( $operation ) {
		'create_group' => array( 'name' ),
		'rename_group' => array( 'id', 'state_hash', 'name' ),
		'bulk_enable', 'bulk_disable', 'bulk_delete' => array( 'selection' ),
		'update_redirect_defaults' => array( 'state_hash', 'changes' ),
		'import_disabled_rules' => array( 'id', 'state_hash', 'rules' ),
		default => array( 'id', 'state_hash' ),
	};
	if ( array_diff( array_keys( $parameters ), $keys ) ) {
		return Redirection\error( 'input', 'Use only the fields for this administration operation.' );
	}
	return match ( true ) {
		str_starts_with( $operation, 'bulk_' ) => bulk_plan( $operation, $parameters ),
		'update_redirect_defaults' === $operation => defaults_plan( $parameters ),
		'import_disabled_rules' === $operation => import_plan( $parameters ),
		default => group_plan( $operation, $parameters ),
	};
}

function preview( $input, ?array $replaces = null ): array|\WP_Error {
	$checked = check( $input, array( 'operation', 'id', 'state_hash', 'name', 'selection', 'changes', 'rules' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$operation = $input['operation'] ?? null;
	if ( ! in_array( $operation, OPERATIONS, true ) ) {
		return Redirection\error( 'operation', 'Choose a supported administration operation.' );
	}
	$parameters = array_diff_key(
		$input,
		array(
			'site_url'  => true,
			'operation' => true,
		)
	);
	$plan       = plan( $operation, $parameters );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	$saved   = Redirection\save_preview(
		array(
			'operation'      => $operation,
			'id'             => $plan['id'] ?? 0,
			'parameters'     => $parameters,
			'review'         => fingerprint( $plan ),
			'changed_fields' => array_keys( $parameters ),
		),
		$replaces
	);
	$message = match ( $operation ) {
		'update_redirect_defaults' => 'These defaults also change matching for the listed existing rules. Review each rule’s current and proposed settings before confirming.',
		'enable_group'  => 'Enabling this group activates every listed rule, including rules previously disabled individually. Review each proposed rule state before confirming.',
		'disable_group' => 'Disabling this group disables every listed rule. Enabling this group later will activate every rule present at that time.',
		default         => 'Review the exact scope and effect. Permanent removal also requires explicit confirmation of removal.',
	};
	return is_wp_error( $saved ) ? $saved : array(
		'site_url'  => Connector\bound_site(),
		'operation' => $operation,
		'change'    => $plan,
		'message'   => $message,
	) + $saved;
}

function verify_bulk( string $operation, array $plan ): array|\WP_Error {
	$items = array();
	foreach ( $plan['before'] as $rule ) {
		$row = Redirection\native_read( $rule['id'] );
		if ( 'bulk_delete' === $operation && is_wp_error( $row ) && 'church_connector_redirect_missing' === $row->get_error_code() ) {
			continue;
		}
		if ( 'bulk_delete' === $operation || is_wp_error( $row ) || ( 'bulk_enable' === $operation ) !== ! empty( $row['enabled'] ) ) {
			return Redirection\error( 'admin_verify', 'The batch could not be verified completely. Read every selected rule before retrying.', 409 );
		}
		$items[]        = summarize( $row );
		$row['enabled'] = $rule['enabled'];
		if ( ! hash_equals( $rule['state_hash'], summarize( $row )['state_hash'] ) ) {
			return Redirection\error( 'admin_verify', 'A rule changed beyond its approved status. Inspect the selection before retrying.', 409 );
		}
	}
	return array(
		'ids'   => $plan['ids'],
		'items' => $items,
	);
}

/** Compare all reviewed rule configuration, allowing only the proposed status change. */
function group_members_match( array $plan, array $rows, bool $after ): bool {
	if ( count( $plan['rules'] ) !== count( $rows ) ) {
		return false;
	}
	foreach ( $rows as $index => $row ) {
		$review = $plan['rules'][ $index ];
		if ( $after ) {
			if ( $review['enabled_after'] !== $row['enabled'] ) {
				return false;
			}
			$row['enabled'] = $review['enabled'];
		}
		if ( ! hash_equals( $review['state_hash'], fingerprint( Redirection\configuration( $row ) ) ) ) {
			return false;
		}
	}
	return true;
}

/** Keep the native group-wide write and the membership review in one transaction. */
function locked_group_action( array $plan, callable $callback, \WP_REST_Request $request ) {
	global $wpdb;
	$id     = $plan['id'];
	$groups = $wpdb->prefix . 'redirection_groups';
	$items  = $wpdb->prefix . 'redirection_items';
	$failed = Redirection\error( 'group_atomic', 'The database could not protect the reviewed group change. Read the group before retrying or use the native editor.', 409 );
	return Redirection\rule_transaction(
		static function () use ( $plan, $callback, $request, $id, $groups, $items, $failed, $wpdb ) {
			if ( 1 !== $wpdb->query( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $groups, $id ) )
				|| false === $wpdb->query( $wpdb->prepare( 'SELECT id FROM %i WHERE group_id = %d FOR UPDATE', $items, $id ) ) ) {
				return $failed;
			}
			$current = group_state( $id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( $current['group'] !== $plan['before'] || ! group_members_match( $plan, $current['rows'], false ) ) {
				return Redirection\error( 'stale', 'The group or its rules changed during the save. Review the group again.', 409 );
			}
			if ( true === ( $plan['after']['enabled'] ?? false ) ) {
				$valid = validate_enable( $current['rows'] );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
			}
			$response = $callback( $request );
			if ( is_wp_error( $response ) || ( $response instanceof \WP_HTTP_Response && $response->get_status() >= 400 ) ) {
				return $response;
			}
			if ( null === $plan['after'] ) {
				if ( '0' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', $groups, $id ) ) ) {
					return $failed;
				}
			} else {
				$current  = group_state( $id );
				$expected = array_replace( $plan['before'], $plan['after'] );
				if ( is_wp_error( $current ) || $expected !== $current['group'] || ! group_members_match( $plan, $current['rows'], true ) ) {
					return $failed;
				}
			}
			return $response;
		}
	);
}

function locked_bulk_action( string $operation, array $plan, callable $callback, \WP_REST_Request $request ) {
	return Redirection\rule_transaction(
		static function () use ( $operation, $plan, $callback, $request ) {
			global $wpdb;
			$selection = array();
			foreach ( $plan['before'] as $rule ) {
				if ( 1 !== $wpdb->query( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $wpdb->prefix . 'redirection_items', $rule['id'] ) ) ) {
					return Redirection\error( 'stale', 'A selected rule changed during the save. Review the selection again.', 409 );
				}
				$selection[] = array_intersect_key( $rule, array_flip( array( 'id', 'state_hash' ) ) );
			}
			$current = bulk_plan( $operation, array( 'selection' => $selection ) );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( $current !== $plan ) {
				return Redirection\error( 'stale', 'A selected rule or its group changed during the save. Review the selection again.', 409 );
			}
			$response = $callback( $request );
			if ( is_wp_error( $response ) || ( $response instanceof \WP_HTTP_Response && $response->get_status() >= 400 ) ) {
				return $response;
			}
			$verified = verify_bulk( $operation, $plan );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			if ( 'bulk_enable' === $operation ) {
				$projected = Redirection\safety_rules();
				if ( is_wp_error( $projected ) ) {
					return $projected;
				}
				foreach ( $projected as $rule ) {
					if ( in_array( $rule['id'], $plan['ids'], true ) ) {
						$safe = Redirection\redirect_safety( $rule['fields'], $rule['id'], $projected );
						if ( is_wp_error( $safe ) ) {
							return $safe;
						}
					}
				}
			}
			return $response;
		}
	);
}

function locked_defaults_action( array $plan, callable $callback, \WP_REST_Request $request ) {
	return Redirection\rule_transaction(
		static function () use ( $plan, $callback, $request ) {
			$ids = Redirection\locked_rule_ids();
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$state    = defaults_state();
			$affected = defaults_impact( $plan['before'], $plan['after'] );
			if ( is_wp_error( $state ) || $state['fields'] !== $plan['before'] || $affected !== $plan['affected_rules'] ) {
				return Redirection\error( 'stale', 'The matching defaults or the rules inheriting them changed. Review the affected rules again.', 409 );
			}
			$safe = defaults_safety( $affected );
			if ( is_wp_error( $safe ) ) {
				return $safe;
			}
			$original = \Red_Options::get();
			$response = $callback( $request );
			if ( is_wp_error( $response ) || ( $response instanceof \WP_HTTP_Response && $response->get_status() >= 400 ) ) {
				return $response;
			}
			Redirection\reset_native_options();
			$stored = \Red_Options::get();
			if ( array_replace( $original, $plan['after'] ) !== $stored || defaults_impact( $plan['before'], $plan['after'] ) !== $affected ) {
				return Redirection\error( 'admin_verify', 'The native defaults or affected rules differed from the review. The change was rolled back.', 409 );
			}
			return $response;
		}
	);
}

function commit( string $operation, array $plan ): array|\WP_Error {
	if ( 'import_disabled_rules' === $operation ) {
		return import_rules( $plan );
	}
	if ( str_starts_with( $operation, 'bulk_' ) ) {
		$result = request(
			'POST',
			'bulk/redirect/' . $plan['after'],
			array(
				'items'  => $plan['ids'],
				'global' => false,
			),
			static fn( $callback, $target ) => locked_bulk_action( $operation, $plan, $callback, $target )
		);
		return is_wp_error( $result ) ? $result : verify_bulk( $operation, $plan );
	}
	if ( 'update_redirect_defaults' === $operation ) {
		$result = request(
			'POST',
			'setting',
			$plan['after'],
			static fn( $callback, $target ) => locked_defaults_action( $plan, $callback, $target )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$state = defaults_state();
		return is_array( $state ) && $state['fields'] === $plan['after']
			? $state : Redirection\error( 'admin_verify', 'Redirection returned different saved rule defaults.', 409 );
	}

	$id    = $plan['id'];
	$error = Redirection\error( 'admin_verify', 'The group save could not be verified. Read it before retrying.', 409 );
	if ( in_array( $operation, array( 'create_group', 'rename_group' ), true ) ) {
		$result = request(
			'POST',
			'group' . ( $id ? '/' . $id : '' ),
			array(
				'name'     => $plan['after']['name'],
				'moduleId' => 1,
				'filterBy' => array( 'module' => 1 ),
				'per_page' => 200,
				'page'     => 0,
			)
		);
	} else {
		$action = match ( $operation ) {
			'enable_group'  => 'enable',
			'disable_group' => 'disable',
			default         => 'delete',
		};
		$result = request(
			'POST',
			'bulk/group/' . $action,
			array(
				'items'  => array( $id ),
				'global' => false,
			),
			static fn( $callback, $target ) => locked_group_action( $plan, $callback, $target )
		);
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( 'create_group' === $operation ) {
		$matches = array_values( array_filter( $result['items'] ?? array(), static fn( $item ) => $item['name'] === $plan['after']['name'] && 1 === (int) $item['module_id'] ) );
		if ( 1 !== count( $matches ) ) {
			return $error;
		}
		$id = (int) $matches[0]['id'];
	}
	$group = Redirection\group( $id );
	if ( 'delete_empty_group' === $operation ) {
		return is_wp_error( $group ) && 'church_connector_redirect_group' === $group->get_error_code()
			? array(
				'id'    => $id,
				'group' => null,
			) : $error;
	}
	if ( is_wp_error( $group ) || $plan['after']['name'] !== $group['name'] || $plan['after']['enabled'] !== $group['enabled'] ) {
		return $error;
	}
	$verified = array(
		'id'    => $id,
		'group' => Redirection\group_configuration( $group ),
	);
	if ( in_array( $operation, array( 'enable_group', 'disable_group' ), true ) ) {
		$rows = group_rules( $id );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$members = array_map( __NAMESPACE__ . '\\summarize', $rows );
		if ( ! group_members_match( $plan, $rows, true ) ) {
			return Redirection\error( 'admin_verify', 'Redirection changed the group but its rule states differ from the preview. Inspect the group before retrying.', 409 );
		}
		$verified['rules'] = $members;
	}
	return $verified;
}

function refresh_admin_review( array $saved, \WP_Error $error ): \WP_Error {
	$parameters = $saved['parameters'];
	if ( isset( $parameters['selection'] ) ) {
		foreach ( $parameters['selection'] as $index => $item ) {
			$row = Redirection\native_read( $item['id'] );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			$parameters['selection'][ $index ]['state_hash'] = summarize( $row )['state_hash'];
		}
	} elseif ( isset( $parameters['state_hash'] ) ) {
		$current = 'update_redirect_defaults' === $saved['operation'] ? defaults_state() : group_state( $parameters['id'] );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$parameters['state_hash'] = $current['state_hash'];
	}
	$request = array(
		'site_url'  => Connector\bound_site(),
		'operation' => $saved['operation'],
	) + $parameters;
	return \ChurchWebsiteConnector\RestTools\refreshed_error( $error, preview( $request, $saved ), $saved, Redirection\PREFIX, Redirection\EXPIRE_HOOK );
}

function apply( $input ): array|\WP_Error {
	$checked = check( $input, array( 'preview_token', 'approved', 'confirm_removal' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	if ( true !== ( $input['approved'] ?? null ) ) {
		return Redirection\error( 'approval', 'Apply the exact preview after explicit approval.' );
	}
	$saved = Redirection\load_preview( $input['preview_token'] ?? null );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$operation = $saved['operation'] ?? null;
	if ( ! in_array( $operation, OPERATIONS, true ) ) {
		return Redirection\error( 'token', 'Use the apply tool named in the preview.', 409 );
	}
	if ( in_array( $operation, array( 'bulk_delete', 'delete_empty_group' ), true ) && true !== ( $input['confirm_removal'] ?? null ) ) {
		return Redirection\error( 'approval', 'Permanent removal requires explicit confirmation of the selected items.' );
	}
	$lock = Redirection\acquire_lock();
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$plan = plan( $operation, $saved['parameters'] );
		if ( is_wp_error( $plan ) ) {
			return 'church_connector_redirect_stale' === $plan->get_error_code() ? refresh_admin_review( $saved, $plan ) : $plan;
		}
		if ( ! hash_equals( $saved['review'], fingerprint( $plan ) ) ) {
			return refresh_admin_review( $saved, Redirection\error( 'stale', 'The reviewed state changed.', 409 ) );
		}
		if ( ! Redirection\consume( $saved ) ) {
			return Redirection\error( 'token', 'The preview was replaced or already claimed.', 409 );
		}
		$result = commit( $operation, $plan );
		foreach ( $plan['ids'] ?? ( is_array( $result ) && ! empty( $result['ids'] ) ? $result['ids'] : array( is_array( $result ) ? ( $result['id'] ?? $saved['id'] ) : $saved['id'] ) ) as $id ) {
			$saved['id'] = $id;
			Redirection\audit( $saved, is_wp_error( $result ) || false === ( $result['verified'] ?? true ) ? 'error' : 'verified' );
		}
		return is_wp_error( $result ) ? $result : array(
			'site_url'  => Connector\bound_site(),
			'operation' => $operation,
		) + $result + array( 'verified' => true );
	} catch ( \Throwable $e ) {
		Redirection\audit( $saved, 'error' );
		return Redirection\error( 'native_admin', 'The operation was interrupted. Inspect the affected items before retrying.', 500 );
	} finally {
		Redirection\remove_exact( Redirection\LOCK, $lock );
	}
}

function register(): void {
	if ( ! Connector\feature_enabled( 'redirection_tools' ) ) {
		return;
	}
	$id        = array(
		'type'    => 'integer',
		'minimum' => 1,
	);
	$hash      = array(
		'type'    => 'string',
		'pattern' => '^[a-f0-9]{64}$',
	);
	$text      = array(
		'type'      => 'string',
		'maxLength' => 500,
	);
	$bool      = array( 'type' => 'boolean' );
	$yes       = $bool + array( 'const' => true );
	$target    = array(
		'id'         => $id,
		'state_hash' => $hash,
	);
	$selection = array(
		'type'     => 'array',
		'minItems' => 1,
		'maxItems' => MAX_ITEMS,
		'items'    => array(
			'type'                 => 'object',
			'properties'           => $target,
			'required'             => array_keys( $target ),
			'additionalProperties' => false,
		),
	);
	$tools     = array(
		'search-redirection-rules'       => array(
			'Search Redirection rules with filters',
			'Filter by source, target, title, status, group, HTTP code or regex. Returns ID and state_hash for selected batch changes.',
			'search',
			array(),
			array(
				'source'       => $text,
				'target'       => $text,
				'title'        => $text,
				'enabled'      => $bool,
				'group_id'     => $id,
				'http_code'    => array(
					'type'    => 'integer',
					'minimum' => 300,
					'maximum' => 599,
				),
				'match_format' => array(
					'type' => 'string',
					'enum' => array( 'plain', 'regular' ),
				),
				'page'         => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 1000,
				),
			),
		),
		'export-plain-redirection-rules' => array(
			'Export selected plain Redirection rules',
			'Export up to 25 exact plain rule IDs as portable JSON. Use a separate reviewed import to reuse these rules as disabled entries.',
			'export_rules',
			array(
				'ids' => array(
					'type'     => 'array',
					'items'    => $id,
					'minItems' => 1,
					'maxItems' => MAX_ITEMS,
				),
			),
		),
		'get-redirection-group-details'  => array( 'Read one Redirection group and its rules', 'Read a WordPress group and current rules. Use its state_hash for a group change.', 'get_group', array( 'id' => $id ) ),
		'get-redirection-log-count'      => array(
			'Count logged redirects or 404s for a path',
			'Count matching retained log entries for a specific local path without query parameters. Returns no individual visitor records.',
			'log_count',
			array(
				'path' => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'log'  => array(
					'type' => 'string',
					'enum' => array( 'redirect', '404' ),
				),
			),
		),
		'get-redirection-rule-defaults'  => array( 'Read Redirection rule defaults', 'Read default matching behaviour for case, trailing slashes and query strings.', 'get_defaults', array() ),
		'preview-redirection-admin'      => array(
			'Review a Redirection administration change',
			'Review group creation, renaming, enable, disable, empty group removal, exact selected rule status/removal, rule default changes, or importing plain rules as disabled entries. Use current group/default hash or search hashes for selection. Permanent removal requires its own confirmation.',
			'preview',
			array(
				'operation' => array(
					'type' => 'string',
					'enum' => OPERATIONS,
				),
			),
			$target + array(
				'name'      => array(
					'type'      => 'string',
					'maxLength' => 50,
				),
				'selection' => $selection,
				'rules'     => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'object' ),
					'minItems' => 1,
					'maxItems' => MAX_ITEMS,
				),
				'changes'   => array(
					'type'                 => 'object',
					'properties'           => array(
						'flag_case'     => $bool,
						'flag_trailing' => $bool,
						'flag_query'    => array(
							'type' => 'string',
							'enum' => array( 'exact', 'exactorder', 'ignore', 'pass' ),
						),
					),
					'additionalProperties' => false,
				),
			),
		),
		'apply-redirection-admin'        => array(
			'Apply a reviewed Redirection administration change',
			'Use only after approving the exact preview. Deleting an empty group or selected rules also needs confirm_removal. Verify the returned native state before making another change.',
			'apply',
			array(
				'preview_token' => $hash,
				'approved'      => $yes,
			),
			array( 'confirm_removal' => $yes ),
		),
	);
	ScopedAbilities\register( $tools, __NAMESPACE__, 'ChurchWebsiteConnector\\RedirectionTools\\permission' );
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
