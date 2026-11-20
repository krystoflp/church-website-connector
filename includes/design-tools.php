<?php
/** Reviewed lifecycle, display rules and usage for GeneratePress and GenerateBlocks. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\DesignTools;

use ChurchWebsiteConnector as Connector;
use ChurchWebsiteConnector\Elements;
use ChurchWebsiteConnector\Integrations;
use ChurchWebsiteConnector\PostTools;
use ChurchWebsiteConnector\RestTools;
use ChurchWebsiteConnector\ScopedAbilities;

defined( 'ABSPATH' ) || exit;

const TYPES         = array(
	'element'   => 'gp_elements',
	'form'      => 'gblocks_form',
	'overlay'   => 'gblocks_overlay',
	'condition' => 'gblocks_condition',
);
const FORM_MESSAGES = array(
	'success_message' => 'successMessage',
	'error_message'   => 'errorMessage',
);
const CONDITIONS    = '/generateblocks-pro/advanced-conditions/v1/conditions/';
const FORMS         = '/generateblocks-pro/v1/admin/forms/';
const OVERLAY       = array(
	'label'            => '_gb_overlay_accessible_label',
	'condition_id'     => '_gb_overlay_display_condition',
	'invert_condition' => '_gb_overlay_display_condition_invert',
	'close_on_escape'  => '_gb_overlay_close_on_esc',
	'close_on_outside' => '_gb_overlay_close_on_click_outside',
);
const OPERATIONS    = array( 'rename', 'publish', 'unpublish', 'trash', 'duplicate_form', 'update_element_rules', 'update_overlay', 'update_form_messages', 'update_condition', 'delete_unused_condition' );

function access( string $kind ): true|\WP_Error {
	$type = TYPES[ $kind ] ?? '';
	if ( ! post_type_exists( $type ) ) {
		return RestTools\error( 'design_unavailable', 'The required design module is unavailable.', 503 );
	}
	if ( 'element' === $kind ) {
		if ( ! defined( 'GP_PREMIUM_VERSION' ) || version_compare( GP_PREMIUM_VERSION, '2.5.6', '<' ) || version_compare( GP_PREMIUM_VERSION, '2.6', '>=' ) || ! method_exists( '\GeneratePress_Conditions', 'get_conditions' ) ) {
			return RestTools\error( 'design_unavailable', 'This tool requires a supported GeneratePress Premium 2.5.x Elements module.', 503 );
		}
		return Elements\permission();
	}
	if ( ! defined( 'GENERATEBLOCKS_PRO_VERSION' ) || version_compare( GENERATEBLOCKS_PRO_VERSION, '2.8.0', '<' ) || version_compare( GENERATEBLOCKS_PRO_VERSION, '2.9', '>=' ) ) {
		return RestTools\error( 'design_unavailable', 'This tool requires GenerateBlocks Pro 2.8.x.', 503 );
	}
	$allowed = match ( $kind ) {
		'form' => method_exists( '\GenerateBlocks_Pro_Form_Post_Type', 'current_user_can' ) && \GenerateBlocks_Pro_Form_Post_Type::current_user_can( 'manage' ),
		'overlay' => method_exists( '\GenerateBlocks_Pro_Overlays', 'current_user_can_use_overlays' ) && \GenerateBlocks_Pro_Overlays::current_user_can_use_overlays( 'manage' ),
		'condition' => method_exists( '\GenerateBlocks_Pro_Conditions', 'current_user_can_use_conditions' ) && \GenerateBlocks_Pro_Conditions::current_user_can_use_conditions( 'manage' ),
		default => false,
	};
	return $allowed ? true : RestTools\error( 'design_permission', 'The plugin has not granted this account permission to manage these items.', 403 );
}

function fingerprint( array $data ): string {
	return hash( 'sha256', wp_json_encode( $data ) );
}

/** Use WordPress and vendor REST validation for the fixed internal operations. */
function request( string $method, string $route, array $parameters = array() ): array|\WP_Error {
	$request = new \WP_REST_Request( $method, $route );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	if ( 'GET' === $method ) {
		$request->set_query_params( $parameters );
	} else {
		$request->set_body_params( $parameters );
	}
	$response = rest_do_request( $request );
	$data     = is_wp_error( $response ) ? null : $response->get_data();
	if ( ! is_array( $data ) || $response->get_status() >= 400 || false === ( $data['success'] ?? null ) ) {
		return RestTools\error( 'design_save', 'WordPress or the plugin refused this operation. Inspect the saved item before retrying.', is_wp_error( $response ) ? 500 : max( 400, $response->get_status() ) );
	}
	return $data;
}

