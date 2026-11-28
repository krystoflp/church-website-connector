<?php
/** Fixed integration operations shared by authenticated REST and MCP clients. */
declare(strict_types=1);
namespace ChurchWebsiteConnector\Integrations;
defined( 'ABSPATH' ) || exit;

const FLAGS = [ 'seo-framework' => 'tsf_tools', 'rank-math' => 'rankmath_tools', 'code-snippets' => 'snippets_tools', 'ws-form' => 'wsform_tools' ];
const PREVIEW = 'church_connector_integration_preview_';
const LOCK = 'church_connector_integration_lock_';
const AUDIT = 'church_connector_integration_audit';
const EXPIRE = 'church_connector_expire_integration_preview';
const TTL = 900;
const MAX_JSON = 262144;

function error( string $code, string $message, int $status = 400 ): \WP_Error {
	return \ChurchWebsiteConnector\RestTools\workflow_error( 'church_connector_integration_', $code, $message, $status );
}
function base_permission( $input = null ) {
    return is_user_logged_in() && current_user_can( 'manage_options' ) && \ChurchWebsiteConnector\is_bound()
        ? true : error( 'forbidden', 'An administrator must sign in on this connector’s enabled, bound website.', 403 );
}
function check( $input, array $keys ) {
    $permission = base_permission(); if ( is_wp_error( $permission ) ) { return $permission; }
    if ( ! is_array( $input ) || array_diff( array_keys( $input ), $keys ) ) { return error( 'input', 'Only documented integration parameters are accepted.' ); }
    if ( ( $input['site_url'] ?? null ) !== \ChurchWebsiteConnector\bound_site() ) { return error( 'site', 'Identify this installation’s exact website address.', 403 ); }
    return true;
}
function canonical( $value ) {
    if ( is_object( $value ) ) { $value = (array) $value; }
    if ( is_array( $value ) ) { if ( ! array_is_list( $value ) ) { ksort( $value ); } return array_map( __NAMESPACE__ . '\\canonical', $value ); }
    return $value;
}
function fingerprint( $value ): string { return hash( 'sha256', (string) wp_json_encode( canonical( $value ) ) ); }
function bounded_json( $value ): bool {
    $json = wp_json_encode( $value );
    return is_string( $json ) && strlen( $json ) <= MAX_JSON && json_decode( $json, true, 20 ) !== null;
}
function definition( string $provider ): array {
    return match ( $provider ) {
        'seo-framework', 'rank-math' => Seo\definition( $provider ),
        'code-snippets' => Snippets\definition(),
        'ws-form' => Forms\definition(),
        default => [],
    };
}
function status( string $provider ): array {
    $d = definition( $provider );
    $version = isset( $d['constant'] ) && defined( $d['constant'] ) ? (string) constant( $d['constant'] ) : '';
    $compatible = $version !== '' && version_compare( $version, $d['minimum'], '>=' ) && version_compare( $version, $d['before'], '<' );
    if ( $compatible ) { foreach ( $d['interfaces'] as $interface ) { if ( ! is_callable( $interface ) ) { $compatible = false; break; } } }
    if ( $provider === 'rank-math' && is_callable( '\\RankMath\\Helper::is_invalid_registration' ) && \RankMath\Helper::is_invalid_registration() ) { $compatible = false; }
    if ( $provider === 'ws-form' && ( ! defined( 'WS_FORM_EDITION' ) || WS_FORM_EDITION !== 'pro' ) ) { $compatible = false; }
    return [ 'integration' => $provider, 'label' => $d['label'] ?? $provider, 'enabled' => isset( FLAGS[ $provider ] ) && \ChurchWebsiteConnector\feature_enabled( FLAGS[ $provider ] ), 'installed_version' => $version ?: null,
        'compatible' => $compatible, 'supported_versions' => '>=' . ( $d['minimum'] ?? '?' ) . ' <' . ( $d['before'] ?? '?' ), 'tested_version' => $d['tested'] ?? null ];
}
function ready( string $provider ) {
    if ( ! isset( FLAGS[ $provider ] ) ) { return error( 'provider', 'Choose one of the four documented integrations.' ); }
    $s = status( $provider );
    if ( ! $s['enabled'] ) { return error( 'disabled', 'Enable this integration separately in Settings > ChurchWeb Connector.', 403 ); }
    if ( ! $s['compatible'] ) { return error( 'unavailable', $s['label'] . ' requires its supported version and native interfaces. See integration-capabilities.', 503 ); }
    return true;
}
function target( array $input ) {
    $provider = $input['integration'] ?? ''; $kind = $input['kind'] ?? ''; $id = $input['id'] ?? 0; $section = $input['section'] ?? '';
    if ( ! is_string( $provider ) || ! is_string( $kind ) || ! is_int( $id ) || $id < 0 || $id > 2147483647 || ! is_string( $section ) || strlen( $section ) > 40 ) { return error( 'target', 'Use a documented integration, item kind, section and integer ID.' ); }
    $allowed = ready( $provider ); if ( is_wp_error( $allowed ) ) { return $allowed; }
    $valid = match ( $provider ) {
        'seo-framework' => ( $kind === 'post' && $id > 0 && $section === '' ) || ( $kind === 'settings' && $id === 0 && $section === '' ),
        'rank-math' => ( $kind === 'post' && $id > 0 && $section === '' ) || ( $kind === 'settings' && $id === 0 && in_array( $section, [ 'general', 'titles', 'sitemap' ], true ) ),
        'code-snippets' => $kind === 'snippet' && $section === '',
        'ws-form' => $kind === 'form' && $section === '',
    };
    return $valid ? compact( 'provider', 'kind', 'id', 'section' ) : error( 'target', 'This item kind or section is not supported for the selected integration.' );
}
function native_rest( string $method, string $path, array $params = [] ) {
    // Callers construct every route from fixed names and validated numeric IDs.
    $allowed = preg_match( '~\A/code-snippets/v1/snippets(?:/[1-9][0-9]*(?:/(?:activate|deactivate))?)?\z~', $path )
        || in_array( $path, [ '/rankmath/v1/updateMeta', '/rankmath/v1/updateSettings' ], true );
	if ( ! $allowed || ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
		return error( 'route', 'This REST operation is outside the integration allowlist.', 403 );
	}
    $request = new \WP_REST_Request( $method, $path );
    $method === 'GET' ? $request->set_query_params( $params ) : $request->set_body_params( $params );
    // Rank Math's native callback temporarily replaces protected-meta filters.
    global $wp_filter;
    $protected = isset( $wp_filter['is_protected_meta'] ) ? clone $wp_filter['is_protected_meta'] : null;
    try { $response = rest_do_request( $request ); }
    finally { if ( str_starts_with( $path, '/rankmath/' ) ) { if ( $protected === null ) { unset( $wp_filter['is_protected_meta'] ); } else { $wp_filter['is_protected_meta'] = $protected; } } }
    if ( is_wp_error( $response ) ) { return $response; }
    $data = $response->get_data();
    if ( $response->get_status() >= 400 ) { return error( 'native', is_array( $data ) && is_string( $data['message'] ?? null ) ? wp_strip_all_tags( $data['message'] ) : 'The plugin refused the REST operation.', $response->get_status() ); }
    return $data;
}
function read( array $target ) {
    $allowed = ready( $target['provider'] ); if ( is_wp_error( $allowed ) ) { return $allowed; }
    try {
        $item = match ( $target['provider'] ) {
            'seo-framework', 'rank-math' => Seo\read( $target ),
            'code-snippets' => Snippets\read( $target ),
            'ws-form' => Forms\read( $target ),
        };
    } catch ( \Throwable $e ) { return error( 'read', 'The plugin could not read this item: ' . $e->getMessage(), 502 ); }
    if ( is_wp_error( $item ) ) { return $item; }
	if ( ! bounded_json( $item['data'] ) ) {
		if ( 'ws-form' !== $target['provider'] || ! isset( $item['data']['design'] ) ) {
			return error( 'size', 'This item exceeds the bounded integration response size.' );
		}
		$item['paged_design'] = true;
	}
    return $item + [ 'state_hash' => fingerprint( [ $target, status( $target['provider'] )['installed_version'], \ChurchWebsiteConnector\current_identity(), $item['state'] ] ) ];
}
function public_item( array $target, array $item, int $page = 1 ): array|\WP_Error {
	$data = $item['data'];
	if ( ! empty( $item['paged_design'] ) ) {
		$data = Forms\design_page( $data, $page );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
	} elseif ( 1 !== $page ) {
		return error( 'design_page', 'The complete design fits on the first page.' );
	}
	return array(
		'site_url'    => \ChurchWebsiteConnector\bound_site(),
		'integration' => $target['provider'],
		'kind'        => $target['kind'],
		'id'          => $target['id'],
		'section'     => $target['section'],
		'data'        => $data,
		'state_hash'  => $item['state_hash'],
	);
}
function capabilities( $input = [] ) {
    $ok = check( $input, [ 'site_url', 'integration' ] ); if ( is_wp_error( $ok ) ) { return $ok; }
    $providers = isset( $input['integration'] ) ? [ $input['integration'] ] : array_keys( FLAGS ); $output = [];
    foreach ( $providers as $provider ) {
        if ( ! is_string( $provider ) || ! isset( FLAGS[ $provider ] ) ) { return error( 'provider', 'Choose a documented integration.' ); }
        $output[] = status( $provider ) + [ 'operations' => definition( $provider )['operations'], 'fields' => match ( $provider ) { 'seo-framework', 'rank-math' => Seo\schemas( $provider ), 'code-snippets' => Snippets\schema(), 'ws-form' => Forms\schema() } ];
    }
    return [ 'site_url' => \ChurchWebsiteConnector\bound_site(), 'integrations' => $output, 'preview_lifetime_seconds' => TTL,
        'workflow' => 'Read the current item; preview one change with its state_hash; review the concrete diff; apply its owner-bound token only after approval. Creation uses ID 0. One pending integration preview per account.',
        'scope' => 'Fixed SEO, snippet and form-design operations only. No arbitrary routes, plugin installation, credentials, licence settings, submissions or bulk changes.' ];
}
function get_item( $input ) {
	$ok = check( $input, array( 'site_url', 'integration', 'kind', 'id', 'section', 'design_page', 'state_hash' ) );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$target = target( $input );
	if ( is_wp_error( $target ) ) {
		return $target;
	}
	$page = $input['design_page'] ?? 1;
	if ( ! is_int( $page ) || $page < 1 || ( ( isset( $input['design_page'] ) || isset( $input['state_hash'] ) ) && 'ws-form' !== $target['provider'] ) ) {
		return error( 'design_page', 'Use a positive page number for a WS Form design.' );
	}
	$item = read( $target );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	if ( ( $page > 1 || isset( $input['state_hash'] ) ) && ( $input['state_hash'] ?? null ) !== $item['state_hash'] ) {
		return error( 'design_changed', 'Read page one again and use its current state hash for subsequent pages.', 409 );
	}
	return public_item( $target, $item, $page );
}
function listing( $input ) {
    $ok = check( $input, [ 'site_url', 'integration', 'page', 'per_page', 'post_type' ] ); if ( is_wp_error( $ok ) ) { return $ok; }
    $provider = $input['integration'] ?? ''; if ( ! is_string( $provider ) ) { return error( 'provider', 'Choose a documented integration.' ); }
    $ok = ready( $provider ); if ( is_wp_error( $ok ) ) { return $ok; }
    $page = $input['page'] ?? 1; $limit = $input['per_page'] ?? 20;
    if ( ! is_int( $page ) || $page < 1 || $page > 10000 || ! is_int( $limit ) || $limit < 1 || $limit > 50 ) { return error( 'pagination', 'Use page 1 or later and between 1 and 50 items.' ); }
    try {
        $items = match ( $provider ) { 'code-snippets' => Snippets\listing( $page, $limit ), 'ws-form' => Forms\listing( $page, $limit ), default => Seo\listing( $provider, $page, $limit, $input['post_type'] ?? 'page' ) };
    } catch ( \Throwable $e ) { return error( 'listing', 'The plugin could not list items: ' . $e->getMessage(), 502 ); }
    return is_wp_error( $items ) ? $items : [ 'site_url' => \ChurchWebsiteConnector\bound_site(), 'integration' => $provider, 'page' => $page, 'per_page' => $limit, 'items' => $items ];
}
function stage_name( int $user ): string { return PREVIEW . $user; }
function expire( int $user, string $hash ): void {
    $saved = get_option( stage_name( $user ), false );
    if ( is_array( $saved ) && ( $saved['token_hash'] ?? '' ) === $hash && ( $saved['expires'] ?? 0 ) <= time() ) { \ChurchWebsiteConnector\RestTools\conditional_delete( stage_name( $user ), $saved ); }
}
function preview( $input, ?array $replaces = null ) {
    $ok = check( $input, [ 'site_url', 'integration', 'kind', 'id', 'section', 'state_hash', 'operation', 'changes' ] ); if ( is_wp_error( $ok ) ) { return $ok; }
    $target = target( $input ); if ( is_wp_error( $target ) ) { return $target; }
    $operation = $input['operation'] ?? 'update';
    if ( ! is_string( $operation ) || ! in_array( $operation, definition( $target['provider'] )['operations'], true ) ) { return error( 'operation', 'Choose an operation reported by integration-capabilities.' ); }
    if ( ( $operation === 'create' ) !== ( $target['id'] === 0 && in_array( $target['kind'], [ 'snippet', 'form' ], true ) ) ) { return error( 'target', 'Creation uses ID 0; other item operations require an existing item.' ); }
    $item = read( $target ); if ( is_wp_error( $item ) ) { return $item; }
    if ( $operation !== 'create' && ( ! is_string( $input['state_hash'] ?? null ) || ! hash_equals( $item['state_hash'], $input['state_hash'] ) ) ) { return error( 'stale', 'The item changed. Read its details again before previewing.', 409 ); }
    $changes = $input['changes'] ?? [];
    if ( ! is_array( $changes ) || ( $changes !== [] && array_is_list( $changes ) ) || ! bounded_json( $changes ) ) { return error( 'changes', 'Supply a bounded object containing supported fields.' ); }
    try { $proposal = match ( $target['provider'] ) { 'seo-framework', 'rank-math' => Seo\prepare( $target, $item, $operation, $changes ), 'code-snippets' => Snippets\prepare( $target, $item, $operation, $changes ), 'ws-form' => Forms\prepare( $target, $item, $operation, $changes ) }; }
    catch ( \Throwable $e ) { return error( 'preview', 'The plugin could not validate the proposed change: ' . $e->getMessage() ); }
    if ( is_wp_error( $proposal ) ) { return $proposal; }
    if ( ! $proposal['diff'] ) { return error( 'unchanged', 'The proposed change has no effect.', 409 ); }
	if ( 'ws-form' === $target['provider'] && ! bounded_json( $proposal['diff'] ) ) {
		return error( 'size', 'The form change exceeds the review size limit. Use smaller field or section edits.', 400 );
	}
	$saved = array(
		'target'         => $target,
		'operation'      => $operation,
		'state_hash'     => $item['state_hash'],
		'changes'        => $proposal['changes'],
		'code_execution' => $proposal['code_execution'] ?? false,
	);
	if ( 'ws-form' === $target['provider'] && 'publish' === $operation ) {
		$saved['form_publication_scope_reviewed'] = true;
	}
	// Rebuild scoped edits from their exact input, never a stale full form design.
	if ( 'create' !== $operation && ! isset( $changes['design'] ) ) {
		$saved['request_changes'] = $changes;
	}
	$staged = \ChurchWebsiteConnector\RestTools\stage_review( $saved, $replaces, PREVIEW, EXPIRE, TTL, 'church_connector_integration_' );
	if ( is_wp_error( $staged ) ) {
		return $staged;
	}
	return array(
		'site_url'                                => \ChurchWebsiteConnector\bound_site(),
		'integration'                             => $target['provider'],
		'operation'                               => $operation,
		'diff'                                    => $proposal['diff'],
		'requires_code_execution_acknowledgement' => $saved['code_execution'],
		'code_review'                             => $proposal['code_review'] ?? null,
		'message'                                 => $proposal['warning'] ?? 'Preview only. Review the exact diff and approve before applying.',
	) + $staged;
}
function load_preview( $token ) {
    if ( ! is_string( $token ) || preg_match( '/\A[a-f0-9]{64}\z/', $token ) !== 1 ) { return error( 'token', 'Use a valid integration preview token.' ); }
    $saved = get_option( stage_name( get_current_user_id() ), false );
    if ( ! is_array( $saved ) || ( $saved['owner'] ?? 0 ) !== get_current_user_id() || ! hash_equals( (string) ( $saved['token_hash'] ?? '' ), hash( 'sha256', $token ) ) ) { return error( 'token', 'This preview is unavailable to the signed-in account.', 403 ); }
	if ( ! \ChurchWebsiteConnector\RestTools\preview_site_matches( $saved ) ) {
		return error( 'site', 'The preview belongs to another website configuration.', 403 );
	}
	if ( $saved['expires'] <= time() ) {
		expire( get_current_user_id(), $saved['token_hash'] );
		return error( 'expired', 'The preview expired.', 409 );
	}
    return $saved;
}
function apply( $input ) {
    $ok = check( $input, [ 'site_url', 'preview_token', 'approved', 'acknowledge_code_execution' ] ); if ( is_wp_error( $ok ) ) { return $ok; }
    if ( ( $input['approved'] ?? false ) !== true ) { return error( 'approval', 'Review and explicitly approve the preview before applying.', 403 ); }
    $saved = load_preview( $input['preview_token'] ?? null ); if ( is_wp_error( $saved ) ) { return $saved; }
    if ( $saved['code_execution'] && ( $input['acknowledge_code_execution'] ?? false ) !== true ) { return error( 'code_execution', 'This change can run code. Obtain explicit approval and acknowledge_code_execution before applying.', 403 ); }
    $target = $saved['target']; $lock = LOCK . substr( fingerprint( $target ), 0, 32 ); $holder = wp_json_encode( [ 'expires' => time() + 120, 'token' => bin2hex( random_bytes( 16 ) ) ] );
    $previous = get_option( $lock, false );
    if ( is_string( $previous ) && ( json_decode( $previous, true )['expires'] ?? PHP_INT_MAX ) < time() ) { \ChurchWebsiteConnector\RestTools\conditional_delete( $lock, $previous ); }
	if ( ! \ChurchWebsiteConnector\RestTools\insert_lock( $lock, $holder ) ) {
		return error( 'locked', 'Another connector request is changing this item. Read it again shortly.', 409 );
	}
    try {
        $item = read( $target ); if ( is_wp_error( $item ) ) { return $item; }
		$old_form_review = 'ws-form' === $target['provider'] && 'publish' === $saved['operation'] && empty( $saved['form_publication_scope_reviewed'] );
		if ( $old_form_review || ! hash_equals( $saved['state_hash'], $item['state_hash'] ) ) {
			$error = error( 'stale', $old_form_review ? 'Review the form and its native settings using the updated publication preview.' : 'The item or plugin changed after preview.', 409 );
			if ( ! isset( $saved['request_changes'] ) ) {
				\ChurchWebsiteConnector\RestTools\retire_preview( $saved, PREVIEW, EXPIRE );
				return $error;
			}
			$request = array(
				'site_url'    => \ChurchWebsiteConnector\bound_site(),
				'integration' => $target['provider'],
				'kind'        => $target['kind'],
				'id'          => $target['id'],
				'section'     => $target['section'],
				'state_hash'  => $item['state_hash'],
				'operation'   => $saved['operation'],
				'changes'     => $saved['request_changes'],
			);
			return \ChurchWebsiteConnector\RestTools\refreshed_error( $error, preview( $request, $saved ), $saved, PREVIEW, EXPIRE );
		}
        if ( ! \ChurchWebsiteConnector\RestTools\conditional_delete( stage_name( get_current_user_id() ), $saved ) ) { return error( 'consumed', 'The preview was replaced or already claimed.', 409 ); }
        wp_clear_scheduled_hook( EXPIRE, [ get_current_user_id(), $saved['token_hash'] ] );
        try { $result = match ( $target['provider'] ) { 'seo-framework', 'rank-math' => Seo\write( $target, $item, $saved['operation'], $saved['changes'] ), 'code-snippets' => Snippets\write( $target, $item, $saved['operation'], $saved['changes'] ), 'ws-form' => Forms\write( $target, $item, $saved['operation'], $saved['changes'] ) }; }
        catch ( \Throwable $e ) { $result = error( 'write', 'The plugin did not complete cleanly: ' . $e->getMessage(), 502 ); }
		if ( ! is_wp_error( $result ) && isset( $result['id'] ) ) {
			$target['id'] = $result['id'];
		}
		\ChurchWebsiteConnector\RestTools\append_audit(
			AUDIT,
			array(
				'time_utc'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'user_id'     => get_current_user_id(),
				'integration' => $target['provider'],
				'kind'        => $target['kind'],
				'id'          => $target['id'],
				'operation'   => $saved['operation'],
				'field_names' => array_keys( $saved['changes'] ),
				'result'      => is_wp_error( $result ) ? 'error' : 'completed',
			)
		);
		if ( is_wp_error( $result ) ) {
			if ( 'church_connector_integration_stale' === $result->get_error_code() ) {
				return $result;
			}
			return error( 'partial', $result->get_error_message() . ' Inspect the item before retrying. The preview was consumed and changes may have partially completed.', 502 );
		}
		if ( isset( $result['verified_item'] ) ) {
			$public = $result['verified_item'];
			unset( $result['verified_item'] );
		} else {
			$after = read( $target );
			if ( is_wp_error( $after ) ) {
				return error( 'readback', 'The operation completed but read-back failed. Inspect before retrying.', 502 );
			}
			$public = public_item( $target, $after );
		}
		return array(
			'changed'   => true,
			'operation' => $saved['operation'],
			'item'      => $public,
			'result'    => $result,
		);
    } finally { \ChurchWebsiteConnector\RestTools\conditional_delete( $lock, $holder ); }
}
function discard( $input ) {
    $ok = check( $input, [ 'site_url', 'preview_token' ] ); if ( is_wp_error( $ok ) ) { return $ok; }
    $saved = load_preview( $input['preview_token'] ?? null ); if ( is_wp_error( $saved ) ) { return $saved; }
    if ( ! \ChurchWebsiteConnector\RestTools\conditional_delete( stage_name( get_current_user_id() ), $saved ) ) { return error( 'replaced', 'The preview was replaced or already claimed.', 409 ); }
    wp_clear_scheduled_hook( EXPIRE, [ get_current_user_id(), $saved['token_hash'] ] ); return [ 'discarded' => true ];
}
function diff( array $before, array $changes ): array {
    $diff = []; foreach ( $changes as $key => $value ) { if ( canonical( $before[ $key ] ?? null ) !== canonical( $value ) ) { $diff[ $key ] = [ 'before' => $before[ $key ] ?? null, 'after' => $value ]; } } return $diff;
}
function validate_fields( array $changes, array $schema ) {
    if ( ! $changes || array_diff( array_keys( $changes ), array_keys( $schema ) ) ) { return error( 'fields', 'Use only the documented editable fields.' ); }
    $out = [];
    foreach ( $changes as $key => $value ) {
        $spec = $schema[ $key ]; $validation_spec = $spec; if ( ( $spec['format'] ?? '' ) === 'uri' ) { unset( $validation_spec['format'] ); } $valid = rest_validate_value_from_schema( $value, $validation_spec, $key ); if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ( $spec['format'] ?? '' ) === 'uri' && $value !== '' ) {
            $parts = wp_parse_url( $value ); if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) || empty( $parts['host'] ) || ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) || preg_match( '/[\x00-\x20]/', $value ) ) { return error( 'url', 'Use an absolute HTTP(S) URL without credentials, or an empty string.' ); }
        }
        $out[ $key ] = $value;
    }
    return $out;
}
require_once __DIR__ . '/seo-integrations.php';
require_once __DIR__ . '/snippets-integration.php';
require_once __DIR__ . '/wsform-integration.php';

