<?php
/** Reviewed File block replacement, draft dating and immediate post publication. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\PostTools;

use ChurchWebsiteConnector as Connector;
use ChurchWebsiteConnector\Editorial;
use ChurchWebsiteConnector\PostFiles;
use ChurchWebsiteConnector\RestTools;

defined( 'ABSPATH' ) || exit;

function permission( $input = null ): true|\WP_Error {
	$permission = RestTools\permission();
	if ( is_wp_error( $permission ) ) {
		return $permission;
	}
	if ( ! Connector\feature_enabled( 'generatepress_bridge' ) || ! Connector\GeneratePressBridge\environment_ready()
		|| ! method_exists( '\WP_HTML_Tag_Processor', 'set_modifiable_text' ) ) {
		return RestTools\error( 'post_connection', 'Use the compatible GeneratePress connection and WordPress HTML API for the post workflow.', 403 );
	}
	return true;
}

/** Read the native edit view and guard the complete relevant stored post state. */
function snapshot( int $id, string $kind = 'post', bool $editorial = false ): array|\WP_Error {
	$item = RestTools\read_item( $kind, $id );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	$post = get_post( $id );
	if ( ! $post instanceof \WP_Post || ! in_array( $post->post_status, $editorial ? array( 'draft', 'publish', 'future' ) : array( 'draft', 'publish' ), true ) ) {
		return RestTools\error( 'post_status', 'Choose an editable ordinary post or page with a supported status.', 409 );
	}
	$ranges = PostFiles\ranges( $post->post_content );
	if ( is_wp_error( $ranges ) ) {
		return $ranges;
	}

	$files = array();
	foreach ( $ranges as $index => $file ) {
		$files[] = PostFiles\describe( $file, $index + 1 );
	}
	$review = array(
		'id'          => $id,
		'kind'        => $kind,
		'title'       => $post->post_title,
		'slug'        => $post->post_name,
		'status'      => $post->post_status,
		'date_local'  => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : str_replace( ' ', 'T', $post->post_date ),
		'date_gmt'    => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : str_replace( ' ', 'T', $post->post_date_gmt ) . 'Z',
		'timezone'    => wp_timezone_string(),
		'categories'  => $item['fields']['categories'] ?? array(),
		'file_blocks' => $files,
		'state_hash'  => hash( 'sha256', $item['state_hash'] . $post->post_date . wp_timezone_string() ),
	);
	if ( $editorial && 'future' === $post->post_status ) {
		$event                          = wp_next_scheduled( 'publish_future_post', array( $id ) );
		$review['wp_cron_scheduled_at'] = false === $event ? null : gmdate( 'Y-m-d\TH:i:s\Z', $event );
		$review['state_hash']           = hash( 'sha256', $review['state_hash'] . wp_json_encode( $event ) );
	}
	return array(
		'post'   => $post,
		'review' => $review,
		'ranges' => $ranges,
	);
}

