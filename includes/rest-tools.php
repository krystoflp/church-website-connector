<?php
declare(strict_types=1);

namespace ChurchWebsiteConnector\RestTools;

defined( 'ABSPATH' ) || exit;

const PREVIEW_PREFIX = 'church_connector_rest_preview_';
const LOCK_PREFIX = 'church_connector_rest_lock_';
const AUDIT = 'church_connector_rest_audit';
const EXPIRE_HOOK = 'church_connector_expire_rest_preview';
const TTL = 900;

/** Recovery instructions also reach assistants whose MCP host only displays error text. */
function workflow_error( string $prefix, string $code, string $message, int $status ): \WP_Error {
	$data = array( 'status' => $status );
	if ( 'stale' === $code || 'expired' === $code ) {
		$data['recovery'] = 'read_and_preview_again';
		$message         .= ' Use the current review if supplied. Otherwise read the same item and prepare one new preview from the original request. Compare the old and new reviews, clarify any ambiguous target, and obtain fresh approval before applying.';
	}
	return new \WP_Error( $prefix . $code, $message, $data );
}

function error( string $code, string $message, int $status = 400 ): \WP_Error {
	return workflow_error( 'church_connector_rest_', $code, $message, $status );
}

/**
 * Keep private review details out of WP_Error, which MCP adapters may log verbatim.
 * The weak reference exists only while this request still holds the original error.
 */
function private_reviews(): \WeakMap {
	static $reviews;
	return $reviews ??= new \WeakMap();
}

function review_error( string $code, string $generic_message, string $private_message, array $details ): \WP_Error {
	$error = new \WP_Error( $code, $generic_message, array( 'status' => $details['status'] ) );

	private_reviews()[ $error ] = array(
		'owner'   => get_current_user_id(),
		'site'    => \ChurchWebsiteConnector\current_identity(),
		'message' => $private_message,
		'data'    => $details,
	);
	return $error;
}

/** Only the signed-in caller on the same bound installation can receive its private review. */
function review_response( $result ): ?array {
	if ( ! is_wp_error( $result ) ) {
		return null;
	}
	$review = private_reviews()[ $result ] ?? null;
	if ( ! $review || ! $review['owner'] || get_current_user_id() !== $review['owner']
		|| ! \ChurchWebsiteConnector\is_bound() || \ChurchWebsiteConnector\current_identity() !== $review['site'] ) {
		return null;
	}
	return array(
		'code'    => $result->get_error_code(),
		'message' => $review['message'],
		'data'    => $review['data'],
	);
}

function review_details( \WP_Error $error ): array {
	return review_response( $error )['data'] ?? (array) $error->get_error_data();
}

/** Adapter-supported failure result: deliver the review to the client without error logging. */
function mcp_review_result( $result, $args, $tool_name ) {
	if ( ! is_string( $tool_name ) || ! str_starts_with( $tool_name, 'church-connector-' ) ) {
		return $result;
	}
	$review  = review_response( $result );
	$encoded = $review ? wp_json_encode( $review ) : false;
	return is_string( $encoded ) ? array(
		'success' => false,
		'error'   => $encoded,
	) : $result;
}

/** Preserve the structured HTTP error for authenticated connector REST clients. */
function rest_review_result( $result, $handler, $request ) {
	if ( ! str_starts_with( $request->get_route(), '/church-connector/v1/' ) ) {
		return $result;
	}
	$review = review_response( $result );
	return $review ? new \WP_REST_Response( $review, $review['data']['status'] ) : $result;
}

function refreshed_error( \WP_Error $error, $next, array $saved, string $prefix = PREVIEW_PREFIX, string $hook = EXPIRE_HOOK ): \WP_Error {
	$data = $error->get_error_data();
	if ( is_wp_error( $next ) ) {
		retire_preview( $saved, $prefix, $hook );
		$private              = review_response( $next );
		$data['recovery']     = 'resolve_review_issue';
		$data['review_issue'] = array(
			'code'    => $next->get_error_code(),
			'message' => $private['message'] ?? $next->get_error_message(),
			'details' => $private['data'] ?? $next->get_error_data(),
		);

		$message = 'The earlier preview is stale. ' . $data['review_issue']['message'];
	} else {
		$data['refreshed_preview'] = $next;
		$data['recovery']          = 'review_new_preview';
		$message                   = 'The earlier preview is stale. Compare the fresh preview with the earlier review and obtain approval for the new token.';
	}
	$fallback = 'The earlier preview is stale. Read the same item and prepare a new preview from the original request before asking for fresh approval.';
	return review_error( $error->get_error_code(), $fallback, $message, $data );
}

