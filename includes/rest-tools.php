<?php
declare(strict_types=1);

namespace ChurchWebsiteConnector\RestTools;

defined( 'ABSPATH' ) || exit;

const PREVIEW_PREFIX = 'church_connector_rest_preview_';
const LOCK_PREFIX = 'church_connector_rest_lock_';
const AUDIT = 'church_connector_rest_audit';
const EXPIRE_HOOK = 'church_connector_expire_rest_preview';
const TTL = 900;

function error( string $code, string $message, int $status = 400 ): \WP_Error {
    return new \WP_Error( 'church_connector_rest_' . $code, $message, [ 'status' => $status ] );
}

function permission( $input = null ) {
    if ( ! is_user_logged_in() || ! \ChurchWebsiteConnector\feature_enabled( 'rest_tools' ) ) {
        return error( 'forbidden', 'Sign in through the existing MCP connection and enable REST-backed tools for this website.', 403 );
    }
    if ( ! function_exists( 'rest_do_request' ) || ! class_exists( '\WP_REST_Request' ) ) {
        return error( 'unavailable', 'The WordPress REST dispatcher is unavailable.', 503 );
    }
    return true;
}

function fields( string $kind ): array {
    return match ( $kind ) {
        'post' => [ 'title', 'excerpt', 'slug', 'featured_media', 'categories', 'tags', 'comment_status', 'ping_status' ],
        'page' => [ 'title', 'excerpt', 'slug', 'featured_media', 'parent', 'menu_order', 'comment_status', 'ping_status' ],
        'media' => [ 'title', 'alt_text', 'caption', 'description' ],
        default => [],
    };
}

function route( string $kind, int $id ): string {
    return '/wp/v2/' . match ( $kind ) { 'post' => 'posts', 'page' => 'pages', 'media' => 'media' } . '/' . $id;
}

function check_input( $input, array $keys ) {
    $allowed = permission();
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    if ( ! is_array( $input ) || array_diff( array_keys( $input ), $keys ) ) {
        return error( 'input', 'Only the documented parameters are accepted.' );
    }
    if ( ( $input['site_url'] ?? null ) !== \ChurchWebsiteConnector\bound_site() ) {
        return error( 'site', 'The request must identify this installation’s exact website address.', 403 );
    }
    return true;
}

function target( array $input ) {
    $kind = $input['kind'] ?? null;
    $id = $input['id'] ?? null;
    if ( ! is_string( $kind ) || ! fields( $kind ) || ! is_int( $id ) || $id < 1 ) {
        return error( 'target', 'Choose kind post, page or media and a positive integer id.' );
    }
    return [ 'kind' => $kind, 'id' => $id ];
}

/** Internal dispatch retains the authenticated user and core permission callbacks. */
function dispatch( string $method, string $path, array $parameters = [] ) {
    if ( ! in_array( $method, [ 'GET', 'POST' ], true ) || ! preg_match( '~\A/wp/v2/(posts|pages|media|categories|tags)/[1-9][0-9]*\z~', $path, $match ) ) {
        return error( 'route', 'This REST operation is outside the fixed tool allowlist.', 403 );
    }
    if ( $method === 'POST' ) {
        $kind = [ 'posts' => 'post', 'pages' => 'page', 'media' => 'media' ][ $match[1] ] ?? '';
        if ( ! $kind || array_diff( array_keys( $parameters ), fields( $kind ) ) ) { return error( 'route', 'This REST write is outside the metadata allowlist.', 403 ); }
    }
    $request = new \WP_REST_Request( $method, $path );
    if ( $method === 'GET' ) {
        $request->set_query_params( $parameters );
    } else {
        $request->set_body_params( $parameters );
    }
    $response = rest_do_request( $request );
    if ( is_wp_error( $response ) ) { return $response; }
    $data = $response->get_data();
    if ( $response->get_status() >= 400 ) {
        return error( 'denied', is_array( $data ) && is_string( $data['message'] ?? null ) ? wp_strip_all_tags( $data['message'] ) : 'WordPress refused the REST operation.', $response->get_status() );
    }
    return is_array( $data ) ? $data : error( 'response', 'WordPress returned an unexpected response.', 502 );
}