function get_post_workflow( $input ): array|\WP_Error {
	$checked = check( $input, array( 'id' ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	return review_post( $input['id'] );
}

function review_post( int $id ): array|\WP_Error {
	$state = snapshot( $id );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	return array(
		'site_url' => Connector\bound_site(),
		'post'     => $state['review'],
	);
}

function check( $input, array $keys ): true|\WP_Error {
	$permission = permission();
	if ( is_wp_error( $permission ) ) {
		return $permission;
	}
	$checked = RestTools\check_input( $input, array_merge( array( 'site_url' ), $keys ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	if ( in_array( 'id', $keys, true ) && ( ! is_int( $input['id'] ?? null ) || $input['id'] < 1 ) ) {
		return RestTools\error( 'post_id', 'Choose a positive WordPress post ID.' );
	}
	return true;
}

/** Parse a site wall-clock date and reject ambiguous or nonexistent DST times. */
function local_date( mixed $input ): array|\WP_Error {
	$invalid = RestTools\error( 'post_date', 'Use a valid site-local date (YYYY-MM-DD) or time (YYYY-MM-DDTHH:MM:SS). Choose an unambiguous time outside a daylight-saving transition.' );
	if ( ! is_string( $input ) || ! preg_match( '/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}(?:T[0-9]{2}:[0-9]{2}:[0-9]{2})?\z/', $input ) ) {
		return $invalid;
	}
	$local = str_replace( 'T', ' ', strlen( $input ) === 10 ? $input . 'T00:00:00' : $input );
	$zone  = wp_timezone();
	$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $local, $zone );
	if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $local ) {
		return $invalid;
	}
	$stamp = $date->getTimestamp();
	foreach ( (array) $zone->getTransitions( $stamp - DAY_IN_SECONDS, $stamp + DAY_IN_SECONDS ) as $transition ) {
		$candidate = $stamp + $date->getOffset() - $transition['offset'];
		if ( $candidate !== $stamp && wp_date( 'Y-m-d H:i:s', $candidate, $zone ) === $local ) {
			return $invalid;
		}
	}
	return array(
		'local'     => $local,
		'gmt'       => gmdate( 'Y-m-d H:i:s', $stamp ),
		'timestamp' => $stamp,
	);
}

function plan( string $operation, array $state, array $input ): array|\WP_Error {
	$post   = $state['post'];
	$review = $state['review'];
	if ( 'replace_pdf' === $operation ) {
		$number = $input['file_block'] ?? null;
		$id     = $input['attachment_id'] ?? null;
		$label  = $input['label'] ?? null;
		if ( ! is_int( $number ) || ! isset( $state['ranges'][ $number - 1 ] ) || ! is_int( $id ) || $id < 1
			|| ! is_string( $label ) || strlen( $label ) > 300 || '' === sanitize_text_field( $label ) ) {
			return RestTools\error( 'post_pdf_input', 'Choose an enumerated File block, a PDF attachment ID and a descriptive plain-text label up to 300 bytes.' );
		}
		$label = sanitize_text_field( $label );
		$pdf   = PostFiles\attachment( $id );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		$content = PostFiles\replace( $post->post_content, $state['ranges'][ $number - 1 ], $pdf, $label );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( $content === $post->post_content ) {
			return RestTools\error( 'unchanged', 'The selected block already has this PDF and label.', 409 );
		}
		$ranges = PostFiles\ranges( $content );
		if ( is_wp_error( $ranges ) || count( $ranges ) !== count( $state['ranges'] ) ) {
			return RestTools\error( 'pdf_block', 'The updated File block could not be verified.' );
		}
		$review['change'] = array(
			'before'       => $review['file_blocks'][ $number - 1 ],
			'after'        => PostFiles\describe( $ranges[ $number - 1 ], $number ),
			'pdf_sha256'   => $pdf['sha256'],
			'after_status' => $post->post_status,
		);
		return array(
			'review'   => $review,
			'body'     => array( 'content' => $content ),
			'expected' => array( 'post_content' => $content ),
		);
	}

	if ( in_array( $operation, array( 'publish_page', 'schedule', 'cancel_schedule', 'restore_revision' ), true ) ) {
		return Editorial\plan( $operation, $state, $input );
	}

	if ( 'draft' !== $post->post_status ) {
		return RestTools\error( 'post_status', 'This operation requires a draft post.', 409 );
	}
	if ( 'set_draft_date' === $operation ) {
		$date = local_date( $input['date_local'] ?? null );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		if ( $post->post_date === $date['local'] && $post->post_date_gmt === $date['gmt'] ) {
			return RestTools\error( 'unchanged', 'The draft already has this publication date.', 409 );
		}
		$expected         = array(
			'post_date'     => $date['local'],
			'post_date_gmt' => $date['gmt'],
		);
		$review['change'] = array(
			'date_local'   => str_replace( ' ', 'T', $date['local'] ),
			'date_gmt'     => str_replace( ' ', 'T', $date['gmt'] ) . 'Z',
			'after_status' => 'draft',
		);
		return array(
			'review'   => $review,
			'body'     => array( 'date_gmt' => $review['change']['date_gmt'] ),
			'expected' => $expected,
		);
	}

	if ( 'publish' !== $operation ) {
		return RestTools\error( 'post_operation', 'This post operation is outside the fixed allowlist.' );
	}
	$type = get_post_type_object( 'post' );
	if ( ! $type || ! current_user_can( $type->cap->publish_posts ) ) {
		return RestTools\error( 'post_publish_permission', 'Your WordPress account cannot publish posts.', 403 );
	}
	if ( '' !== $post->post_password ) {
		return RestTools\error( 'post_visibility', 'Use the WordPress editor to publish password-protected drafts.', 409 );
	}
	$date = local_date( $review['date_local'] );
	if ( is_wp_error( $date ) || $date['gmt'] !== $post->post_date_gmt || $date['timestamp'] > time() ) {
		return RestTools\error( 'post_publish_date', 'Save an explicit current or past publication date on the draft before publishing. Use the separate scheduling workflow for future publication.', 409 );
	}
	$review = review_files( $review );
	if ( is_wp_error( $review ) ) {
		return $review;
	}
	$review['change'] = array(
		'after_status'   => 'publish',
		'visibility'     => 'public',
		'publish_timing' => 'immediate',
	);
	return array(
		'review'   => $review,
		'body'     => array( 'status' => 'publish' ),
		'expected' => array( 'post_status' => 'publish' ),
	);
}

function review_files( array $review ): array|\WP_Error {
	foreach ( $review['file_blocks'] as &$file ) {
		if ( $file['attachment_id'] && 'application/pdf' === get_post_mime_type( $file['attachment_id'] ) ) {
			$pdf = PostFiles\attachment( $file['attachment_id'] );
			if ( is_wp_error( $pdf ) ) {
				return $pdf;
			}
			$file['media_library_pdf'] = $pdf;
		}
	}
	unset( $file );
	return $review;
}

function preview_pdf( $input ): array|\WP_Error {
	return preview( 'replace_pdf', $input, array( 'file_block', 'attachment_id', 'label' ) );
}

function preview_date( $input ): array|\WP_Error {
	return preview( 'set_draft_date', $input, array( 'date_local' ) );
}

function preview_publish( $input ): array|\WP_Error {
	return preview( 'publish', $input, array() );
}

function preview( string $operation, $input, array $keys, bool $editorial = false ): array|\WP_Error {
	$checked = check( $input, array_merge( array( 'id', 'state_hash' ), $editorial ? array( 'kind' ) : array(), $keys ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$kind = $editorial ? ( $input['kind'] ?? null ) : 'post';
	if ( ! in_array( $kind, array( 'post', 'page' ), true ) ) {
		return RestTools\error( 'target', 'Choose post or page and its exact ID.' );
	}
	$state = snapshot( $input['id'], $kind, $editorial );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	$hash = $state['review']['state_hash'];
	if ( ! is_string( $input['state_hash'] ?? null ) || ! hash_equals( $hash, $input['state_hash'] ) ) {
		return RestTools\error( 'stale', 'The post changed. Read its workflow details again.', 409 );
	}
	$parameters = array_intersect_key( $input, array_flip( $keys ) );
	$plan       = plan( $operation, $state, $parameters );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	$saved = RestTools\save_preview(
		array(
			'operation'  => $operation,
			'kind'       => $kind,
			'id'         => $input['id'],
			'state_hash' => $hash,
			'changes'    => $parameters,
			'review'     => hash( 'sha256', wp_json_encode( $plan['review'] ) ),
		)
	);
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return array(
		'site_url'  => Connector\bound_site(),
		'operation' => $operation,
		'post'      => $plan['review'],
		'message'   => 'Preview only. Review the exact change with the user before applying. A new preview replaces your previous post or metadata preview.',
	) + $saved;
}

function apply_edit( $input ): array|\WP_Error {
	return apply( $input, 'approved', array( 'replace_pdf', 'set_draft_date' ) );
}

function apply_publish( $input ): array|\WP_Error {
	return apply( $input, 'confirm_publish', array( 'publish', 'publish_page' ) );
}

function apply( $input, string $approval, array $operations ): array|\WP_Error {
	$checked = check( $input, array( 'preview_token', $approval ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	if ( true !== ( $input[ $approval ] ?? null ) ) {
		return RestTools\error( 'approval', 'Get explicit approval for the exact preview before applying.' );
	}
	$saved = RestTools\load_preview( $input['preview_token'] ?? null );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$operation = $saved['operation'] ?? '';
	if ( ! in_array( $operation, $operations, true ) ) {
		return RestTools\error( 'token', 'Use the apply tool for the operation named in the preview.', 409 );
	}
	$id        = $saved['id'];
	$kind      = $saved['kind'];
	$editorial = in_array( $operation, array( 'publish_page', 'schedule', 'cancel_schedule', 'restore_revision' ), true );
	$lock      = RestTools\lock_item( $kind, $id );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	try {
		wp_cache_delete( $id, 'posts' );
		$state = snapshot( $id, $kind, $editorial );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( ! hash_equals( $saved['state_hash'], $state['review']['state_hash'] ) ) {
			return RestTools\error( 'stale', 'The post changed after review. Prepare a fresh preview.', 409 );
		}
		$plan = plan( $operation, $state, $saved['changes'] );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! hash_equals( $saved['review'], hash( 'sha256', wp_json_encode( $plan['review'] ) ) ) ) {
			return RestTools\error( 'stale', 'The reviewed change is stale. Prepare a fresh preview.', 409 );
		}
		if ( ! RestTools\consume_preview( $saved ) ) {
			return RestTools\error( 'token', 'The preview was replaced, discarded or used.', 409 );
		}

		$review = commit( $state['post'], $plan, $editorial );
		RestTools\audit( $saved, is_wp_error( $review ) ? 'error' : 'verified' );
		return $review;
	} finally {
		RestTools\conditional_delete( $lock[0], $lock[1] );
	}
}

/** Keep native REST validation, permission callbacks, content filtering and save hooks. */
function commit( \WP_Post $post, array $plan, bool $editorial ): array|\WP_Error {
	$id        = $post->ID;
	$protected = array_intersect_key(
		get_object_vars( $post ),
		array_flip( array( 'post_content', 'post_status', 'post_date', 'post_date_gmt', 'post_title', 'post_excerpt', 'post_type', 'post_author', 'post_password', 'post_parent' ) )
	);
	$expected  = $plan['expected'] + $protected;
	$request   = new \WP_REST_Request( 'POST', RestTools\route( $post->post_type, $id ) );
	$request->set_body_params( $plan['body'] );

	// Preserve the reviewed date through native saves, including undated drafts.
	$native_save = false;
	$prepare     = static function ( $prepared, $incoming ) use ( $request, &$native_save ) {
		if ( $incoming === $request && is_object( $prepared ) && ! is_wp_error( $prepared ) ) {
			$native_save         = true;
			$prepared->edit_date = true;
		}
		return $prepared;
	};
	// WordPress can reserialise block delimiters. Keep the reviewed bytes only
	// when the native result has exactly the same parsed blocks and inner HTML.
	$preserve_blocks = static function ( $data, $post_array ) use ( $id, $expected, &$native_save ) {
		if ( $native_save && (int) ( $post_array['ID'] ?? 0 ) === $id ) {
			$content = wp_unslash( $data['post_content'] );
			if ( $content !== $expected['post_content'] && parse_blocks( $content ) === parse_blocks( $expected['post_content'] ) ) {
				$data['post_content'] = wp_slash( $expected['post_content'] );
			}
		}
		return $data;
	};
	$verify          = static function ( $post_id, $data ) use ( $id, $post, $expected, $plan ) {
		if ( $id !== $post_id ) {
			return;
		}
		wp_cache_delete( $id, 'posts' );
		$stored = get_post( $id );
		if ( ! $stored || get_object_vars( $stored ) !== get_object_vars( $post ) || wp_get_post_categories( $id ) !== $plan['review']['categories'] ) {
			throw new \DomainException( 'Post changed at save' );
		}
		if ( isset( $plan['minimum_schedule_timestamp'] ) && $plan['minimum_schedule_timestamp'] < time() + MINUTE_IN_SECONDS ) {
			throw new \DomainException( 'The schedule is too close' );
		}
		foreach ( $expected as $key => $value ) {
			if ( (string) ( $data[ $key ] ?? '' ) !== (string) $value ) {
				throw new \DomainException( 'Native save would change ' . $key );
			}
		}
	};

	$prepare_hook = 'rest_pre_insert_' . $post->post_type;
	add_filter( $prepare_hook, $prepare, PHP_INT_MAX, 2 );
	add_filter( 'wp_insert_post_data', $preserve_blocks, PHP_INT_MAX, 2 );
	add_action( 'pre_post_update', $verify, PHP_INT_MAX, 2 );
	try {
		$response = rest_do_request( $request );
	} catch ( \DomainException $e ) {
		return RestTools\error( 'post_guard', 'WordPress would save a different result or the post changed. Check the post and prepare a fresh preview.', 409 );
	} catch ( \Throwable $e ) {
		return RestTools\error( 'post_save', 'WordPress did not complete the save cleanly. Read the post before retrying.', 500 );
	} finally {
		remove_filter( $prepare_hook, $prepare, PHP_INT_MAX );
		remove_filter( 'wp_insert_post_data', $preserve_blocks, PHP_INT_MAX );
		remove_action( 'pre_post_update', $verify, PHP_INT_MAX );
	}
	if ( is_wp_error( $response ) || $response->get_status() >= 400 ) {
		return RestTools\error( 'post_save', 'WordPress refused or could not complete the save. Read the post before retrying.', is_wp_error( $response ) ? 500 : $response->get_status() );
	}
	wp_cache_delete( $id, 'posts' );
	$after = snapshot( $id, $post->post_type, $editorial );
	if ( is_wp_error( $after ) ) {
		return $after;
	}
	foreach ( $expected as $key => $value ) {
		if ( $after['post']->$key !== $value ) {
			return RestTools\error( 'post_read_back', 'WordPress saved different values. Read the post before making another change.', 409 );
		}
	}
	if ( $after['review']['categories'] !== $plan['review']['categories'] ) {
		return RestTools\error( 'post_read_back', 'The post categories changed during the save. Review the post.', 409 );
	}
	if ( array_key_exists( 'schedule_timestamp', $plan ) ) {
		$event = wp_next_scheduled( 'publish_future_post', array( $id ) );
		if ( $event !== $plan['schedule_timestamp'] ) {
			return RestTools\error( 'post_read_back', 'The status was saved but the WordPress publication event could not be verified. Check the post and WP-Cron before retrying.', 409 );
		}
	}
	return array(
		'site_url' => Connector\bound_site(),
		'post'     => $after['review'],
		'verified' => true,
	)
		+ ( 'publish' === $after['post']->post_status ? array( 'permalink' => get_permalink( $id ) ) : array() );
}

function register_abilities(): void {
	if ( ! function_exists( 'wp_register_ability' ) || ! Connector\feature_enabled( 'rest_tools' ) || ! Connector\feature_enabled( 'generatepress_bridge' ) ) {
		return;
	}
	$site    = array(
		'site_url' => array(
			'type' => 'string',
			'enum' => array( Connector\bound_site() ),
		),
	);
	$id      = array(
		'type'    => 'integer',
		'minimum' => 1,
	);
	$token   = array(
		'type'    => 'string',
		'pattern' => '^[a-f0-9]{64}$',
	);
	$target  = $site + array( 'id' => $id );
	$state   = $target + array( 'state_hash' => $token );
	$approve = array(
		'type'  => 'boolean',
		'const' => true,
	);
	$apply   = $site + array( 'preview_token' => $token );

	$tools = array(
		'get-post-workflow'       => array(
			'Read a post for the reviewed workflow',
			'Read an editable post or draft, its saved site-local date, category IDs, numbered File blocks with stored links, and state_hash. File blocks are numbered from 1 in document order, including inside groups.',
			'get_post_workflow',
			$target,
		),
		'preview-pdf-replacement' => array(
			'Preview a PDF File block replacement',
			'Preview updating one numbered core/file block using a same-site Media Library PDF ID and a descriptive plain-text label. Preserve preview height, other blocks and post status. Use latest get-post-workflow state_hash.',
			'preview_pdf',
			$state + array(
				'file_block'    => $id,
				'attachment_id' => $id,
				'label'         => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 300,
				),
			),
		),
		'preview-draft-date'      => array(
			'Preview saving a draft publication date',
			'Preview saving an explicit site-local date YYYY-MM-DD (midnight) or YYYY-MM-DDTHH:MM:SS on a draft. Past dates are allowed. The draft stays a draft. Use latest get-post-workflow state_hash.',
			'preview_date',
			$state + array(
				'date_local' => array(
					'type'      => 'string',
					'minLength' => 10,
					'maxLength' => 19,
				),
			),
		),
		'apply-post-edit'         => array(
			'Apply an approved PDF replacement or draft date',
			'Apply only the approved PDF replacement or draft date preview. This cannot publish or schedule a post. Review the stored result returned by WordPress.',
			'apply_edit',
			$apply + array( 'approved' => $approve ),
		),
		'preview-draft-publish'   => array(
			'Preview immediate public publication of a draft',
			'Preview publishing one draft immediately using its saved current or past site-local date. Show the exact website, post ID, title, category IDs, date and File blocks to the user. Obtain an explicit instruction to publish this preview.',
			'preview_publish',
			$state,
		),
		'apply-draft-publish'     => array(
			'Publish the explicitly confirmed draft post or page',
			'PUBLISH: Immediately publish the exact draft post or page in an approved publication preview. Requires explicit confirmation of the post, saved date and PDF where present. Rechecks current WordPress permissions and reviewed state. Returns the verified published post and permalink.',
			'apply_publish',
			$apply + array( 'confirm_publish' => $approve ),
		),
	);
	foreach ( $tools as $name => $definition ) {
		list( $label, $description, $callback, $properties ) = $definition;
		$readonly = 'get-post-workflow' === $name;
		wp_register_ability(
			'church-connector/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'church-connector',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => array_keys( $properties ),
					'additionalProperties' => false,
				),
				'permission_callback' => __NAMESPACE__ . '\\permission',
				'execute_callback'    => __NAMESPACE__ . '\\' . $callback,
				'meta'                => array(
					'show_in_rest' => false,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => $readonly,
						'destructive' => str_starts_with( $name, 'apply-' ),
						'idempotent'  => $readonly,
					),
				),
			)
		);
	}
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities' );