/** Replace precisely the reviewed preview, even when another tab is preparing a change. */
function replace_saved_preview( string $name, array $previous, array $replacement ): bool {
	global $wpdb;
	$changed = $wpdb->update(
		$wpdb->options,
		array( 'option_value' => maybe_serialize( $replacement ) ),
		array(
			'option_name'  => $name,
			'option_value' => maybe_serialize( $previous ),
		)
	);
	wp_cache_delete( $name, 'options' );
	return 1 === $changed;
}

function retire_preview( array $saved, string $prefix, string $hook ): bool {
	$owner = get_current_user_id();
	if ( ! conditional_delete( $prefix . $owner, $saved ) ) {
		return false;
	}
	wp_clear_scheduled_hook( $hook, array( $owner, $saved['token_hash'] ) );
	return true;
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

function read_item( string $kind, int $id, bool $for_publication_workflow = false ) {
    // Check before dispatch too, including when callbacks are invoked directly.
    if ( ! current_user_can( 'edit_post', $id ) ) {
        return error( 'forbidden', 'Your WordPress account cannot edit this item.', 403 );
    }
	$query = array( 'context' => 'edit' );
	if ( $for_publication_workflow ) {
		// Core renders shortcodes for content and runs excerpt filters even for excerpt.raw.
		$query['_fields'] = implode( ',', array_merge( array( 'id', 'title.raw' ), array_diff( fields( $kind ), array( 'title', 'excerpt' ) ) ) );
	}
	$data = dispatch( 'GET', route( $kind, $id ), $query );
    if ( is_wp_error( $data ) ) { return $data; }
    $post = get_post( $id );
    $expected_type = $kind === 'media' ? 'attachment' : $kind;
    if ( ! $post || $post->post_type !== $expected_type || ( $data['id'] ?? null ) !== $id || $post->post_status === 'trash' ) {
        return error( 'target', 'The item does not match the requested kind or is in the trash.', 404 );
    }
	if ( $for_publication_workflow && post_type_supports( $kind, 'excerpt' ) ) {
		$data['excerpt'] = $post->post_excerpt;
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
	$snapshot['state_hash'] = hash( 'sha256', wp_json_encode( array( $snapshot, metadata_columns( $post ), publication_event( $post ) ) ) );
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
	if ( 'future' === $item['status'] && 'media' !== $item['kind'] ) {
		$date = get_post_datetime( $item['id'], 'date', 'gmt' );
		if ( ! $date || $date->getTimestamp() < time() + MINUTE_IN_SECONDS ) {
			$message = $date ? 'This item is scheduled for ' . gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ) . ' UTC and is due or less than a minute from publication.' : 'The publication time could not be read.';
			return error( 'metadata_status', $message . ' Check its schedule before editing its metadata.', 409 );
		}
		if ( publication_event( get_post( $item['id'] ) ) !== $date->getTimestamp() ) {
			return error( 'metadata_status', 'The publication event is missing or differs from the saved date. Review the schedule before editing metadata.', 409 );
		}
	}
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
	if ( isset( $clean['parent'] ) && $clean['parent'] !== $item['fields']['parent'] && ! empty( $item['fields']['slug'] ) ) {
		$clean['slug'] ??= $item['fields']['slug'];
	}
	if ( isset( $clean['slug'] ) ) {
		$status        = in_array( $item['status'], array( 'draft', 'pending' ), true ) ? 'publish' : $item['status'];
		$clean['slug'] = wp_unique_post_slug( $clean['slug'], $item['id'], $status, $item['kind'], $clean['parent'] ?? ( $item['fields']['parent'] ?? 0 ) );
	}
    return $clean;
}

/** Use the actual cron event. A future status alone does not schedule publication. */
function publication_event( ?\WP_Post $post ): int|false {
	return $post && 'future' === $post->post_status ? wp_next_scheduled( 'publish_future_post', array( $post->ID ) ) : false;
}

/** Validate a future URL against the actual page rewrite before the new hierarchy exists in the database. */
function projected_page_route( string $url, string $uri, int $id ): bool {
	global $wp_rewrite;
	if ( ! str_starts_with( $url, home_url( '/' ) ) ) {
		return false;
	}
	$existing = url_to_postid( $url );
	if ( $existing && $existing !== $id ) {
		return false;
	}
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	$by_id = $query['page_id'] ?? $query['p'] ?? null;
	if ( $by_id === (string) $id || untrailingslashit( $url ) === untrailingslashit( home_url() ) ) {
		return $existing === $id;
	}
	$path = trim( substr( $url, strlen( home_url( '/' ) ) ), '/' );
	if ( ! $wp_rewrite->using_permalinks() || '' === $path || str_contains( $path, '?' ) ) {
		return false;
	}
	foreach ( $wp_rewrite->wp_rewrite_rules() as $rule => $replacement ) {
		if ( ! preg_match( '#^' . $rule . '#', $path, $matches ) && ! preg_match( '#^' . $rule . '#', urldecode( $path ), $matches ) ) {
			continue;
		}
		parse_str( \WP_MatchesMapRegex::apply( (string) wp_parse_url( $replacement, PHP_URL_QUERY ), $matches ), $vars );
		return isset( $vars['pagename'] ) && rawurldecode( $uri ) === rawurldecode( $vars['pagename'] ) && empty( array_filter( array_diff_key( $vars, array( 'pagename' => true ) ) ) );
	}
	return false;
}

/** A public page and its public descendants need a routable, published ancestor chain. */
function public_page_ancestry( \WP_Post $leaf, \WP_Post $proposed ): bool {
	$seen = array();
	while ( $leaf ) {
		if ( $leaf->ID === $proposed->ID ) {
			$leaf = $proposed;
		}
		if ( isset( $seen[ $leaf->ID ] ) || 'page' !== $leaf->post_type || 'publish' !== $leaf->post_status || '' === $leaf->post_name ) {
			return false;
		}
		if ( ! $leaf->post_parent ) {
			return true;
		}
		$seen[ $leaf->ID ] = true;
		wp_cache_delete( $leaf->post_parent, 'posts' );
		$leaf = get_post( $leaf->post_parent );
	}
	return false;
}

/** Project native page links, including child links that inherit their ancestor's path. */
function page_links( array $item, array $changes ): array|\WP_Error {
	if ( 'page' !== $item['kind'] || ( ! isset( $changes['parent'] ) && ! isset( $changes['slug'] ) ) ) {
		return array();
	}
	$page                  = get_post( $item['id'] );
	$proposed              = clone $page;
	$proposed->post_parent = $changes['parent'] ?? $page->post_parent;
	$proposed->post_name   = $changes['slug'] ?? $page->post_name;
	$links                 = array(
		array(
			'id'     => $page->ID,
			'before' => get_permalink( $page ),
			'after'  => get_permalink( $proposed ),
		),
	);

	$route_error = error( 'hierarchy', 'The proposed page hierarchy does not have a verified public route. Choose a published parent with a usable path, or review the move in WordPress.', 409 );
	if ( $proposed->post_parent && 'trash' === get_post_status( $proposed->post_parent ) ) {
		return $route_error;
	}
	if ( 'publish' === $page->post_status && ( ! public_page_ancestry( $proposed, $proposed ) || ! projected_page_route( $links[0]['after'], get_page_uri( $proposed ), $page->ID ) ) ) {
		return $route_error;
	}
	if ( ! get_option( 'permalink_structure' ) || ( $page->post_parent === $proposed->post_parent && $page->post_name === $proposed->post_name ) ) {
		return $links;
	}

	// Substitute the proposed ancestor in core's raw hierarchy, before custom permalink filters.
	$descendants = array();
	$parents     = array( $page->ID );
	while ( $parents ) {
		$children = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array_values( get_post_stati() ),
				'post_parent__in'  => $parents,
				'posts_per_page'   => 101 - count( $descendants ),
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		if ( count( $descendants ) + count( $children ) > 100 || array_intersect( $children, array_merge( $descendants, array( $page->ID ) ) ) ) {
			return error( 'hierarchy', 'This page hierarchy exceeds the review limit or contains a loop. Review the move in the WordPress page editor.', 409 );
		}
		$descendants = array_merge( $descendants, $children );
		$parents     = $children;
	}
	$old_path = get_page_uri( $page );
	$new_path = get_page_uri( $proposed );
	foreach ( $descendants as $id ) {
		$before  = get_permalink( $id );
		$project = static function ( $uri, $post ) use ( $id, $old_path, $new_path ) {
			return $post->ID === $id && str_starts_with( $uri, $old_path . '/' ) ? $new_path . substr( $uri, strlen( $old_path ) ) : $uri;
		};
		add_filter( 'get_page_uri', $project, PHP_INT_MAX, 2 );
		try {
			$after = get_permalink( $id );
		} finally {
			remove_filter( 'get_page_uri', $project, PHP_INT_MAX );
		}
		$child = get_post( $id );
		if ( 'publish' === $child->post_status && ( ! public_page_ancestry( $child, $proposed ) || ! projected_page_route( $after, $project( get_page_uri( $child ), $child ), $id ) ) ) {
			return $route_error;
		}
		if ( $after !== $before ) {
			if ( ! current_user_can( 'read_post', $id ) ) {
				return error( 'hierarchy', 'A descendant affected by this move is unavailable to this account. Review the move in WordPress.', 403 );
			}
			$links[] = array(
				'id'     => $id,
				'before' => $before,
				'after'  => $after,
			);
		}
	}
	return $links;
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

function preview( $input, ?array $replaces = null ) {
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
	$reviewed_changes = array_intersect_key( $changes, $diff );
	$links            = page_links( $item, $reviewed_changes );
	if ( is_wp_error( $links ) ) {
		return $links;
	}
	$result = save_preview(
		array(
			'kind'       => $target['kind'],
			'id'         => $target['id'],
			'state_hash' => $item['state_hash'],
			'changes'    => $reviewed_changes,
			'request'    => $input['changes'],
			'links'      => $links,
		),
		$replaces
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return array(
		'site_url' => \ChurchWebsiteConnector\bound_site(),
		'kind'     => $target['kind'],
		'id'       => $target['id'],
		'diff'     => $diff,
		'permalink_changes' => $links,
		'message'  => 'Metadata preview only; the item is unchanged. Apply this owner-bound token after approval. A new preview replaces your previous one.',
	) + $result;
}

/**
 * Share the owner-bound preview slot across metadata and typed post operations.
 *
 * A successful replacement invalidates the previous token before scheduling cleanup.
 */
function save_preview( array $operation, ?array $replaces = null ): array|\WP_Error {
	return stage_review( $operation, $replaces, PREVIEW_PREFIX, EXPIRE_HOOK, TTL, 'church_connector_rest_' );
}

function stage_review( array $operation, ?array $replaces, string $prefix, string $hook, int $ttl, string $error_prefix ): array|\WP_Error {
	$token   = bin2hex( random_bytes( 32 ) );
	$user_id = get_current_user_id();
	$old     = get_option( $prefix . $user_id, false );
	$saved   = array(
		'token_hash' => hash( 'sha256', $token ),
		'owner'      => $user_id,
		'site'       => \ChurchWebsiteConnector\current_identity(),
		'site_hash'  => \ChurchWebsiteConnector\identity_fingerprint( \ChurchWebsiteConnector\current_identity() ),
		'expires'    => time() + $ttl,
	) + $operation;
	if ( null !== $replaces ) {
		if ( ! replace_saved_preview( $prefix . $user_id, $replaces, $saved ) ) {
			return workflow_error( $error_prefix, 'token', 'Another preview replaced this one. Review the active request.', 409 );
		}
		$old = $replaces;
	} elseif ( ! update_option( $prefix . $user_id, $saved, false ) ) {
		return workflow_error( $error_prefix, 'storage', 'The preview could not be saved. No item was changed.', 503 );
	}
	if ( is_array( $old ) && isset( $old['token_hash'] ) ) {
		wp_clear_scheduled_hook( $hook, array( $user_id, $old['token_hash'] ) );
	}
	wp_schedule_single_event( $saved['expires'], $hook, array( $user_id, $saved['token_hash'] ) );
	return array(
		'preview_token'  => $token,
		'expires_at_utc' => gmdate( 'c', $saved['expires'] ),
	);
}

function consume_preview( array $saved ): bool {
	return retire_preview( $saved, PREVIEW_PREFIX, EXPIRE_HOOK );
}

function preview_site_matches( array $saved ): bool {
	$identity = \ChurchWebsiteConnector\current_identity();
	return ( $saved['site'] ?? null ) === $identity && ( $saved['site_hash'] ?? null ) === \ChurchWebsiteConnector\identity_fingerprint( $identity );
}

function load_preview( mixed $token ) {
    if ( ! is_string( $token ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $token ) ) { return error( 'token', 'Use a valid preview token.' ); }
    $saved = get_option( stage_name( get_current_user_id() ), false );
    if ( ! is_array( $saved ) || ! hash_equals( (string) ( $saved['token_hash'] ?? '' ), hash( 'sha256', $token ) ) || ( $saved['owner'] ?? 0 ) !== get_current_user_id() ) { return error( 'token', 'This preview is unavailable for the signed-in account.', 410 ); }
    if ( ( $saved['expires'] ?? 0 ) <= time() ) { expire_preview( get_current_user_id(), $saved['token_hash'] ); return error( 'expired', 'This preview expired. Prepare a new preview.', 410 ); }
	if ( ! preview_site_matches( $saved ) ) {
		return error( 'site', 'This review belongs to an earlier or different website configuration. Prepare a fresh preview.', 403 );
	}
    return $saved;
}

/** Append to the retained history without overwriting another request's entry. */
function append_audit( string $option, array $row, int $limit = 50 ): bool {
	global $wpdb;
	for ( $attempt = 0; $attempt < 12; ++$attempt ) {
		$old  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$rows = null === $old ? array() : maybe_unserialize( $old );
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			return false;
		}
		array_unshift( $rows, $row );
		$value = maybe_serialize( array_slice( $rows, 0, $limit ) );
		if ( $old === $value ) {
			return true;
		}
		if ( null === $old ) {
			$query = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $value );
		} else {
			$query = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND HEX(option_value) = HEX(%s)", $value, $option, $old );
		}
		$written = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Both branches prepare the complete statement.
		if ( false === $written ) {
			return false;
		}
		if ( 1 === $written ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			return true;
		}
	}
	return false;
}

function audit( array $saved, string $result ): void {
	append_audit(
		AUDIT,
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
}

/** Atomically claim a new option. A concurrent holder must never be replaced. */
function insert_lock( string $name, string $value ): bool {
	global $wpdb;
	$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return 1 === $inserted;
}

function lock_item( string $kind, int $id ): array|\WP_Error {
	$lock_name = LOCK_PREFIX . $kind . '_' . $id;
	$lock      = wp_json_encode(
		array(
			'expires' => time() + 120,
			'token'   => wp_generate_uuid4(),
		)
	);
	if ( ! insert_lock( $lock_name, $lock ) ) {
		$old     = get_option( $lock_name, '' );
		$decoded = json_decode( (string) $old, true );
		if ( ! is_array( $decoded ) || ( $decoded['expires'] ?? PHP_INT_MAX ) >= time()
			|| ! conditional_delete( $lock_name, $old ) || ! insert_lock( $lock_name, $lock ) ) {
			return error( 'busy', 'Another connector update is running for this item.', 409 );
		}
	}
	return array( $lock_name, $lock );
}

/** Columns a metadata update must preserve, except for explicitly reviewed fields. */
function metadata_columns( \WP_Post $post, array $changes = array() ): array {
	$columns = array(
		'post_author',
		'post_status',
		'post_type',
		'post_date',
		'post_date_gmt',
		'post_password',
		'post_content',
		'post_content_filtered',
		'post_title',
		'post_excerpt',
		'post_name',
		'post_parent',
		'menu_order',
		'comment_status',
		'ping_status',
		'post_mime_type',
	);
	$map     = array(
		'title'          => 'post_title',
		'excerpt'        => 'post_excerpt',
		'caption'        => 'post_excerpt',
		'description'    => 'post_content',
		'parent'         => 'post_parent',
		'menu_order'     => 'menu_order',
		'slug'           => 'post_name',
		'comment_status' => 'comment_status',
		'ping_status'    => 'ping_status',
	);
	$data    = array();
	foreach ( $columns as $column ) {
		$data[ $column ] = (string) $post->$column;
	}
	foreach ( array_intersect_key( $changes, $map ) as $key => $value ) {
		$data[ $map[ $key ] ] = (string) $value;
	}
	return $data;
}

/** Match wpdb's prepared write by caller and row, even with a database drop-in. */
function is_post_update_query( int $id ): bool {
	global $wpdb;
	$in_query = false;
	foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $call ) {
		$database_call = ( $call['object'] ?? null ) === $wpdb;
		if ( $database_call && 'query' === $call['function'] ) {
			$in_query = true;
		} elseif ( $in_query ) {
			if ( ! $database_call || 'update' !== $call['function'] || ( $call['args'][0] ?? null ) !== $wpdb->posts ) {
				return false;
			}
			$where_id = $call['args'][2]['ID'] ?? null;
			return (int) ( $where_id['value'] ?? $where_id ) === $id;
		}
	}
	return false;
}

function veto_post_update( string $sql, int $id ): string {
	return is_post_update_query( $id ) ? '' : $sql;
}

/** An independent save started by a WordPress callback owns its own write. */
function is_rest_metadata_write(): bool {
	$caller = false;
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
		if ( $caller ) {
			return 'update_item' === $frame['function'] && is_a( $frame['class'] ?? '', 'WP_REST_Posts_Controller', true );
		}
		$caller = 'wp_update_post' === $frame['function'];
	}
	return false;
}