function schemas(): array {
    $site = [ 'site_url' => [ 'type' => 'string', 'format' => 'uri' ] ];
    $provider = [ 'integration' => [ 'type' => 'string', 'enum' => array_keys( FLAGS ) ] ];
    $target = $site + $provider + [ 'kind' => [ 'type' => 'string', 'enum' => [ 'post', 'settings', 'snippet', 'form' ] ], 'id' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647 ], 'section' => [ 'type' => 'string', 'enum' => [ '', 'general', 'titles', 'sitemap' ] ] ];
    $token = $site + [ 'preview_token' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ] ];
    return [
        'integration-capabilities' => [ 'Integration capabilities', 'Read installed versions, switches, supported operations and editable schemas for The SEO Framework, Rank Math, Code Snippets and WS Form Pro. No credentials or submissions.', 'capabilities', $site + $provider, [ 'site_url' ], true ],
        'list-integration-items' => [ 'List integration items', 'List bounded SEO post/page IDs, snippet summaries or form summaries. Snippet code and submission data are omitted from lists.', 'listing', $site + $provider + [ 'page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ], 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ], 'post_type' => [ 'type' => 'string', 'enum' => [ 'post', 'page' ] ] ], [ 'site_url', 'integration' ], true ],
		'get-integration-item'       => array(
			'Read an integration item',
			'Read supported SEO fields/settings, one snippet, or form design and settings, with a state_hash. SEO settings use ID 0. Rank Math also requires section general, titles or sitemap. Large WS Form designs return ordered nodes, design_page and next_design_page. Read the next page with its state_hash.',
			'get_item',
			$target + array(
				'design_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'state_hash'  => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
			),
			array( 'site_url', 'integration', 'kind', 'id' ),
			true,
		),
        'preview-integration-change' => [ 'Preview an integration change', 'Stage one supported integration change without applying it. Read current state_hash first; creation uses ID 0. Use only fields and operations from integration-capabilities. Review code execution and publishing effects before approval.', 'preview', $target + [ 'state_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ], 'operation' => [ 'type' => 'string', 'enum' => [ 'update', 'create', 'activate', 'deactivate', 'trash', 'publish', 'unpublish', 'add_field', 'add_section', 'add_tab', 'update_field', 'update_section', 'update_tab', 'delete_field', 'delete_section', 'delete_tab' ] ], 'changes' => [ 'type' => 'object', 'maxProperties' => 64 ] ], [ 'site_url', 'integration', 'kind', 'id', 'operation' ], false ],
        'apply-integration-change' => [ 'Apply an approved integration change', 'WRITE: apply exactly the signed-in account’s reviewed integration preview. Rechecks website, item, plugin, switches and native permissions. Set approved true only after user approval; code-executing changes additionally require acknowledge_code_execution true. No arbitrary REST routes or submissions.', 'apply', $token + [ 'approved' => [ 'type' => 'boolean', 'const' => true ], 'acknowledge_code_execution' => [ 'type' => 'boolean' ] ], [ 'site_url', 'preview_token', 'approved' ], false ],
        'discard-integration-change' => [ 'Discard an integration preview', 'Discard only the signed-in account’s pending integration preview without changing the plugin item.', 'discard', $token, [ 'site_url', 'preview_token' ], false ],
    ];
}
function register_abilities(): void {
    if ( ! function_exists( 'wp_register_ability' ) || ! array_filter( FLAGS, '\\ChurchWebsiteConnector\\feature_enabled' ) ) { return; }
    foreach ( schemas() as $name => [ $label, $description, $callback, $properties, $required, $readonly ] ) {
        wp_register_ability( 'church-connector/' . $name, [ 'label' => $label, 'description' => $description, 'category' => 'site', 'input_schema' => [ 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ], 'output_schema' => [ 'type' => 'object' ], 'execute_callback' => __NAMESPACE__ . '\\' . $callback, 'permission_callback' => __NAMESPACE__ . '\\base_permission', 'meta' => [ 'show_in_rest' => false, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readonly' => $readonly, 'destructive' => $name === 'apply-integration-change', 'idempotent' => $readonly ] ] ] );
    }
}
function register_routes(): void {
    foreach ( schemas() as $name => [ $label, $description, $callback, $properties, $required, $readonly ] ) {
        $args = []; foreach ( $properties as $key => $spec ) { $args[ $key ] = $spec + [ 'required' => in_array( $key, $required, true ) ]; }
        register_rest_route( 'church-connector/v1', '/integrations/' . $name, [ 'methods' => $readonly ? 'GET' : 'POST', 'callback' => static function( $request ) use ( $callback ) { return ( __NAMESPACE__ . '\\' . $callback )( $request->get_params() ); }, 'permission_callback' => __NAMESPACE__ . '\\base_permission', 'args' => $args ] );
    }
}
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities', 20 );
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
add_action( EXPIRE, __NAMESPACE__ . '\\expire', 10, 2 );