function raw_text( mixed $value ): string {
    return is_array( $value ) ? (string) ( $value['raw'] ?? '' ) : ( is_string( $value ) ? $value : '' );
}

function read_item( string $kind, int $id ) {
    // Check before dispatch too, including when callbacks are invoked directly.
    if ( ! current_user_can( 'edit_post', $id ) ) {
        return error( 'forbidden', 'Your WordPress account cannot edit this item.', 403 );
    }
    $data = dispatch( 'GET', route( $kind, $id ), [ 'context' => 'edit' ] );
    if ( is_wp_error( $data ) ) { return $data; }
    $post = get_post( $id );
    $expected_type = $kind === 'media' ? 'attachment' : $kind;
    if ( ! $post || $post->post_type !== $expected_type || ( $data['id'] ?? null ) !== $id || $post->post_status === 'trash' ) {
        return error( 'target', 'The item does not match the requested kind or is in the trash.', 404 );
    }
    $values = [];
    foreach ( fields( $kind ) as $field ) {
        if ( array_key_exists( $field, $data ) ) {
            $values[ $field ] = in_array( $field, [ 'title', 'excerpt', 'caption', 'description' ], true ) ? raw_text( $data[ $field ] ) : $data[ $field ];
        }
    }
    $snapshot = [
        'kind' => $kind, 'id' => $id, 'status' => $post->post_status,
        'modified_gmt' => $post->post_modified_gmt,
        'fields' => $values,
    ];
    // Raw block content and password are hashed server-side, never returned.
    $guard = [ $snapshot, $post->post_author, $post->post_date_gmt, $post->post_parent,
        hash( 'sha256', $post->post_content ), hash( 'sha256', $post->post_password ) ];
    $snapshot['state_hash'] = hash( 'sha256', wp_json_encode( $guard ) );
    return $snapshot;
}