/** Match the taxonomy and media values that WordPress stores outside wp_posts. */
function related_write_condition( int $id, string $kind ): string {
	global $wpdb;
	// The fixed native fields use these metadata keys. Include duplicates and missing rows.
	$key   = 'media' === $kind ? '_wp_attachment_image_alt' : '_thumbnail_id';
	$where = $wpdb->prepare( 'post_id = %d AND meta_key = %s', $id, $key );
	$meta  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id", $id, $key ), ARRAY_A );
	if ( ! is_array( $meta ) || $wpdb->last_error ) {
		return '';
	}
	$condition = ' AND (SELECT COUNT(*) FROM ' . $wpdb->postmeta . ' WHERE ' . $where . ') = ' . count( $meta );
	foreach ( $meta as $row ) {
		$condition .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE meta_id = %d AND HEX(meta_value) = HEX(%s))", $row['meta_id'], $row['meta_value'] );
	}
	if ( 'post' !== $kind ) {
		return $condition;
	}
	$terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id", $id ) );
	if ( ! is_array( $terms ) || $wpdb->last_error ) {
		return '';
	}
	$condition .= $wpdb->prepare( " AND (SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d) = %d", $id, count( $terms ) );
	if ( $terms ) {
		$ids        = implode( ',', array_map( 'intval', $terms ) );
		$condition .= $wpdb->prepare( " AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id NOT IN ($ids))", $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Every list member is a database ID converted to an integer.
	}
	return $condition;
}

