<?php
/** Reviewed edits to existing classic WordPress menus. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Navigation;

use ChurchWebsiteConnector as Connector;
use ChurchWebsiteConnector\PostTools;
use ChurchWebsiteConnector\RestTools;

defined( 'ABSPATH' ) || exit;

function permission( $input = null ): true|\WP_Error {
	$permission = PostTools\permission();
	if ( is_wp_error( $permission ) ) {
		return $permission;
	}
	return current_user_can( 'edit_theme_options' ) ? true : RestTools\error( 'menu_permission', 'Your account cannot manage classic navigation menus.', 403 );
}

/** The native menu view can strip markup, shorten descriptions and substitute a linked post's title. */
function stored_attributes( \WP_Post $item ): array {
	return array(
		'menu-item-title'       => $item->post_title,
		'menu-item-description' => $item->post_content,
		'menu-item-attr-title'  => $item->post_excerpt,
		'menu-item-target'      => (string) get_post_meta( $item->ID, '_menu_item_target', true ),
		'menu-item-xfn'         => (string) get_post_meta( $item->ID, '_menu_item_xfn', true ),
		'menu-item-classes'     => get_post_meta( $item->ID, '_menu_item_classes', true ),
	);
}

function snapshot( int $id ): array|\WP_Error {
	$menu = wp_get_nav_menu_object( $id );
	if ( ! $menu || $id !== $menu->term_id ) {
		return RestTools\error( 'menu', 'Choose an existing classic navigation menu by ID.', 404 );
	}
	// Read all assigned links, including broken targets hidden on the front end.
	$items = get_posts(
		array(
			'post_type'      => 'nav_menu_item',
			'post_status'    => 'publish',
			'posts_per_page' => 101,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'ID'         => 'ASC',
			),
			'tax_query'      => array(
				array(
					'taxonomy' => 'nav_menu',
					'field'    => 'term_taxonomy_id',
					'terms'    => $menu->term_taxonomy_id,
				),
			),
		)
	);
	if ( count( $items ) > 100 ) {
		return RestTools\error( 'menu_size', 'Use the WordPress menu editor for a menu with more than 100 links.', 409 );
	}

	$rows  = array();
	$guard = array();
	foreach ( $items as $item ) {
		$guard[]           = array( stored_attributes( $item ), $item->post_parent, $item->ping_status, $item->post_modified_gmt );
		$item              = wp_setup_nav_menu_item( $item );
		$rows[ $item->ID ] = array(
			'id'        => $item->ID,
			'label'     => $item->title,
			'url'       => $item->url,
			'parent_id' => (int) $item->menu_item_parent,
			'position'  => $item->menu_order,
			'type'      => $item->type,
			'object'    => $item->object,
			'object_id' => (int) $item->object_id,
			'valid'     => empty( $item->_invalid ),
		);
	}
	$locations = array_keys( array_map( 'intval', get_nav_menu_locations() ), $id, true );
	$review    = array(
		'id'        => $id,
		'name'      => $menu->name,
		'locations' => $locations,
		'items'     => array_values( $rows ),
	);
	return $review + array( 'state_hash' => hash( 'sha256', wp_json_encode( array( $review, $guard ) ) ) );
}