function usage( string $kind, int $id ): array|\WP_Error {
	$path = ( 'form' === $kind ? FORMS : CONDITIONS ) . $id . '/usage';
	$data = request(
		'GET',
		$path,
		array(
			'limit'   => 25,
			'refresh' => true,
		)
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$usage = $data['response'] ?? $data['data'] ?? $data;
	if ( ! isset( $usage['total'] ) || ! is_array( $usage['usage'] ?? null ) ) {
		return RestTools\error( 'design_usage', 'The plugin could not verify where this item is used.', 409 );
	}
	return array_intersect_key( $usage, array_flip( array( 'usage', 'total', 'has_more', 'limited' ) ) );
}

function snapshot( string $kind, int $id ): array|\WP_Error {
	$allowed = access( $kind );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	wp_cache_delete( $id, 'posts' );
	$post = get_post( $id );
	if ( ! $post || TYPES[ $kind ] !== $post->post_type || ! in_array( $post->post_status, array( 'draft', 'publish', 'trash' ), true ) || ! current_user_can( 'edit_post', $id ) ) {
		return RestTools\error( 'design_item', 'Choose an exact editable item of the requested kind.', 404 );
	}
	$details = array();
	$meta    = get_post_meta( $id );
	ksort( $meta );
	$meta = array_diff_key( $meta, array_flip( array( '_edit_lock', '_edit_last', '_gb_form_health', '_gb_form_failed_delivery' ) ) );
	if ( 'element' === $kind ) {
		$details = Elements\describe( $post );
	}
	if ( 'form' === $kind ) {
		$config = get_post_meta( $id, \GenerateBlocks_Pro_Form_Post_Type::META_KEY, true );
		foreach ( FORM_MESSAGES as $field => $key ) {
			$details[ $field ] = (string) ( $config['config'][ $key ] ?? '' );
		}
	}
	if ( 'overlay' === $kind ) {
		foreach ( OVERLAY as $field => $key ) {
			$value             = get_post_meta( $id, $key, true );
			$details[ $field ] = in_array( $field, array( 'label', 'condition_id' ), true ) ? (string) $value : (bool) $value;
		}
		$details['overlay_type'] = get_post_meta( $id, '_gb_overlay_type', true );
		$details['trigger']      = get_post_meta( $id, '_gb_overlay_trigger_type', true );
	}
	if ( 'condition' === $kind ) {
		$details['conditions'] = get_post_meta( $id, '_gb_conditions', true );
		$details['categories'] = wp_get_object_terms( $id, 'gblocks_condition_cat', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $details['categories'] ) ) {
			return $details['categories'];
		}
	}
	$review = array(
		'id'         => $id,
		'kind'       => $kind,
		'title'      => $post->post_title,
		'status'     => $post->post_status,
		'details'    => $details,
		'state_hash' => fingerprint( array( get_object_vars( $post ), $meta, $details ) ),
	);
	return array(
		'post'   => $post,
		'review' => $review,
	);
}

