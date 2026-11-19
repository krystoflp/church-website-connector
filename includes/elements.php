<?php
/** Inspect classic GeneratePress Premium Element placement. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Elements;

use ChurchWebsiteConnector as Connector;
use ChurchWebsiteConnector\PostTools;
use ChurchWebsiteConnector\RestTools;

defined( 'ABSPATH' ) || exit;

const RULES = array(
	'locations'  => '_generate_element_display_conditions',
	'exclusions' => '_generate_element_exclude_conditions',
	'audiences'  => '_generate_element_user_conditions',
);

function permission( $input = null ): true|\WP_Error {
	$permission = PostTools\permission();
	if ( is_wp_error( $permission ) ) {
		return $permission;
	}
	$type = get_post_type_object( 'gp_elements' );
	if ( ! $type ) {
		return RestTools\error( 'elements_unavailable', 'GeneratePress Premium Elements is unavailable on this website.', 503 );
	}
	return current_user_can( 'edit_theme_options' ) && current_user_can( $type->cap->edit_posts )
		? true : RestTools\error( 'elements_permission', 'Your account cannot manage GeneratePress Elements.', 403 );
}

function describe( \WP_Post $element ): array {
	$id     = $element->ID;
	$result = array(
		'id'         => $id,
		'title'      => $element->post_title,
		'status'     => $element->post_status,
		'type'       => get_post_meta( $id, '_generate_element_type', true ),
		'block_type' => get_post_meta( $id, '_generate_block_type', true ),
	);
	foreach ( array( 'type', 'block_type' ) as $field ) {
		if ( ! is_string( $result[ $field ] ) ) {
			$result[ $field ] = '';
		}
	}
	foreach ( RULES as $group => $key ) {
		$value = get_post_meta( $id, $key, true );
		$rules = array();
		foreach ( is_array( $value ) ? array_slice( $value, 0, 100 ) : array() as $rule ) {
			if ( is_string( $rule ) ) {
				$rules[] = $rule;
			} elseif ( is_array( $rule ) && is_string( $rule['rule'] ?? null ) ) {
				$id_value = $rule['object'] ?? '';
				$item     = array(
					'rule'   => $rule['rule'],
					'object' => is_scalar( $id_value ) ? (string) $id_value : '',
				);
				if ( 'post:page' === $rule['rule'] || 'post:post' === $rule['rule'] ) {
					$target = ctype_digit( $item['object'] ) && (int) $item['object'] > 0 ? get_post( (int) $item['object'] ) : null;
					if ( $target && 'post:' . $target->post_type === $rule['rule']
						&& ( 'publish' === $target->post_status || current_user_can( 'read_post', $target->ID ) ) ) {
						$item['title'] = $target->post_title;
					}
				}
				$rules[] = $item;
			}
		}
		$result[ $group ] = $rules;
		if ( is_array( $value ) && ( count( $value ) > count( $rules ) ) ) {
			$result['partial_rules'] = true;
		}
	}
	return $result;
}

function list_elements( $input ): array|\WP_Error {
	$checked = PostTools\check( $input, array( 'page' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$checked = permission();
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$page = $input['page'] ?? 1;
	if ( ! is_int( $page ) || $page < 1 || $page > 100 ) {
		return RestTools\error( 'search_page', 'Choose a results page from 1 to 100.' );
	}
	$query = new \WP_Query(
		array(
			'post_type'      => 'gp_elements',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 20,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	$items = array();
	foreach ( $query->posts as $element ) {
		if ( current_user_can( 'edit_post', $element->ID ) ) {
			$items[] = describe( $element );
		}
	}
	return array(
		'site_url'  => Connector\bound_site(),
		'items'     => $items,
		'page'      => $page,
		'next_page' => $page < min( 100, $query->max_num_pages ) ? $page + 1 : null,
		'message'   => 'These are the stored placement rules. Theme filters, audiences and competing Elements can affect what appears on a particular request.',
	);
}