function capabilities( $input = array() ) {
	$checked = check_input( $input, array( 'site_url' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	return array(
		'site_url'                 => \ChurchWebsiteConnector\bound_site(),
		'resources'                => array_map( __NAMESPACE__ . '\\fields', array( 'post', 'page', 'media' ) ),
		'resource_order'           => array( 'post', 'page', 'media' ),
		'preview_lifetime_seconds' => TTL,
		'sign_in'                  => 'Existing MCP authentication; internal REST requests use the current WordPress user and native REST permission callbacks.',
		'workflow'                 => 'Read item details, preview an allowed metadata update with its state_hash, then apply the owner-bound token after approval. One outstanding preview per user.',
		'content_editing'          => 'Use GeneratePress for block authoring. The metadata tools do not change content, status or publication dates. Separate post and editorial tools provide typed PDF replacement, draft dating, publication and scheduling.',
	);
}

function get_item( $input ) {
    $checked = check_input( $input, [ 'site_url', 'kind', 'id' ] );
    if ( is_wp_error( $checked ) ) { return $checked; }
    $target = target( $input );
    if ( is_wp_error( $target ) ) { return $target; }
    $result = read_item( $target['kind'], $target['id'] );
    return is_wp_error( $result ) ? $result : [ 'site_url' => \ChurchWebsiteConnector\bound_site(), 'item' => $result ];
}

function normalise_changes( array $item, mixed $changes ) {
    if ( ! is_array( $changes ) || ! $changes || count( $changes ) > 10 || array_diff( array_keys( $changes ), fields( $item['kind'] ) ) ) {
        return error( 'fields', 'Use only supported metadata fields for this kind of item.' );
    }
    $clean = [];
    foreach ( $changes as $field => $value ) {
        if ( ! array_key_exists( $field, $item['fields'] ) ) { return error( 'fields', 'This WordPress REST controller does not expose the requested field.' ); }
        if ( in_array( $field, [ 'title', 'excerpt', 'slug', 'alt_text', 'caption', 'description' ], true ) ) {
            $limit = in_array( $field, [ 'title', 'slug', 'alt_text' ], true ) ? 2000 : 10000;
            if ( ! is_string( $value ) || strlen( $value ) > $limit ) { return error( 'value', 'Text fields must be strings within the documented length limit.' ); }
            $value = $field === 'slug' ? sanitize_title( $value ) : ( in_array( $field, [ 'title', 'alt_text' ], true ) ? sanitize_text_field( $value ) : sanitize_textarea_field( $value ) );
            if ( $field === 'slug' && $value === '' ) { return error( 'value', 'A slug cannot be empty.' ); }
        } elseif ( in_array( $field, [ 'comment_status', 'ping_status' ], true ) ) {
            if ( ! in_array( $value, [ 'open', 'closed' ], true ) ) { return error( 'value', 'Comment and ping status must be open or closed.' ); }
        } elseif ( in_array( $field, [ 'categories', 'tags' ], true ) ) {
            if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 50 ) { return error( 'value', 'Use a list of at most 50 existing term IDs.' ); }
            $taxonomy = get_taxonomy( $field === 'categories' ? 'category' : 'post_tag' );
            if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) ) { return error( 'forbidden', 'Your WordPress account cannot assign these terms.', 403 ); }
            foreach ( $value as $term_id ) {
                if ( ! is_int( $term_id ) || $term_id < 1 ) { return error( 'value', 'Term IDs must be positive integers.' ); }
                $term = dispatch( 'GET', '/wp/v2/' . $field . '/' . $term_id );
                if ( is_wp_error( $term ) ) { return $term; }
            }
            $value = array_values( array_unique( $value ) );
            sort( $value );
        } else {
            if ( ! is_int( $value ) || ( $field !== 'menu_order' && $value < 0 ) || abs( $value ) > 2147483647 ) { return error( 'value', 'Use an integer in the supported range.' ); }
            if ( $field === 'featured_media' && $value > 0 ) {
                $media = dispatch( 'GET', '/wp/v2/media/' . $value );
                if ( is_wp_error( $media ) ) { return $media; }
                if ( ! str_starts_with( (string) ( $media['mime_type'] ?? '' ), 'image/' ) ) { return error( 'value', 'Featured media must be an existing image.' ); }
            }
            if ( $field === 'parent' && $value > 0 ) {
                $parent = dispatch( 'GET', '/wp/v2/pages/' . $value, [ 'context' => 'edit' ] );
                if ( is_wp_error( $parent ) ) { return $parent; }
                if ( $value === $item['id'] || in_array( $item['id'], get_post_ancestors( $value ), true ) ) { return error( 'value', 'The parent would create a page hierarchy loop.' ); }
            }
        }
        $clean[ $field ] = $value;
    }
    if ( isset( $clean['slug'] ) ) {
        $clean['slug'] = wp_unique_post_slug( $clean['slug'], $item['id'], $item['status'], $item['kind'], $clean['parent'] ?? ( $item['fields']['parent'] ?? 0 ) );
    }
    return $clean;
}

function stage_name( int $user_id ): string { return PREVIEW_PREFIX . $user_id; }

/** Delete only the exact saved value; another request may have replaced it. */
function conditional_delete( string $name, mixed $value ): bool {
    global $wpdb;
    $removed = $wpdb->delete( $wpdb->options, [ 'option_name' => $name, 'option_value' => maybe_serialize( $value ) ], [ '%s', '%s' ] );
    wp_cache_delete( $name, 'options' );
    return $removed === 1;
}

function expire_preview( int $user_id, string $token_hash ): void {
    $saved = get_option( stage_name( $user_id ), false );
    if ( is_array( $saved ) && ( $saved['token_hash'] ?? '' ) === $token_hash && ( $saved['expires'] ?? 0 ) <= time() ) {
        conditional_delete( stage_name( $user_id ), $saved );
    }
}