function target( $input, array $keys = array() ): array|\WP_Error {
	$checked = PostTools\check( $input, array_merge( array( 'id', 'kind' ), $keys ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	if ( ! is_string( $input['kind'] ?? null ) || ! isset( TYPES[ $input['kind'] ] ) ) {
		return RestTools\error( 'design_item', 'Choose a supported design item type.' );
	}
	return snapshot( $input['kind'], $input['id'] );
}

function read_item( $input ): array|\WP_Error {
	$state = target( $input );
	return is_wp_error( $state ) ? $state : array(
		'site_url' => Connector\bound_site(),
		'item'     => $state['review'],
	);
}

function read_usage( $input ): array|\WP_Error {
	$state = target( $input );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	$kind = $state['review']['kind'];
	if ( ! in_array( $kind, array( 'form', 'condition' ), true ) ) {
		return RestTools\error( 'design_usage', 'Choose a GenerateBlocks form or condition.' );
	}
	$result = usage( $kind, $input['id'] );
	return is_wp_error( $result ) ? $result : array(
		'site_url' => Connector\bound_site(),
		'item'     => $state['review'],
		'usage'    => $result,
	);
}

function listing( $input ): array|\WP_Error {
	$checked = PostTools\check( $input, array( 'kind', 'page' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$kind = $input['kind'] ?? null;
	$page = $input['page'] ?? 1;
	if ( ! is_string( $kind ) || ! isset( TYPES[ $kind ] ) || ! is_int( $page ) || $page < 1 || $page > 100 ) {
		return RestTools\error( 'design_item', 'Choose a design type and a page from 1 to 100.' );
	}
	$allowed = access( $kind );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	$query = new \WP_Query(
		array(
			'post_type'      => TYPES[ $kind ],
			'post_status'    => array( 'draft', 'publish', 'trash' ),
			'posts_per_page' => 20,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	$items = array();
	foreach ( $query->posts as $post ) {
		if ( current_user_can( 'edit_post', $post->ID )
			&& ( 'element' !== $kind || 'block' === get_post_meta( $post->ID, '_generate_element_type', true ) ) ) {
			$items[] = array(
				'id'     => $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
			);
		}
	}
	return array(
		'site_url'  => Connector\bound_site(),
		'kind'      => $kind,
		'items'     => $items,
		'next_page' => $page < min( 100, $query->max_num_pages ) ? $page + 1 : null,
	);
}

function element_rule_options( $input ): array|\WP_Error {
	$checked = PostTools\check( $input, array() );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$allowed = access( 'element' );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	if ( ! function_exists( 'get_editable_roles' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	return array(
		'site_url'  => Connector\bound_site(),
		'locations' => \GeneratePress_Conditions::get_conditions(),
		'audiences' => \GeneratePress_Conditions::get_user_conditions(),
	);
}

function form_messages( int $id, array $changes ): array|\WP_Error {
	$error = RestTools\error( 'form_messages', 'Choose plain success or error text up to 1000 bytes.' );
	$key   = \GenerateBlocks_Pro_Form_Post_Type::META_KEY;
	$meta  = get_post_meta( $id, $key, true );
	if ( ! $changes || array_diff( array_keys( $changes ), array_keys( FORM_MESSAGES ) ) || ! is_array( $meta['config'] ?? null ) ) {
		return $error;
	}
	$review = array();
	$after  = $meta;
	foreach ( $changes as $field => $value ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 1000 || sanitize_text_field( $value ) !== $value ) {
			return $error;
		}
		$name                     = FORM_MESSAGES[ $field ];
		$review[ $field ]         = array(
			'before' => $meta['config'][ $name ] ?? '',
			'after'  => $value,
		);
		$after['config'][ $name ] = $value;
	}
	$normalized = \GenerateBlocks_Pro_Form_Post_Type::sanitize_meta( $after );
	if ( Integrations\canonical( $normalized ) !== Integrations\canonical( $after ) ) {
		return RestTools\error( 'form_messages', 'The vendor would change other form settings. Review the form in its editor first.', 409 );
	}
	return array(
		'meta'   => array( $key => $after ),
		'review' => $review,
	);
}

/** Resolve IDs according to the same rule keys and object types as GP Premium. */
function placement( string $group, array $rules ): array|\WP_Error {
	$error = RestTools\error( 'element_rules', 'Use available GeneratePress rules and exact readable object IDs, with no duplicate rules.' );
	if ( ! array_is_list( $rules ) || count( $rules ) > 25 ) {
		return $error;
	}
	if ( 'audiences' === $group ) {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$allowed = array();
		foreach ( \GeneratePress_Conditions::get_user_conditions() as $category ) {
			$allowed += $category['rules'];
		}
		foreach ( $rules as $rule ) {
			if ( ! is_string( $rule ) || ! isset( $allowed[ $rule ] ) ) {
				return $error;
			}
		}
		return count( array_unique( $rules ) ) === count( $rules ) ? $rules : $error;
	}
	$allowed = array();
	foreach ( \GeneratePress_Conditions::get_conditions() as $category ) {
		$allowed += $category['locations'];
	}
	$output = array();
	foreach ( $rules as $item ) {
		$rule   = $item['rule'] ?? null;
		$object = $item['object_id'] ?? 0;
		if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'rule', 'object_id' ) ) || ! is_string( $rule ) || ! isset( $allowed[ $rule ] ) || ! is_int( $object ) || $object < 0 ) {
			return $error;
		}
		$parts = explode( ':', $rule );
		if ( $object ) {
			if ( 'taxonomy' === $parts[0] || 'taxonomy' === ( $parts[1] ?? '' ) ) {
				$term = get_term( $object, end( $parts ) );
				if ( ! $term instanceof \WP_Term ) {
					return $error;
				}
			} elseif ( 'post' === $parts[0] ) {
				$post = get_post( $object );
				if ( ! $post || $post->post_type !== $parts[1] || 'publish' !== $post->post_status || ! current_user_can( 'read_post', $object ) ) {
					return $error;
				}
			} else {
				return $error;
			}
		} elseif ( 'taxonomy' === ( $parts[1] ?? '' ) ) {
			return $error;
		}
		$key = $rule . ':' . $object;
		if ( isset( $output[ $key ] ) ) {
			return $error;
		}
		$output[ $key ] = array(
			'rule'   => $rule,
			'object' => $object ? (string) $object : '',
		);
	}
	return array_values( $output );
}

function overlay_changes( array $changes ): array|\WP_Error {
	$error = RestTools\error( 'overlay_options', 'Choose a plain label, keyboard/backdrop options, or an exact published condition ID. Set the condition ID to zero to clear it.' );
	if ( ! $changes || array_diff( array_keys( $changes ), array_keys( OVERLAY ) ) ) {
		return $error;
	}
	$meta      = array();
	$condition = null;
	foreach ( $changes as $field => $value ) {
		if ( 'label' === $field ) {
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 180 || sanitize_text_field( $value ) !== $value ) {
				return $error;
			}
		} elseif ( 'condition_id' === $field ) {
			if ( ! is_int( $value ) || $value < 0 ) {
				return $error;
			}
			if ( $value ) {
				$item = snapshot( 'condition', $value );
				if ( is_wp_error( $item ) || 'publish' !== $item['post']->post_status ) {
					return $error;
				}
				$condition = $item['review'];
			}
			$value = $value ? (string) $value : '';
		} elseif ( ! is_bool( $value ) ) {
			return $error;
		} elseif ( 'close_on_escape' === $field && ! $value ) {
			return RestTools\error( 'overlay_accessibility', 'Keep Escape dismissal available for this overlay.' );
		}
		$meta[ OVERLAY[ $field ] ] = $value;
	}
	return array(
		'meta'      => $meta,
		'condition' => $condition,
	);
}

function condition_changes( array $changes ): array|\WP_Error {
	if ( array_keys( $changes ) !== array( 'conditions' ) || ! is_array( $changes['conditions'] )
		|| strlen( wp_json_encode( $changes['conditions'] ) ) > 16000
		|| ! method_exists( '\GeneratePress_AI_Conditions_Api', 'authorize_references' )
		|| ! method_exists( '\GeneratePress_AI_Conditions_Api', 'validate_conditions' ) ) {
		return RestTools\error( 'condition_rules', 'Provide the supported GenerateBlocks condition structure.' );
	}
	$rules = $changes['conditions'];
	foreach ( array( 'validate_conditions', 'authorize_references' ) as $validator ) {
		$result = \GeneratePress_AI_Conditions_Api::$validator( $rules );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return RestTools\error( 'condition_rules', 'The vendor could not verify these condition rules.' );
		}
	}
	$normalized = \GenerateBlocks_Pro_Conditions::get_instance()->sanitize_conditions( $rules );
	return array( 'conditions' => $normalized );
}

function plan( string $operation, array $state, array $changes ): array|\WP_Error {
	$post = $state['post'];
	$item = $state['review'];
	$kind = $item['kind'];
	$id   = $post->ID;
	$type = get_post_type_object( $post->post_type );
	if ( 'trash' === $post->post_status ) {
		return RestTools\error( 'design_status', 'Restore this item from the WordPress Trash before editing it.', 409 );
	}
	if ( 'element' === $kind && 'block' !== $item['details']['type'] ) {
		return RestTools\error( 'design_item', 'Choose a native Block Element. Other Element types are managed in WordPress.', 409 );
	}
	$body      = array();
	$meta      = array();
	$impact    = null;
	$method    = 'POST';
	$rest_base = match ( $kind ) {
		'form'      => 'gblocks-forms',
		'condition' => 'gblocks-conditions',
		default     => $post->post_type,
	};
	$route = '/wp/v2/' . $rest_base . '/' . $id;

	if ( 'rename' === $operation ) {
		$title = $changes['title'] ?? null;
		if ( array_keys( $changes ) !== array( 'title' ) || ! is_string( $title ) || '' === $title || strlen( $title ) > 180 || sanitize_text_field( $title ) !== $title || $post->post_title === $title ) {
			return RestTools\error( 'design_title', 'Choose a different plain title up to 180 bytes.' );
		}
		$body['title'] = $title;
	} elseif ( 'update_element_rules' === $operation && 'element' === $kind ) {
		$group = $changes['group'] ?? null;
		$rules = $changes['rules'] ?? null;
		if ( count( $changes ) !== 2 || ! is_string( $group ) || ! isset( Elements\RULES[ $group ] ) || ! is_array( $rules ) ) {
			return RestTools\error( 'element_rules', 'Choose one Element rule group and its replacement list.' );
		}
		$normalized = placement( $group, $rules );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$key = Elements\RULES[ $group ];
		if ( (array) get_post_meta( $id, $key, true ) === $normalized ) {
			return RestTools\error( 'unchanged', 'This rule group already has that value.', 409 );
		}
		$meta = array( $key => $normalized );
	} elseif ( 'update_overlay' === $operation && 'overlay' === $kind ) {
		$overlay = overlay_changes( $changes );
		if ( is_wp_error( $overlay ) ) {
			return $overlay;
		}
		$body['meta'] = $overlay['meta'];
		$impact       = $overlay['condition'];
	} elseif ( 'update_form_messages' === $operation && 'form' === $kind ) {
		$form = form_messages( $id, $changes );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$body['meta'] = $form['meta'];
		$impact       = $form['review'];
	} elseif ( 'update_condition' === $operation && 'condition' === $kind ) {
		$body = condition_changes( $changes );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$route = CONDITIONS . $id;
	} elseif ( ! $changes && 'duplicate_form' === $operation && 'form' === $kind && current_user_can( $type->cap->create_posts ) ) {
		$route = FORMS . $id . '/duplicate';
	} elseif ( ! $changes && 'delete_unused_condition' === $operation && 'condition' === $kind && current_user_can( 'delete_post', $id ) ) {
		$route         = CONDITIONS . $id;
		$method        = 'DELETE';
		$body['force'] = false;
	} elseif ( ! $changes && 'trash' === $operation && 'condition' !== $kind && EMPTY_TRASH_DAYS > 0 && current_user_can( 'delete_post', $id ) ) {
		$method        = 'DELETE';
		$body['force'] = false;
	} elseif ( ! $changes && in_array( $operation, array( 'publish', 'unpublish' ), true ) && current_user_can( $type->cap->publish_posts ) ) {
		$publish = 'publish' === $operation;
		if ( ( $publish ? 'draft' : 'publish' ) !== $post->post_status || '' !== $post->post_password ) {
			return RestTools\error( 'design_status', 'Choose an unprotected draft to publish or a published item to return to draft.', 409 );
		}
		if ( $publish && (int) strtotime( $post->post_date_gmt . ' UTC' ) > time() ) {
			return RestTools\error( 'design_status', 'Review the future date in WordPress before publishing this item.', 409 );
		}
		if ( $publish && 'element' === $kind && empty( $item['details']['locations'] ) ) {
			return RestTools\error( 'element_rules', 'Set and review display locations before publishing this Block Element.', 409 );
		}
		$body['status'] = $publish ? 'publish' : 'draft';
	} else {
		return RestTools\error( 'design_operation', 'Choose an operation available for this item and your WordPress permissions.', 403 );
	}

	if ( in_array( $kind, array( 'form', 'condition' ), true ) && in_array( $operation, array( 'publish', 'unpublish', 'trash', 'update_condition', 'delete_unused_condition' ), true ) ) {
		$impact = usage( $kind, $id );
		if ( is_wp_error( $impact ) ) {
			return $impact;
		}
		if ( 'condition' === $kind && in_array( $operation, array( 'unpublish', 'delete_unused_condition' ), true )
			&& ( ! empty( $impact['total'] ) || ! empty( $impact['limited'] ) || ! empty( $impact['has_more'] ) ) ) {
			return RestTools\error( 'condition_in_use', 'This condition is in use or its usage could not be checked completely.', 409 );
		}
	}
	return array(
		'route'  => $route,
		'method' => $method,
		'body'   => $body,
		'meta'   => $meta,
		'impact' => $impact,
	);
}

function preview( $input ): array|\WP_Error {
	$state = target( $input, array( 'state_hash', 'operation', 'changes' ) );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	$item = $state['review'];
	if ( ! is_string( $input['state_hash'] ?? null ) || ! hash_equals( $item['state_hash'], $input['state_hash'] ) ) {
		return RestTools\error( 'stale', 'Read the current item before preparing a preview.', 409 );
	}
	$operation = $input['operation'] ?? null;
	$changes   = $input['changes'] ?? array();
	if ( ! in_array( $operation, OPERATIONS, true ) || ! is_array( $changes ) ) {
		return RestTools\error( 'design_operation', 'Choose a supported design change.' );
	}
	$plan = plan( $operation, $state, $changes );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	$saved = RestTools\save_preview(
		array(
			'operation'  => 'design_' . $operation,
			'kind'       => $item['kind'],
			'id'         => $item['id'],
			'state_hash' => $item['state_hash'],
			'changes'    => $changes,
			'review'     => fingerprint( $plan ),
		)
	);
	return is_wp_error( $saved ) ? $saved : array(
		'site_url'  => Connector\bound_site(),
		'item'      => $item,
		'operation' => $operation,
		'proposed'  => 'update_form_messages' === $operation ? $plan['impact'] : $plan['body'] + $plan['meta'],
		'usage'     => 'update_form_messages' === $operation ? null : $plan['impact'],
		'message'   => 'Show the exact item, proposed change and known usage before asking for approval. Display conditions can change what appears on public pages.',
	) + $saved;
}

function commit( string $operation, array $plan, array $state ): array|\WP_Error {
	$item = $state['review'];
	$id   = $item['id'];
	if ( $plan['meta'] ) {
		foreach ( $plan['meta'] as $key => $value ) {
			$previous = get_post_meta( $id, $key, true );
			update_post_meta( $id, $key, wp_slash( $value ), $previous );
			if ( get_post_meta( $id, $key, true ) !== $value ) {
				return RestTools\error( 'design_readback', 'GeneratePress stored a different rule. Read the item before retrying.', 409 );
			}
		}
		return read_item(
			array(
				'site_url' => Connector\bound_site(),
				'kind'     => $item['kind'],
				'id'       => $id,
			)
		);
	}

	// pre_post_update supplies unslashed database values, including literal block JSON escapes.
	$guard = static function ( $id_to_save, $data ) use ( $id, $state, $plan ) {
		if ( $id_to_save !== $id ) {
			return;
		}
		$original = $state['post'];
		$status   = $plan['body']['status'] ?? $original->post_status;
		if ( ( $data['post_status'] ?? '' ) !== $status || ( $data['post_content'] ?? '' ) !== $original->post_content ) {
			throw new \DomainException( 'The underlying save changed the reviewed status or content.' );
		}
	};
	if ( 'POST' === $plan['method'] && str_starts_with( $plan['route'], '/wp/v2/' ) ) {
		add_action( 'pre_post_update', $guard, PHP_INT_MAX, 2 );
	}
	try {
		$result = request( $plan['method'], $plan['route'], $plan['body'] );
	} finally {
		remove_action( 'pre_post_update', $guard, PHP_INT_MAX );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( 'delete_unused_condition' === $operation && ! get_post( $id ) ) {
		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}
	if ( 'duplicate_form' === $operation ) {
		$id = (int) ( $result['id'] ?? 0 );
		if ( $id < 1 || $id === $item['id'] ) {
			return RestTools\error( 'design_readback', 'The duplicate could not be verified. Inspect the forms list before retrying.', 409 );
		}
	}
	$after = snapshot( $item['kind'], $id );
	if ( is_wp_error( $after ) ) {
		return $after;
	}
	$expected_status = match ( $operation ) {
		'trash' => 'trash',
		'duplicate_form' => 'draft',
		default => $plan['body']['status'] ?? $item['status'],
	};
	if ( $after['post']->post_status !== $expected_status
		|| ( isset( $plan['body']['title'] ) && $after['post']->post_title !== $plan['body']['title'] )
		|| ( isset( $plan['body']['conditions'] ) && $after['review']['details']['conditions'] !== $plan['body']['conditions'] ) ) {
		return RestTools\error( 'design_readback', 'The plugin stored different values. Inspect the item before retrying.', 409 );
	}
	foreach ( $plan['body']['meta'] ?? array() as $key => $value ) {
		$stored = get_post_meta( $id, $key, true );
		if ( is_array( $value ) ? Integrations\canonical( $stored ) !== Integrations\canonical( $value ) : (string) $stored !== (string) $value ) {
			return RestTools\error( 'design_readback', 'The plugin stored different settings. Inspect the item before retrying.', 409 );
		}
	}
	return array( 'item' => $after['review'] );
}

function apply( $input ): array|\WP_Error {
	$checked = PostTools\check( $input, array( 'preview_token', 'approved', 'confirm_removal' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	if ( true !== ( $input['approved'] ?? null ) ) {
		return RestTools\error( 'approval', 'Confirm the exact design preview before applying.' );
	}
	$saved = RestTools\load_preview( $input['preview_token'] ?? null );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$operation = $saved['operation'] ?? '';
	if ( ! str_starts_with( $operation, 'design_' ) || ! in_array( substr( $operation, 7 ), OPERATIONS, true ) ) {
		return RestTools\error( 'token', 'Use a design change preview.', 409 );
	}
	$operation = substr( $operation, 7 );
	if ( 'delete_unused_condition' === $operation && true !== ( $input['confirm_removal'] ?? null ) ) {
		return RestTools\error( 'approval', 'Confirm permanent deletion of the unused condition separately.' );
	}
	$lock = RestTools\lock_item( TYPES[ $saved['kind'] ], $saved['id'] );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$state = snapshot( $saved['kind'], $saved['id'] );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( ! hash_equals( $saved['state_hash'], $state['review']['state_hash'] ) ) {
			return RestTools\error( 'stale', 'The design item changed after review. Prepare a new preview.', 409 );
		}
		$plan = plan( $operation, $state, $saved['changes'] );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! hash_equals( $saved['review'], fingerprint( $plan ) ) || ! RestTools\consume_preview( $saved ) ) {
			return RestTools\error( 'stale', 'Usage, settings or the active preview changed. Prepare a fresh preview.', 409 );
		}
		try {
			$result = commit( $operation, $plan, $state );
		} catch ( \Throwable $e ) {
			$result = RestTools\error( 'design_save', 'The save was interrupted. Inspect the item before retrying.', 409 );
		}
		RestTools\audit( $saved, is_wp_error( $result ) ? 'error' : 'verified' );
		return is_wp_error( $result ) ? $result : array(
			'site_url' => Connector\bound_site(),
			'verified' => true,
		) + $result;
	} finally {
		RestTools\conditional_delete( $lock[0], $lock[1] );
	}
}

function register(): void {
	if ( ! Connector\feature_enabled( 'rest_tools' ) || ! Connector\feature_enabled( 'generatepress_bridge' ) ) {
		return;
	}
	$kind   = array(
		'type' => 'string',
		'enum' => array_keys( TYPES ),
	);
	$id     = array(
		'type'    => 'integer',
		'minimum' => 1,
	);
	$hash   = array(
		'type'    => 'string',
		'pattern' => '^[a-f0-9]{64}$',
	);
	$yes    = array(
		'type'  => 'boolean',
		'const' => true,
	);
	$target = array(
		'kind' => $kind,
		'id'   => $id,
	);
	$tools  = array(
		'list-design-items'        => array(
			'List GeneratePress or GenerateBlocks design items',
			'List editable Block Elements, GenerateBlocks forms, overlays or conditions. Choose kind element, form, overlay or condition.',
			'listing',
			array( 'kind' => $kind ),
			array(
				'page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
			),
		),
		'get-element-rule-options' => array(
			'List native GeneratePress Element rule choices',
			'Read available placement, exclusion and audience options from GeneratePress Premium. Use exact page, post or term IDs with the selected rule.',
			'element_rule_options',
			array(),
		),
		'get-design-item'          => array( 'Read one design item before previewing changes', 'Read the title, status, relevant settings and current state_hash for an exact design item.', 'read_item', $target ),
		'get-design-usage'         => array( 'Read where a form or condition is used', 'Run GenerateBlocks Pro’s native usage scan. It may be limited on large sites.', 'read_usage', $target ),
		'preview-design-change'    => array(
			'Preview a GeneratePress or GenerateBlocks design change',
			'Preview rename, publish, unpublish or trash where available, form duplication, changing one Block Element rule group, changing overlay display or accessibility settings, editing front-end form success/error messages, or updating or deleting an unused condition. Use the exact state_hash. Status and duplication need no changes. Rename uses title. Element rules use group (locations, exclusions or audiences) and rules (objects with rule and optional object_id, or audience strings). Overlay changes accept label, condition_id (0 clears), invert_condition, close_on_escape and close_on_outside. Form message changes use success_message and error_message. Condition updates use conditions in the native GenerateBlocks shape.',
			'preview',
			$target + array(
				'state_hash' => $hash,
				'operation'  => array(
					'type' => 'string',
					'enum' => OPERATIONS,
				),
			),
			array( 'changes' => array( 'type' => 'object' ) ),
		),
		'apply-design-change'      => array(
			'Apply an explicitly approved design change',
			'Review the exact item and impact before applying this one-time preview. Permanent deletion of an unused condition requires confirm_removal as well as approval.',
			'apply',
			array(
				'preview_token' => $hash,
				'approved'      => $yes,
			),
			array( 'confirm_removal' => $yes ),
		),
	);
	ScopedAbilities\register( $tools, __NAMESPACE__, 'ChurchWebsiteConnector\\PostTools\\permission' );
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
