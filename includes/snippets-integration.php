<?php
/** Code Snippets Free 4.1.x through its native REST controller. */
declare(strict_types=1);
namespace ChurchWebsiteConnector\Integrations\Snippets;
use ChurchWebsiteConnector\Integrations as I;
defined( 'ABSPATH' ) || exit;
function definition(): array { return [ 'label' => 'Code Snippets', 'constant' => 'CODE_SNIPPETS_VERSION', 'minimum' => '4.1.0', 'before' => '4.2', 'tested' => '4.1.0 Free', 'interfaces' => [ '\\Code_Snippets\\code_snippets' ], 'operations' => [ 'create', 'update', 'activate', 'deactivate', 'trash' ] ]; }
function schema(): array { return [ 'name' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 300 ], 'desc' => [ 'type' => 'string', 'maxLength' => 5000 ], 'code' => [ 'type' => 'string', 'maxLength' => 65536 ], 'scope' => [ 'type' => 'string', 'enum' => [ 'global', 'admin', 'front-end', 'single-use', 'content', 'head-content', 'body-content', 'footer-content', 'admin-css', 'site-css', 'site-head-js', 'site-footer-js' ] ], 'priority' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 999 ], 'tags' => [ 'type' => 'array', 'maxItems' => 30, 'items' => [ 'type' => 'string', 'maxLength' => 100 ], 'uniqueItems' => true ] ]; }
function permission() { return \Code_Snippets\code_snippets()->current_user_can() ? true : I\error( 'forbidden', 'Code Snippets does not permit this account to manage snippets.', 403 ); }
function filtered( array $data, bool $code = true ): array {
    $keys = [ 'id', 'name', 'desc', 'scope', 'priority', 'tags', 'active', 'trashed', 'locked', 'modified' ]; if ( $code ) { $keys[] = 'code'; }
    return array_intersect_key( $data, array_flip( $keys ) );
}
function read( array $target ) {
    $ok = permission(); if ( is_wp_error( $ok ) ) { return $ok; }
    if ( $target['id'] === 0 ) { return [ 'data' => [ 'new_item' => true, 'active' => false ], 'state' => [ 'create', I\status( 'code-snippets' )['installed_version'] ] ]; }
	$storage = I\WriteGuard\storage( $target );
	if ( is_wp_error( $storage ) ) {
		return $storage;
	}
	// The native item endpoint can reuse a list cached earlier in this request.
	// Read the individual record underlying the newly captured storage snapshot.
	wp_cache_delete( 'all_snippets_' . $storage['table'], \Code_Snippets\CACHE_GROUP );
    $data = I\native_rest( 'GET', '/code-snippets/v1/snippets/' . $target['id'], [ 'network' => false ] ); if ( is_wp_error( $data ) ) { return $data; }
    if ( ! is_array( $data ) || (int) ( $data['id'] ?? 0 ) !== $target['id'] || ! empty( $data['network'] ) || ! empty( $data['shared_network'] ) ) { return I\error( 'response', 'The native snippet response does not identify this site’s snippet.', 502 ); }
	return array(
		'data'          => filtered( $data ),
		'state'         => array( filtered( $data ), I\fingerprint( $storage ) ),
		'raw'           => $data,
		'write_storage' => $storage,
	);
}
function listing( int $page, int $limit ) {
    $ok = permission(); if ( is_wp_error( $ok ) ) { return $ok; }
    $data = I\native_rest( 'GET', '/code-snippets/v1/snippets', [ 'network' => false, 'page' => $page, 'per_page' => $limit, 'orderby' => 'id', 'order' => 'asc' ] );
    if ( is_wp_error( $data ) ) { return $data; } if ( ! is_array( $data ) || ! array_is_list( $data ) ) { return I\error( 'response', 'Unexpected snippet list.', 502 ); }
    return array_map( static fn( $row ) => filtered( $row, false ), $data );
}
function prepare( array $target, array $item, string $operation, array $changes ) {
    if ( ! empty( $item['data']['locked'] ) || ! empty( $item['data']['trashed'] ) ) { return I\error( 'locked', 'Use Code Snippets to unlock or restore this snippet before changing it.', 409 ); }
    if ( in_array( $operation, [ 'activate', 'deactivate', 'trash' ], true ) ) {
        if ( $changes ) { return I\error( 'fields', 'Activation, deactivation and trash operations do not accept field changes.' ); }
        $diff = $operation === 'trash' ? [ 'trashed' => [ 'before' => false, 'after' => true ] ] : I\diff( $item['data'], [ 'active' => $operation === 'activate' ] );
        $code_execution = $operation === 'activate'; $clean = [];
    } else {
        $clean = I\validate_fields( $changes, schema() ); if ( is_wp_error( $clean ) ) { return $clean; }
        if ( $operation === 'create' && ( ! isset( $clean['name'], $clean['code'] ) ) ) { return I\error( 'fields', 'A new snippet requires name and code.' ); }
        $scope = $clean['scope'] ?? $item['data']['scope'] ?? 'global';
        if ( in_array( $scope, [ 'global', 'admin', 'front-end', 'single-use' ], true ) ) {
            try { token_get_all( '<?php ' . ( $clean['code'] ?? $item['data']['code'] ?? '' ), TOKEN_PARSE ); } catch ( \ParseError $e ) { return I\error( 'syntax', 'The PHP snippet has a syntax error: ' . $e->getMessage() ); }
        }
        $diff = I\diff( $item['data'], $clean ); $code_execution = ! empty( $item['data']['active'] );
    }
    return [ 'changes' => $clean, 'diff' => $diff, 'code_review' => $code_execution ? array_intersect_key( array_replace( $item['data'], $clean ), array_flip( [ 'name', 'scope', 'priority', 'code' ] ) ) : null, 'code_execution' => $code_execution, 'warning' => $code_execution ? 'This approved change can execute code on the website. Review the complete snippet and explicitly approve code execution; apply requires acknowledge_code_execution=true. Native validation is not a guarantee of safe behaviour.' : ( $operation === 'create' ? 'Creates an inactive snippet only. Activation requires a separate reviewed preview.' : 'Preview only. Native Code Snippets permissions and locks are preserved.' ) ];
}
function write( array $target, array $item, string $operation, array $changes ) {
	$allowed = permission();
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	if ( 'create' === $operation ) {
		return save( $target, $item, $operation, $changes );
	}
	return I\WriteGuard\run( $target, $item, $operation, static fn() => save( $target, $item, $operation, $changes ) );
}