function get_menu( $input ): array|\WP_Error {
	$checked = PostTools\check( $input, array( 'id' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$checked = permission();
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$menu = snapshot( $input['id'] );
	return is_wp_error( $menu ) ? $menu : array(
		'site_url' => Connector\bound_site(),
		'menu'     => $menu,
	);
}

function destination( array $target ): array|\WP_Error {
	if ( array( 'url' ) === array_keys( $target ) && is_string( $target['url'] ) && strlen( $target['url'] ) <= 2048 ) {
		$url   = sanitize_url( $target['url'], array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( $url === $target['url'] && ! empty( $parts['host'] ) && in_array( $parts['scheme'] ?? null, array( 'http', 'https' ), true )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! preg_match( '/[[:space:]\\\\]/', $url ) ) {
			return array(
				'type'      => 'custom',
				'object'    => 'custom',
				'object_id' => 0,
				'url'       => $url,
			);
		}
	}
	if ( count( $target ) === 2 && in_array( $target['kind'] ?? null, array( 'post', 'page' ), true ) && is_int( $target['id'] ?? null ) && $target['id'] > 0 ) {
		$post = get_post( $target['id'] );
		if ( $post && $post->post_type === $target['kind'] && 'publish' === $post->post_status && '' === $post->post_password ) {
			return array(
				'type'      => 'post_type',
				'object'    => $post->post_type,
				'object_id' => $post->ID,
				'url'       => get_permalink( $post ),
			);
		}
	}
	return RestTools\error( 'menu_destination', 'Choose an absolute HTTP(S) link or a published post or page on this site.' );
}

function flatten( array $groups, int $parent_id, array &$ordered ): void {
	foreach ( $groups[ $parent_id ] ?? array() as $item ) {
		$ordered[] = $item;
		flatten( $groups, $item, $ordered );
	}
}

function plan( array $menu, array $input ): array|\WP_Error {
	$changes = $input['changes'] ?? null;
	$id      = $input['item_id'] ?? null;
	$rows    = array_column( $menu['items'], null, 'id' );
	if ( ! is_int( $id ) || $id < 0 || ( $id && ! isset( $rows[ $id ] ) ) || ! is_array( $changes ) || ! $changes
		|| array_diff( array_keys( $changes ), array( 'label', 'destination', 'placement' ) ) ) {
		return RestTools\error( 'menu_item', 'Choose an exact item in this menu, or use item_id 0 to add a link, and specify its changes.' );
	}
	if ( ! $id && ( ! isset( $changes['label'], $changes['destination'], $changes['placement'] ) || count( $rows ) >= 100 ) ) {
		return RestTools\error( 'menu_item', 'A new link needs a label, destination and placement, in a menu with room for another item.' );
	}
	$row  = $rows[ $id ] ?? array(
		'id'    => 0,
		'valid' => true,
	);
	$body = array();
	if ( array_key_exists( 'label', $changes ) ) {
		$label = $changes['label'];
		if ( ! is_string( $label ) || strlen( $label ) > 180 || '' === $label || sanitize_text_field( $label ) !== $label ) {
			return RestTools\error( 'menu_label', 'Provide a plain text menu label up to 180 bytes.' );
		}
		$row['label']  = $label;
		$body['title'] = $label;
	}
	if ( array_key_exists( 'destination', $changes ) ) {
		$target = is_array( $changes['destination'] ) ? destination( $changes['destination'] ) : RestTools\error( 'menu_destination', 'Specify a link destination.' );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$row          = $target + $row;
		$row['valid'] = true;
		$body        += $target;
	}
	$positions = array();
	if ( array_key_exists( 'placement', $changes ) ) {
		$place  = $changes['placement'];
		$parent = $place['parent_id'] ?? null;
		$after  = $place['after_item_id'] ?? null;
		if ( ! is_array( $place ) || count( $place ) !== 2 || ! is_int( $parent ) || $parent < 0 || ! is_int( $after ) || $after < 0
			|| ( $parent && ( $parent === $id || ! isset( $rows[ $parent ] ) ) )
			|| ( $after && ( $after === $id || ! isset( $rows[ $after ] ) || $rows[ $after ]['parent_id'] !== $parent ) ) ) {
			return RestTools\error( 'menu_placement', 'Choose a parent in this menu and the preceding sibling, or zero for the top level or first sibling.' );
		}
		$groups = array();
		foreach ( $rows as $item ) {
			if ( $item['id'] !== $id ) {
				$groups[ $item['parent_id'] ][] = $item['id'];
			}
		}
		$siblings = $groups[ $parent ] ?? array();
		array_splice( $siblings, $after ? array_search( $after, $siblings, true ) + 1 : 0, 0, array( $id ) );
		$groups[ $parent ] = $siblings;
		$order             = array();
		// Zero is the new-link placeholder as well as the root parent.
		if ( ! $id ) {
			$groups = array_map( static fn( $ids ) => array_map( static fn( $value ) => 0 === $value ? -1 : $value, $ids ), $groups );
		}
		flatten( $groups, 0, $order );
		if ( count( $order ) !== count( $rows ) + ( $id ? 0 : 1 ) ) {
			return RestTools\error( 'menu_placement', 'This placement would create a cycle or the menu has an invalid hierarchy.', 409 );
		}
		$row['parent_id'] = $parent;
		$body['parent']   = $parent;
		$rows[ $id ]      = $row;
		$sorted           = array();
		foreach ( $order as $index => $key ) {
			$key                        = max( 0, $key );
			$sorted[ $key ]             = $rows[ $key ];
			$sorted[ $key ]['position'] = $index + 1;
			$positions[ $key ]          = $index + 1;
		}
		$rows               = $sorted;
		$body['menu_order'] = $positions[ $id ];
	} else {
		$rows[ $id ] = $row;
	}
	if ( $id && array_values( $rows ) === $menu['items'] ) {
		return RestTools\error( 'unchanged', 'The selected menu link already has these values.', 409 );
	}
	return array(
		'body'      => $body,
		'positions' => $positions,
		'after'     => array_values( $rows ),
	);
}

function preview( $input, ?array $replaces = null ): array|\WP_Error {
	$checked = PostTools\check( $input, array( 'id', 'state_hash', 'item_id', 'changes' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$state = get_menu( array_intersect_key( $input, array_flip( array( 'site_url', 'id' ) ) ) );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	$menu = $state['menu'];
	if ( ! is_string( $input['state_hash'] ?? null ) || ! hash_equals( $menu['state_hash'], $input['state_hash'] ) ) {
		return RestTools\error( 'stale', 'The menu changed. Read it again before preparing a preview.', 409 );
	}
	$parameters = array_intersect_key( $input, array_flip( array( 'item_id', 'changes' ) ) );
	$plan       = plan( $menu, $parameters );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	$saved = RestTools\save_preview(
		array(
			'operation'  => 'edit_menu',
			'kind'       => 'menu',
			'id'         => $menu['id'],
			'state_hash' => $menu['state_hash'],
			'changes'    => $parameters,
			'review'     => hash( 'sha256', wp_json_encode( $plan ) ),
		),
		$replaces
	);
	return is_wp_error( $saved ) ? $saved : $state + array(
		'proposed_items' => $plan['after'],
		'message'        => 'Preview only. Review the exact menu and proposed items before confirming.',
	) + $saved;
}

function apply( $input ): array|\WP_Error {
	$checked = PostTools\check( $input, array( 'preview_token', 'confirm_menu_edit' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$checked = permission();
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	if ( true !== ( $input['confirm_menu_edit'] ?? null ) ) {
		return RestTools\error( 'approval', 'Confirm the reviewed menu change explicitly.' );
	}
	$saved = RestTools\load_preview( $input['preview_token'] ?? null );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	if ( 'edit_menu' !== ( $saved['operation'] ?? null ) ) {
		return RestTools\error( 'token', 'Use a preview from the menu editor.', 409 );
	}
	$lock = RestTools\lock_item( 'menu', $saved['id'] );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$menu = snapshot( $saved['id'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$plan  = plan( $menu, $saved['changes'] );
		$stale = ! hash_equals( $saved['state_hash'], $menu['state_hash'] );
		if ( $stale || ( ! is_wp_error( $plan ) && ! hash_equals( $saved['review'], hash( 'sha256', wp_json_encode( $plan ) ) ) ) ) {
			$request = array(
				'site_url'   => Connector\bound_site(),
				'id'         => $saved['id'],
				'state_hash' => $menu['state_hash'],
			) + $saved['changes'];
			$error   = RestTools\error( 'stale', 'The reviewed item or its proposed effect changed.', 409 );
			return RestTools\refreshed_error( $error, preview( $request, $saved ), $saved );
		}
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! RestTools\consume_preview( $saved ) ) {
			return RestTools\error( 'token', 'The preview was replaced or already claimed.', 409 );
		}
		$result = commit( $menu, $saved['changes']['item_id'], $plan );
		RestTools\audit( $saved, is_wp_error( $result ) ? 'error' : 'verified' );
		return $result;
	} finally {
		RestTools\conditional_delete( $lock[0], $lock[1] );
	}
}

/** Include the saved item and fresh state if WordPress stopped after the first write. */
function partially_saved( int $menu_id, int $saved_id, bool $created, array $moves, $current = null ): \WP_Error {
	$current = $current ?? snapshot( $menu_id );
	$message = sprintf( 'WordPress saved menu item %d, but could not verify the full change. Read menu %d before another edit.', $saved_id, $menu_id );
	return RestTools\review_error(
		'church_connector_rest_menu_partial',
		$message,
		$message,
		array(
			'status'          => 409,
			'item_id'         => $saved_id,
			'created_item_id' => $created ? $saved_id : null,
			'completed_moves' => $moves,
			'menu'            => is_wp_error( $current ) ? null : $current,
		)
	);
}

function commit( array $menu, int $item_id, array $plan ): array|\WP_Error {
	$before = array_column( $menu['items'], null, 'id' );
	$error  = RestTools\error( 'menu_save', 'WordPress could not verify the menu change. Read the menu before retrying.', 409 );
	$item   = $item_id ? get_post( $item_id ) : null;
	if ( $item_id && ! $item ) {
		return $error;
	}
	$keep = $item ? stored_attributes( $item ) : array();
	if ( isset( $plan['body']['title'] ) || isset( $plan['body']['type'] ) ) {
		unset( $keep['menu-item-title'] );
	}
	$columns = array();
	if ( $item ) {
		$columns = array(
			'post_content' => $item->post_content,
			'post_excerpt' => $item->post_excerpt,
			'ping_status'  => $item->ping_status,
		);
		if ( isset( $keep['menu-item-title'] ) ) {
			$columns['post_title'] = $item->post_title;
		}
		if ( ! isset( $plan['body']['menu_order'] ) ) {
			$columns['menu_order'] = $item->menu_order;
		}
		if ( ! isset( $plan['body']['type'] ) ) {
			$columns['post_parent'] = $item->post_parent;
		}
	}
	// Keep the native REST capability, validation, sanitisation and save hooks.
	$request = new \WP_REST_Request( 'POST', '/wp/v2/menu-items' . ( $item_id ? '/' . $item_id : '' ) );
	$request->set_body_params( $plan['body'] + ( $item_id ? array() : array( 'menus' => $menu['id'] ) ) );
	$protected_meta = array();
	$preserve_post  = false;
	$verify         = static function ( $prepared, $incoming ) use ( $request, $menu, $item_id, $plan, $keep, &$protected_meta, &$preserve_post ) {
		if ( $incoming !== $request || is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$current = snapshot( $menu['id'] );
		if ( is_wp_error( $current ) || ! hash_equals( $menu['state_hash'], $current['state_hash'] ) ) {
			return RestTools\error( 'stale', 'The menu changed just before saving.', 409 );
		}
		$fields = array(
			'title'      => 'menu-item-title',
			'url'        => 'menu-item-url',
			'parent'     => 'menu-item-parent-id',
			'menu_order' => 'menu-item-position',
			'object_id'  => 'menu-item-object-id',
			'type'       => 'menu-item-type',
			'object'     => 'menu-item-object',
		);
		if ( ! is_object( $prepared ) || (int) ( $prepared->{'menu-id'} ?? 0 ) !== $menu['id']
			|| (int) ( $prepared->{'menu-item-db-id'} ?? 0 ) !== $item_id || 'publish' !== ( $prepared->{'menu-item-status'} ?? null ) ) {
			return RestTools\error( 'menu_save', 'WordPress would save a different menu item.', 409 );
		}
		foreach ( $plan['body'] as $field => $value ) {
			if ( (string) ( $prepared->{ $fields[ $field ] } ?? '' ) !== (string) $value ) {
				return RestTools\error( 'menu_save', 'WordPress would save different link values.', 409 );
			}
		}
		foreach ( $keep as $field => $value ) {
			if ( 'menu-item-classes' === $field ) {
				if ( ! is_array( $value ) || array_filter( $value, 'is_string' ) !== $value ) {
					continue;
				}
				$value = implode( ' ', $value );
			}
			$prepared->{ $field } = $value;
		}
		if ( $item_id ) {
			$preserve_post  = true;
			$protected_meta = array_fill_keys( array( '_menu_item_target', '_menu_item_xfn', '_menu_item_classes' ), true );
			if ( ! isset( $plan['body']['type'] ) ) {
				$protected_meta += array_fill_keys( array( '_menu_item_type', '_menu_item_object_id', '_menu_item_object', '_menu_item_url' ), true );
			}
			if ( ! isset( $plan['body']['parent'] ) ) {
				$protected_meta['_menu_item_menu_item_parent'] = true;
			}
		}
		return $prepared;
	};
	// Core writes every menu meta key. Omit each unrequested write, including normalisation of absent or legacy values.
	$preserve_meta = static function ( $check, $id, $key ) use ( $item_id, &$protected_meta ) {
		if ( $item_id === $id && isset( $protected_meta[ $key ] ) ) {
			unset( $protected_meta[ $key ] );
			return $check ?? true;
		}
		return $check;
	};
	// Core can replace an empty description with a space or move a zero-position item. Keep untouched native columns.
	$preserve = static function ( $data, $postarr ) use ( $item_id, $columns, &$preserve_post ) {
		if ( $preserve_post && (int) ( $postarr['ID'] ?? 0 ) === $item_id && 'nav_menu_item' === $data['post_type'] ) {
			$preserve_post = false;
			foreach ( $columns as $name => $value ) {
				$data[ $name ] = wp_slash( $value );
			}
		}
		return $data;
	};
	add_filter( 'rest_pre_insert_nav_menu_item', $verify, PHP_INT_MAX, 2 );
	add_filter( 'update_post_metadata', $preserve_meta, PHP_INT_MAX, 3 );
	add_filter( 'wp_insert_post_data', $preserve, PHP_INT_MAX, 2 );
	try {
		$result = rest_do_request( $request );
	} finally {
		remove_filter( 'rest_pre_insert_nav_menu_item', $verify, PHP_INT_MAX );
		remove_filter( 'update_post_metadata', $preserve_meta, PHP_INT_MAX );
		remove_filter( 'wp_insert_post_data', $preserve, PHP_INT_MAX );
	}
	if ( is_wp_error( $result ) || $result->get_status() >= 400 || empty( $result->get_data()['id'] ) ) {
		return $error;
	}
	$saved_id   = (int) $result->get_data()['id'];
	$moves      = array();
	$saved_item = get_post( $saved_id );
	if ( $item && ( ! $saved_item || array_intersect_key( stored_attributes( $saved_item ), $keep ) !== $keep ) ) {
		return partially_saved( $menu['id'], $saved_id, false, $moves );
	}
	foreach ( $columns as $key => $value ) {
		if ( $saved_item->{$key} !== $value ) {
			return partially_saved( $menu['id'], $saved_id, false, $moves );
		}
	}
	foreach ( $plan['positions'] as $id => $position ) {
		if ( $id === $item_id ) {
			if ( get_post( $saved_id )?->menu_order === $position ) {
				$moves[ $saved_id ] = $position;
			}
			continue;
		}
		if ( $before[ $id ]['position'] === $position ) {
			continue;
		}
		$item = get_post( $id );
		if ( ! $item || $item->menu_order !== $before[ $id ]['position'] || ! has_term( $menu['id'], 'nav_menu', $id ) ) {
			return partially_saved( $menu['id'], $saved_id, ! $item_id, $moves );
		}
		$updated = wp_update_post(
			array(
				'ID'         => $id,
				'menu_order' => $position,
			),
			true
		);
		if ( is_wp_error( $updated ) || get_post( $id )?->menu_order !== $position ) {
			return partially_saved( $menu['id'], $saved_id, ! $item_id, $moves );
		}
		$moves[ $id ] = $position;
	}
	$after = snapshot( $menu['id'] );
	if ( is_wp_error( $after ) ) {
		return partially_saved( $menu['id'], $saved_id, ! $item_id, $moves, $after );
	}
	$expected = $plan['after'];
	foreach ( $expected as &$row ) {
		if ( 0 === $row['id'] ) {
			$row['id'] = $saved_id;
		}
		if ( 'custom' === $row['type'] && 0 === $row['object_id'] ) {
			$row['object_id'] = $row['id'];
		}
		ksort( $row );
	}
	unset( $row );
	$actual = $after['items'];
	foreach ( $actual as &$row ) {
		ksort( $row );
	}
	unset( $row );
	if ( $expected !== $actual ) {
		return partially_saved( $menu['id'], $saved_id, ! $item_id, $moves, $after );
	}
	return array(
		'site_url' => Connector\bound_site(),
		'menu'     => $after,
		'item_id'  => $saved_id,
		'verified' => true,
	);
}
