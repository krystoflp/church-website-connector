<?php
/** Native searches, scheduling, page publication and revision review. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Editorial;

use ChurchWebsiteConnector as Connector;
use ChurchWebsiteConnector\PostFiles;
use ChurchWebsiteConnector\PostTools;
use ChurchWebsiteConnector\RestTools;

defined( 'ABSPATH' ) || exit;

function search_media( $input ): array|\WP_Error {
	return search( 'media', $input );
}

function search_posts( $input ): array|\WP_Error {
	return search( 'posts', $input );
}

function search( string $collection, $input ): array|\WP_Error {
	$media = 'media' === $collection;
	$extra = $media ? array( 'mime_type' ) : array( 'category_id', 'status' );
	$check = PostTools\check( $input, array_merge( array( 'query', 'after', 'before', 'page' ), $extra ) );
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	$page = $input['page'] ?? 1;
	if ( ! is_int( $page ) || $page < 1 || $page > 100 ) {
		return RestTools\error( 'search_page', 'Choose a results page from 1 to 100.' );
	}
	if ( $media && ! current_user_can( 'upload_files' ) ) {
		return RestTools\error( 'forbidden', 'Your account cannot access the Media Library.', 403 );
	}
	$params = array(
		'context'  => 'edit',
		'per_page' => 20,
		'page'     => $page,
		'orderby'  => 'date',
		'order'    => 'desc',
		'_fields'  => $media ? 'id,title,date,mime_type,source_url' : 'id,title,date,status,slug,categories,link',
	);
	if ( isset( $input['query'] ) ) {
		if ( ! is_string( $input['query'] ) || strlen( $input['query'] ) > 120 || '' === sanitize_text_field( $input['query'] ) ) {
			return RestTools\error( 'search_query', 'Enter a search term up to 120 bytes.' );
		}
		$params['search'] = sanitize_text_field( $input['query'] );
	}
	foreach ( array( 'after', 'before' ) as $bound ) {
		if ( isset( $input[ $bound ] ) ) {
			$date = PostTools\local_date( $input[ $bound ] );
			if ( is_wp_error( $date ) ) {
				return $date;
			}
			$params[ $bound ] = str_replace( ' ', 'T', $date['local'] );
		}
	}
	if ( isset( $params['after'], $params['before'] ) && $params['after'] >= $params['before'] ) {
		return RestTools\error( 'search_dates', 'Choose an end date after the start date.' );
	}
	if ( $media && isset( $input['mime_type'] ) ) {
		if ( ! is_string( $input['mime_type'] ) || ! preg_match( '~\A[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*\z~i', $input['mime_type'] ) ) {
			return RestTools\error( 'mime_type', 'Enter an exact MIME type, such as application/pdf.' );
		}
		$params['mime_type'] = array( strtolower( $input['mime_type'] ) );
	}
	if ( ! $media ) {
		$status = $input['status'] ?? 'publish';
		if ( ! in_array( $status, array( 'publish', 'draft', 'future', 'pending', 'private' ), true ) ) {
			return RestTools\error( 'search_status', 'Choose a supported post status.' );
		}
		$params['status'] = $status;
		if ( isset( $input['category_id'] ) ) {
			$id = $input['category_id'];
			if ( ! is_int( $id ) || $id < 1 || ! term_exists( $id, 'category' ) ) {
				return RestTools\error( 'search_category', 'Choose a category ID on this website.' );
			}
			$params['categories'] = array( $id );
		}
	}

	$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $collection );
	$request->set_query_params( $params );
	// Core treats both bounds as exclusive. Include midnight for date-only starts.
	$hook      = $media ? 'rest_attachment_query' : 'rest_post_query';
	$inclusive = static function ( $args, $incoming ) use ( $request ) {
		if ( $request === $incoming ) {
			foreach ( $args['date_query'] ?? array() as $index => $clause ) {
				if ( ( $clause['after'] ?? null ) === $request['after'] && 'post_date' === ( $clause['column'] ?? '' ) ) {
					$args['date_query'][ $index ]['inclusive'] = true;
				}
			}
		}
		return $args;
	};
	if ( isset( $input['after'] ) && 10 === strlen( $input['after'] ) ) {
		add_filter( $hook, $inclusive, 10, 2 );
	}
	try {
		$response = rest_do_request( $request );
	} finally {
		remove_filter( $hook, $inclusive, 10 );
	}
	if ( is_wp_error( $response ) || $response->get_status() >= 400 ) {
		return RestTools\error( 'search_denied', 'WordPress refused the search or this results page is unavailable.', is_wp_error( $response ) ? 500 : $response->get_status() );
	}
	$items = array();
	foreach ( $response->get_data() as $row ) {
		$id   = (int) ( $row['id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $id || ! $post || ( $media ? 'attachment' : 'post' ) !== $post->post_type || ! current_user_can( 'edit_post', $id )
			|| ( isset( $params['mime_type'] ) && ! in_array( $post->post_mime_type, $params['mime_type'], true ) ) ) {
			continue;
		}
		$item = array(
			'id'         => $id,
			'title'      => $post->post_title,
			'date_local' => $row['date'],
		);
		if ( $media ) {
			// Return the basename only, never the server's upload directory.
			$item['filename']  = wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );
			$item['mime_type'] = $row['mime_type'];
			$item['url']       = $row['source_url'];
		} else {
			$item['slug']       = $row['slug'];
			$item['status']     = $row['status'];
			$item['categories'] = $row['categories'];
			$item['permalink']  = 'publish' === $row['status'] ? $row['link'] : null;
		}
		$items[] = $item;
	}
	return array(
		'site_url'  => Connector\bound_site(),
		'timezone'  => wp_timezone_string(),
		'items'     => $items,
		'page'      => $page,
		'next_page' => $page < min( 100, (int) ( $response->get_headers()['X-WP-TotalPages'] ?? 0 ) ) ? $page + 1 : null,
	);
}

function state( $input, array $extra = array() ): array|\WP_Error {
	$checked = PostTools\check( $input, array_merge( array( 'id', 'kind' ), $extra ) );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}
	$kind = $input['kind'] ?? null;
	if ( ! in_array( $kind, array( 'post', 'page' ), true ) ) {
		return RestTools\error( 'target', 'Choose post or page and its exact ID.' );
	}
	return PostTools\snapshot( $input['id'], $kind, true );
}

function get_publication( $input ): array|\WP_Error {
	$state = state( $input );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	$review = $state['review'];
	return array(
		'site_url' => Connector\bound_site(),
		'post'     => $review,
	);
}

function preview_page( $input ): array|\WP_Error {
	return PostTools\preview( 'publish_page', $input, array( 'date_local' ), true );
}

function preview_schedule( $input ): array|\WP_Error {
	return PostTools\preview( 'schedule', $input, array( 'date_local' ), true );
}

function preview_cancel( $input ): array|\WP_Error {
	return PostTools\preview( 'cancel_schedule', $input, array(), true );
}

function apply_schedule( $input ): array|\WP_Error {
	return PostTools\apply( $input, 'confirm_schedule_change', array( 'schedule', 'cancel_schedule' ) );
}

function compare_revision( $input ): array|\WP_Error {
	$state = state( $input, array( 'revision_id' ) );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	$plan = revision( $state, $input['revision_id'] ?? null );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	return array(
		'site_url' => Connector\bound_site(),
		'post'     => $plan['review'],
	);
}

function preview_revision( $input ): array|\WP_Error {
	return PostTools\preview( 'restore_revision', $input, array( 'revision_id' ), true );
}

function apply_revision( $input ): array|\WP_Error {
	return PostTools\apply( $input, 'confirm_restore', array( 'restore_revision' ) );
}

function plan( string $operation, array $state, array $input ): array|\WP_Error {
	if ( 'restore_revision' === $operation ) {
		$plan = revision( $state, $input['revision_id'] ?? null, true );
		return is_array( $plan ) && ! $plan['body'] ? RestTools\error( 'unchanged', 'The selected revision matches the current fields.', 409 ) : $plan;
	}
	$post = $state['post'];
	$type = get_post_type_object( $post->post_type );
	if ( ! $type || ! current_user_can( $type->cap->publish_posts ) ) {
		return RestTools\error( 'post_publish_permission', 'Your WordPress account cannot manage publication for this item.', 403 );
	}
	$cancel = 'cancel_schedule' === $operation;
	$page   = 'publish_page' === $operation;
	if ( ( $cancel && 'future' !== $post->post_status )
		|| ( $page && ( 'page' !== $post->post_type || 'draft' !== $post->post_status ) )
		|| ( ! $cancel && ! $page && ! in_array( $post->post_status, array( 'draft', 'future' ), true ) ) ) {
		return RestTools\error( 'publication_status', 'Choose a draft or scheduled item appropriate to this operation.', 409 );
	}
	if ( ! $cancel && '' !== $post->post_password ) {
		return RestTools\error( 'post_visibility', 'Use the WordPress editor to manage publication of a password-protected item.', 409 );
	}

	$review = $cancel ? $state['review'] : PostTools\review_files( $state );
	if ( is_wp_error( $review ) ) {
		return $review;
	}
	// Cancelling uses the already reviewed stored instant, including repeated local times.
	$date = $cancel ? array(
		'local' => $post->post_date,
		'gmt'   => $post->post_date_gmt,
	) : PostTools\local_date( $input['date_local'] ?? null );
	if ( is_wp_error( $date ) ) {
		return $date;
	}
	$status = $cancel ? 'draft' : ( $page ? 'publish' : 'future' );
	if ( ( $page && $date['timestamp'] > time() ) || ( 'future' === $status && $date['timestamp'] < time() + MINUTE_IN_SECONDS ) ) {
		return RestTools\error( 'publication_date', $page ? 'Choose a current or past publication time for immediate page publication.' : 'Schedule publication at least one minute from now.', 409 );
	}
	$local            = str_replace( ' ', 'T', $date['local'] );
	$gmt              = str_replace( ' ', 'T', $date['gmt'] ) . 'Z';
	$review['change'] = array(
		'after_status' => $status,
		'date_local'   => $local,
		'date_gmt'     => $gmt,
	);
	$plan             = array(
		'review'   => $review,
		'body'     => array(
			'status'   => $status,
			'date_gmt' => $gmt,
		),
		'expected' => array(
			'post_status'   => $status,
			'post_date'     => $date['local'],
			'post_date_gmt' => $date['gmt'],
		),
	);
	if ( $cancel ) {
		unset( $plan['body']['date_gmt'] );
	} else {
		$plan['verify_files'] = true;
	}
	if ( ! $page ) {
		$plan['schedule_timestamp'] = $cancel ? false : $date['timestamp'];
	}
	if ( 'future' === $status ) {
		$plan['minimum_schedule_timestamp'] = $date['timestamp'];
	}
	return $plan;
}

function revision( array $state, mixed $revision_id, bool $for_restore = false ): array|\WP_Error {
	$post     = $state['post'];
	$revision = is_int( $revision_id ) && $revision_id > 0 ? wp_get_post_revision( $revision_id ) : null;
	if ( ! $revision || $revision->post_parent !== $post->ID || wp_is_post_autosave( $revision ) ) {
		return RestTools\error( 'revision', 'Choose a saved, non-autosave revision of this exact item.', 404 );
	}
	$body     = array();
	$expected = array();
	$diff     = array();
	foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
		$key = 'post_' . $field;
		if ( $post->$key === $revision->$key ) {
			continue;
		}
		if ( strlen( $post->$key ) + strlen( $revision->$key ) > 64000 ) {
			return RestTools\error( 'revision_size', 'This change is too large to review here. Compare it in the WordPress revision editor.', 409 );
		}
		$diff[ $field ]   = array(
			'before' => $post->$key,
			'after'  => $revision->$key,
		);
		$body[ $field ]   = $revision->$key;
		$expected[ $key ] = $revision->$key;
	}
	$review = $state['review'];
	if ( '' !== $post->post_password && 'publish' === $post->post_status ) {
		return RestTools\error( 'post_visibility', 'Use the WordPress editor to restore a revision of a password-protected item.', 409 );
	}
	if ( 'publish' === $post->post_status && ! current_user_can( get_post_type_object( $post->post_type )->cap->publish_posts ) ) {
		return RestTools\error( 'post_publish_permission', 'Your account cannot approve changes to this published item.', 403 );
	}
	$guards = array();
	if ( $for_restore && 'future' === $post->post_status ) {
		$due   = get_post_datetime( $post, 'date', 'gmt' );
		$event = wp_next_scheduled( 'publish_future_post', array( $post->ID ) );
		if ( ! $due || $event !== $due->getTimestamp() || $event < time() + MINUTE_IN_SECONDS ) {
			return RestTools\error( 'revision_schedule', 'Review the scheduled publication event with the scheduling workflow before restoring this revision.', 409 );
		}
		$guards['preserve_event']             = $event;
		$guards['schedule_timestamp']         = $event;
		$guards['minimum_schedule_timestamp'] = $event;
	}
	if ( isset( $body['content'] ) ) {
		$files = PostFiles\content_files( $revision->post_content );
		unset( $review['synced_patterns'], $review['pattern_review_issue'] );
		$review = array_replace( $review, is_wp_error( $files ) ? array( 'file_blocks' => array() ) : PostFiles\describe_content( $files ) );
		$public = in_array( $post->post_status, array( 'publish', 'future' ), true );
		if ( $public ) {
			$guards['verify_files'] = true;
			$files                  = is_wp_error( $files ) ? $files : PostTools\review_files( array( 'review' => $review ) + $files );
			if ( is_wp_error( $files ) ) {
				$message = 'The revision contains a file reference that needs review before restoring it to this post.';
				$private = RestTools\review_response( $files );
				$issue   = $message . ' ' . ( $private['message'] ?? $files->get_error_message() );
				if ( $for_restore ) {
					return RestTools\review_error( 'church_connector_rest_revision_file', $message, $issue, array( 'status' => 409 ) + RestTools\review_details( $files ) );
				}
				$review['file_review_issue'] = $issue;
			} else {
				$review = $files;
			}
		}
	}
	$review['change'] = array(
		'revision_id'  => $revision_id,
		'date_gmt'     => $revision->post_date_gmt,
		'diff'         => $diff,
		'after_status' => $post->post_status,
	);
	return array(
		'review'   => $review,
		'body'     => $body,
		'expected' => $expected,
	) + $guards;
}