function save( array $target, array $item, string $operation, array $changes ) {
    if ( $operation === 'create' ) { $result = I\native_rest( 'POST', '/code-snippets/v1/snippets', $changes + [ 'active' => false, 'network' => false ] ); }
    elseif ( $operation === 'update' ) { $result = I\native_rest( 'POST', '/code-snippets/v1/snippets/' . $target['id'], $changes + [ 'network' => false ] ); }
	elseif ( 'trash' === $operation ) {
		// The native REST DELETE permanently removes a record that just entered Trash.
		$result = \Code_Snippets\trash_snippet( $target['id'], false );
		if ( ! $result ) {
			return I\error( 'trash', 'Code Snippets refused to move the snippet to Trash.', 409 );
		}
	} else {
		$result = I\native_rest( 'POST', '/code-snippets/v1/snippets/' . $target['id'] . '/' . $operation, array( 'network' => false ) );
	}
    if ( is_wp_error( $result ) ) { return $result; }
    $id = $operation === 'create' ? (int) ( $result['id'] ?? 0 ) : $target['id']; if ( $id < 1 ) { return I\error( 'response', 'The native snippet write did not return an item ID.', 502 ); }
    $after = read( array_replace( $target, [ 'id' => $id ] ) ); if ( is_wp_error( $after ) ) { return $after; }
	if ( 'update' === $operation && (bool) $after['data']['active'] !== (bool) $item['data']['active'] ) {
		$message = $after['data']['active'] ? 'The updated snippet is unexpectedly active.' : 'Code Snippets saved the update but the snippet is now inactive. Check its native validation result before reactivating it.';
		return I\error( 'readback', $message, 409 );
	}
    if ( $operation === 'create' && ! empty( $after['data']['active'] ) ) { return I\error( 'readback', 'The snippet was unexpectedly activated; inspect it immediately.', 502 ); }
    if ( $operation === 'activate' && empty( $after['data']['active'] ) && ( $after['data']['scope'] ?? '' ) !== 'single-use' ) { return I\error( 'readback', 'The native plugin did not activate the snippet. Inspect its validation error.', 502 ); }
    if ( $operation === 'deactivate' && ! empty( $after['data']['active'] ) ) { return I\error( 'readback', 'The native plugin did not deactivate the snippet.', 502 ); }
    if ( $operation === 'trash' && empty( $after['data']['trashed'] ) ) { return I\error( 'readback', 'The native plugin did not move the snippet to Trash.', 502 ); }
    foreach ( $changes as $key => $value ) { if ( I\canonical( $after['data'][ $key ] ?? null ) !== I\canonical( $value ) ) { return I\error( 'readback', 'A snippet field did not match the approved value. Inspect it before retrying.', 502 ); } }
    return [ 'id' => $id, 'native_result_verified' => true ];
}