/** Scope the final conflict check to core's update of this item. */
function save_metadata( array $item, array $changes, array $links, \WP_Post $baseline ) {
	$id       = $item['id'];
	$expected = metadata_columns( $baseline, $changes );
	$conflict = null;
	$guard    = static function ( $post_id, $data ) use ( $item, $changes, $links, $expected, $id, &$conflict ) {
		if ( $post_id !== $id || ! is_rest_metadata_write() ) {
			return;
		}
		wp_cache_delete( $id, 'posts' );
		$stored  = get_post( $id );
		$current = read_item( $item['kind'], $id );
		if ( ! $stored || $stored->post_status !== $item['status'] || $data['post_status'] !== $item['status']
			|| ( 'future' === $item['status'] && ( $expected['post_date_gmt'] !== $data['post_date_gmt'] || $expected['post_date'] !== $data['post_date'] || is_wp_error( normalise_changes( $item, $changes ) ) ) ) ) {
			$conflict = error( 'metadata_status', 'The publication status or schedule changed. Review the item before retrying.', 409 );
		} elseif ( is_wp_error( $current ) || $item['state_hash'] !== $current['state_hash'] || page_links( $current, $changes ) !== $links ) {
			$conflict = error( 'stale', 'The item or its permalink changed during the save.', 409 );
		} else {
			foreach ( array_intersect_key( $expected, $data ) as $column => $value ) {
				if ( (string) $data[ $column ] !== $value ) {
					$conflict = error( 'substituted', 'WordPress prepared values outside the approved edit. Read the item before retrying.', 409 );
					break;
				}
			}
		}
	};
	$related  = related_write_condition( $id, $item['kind'] );
	if ( '' === $related ) {
		return error( 'update_unverified', 'The current metadata could not be verified before saving.', 503 );
	}
	$veto = static function ( $sql ) use ( $id, $baseline, $item, $expected, $related, &$conflict ) {
		if ( ! str_starts_with( ltrim( $sql ), 'UPDATE ' ) || ! is_post_update_query( $id ) || ! is_rest_metadata_write() ) {
			return $sql;
		}
		if ( $conflict ) {
			return '';
		}
		global $wpdb;
		$conditions = array();
		foreach ( metadata_columns( $baseline ) as $column => $value ) {
			$conditions[] = $wpdb->prepare( 'HEX(CAST(%i AS CHAR)) = HEX(%s)', $column, $value );
		}
		// Core treats zero matching rows as success, then assigns terms and thumbnail.
		// Execute its exact UPDATE with the reviewed-state predicate and pass a failed
		// query back to core on conflict so subsequent REST side effects never start.
		// This nested query is not a wpdb::update() call, so the guard cannot recurse.
		$written = $wpdb->query( $sql . ' AND ' . implode( ' AND ', $conditions ) . $related ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The original wpdb UPDATE and all additional predicates are prepared.
		if ( 0 === $written && metadata_columns( $baseline ) === $expected ) {
			// MySQL reports zero for a genuine no-op row, e.g. a terms-only edit.
			clean_post_cache( $id );
			$now = read_item( $item['kind'], $id );
			if ( ! is_wp_error( $now ) && $now['state_hash'] === $item['state_hash'] && related_write_condition( $id, $item['kind'] ) === $related ) {
				return 'SELECT 1';
			}
		}
		if ( 1 !== $written ) {
			$conflict = error( 'write_conflict', 'The approved state could not be saved. Read the current item before retrying.', 409 );
			return '';
		}
		// The native row write has already succeeded. Let WordPress continue its normal hooks.
		return 'SELECT 1';
	};
	// Core otherwise resets the local creation date of an undated draft to the time of the metadata edit.
	$preserve_date = static function ( $prepared, $request ) use ( $id, $baseline ) {
		if ( (int) $request['id'] === $id && is_object( $prepared ) && '0000-00-00 00:00:00' === $baseline->post_date_gmt ) {
			$prepared->edit_date = true;
		}
		return $prepared;
	};
	// Native save_post callbacks run before REST assigns terms, the thumbnail or alt text.
	// Retire these pending assignments if an independent change landed in that interval.
	$guard_related = static function ( $post, $request ) use ( $id, $item, $related, &$conflict ) {
		if ( $post->ID !== $id || related_write_condition( $id, $item['kind'] ) === $related ) {
			return;
		}
		foreach ( array( 'categories', 'tags', 'featured_media', 'alt_text' ) as $field ) {
			unset( $request[ $field ] );
		}
		$conflict = error( 'partial_write', 'Related metadata changed during the save. The post row may already have changed. Read the stored item before preparing another edit.', 409 );
	};
	$insert_hook   = 'rest_insert_' . $baseline->post_type;
	add_action( $insert_hook, $guard_related, PHP_INT_MAX, 2 );
	$prepare_hook = 'rest_pre_insert_' . $baseline->post_type;
	add_filter( $prepare_hook, $preserve_date, PHP_INT_MAX, 2 );
	add_action( 'pre_post_update', $guard, PHP_INT_MAX, 2 );
	add_filter( 'query', $veto, PHP_INT_MIN );
	try {
		$result = dispatch( 'POST', route( $item['kind'], $id ), $changes );
		return $conflict ?? $result;
	} finally {
		remove_action( $insert_hook, $guard_related, PHP_INT_MAX );
		remove_filter( $prepare_hook, $preserve_date, PHP_INT_MAX );
		remove_action( 'pre_post_update', $guard, PHP_INT_MAX );
		remove_filter( 'query', $veto, PHP_INT_MIN );
	}
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
		$original = $saved['request'] ?? $saved['changes'];
		$changes  = normalise_changes( $current, $original );
		if ( ! is_wp_error( $changes ) ) {
			$changes = array_filter( $changes, static fn( $value, $key ) => $current['fields'][ $key ] !== $value, ARRAY_FILTER_USE_BOTH );
		}
		$links = is_wp_error( $changes ) ? array() : page_links( $current, $changes );
		if ( ! hash_equals( $saved['state_hash'], $current['state_hash'] ) || ( ! is_wp_error( $changes ) && ( $changes !== $saved['changes'] || ( $saved['links'] ?? array() ) !== $links ) ) ) {
			$request = array(
				'site_url'   => \ChurchWebsiteConnector\bound_site(),
				'kind'       => $saved['kind'],
				'id'         => $saved['id'],
				'changes'    => $original,
				'state_hash' => $current['state_hash'],
			);
			return refreshed_error( error( 'stale', 'The item changed after preview.', 409 ), preview( $request, $saved ), $saved );
		}
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		$baseline = get_post( $saved['id'] );
		if ( ! consume_preview( $saved ) ) {
			return error( 'token', 'The preview was replaced, used or discarded. Prepare a new preview.', 409 );
		}
		$result = save_metadata( $current, $changes, $links, $baseline );
		if ( is_wp_error( $result ) ) {
			audit( $saved, 'error' );
			if ( in_array( $result->get_error_code(), array( 'church_connector_rest_metadata_status', 'church_connector_rest_stale', 'church_connector_rest_substituted' ), true ) ) {
				return $result;
			}
			$data = $result->get_error_data();
			return error( 'update_failed', $result->get_error_message() . ' Some fields may already have changed. Inspect the item before preparing another preview.', is_array( $data ) ? (int) ( $data['status'] ?? 500 ) : 500 );
		}
		$after  = read_item( $saved['kind'], $saved['id'] );
		$stored = get_post( $saved['id'] );
		if ( is_wp_error( $after ) || ! $stored ) {
			audit( $saved, 'verification_unavailable' );
			return error( 'update_unverified', 'WordPress processed the request, but its stored result could not be read. Inspect the item before retrying.', 502 );
		}
		$matches = metadata_columns( $stored ) === metadata_columns( $baseline, $changes );
		if ( 'future' === $current['status'] ) {
			$date    = get_post_datetime( $stored, 'date', 'gmt' );
			$matches = $matches && $date && publication_event( $stored ) === $date->getTimestamp();
		}
		foreach ( array_replace( $current['fields'], $changes ) as $field => $expected ) {
			$actual = $after['fields'][ $field ] ?? null;
			if ( in_array( $field, array( 'categories', 'tags' ), true ) && is_array( $actual ) ) {
				sort( $actual );
				sort( $expected );
			}
			$matches = $matches && $actual === $expected;
		}
		foreach ( $links as $link ) {
			$matches = $matches && get_permalink( $link['id'] ) === $link['after'] && ( 'publish' !== get_post_status( $link['id'] ) || url_to_postid( $link['after'] ) === $link['id'] );
		}
		if ( ! $matches ) {
			audit( $saved, 'normalised' );
			return error( 'update_unverified', 'The saved item differs from the approved review. Some changes may have been saved. Read the item before retrying.', 409 );
		}
		audit( $saved, 'updated' );
		return array(
			'site_url'          => \ChurchWebsiteConnector\bound_site(),
			'updated'           => true,
			'kind'              => $saved['kind'],
			'id'                => $saved['id'],
			'changed_fields'    => array_keys( $changes ),
			'item'              => $after,
			'permalink_changes' => $links,
			'message'           => 'The stored metadata matches the approved review. The preview token has been consumed.',
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
add_filter( 'mcp_adapter_tool_call_result', __NAMESPACE__ . '\\mcp_review_result', PHP_INT_MAX, 3 );
add_filter( 'rest_request_after_callbacks', __NAMESPACE__ . '\\rest_review_result', PHP_INT_MAX, 3 );
add_action( EXPIRE_HOOK, __NAMESPACE__ . '\\expire_preview', 10, 2 );