function preview( $input ) {
	$checked = check_input( $input, array( 'site_url', 'kind', 'id', 'state_hash', 'changes' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$target = target( $input );
	if ( is_wp_error( $target ) ) {
		return $target;
	}
	$item = read_item( $target['kind'], $target['id'] );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	if ( ! is_string( $input['state_hash'] ?? null ) || ! hash_equals( $item['state_hash'], $input['state_hash'] ) ) {
		return error( 'stale', 'The item changed. Read its details again before previewing.', 409 );
	}
	$changes = normalise_changes( $item, $input['changes'] ?? null );
	if ( is_wp_error( $changes ) ) {
		return $changes;
	}
	$diff = array();
	foreach ( $changes as $field => $value ) {
		if ( $item['fields'][ $field ] !== $value ) {
			$diff[ $field ] = array(
				'before' => $item['fields'][ $field ],
				'after'  => $value,
			);
		}
	}
	if ( ! $diff ) {
		return error( 'unchanged', 'The proposed metadata is already saved.', 409 );
	}
	$result = save_preview(
		array(
			'kind'       => $target['kind'],
			'id'         => $target['id'],
			'state_hash' => $item['state_hash'],
			'changes'    => array_intersect_key( $changes, $diff ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return array(
		'site_url' => \ChurchWebsiteConnector\bound_site(),
		'kind'     => $target['kind'],
		'id'       => $target['id'],
		'diff'     => $diff,
		'message'  => 'Metadata preview only; the item is unchanged. Apply this owner-bound token after approval. A new preview replaces your previous one.',
	) + $result;
}

/**
 * Share the owner-bound preview slot across metadata and typed post operations.
 *
 * A successful replacement invalidates the previous token before scheduling cleanup.
 */
function save_preview( array $operation ): array|\WP_Error {
	$token   = bin2hex( random_bytes( 32 ) );
	$user_id = get_current_user_id();
	$old     = get_option( stage_name( $user_id ), false );
	$saved   = array(
		'token_hash' => hash( 'sha256', $token ),
		'owner'      => $user_id,
		'site'       => \ChurchWebsiteConnector\current_identity(),
		'expires'    => time() + TTL,
	) + $operation;
	if ( ! update_option( stage_name( $user_id ), $saved, false ) ) {
		return error( 'storage', 'The preview could not be saved. No item was changed.', 503 );
	}
	if ( is_array( $old ) && isset( $old['token_hash'] ) ) {
		wp_clear_scheduled_hook( EXPIRE_HOOK, array( $user_id, $old['token_hash'] ) );
	}
	wp_schedule_single_event( $saved['expires'], EXPIRE_HOOK, array( $user_id, $saved['token_hash'] ) );
	return array(
		'preview_token'  => $token,
		'expires_at_utc' => gmdate( 'c', $saved['expires'] ),
	);
}

function consume_preview( array $saved ): bool {
	if ( ! conditional_delete( stage_name( get_current_user_id() ), $saved ) ) {
		return false;
	}
	wp_clear_scheduled_hook( EXPIRE_HOOK, array( get_current_user_id(), $saved['token_hash'] ) );
	return true;
}

function load_preview( mixed $token ) {
    if ( ! is_string( $token ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $token ) ) { return error( 'token', 'Use a valid preview token.' ); }
    $saved = get_option( stage_name( get_current_user_id() ), false );
    if ( ! is_array( $saved ) || ! hash_equals( (string) ( $saved['token_hash'] ?? '' ), hash( 'sha256', $token ) ) || ( $saved['owner'] ?? 0 ) !== get_current_user_id() ) { return error( 'token', 'This preview is unavailable for the signed-in account.', 410 ); }
    if ( ( $saved['expires'] ?? 0 ) <= time() ) { expire_preview( get_current_user_id(), $saved['token_hash'] ); return error( 'expired', 'This preview expired. Prepare a new preview.', 410 ); }
    if ( $saved['site'] !== \ChurchWebsiteConnector\current_identity() ) { return error( 'site', 'This preview belongs to another website configuration.', 403 ); }
    return $saved;
}

function audit( array $saved, string $result ): void {
	$rows = (array) get_option( AUDIT, array() );
	array_unshift(
		$rows,
		array(
			'time_utc'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'user_id'   => get_current_user_id(),
			'kind'      => $saved['kind'],
			'id'        => $saved['id'],
			'fields'    => array_keys( $saved['changes'] ),
			'operation' => $saved['operation'] ?? 'update',
			'result'    => $result,
		)
	);
	update_option( AUDIT, array_slice( $rows, 0, 50 ), false );
}

function lock_item( string $kind, int $id ): array|\WP_Error {
	$lock_name = LOCK_PREFIX . $kind . '_' . $id;
	$lock      = wp_json_encode(
		array(
			'expires' => time() + 120,
			'token'   => wp_generate_uuid4(),
		)
	);
	if ( ! add_option( $lock_name, $lock, '', false ) ) {
		$old     = get_option( $lock_name, '' );
		$decoded = json_decode( (string) $old, true );
		if ( ! is_array( $decoded ) || ( $decoded['expires'] ?? PHP_INT_MAX ) >= time()
			|| ! conditional_delete( $lock_name, $old ) || ! add_option( $lock_name, $lock, '', false ) ) {
			return error( 'busy', 'Another connector update is running for this item.', 409 );
		}
	}
	return array( $lock_name, $lock );
}

function apply( $input ) {
	$checked = check_input( $input, array( 'site_url', 'preview_token', 'approved' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	if ( ( $input['approved'] ?? false ) !== true ) {
		return error( 'approval', 'Apply only after approval, with approved=true.' );
	}
	$saved = load_preview( $input['preview_token'] ?? null );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	if ( isset( $saved['operation'] ) ) {
		return error( 'token', 'Use the apply tool for the operation named in the preview.', 409 );
	}
	$lock = lock_item( $saved['kind'], $saved['id'] );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$current = read_item( $saved['kind'], $saved['id'] );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! hash_equals( $saved['state_hash'], $current['state_hash'] ) ) {
			return error( 'stale', 'The item changed after preview. Prepare a new preview.', 409 );
		}
		$changes = normalise_changes( $current, $saved['changes'] );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		if ( $changes !== $saved['changes'] ) {
			return error( 'stale', 'WordPress would now normalise the changes differently. Prepare a new preview.', 409 );
		}
		if ( ! consume_preview( $saved ) ) {
			return error( 'token', 'The preview was replaced, used or discarded. Prepare a new preview.', 409 );
		}
		$result = dispatch( 'POST', route( $saved['kind'], $saved['id'] ), $changes );
		if ( is_wp_error( $result ) ) {
			audit( $saved, 'error' );
			$data = $result->get_error_data();
			return error( 'update_failed', $result->get_error_message() . ' Some fields may already have changed. Inspect the item before preparing another preview.', is_array( $data ) ? (int) ( $data['status'] ?? 500 ) : 500 );
		}
		$after = read_item( $saved['kind'], $saved['id'] );
		audit( $saved, is_wp_error( $after ) ? 'verification_unavailable' : 'updated' );
		return array(
			'site_url'       => \ChurchWebsiteConnector\bound_site(),
			'updated'        => true,
			'kind'           => $saved['kind'],
			'id'             => $saved['id'],
			'changed_fields' => array_keys( $changes ),
			'item'           => is_wp_error( $after ) ? null : $after,
			'message'        => 'WordPress processed the update. Review returned stored values; native filters may normalise them. The preview token has been consumed.',
		);
	} catch ( \Throwable $e ) {
		audit( $saved, 'error' );
		return error( 'failed', 'The operation did not complete cleanly; some fields may already have changed. Inspect the item before retrying.', 500 );
	} finally {
		conditional_delete( $lock[0], $lock[1] );
	}
}

function discard( $input ) {
	$checked = check_input( $input, array( 'site_url', 'preview_token' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$saved = load_preview( $input['preview_token'] ?? null );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	if ( ! consume_preview( $saved ) ) {
		return error( 'token', 'The preview was already replaced or consumed.', 409 );
	}
	return array( 'discarded' => true );
}

function register_abilities(): void {
    if ( ! function_exists( 'wp_register_ability' ) || ! \ChurchWebsiteConnector\feature_enabled( 'rest_tools' ) ) { return; }
    $site = [ 'site_url' => [ 'type' => 'string', 'enum' => [ \ChurchWebsiteConnector\bound_site() ] ] ];
    $target = $site + [ 'kind' => [ 'type' => 'string', 'enum' => [ 'post', 'page', 'media' ] ], 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ];
    $properties = [];
    foreach ( [ 'title', 'excerpt', 'slug', 'alt_text', 'caption', 'description' ] as $field ) { $properties[ $field ] = [ 'type' => 'string', 'maxLength' => in_array( $field, [ 'title', 'slug', 'alt_text' ], true ) ? 2000 : 10000 ]; }
    foreach ( [ 'featured_media', 'parent' ] as $field ) { $properties[ $field ] = [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647 ]; }
    $properties['menu_order'] = [ 'type' => 'integer', 'minimum' => -2147483647, 'maximum' => 2147483647 ];
    foreach ( [ 'categories', 'tags' ] as $field ) { $properties[ $field ] = [ 'type' => 'array', 'maxItems' => 50, 'items' => [ 'type' => 'integer', 'minimum' => 1 ] ]; }
    foreach ( [ 'comment_status', 'ping_status' ] as $field ) { $properties[ $field ] = [ 'type' => 'string', 'enum' => [ 'open', 'closed' ] ]; }
    $token = [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ];
    $definitions = [
        'rest-capabilities' => [ 'REST-backed tool capabilities', 'Lists the fixed page/post and media metadata operations supported behind existing MCP sign-in. Read-only; not a general REST proxy.', 'capabilities', $site, [ 'site_url' ], true ],
        'get-item-details' => [ 'Read page, post or media details', 'Reads supported item metadata and a state_hash through core REST edit-context permissions. Does not return raw block content, passwords or arbitrary meta.', 'get_item', $target, [ 'site_url', 'kind', 'id' ], true ],
        'preview-item-update' => [ 'Preview item metadata update', 'Stages a metadata-only update without changing the item. Post/page fields include title, excerpt, slug, featured_media and taxonomy/hierarchy details; media supports title, alt_text, caption and description. Use latest state_hash. Text is plain text. Returns a 15-minute owner-bound token; a new preview replaces the previous one. Use GeneratePress for block content.', 'preview', $target + [ 'state_hash' => $token, 'changes' => [ 'type' => 'object', 'properties' => $properties, 'minProperties' => 1, 'maxProperties' => 10, 'additionalProperties' => false ] ], [ 'site_url', 'kind', 'id', 'state_hash', 'changes' ], false ],
        'apply-item-update' => [ 'Apply approved item metadata update', 'WRITE: applies one retained metadata preview only after approval. Rechecks user, exact website, expiry, latest item state and native REST permissions. approved=true acknowledges approval; it is not authentication or proof of human consent. No block content or publication-status changes.', 'apply', $site + [ 'preview_token' => $token, 'approved' => [ 'type' => 'boolean', 'const' => true ] ], [ 'site_url', 'preview_token', 'approved' ], false ],
        'discard-item-update' => [ 'Discard item metadata preview', 'Discards only the current account’s preview without changing the item.', 'discard', $site + [ 'preview_token' => $token ], [ 'site_url', 'preview_token' ], false ],
    ];
    foreach ( $definitions as $name => [ $label, $description, $callback, $input, $required, $readonly ] ) {
        wp_register_ability( 'church-connector/' . $name, [ 'label' => $label, 'description' => $description, 'category' => 'church-connector',
            'input_schema' => [ 'type' => 'object', 'properties' => $input, 'required' => $required, 'additionalProperties' => false ],
            'permission_callback' => __NAMESPACE__ . '\\permission', 'execute_callback' => __NAMESPACE__ . '\\' . $callback,
            'meta' => [ 'show_in_rest' => false, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readonly' => $readonly, 'destructive' => $name === 'apply-item-update', 'idempotent' => $readonly ] ],
        ] );
    }
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities' );
add_action( EXPIRE_HOOK, __NAMESPACE__ . '\\expire_preview', 10, 2 );
