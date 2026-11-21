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
	$item = RestTools\read_item( $kind, $id, true );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	$post = get_post( $id );
	if ( ! $post instanceof \WP_Post || ! in_array( $post->post_status, $editorial ? array( 'draft', 'publish', 'future' ) : array( 'draft', 'publish' ), true ) ) {
		return RestTools\error( 'post_status', 'Choose an editable ordinary post or page with a supported status.', 409 );
	}
	$files = PostFiles\content_files( $post->post_content );
	if ( is_wp_error( $files ) ) {
		return $files;
	}

	$review = array(
		'id'         => $id,
		'kind'       => $kind,
		'title'      => $post->post_title,
		'slug'       => $post->post_name,
		'status'     => $post->post_status,
		'date_local' => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : str_replace( ' ', 'T', $post->post_date ),
		'date_gmt'   => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : str_replace( ' ', 'T', $post->post_date_gmt ) . 'Z',
		'timezone'   => wp_timezone_string(),
		'categories' => $item['fields']['categories'] ?? array(),
		'state_hash' => hash( 'sha256', $item['state_hash'] . $post->post_date . wp_timezone_string() ),
	) + PostFiles\describe_content( $files );
	if ( isset( $review['synced_patterns'] ) || isset( $review['pattern_review_issue'] ) ) {
		$review['state_hash'] = hash( 'sha256', $review['state_hash'] . wp_json_encode( $review['synced_patterns'] ?? $review['pattern_review_issue'] ) );
	}
	if ( $editorial && 'future' === $post->post_status ) {
		$event                          = wp_next_scheduled( 'publish_future_post', array( $id ) );
		$review['wp_cron_scheduled_at'] = false === $event ? null : gmdate( 'Y-m-d\TH:i:s\Z', $event );
		$review['state_hash']           = hash( 'sha256', $review['state_hash'] . wp_json_encode( $event ) );
	}
	return array(
		'post'   => $post,
		'review' => $review,
	) + $files;
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
	$stamp       = $date->getTimestamp();
	$transitions = $zone->getTransitions( $stamp - DAY_IN_SECONDS, $stamp + DAY_IN_SECONDS );
	// Fixed UTC offsets have no transition table and cannot repeat a local time.
	foreach ( false === $transitions ? array() : $transitions as $transition ) {
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
		if ( 'future' === $post->post_status ) {
			$due   = get_post_datetime( $post, 'date', 'gmt' );
			$event = wp_next_scheduled( 'publish_future_post', array( $post->ID ) );
			if ( ! $due || $event !== $due->getTimestamp() || $event < time() + MINUTE_IN_SECONDS ) {
				return RestTools\error( 'post_status', 'Review the scheduled publication before correcting this file.', 409 );
			}
		}
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
		$targets = PostFiles\publication_targets( $ranges[ $number - 1 ], $number, $pdf['url'] );
		if ( is_wp_error( $targets ) ) {
			return RestTools\error( 'pdf_block', 'The replacement still contains a destination that needs review in WordPress.', 409 );
		}
		$review['change'] = array(
			'before'       => $review['file_blocks'][ $number - 1 ],
			'after'        => PostFiles\describe( $ranges[ $number - 1 ], $number ),
			'pdf_sha256'   => $pdf['sha256'],
			'after_status' => $post->post_status,
		);

		$plan = array(
			'review'   => $review,
			'body'     => array( 'content' => $content ),
			'expected' => array( 'post_content' => $content ),
			'pdf'      => $pdf,
		);
		if ( isset( $event ) ) {
			$plan['minimum_schedule_timestamp'] = $event;
			$plan['schedule_timestamp']         = $event;
		}
		return $plan;
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
	$review = review_files( $state );
	if ( is_wp_error( $review ) ) {
		return $review;
	}
	$review['change'] = array(
		'after_status'   => 'publish',
		'visibility'     => 'public',
		'publish_timing' => 'immediate',
	);
	return array(
		'review'       => $review,
		'body'         => array( 'status' => 'publish' ),
		'expected'     => array( 'post_status' => 'publish' ),
		'verify_files' => true,
	);
}

function review_files( array $state ): array|\WP_Error {
	$review = $state['review'];
	if ( is_wp_error( $state['patterns'] ?? null ) ) {
		return $state['patterns'];
	}
	$media    = array();
	$verified = verify_blocks( $review['file_blocks'], $state['ranges'], $media );
	if ( is_wp_error( $verified ) ) {
		return $verified;
	}
	$review['file_blocks'] = $verified;
	foreach ( $review['synced_patterns'] ?? array() as $index => $pattern ) {
		$verified = verify_blocks( $pattern['file_blocks'], $state['patterns'][ $pattern['id'] ]['ranges'], $media );
		if ( is_wp_error( $verified ) ) {
			$message = 'A File block in a synced pattern needs review in the WordPress pattern editor before publication.';
			$private = RestTools\review_response( $verified );
			$details = array(
				'status'     => 409,
				'pattern_id' => $pattern['id'],
			) + RestTools\review_details( $verified );
			return RestTools\review_error( 'church_connector_rest_pattern_file', $message, $message . ' ' . ( $private['message'] ?? $verified->get_error_message() ), $details );
		}
		$review['synced_patterns'][ $index ]['file_blocks'] = $verified;
	}
	return $review;
}

function verify_blocks( array $blocks, array $ranges, array &$media ): array|\WP_Error {
	foreach ( $blocks as $index => $file ) {
		$id = $file['attachment_id'];
		if ( ! $id ) {
			continue;
		}
		if ( ! array_key_exists( $id, $media ) ) {
			wp_cache_delete( $id, 'posts' );
			wp_cache_delete( $id, 'post_meta' );
			$attachment = get_post( $id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type || 'trash' === $attachment->post_status ) {
				return RestTools\error( 'file_reference', sprintf( 'File block %d refers to an unavailable Media Library attachment. Choose the intended file and preview a replacement before publishing.', $file['file_block'] ), 409 );
			}
			$media[ $id ] = 'application/pdf' === $attachment->post_mime_type ? PostFiles\attachment( $id ) : null;
		}
		$pdf = $media[ $id ];
		if ( null === $pdf ) {
			continue;
		}
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		$targets = PostFiles\publication_targets( $ranges[ $index ], $file['file_block'], $pdf['url'] );
		if ( is_wp_error( $targets ) ) {
			return $targets;
		}
		$blocks[ $index ]                      = array_replace( $file, $targets );
		$blocks[ $index ]['media_library_pdf'] = $pdf;
	}
	return $blocks;
}

function preview_pdf( $input ): array|\WP_Error {
	return preview( 'replace_pdf', $input, array( 'file_block', 'attachment_id', 'label' ), true );
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
	$kind = $editorial ? ( $input['kind'] ?? ( 'replace_pdf' === $operation ? 'post' : null ) ) : 'post';
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
	return prepare_review( $operation, $state, $parameters );
}

/** Return saved source for short, static wording. Never execute draft blocks or shortcodes to build a review. */
function publication_wording( \WP_Post $post ): array {
	$body    = $post->post_content;
	$excerpt = $post->post_excerpt;
	$review  = array(
		'mode'        => 'wordpress',
		'preview_url' => get_preview_post_link( $post ),
		'edit_url'    => get_edit_post_link( $post->ID, 'raw' ),
	);
	if ( strlen( $excerpt ) > 2000 ) {
		return $review;
	}
	$review['excerpt_source'] = $excerpt;
	if ( strlen( $body ) + strlen( $excerpt ) > 4000 || str_contains( $body . $excerpt, '[' ) ) {
		return $review;
	}
	$blocks = parse_blocks( $body . "\n" . $excerpt );
	while ( $blocks ) {
		$block = array_pop( $blocks );
		if ( ! in_array( $block['blockName'], array( null, 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/file' ), true )
			|| ! empty( $block['attrs']['metadata']['bindings'] ) ) {
			return $review;
		}
		array_push( $blocks, ...$block['innerBlocks'] );
	}
	$html = new \WP_HTML_Tag_Processor( $body . "\n" . $excerpt );
	while ( $html->next_tag() ) {
		if ( 'OBJECT' === $html->get_tag() && 'application/pdf' === $html->get_attribute( 'type' ) ) {
			continue;
		}
		if ( ! in_array( $html->get_tag(), array( 'P', 'BR', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'UL', 'OL', 'LI', 'B', 'I', 'STRONG', 'EM', 'A', 'SPAN', 'DIV' ), true ) ) {
			return $review;
		}
	}
	$review['mode']        = 'inline';
	$review['body_source'] = $body;
	return $review;
}

/** Reuse the normal plan and staging path for a fresh preview or a proposed correction. */
function prepare_review( string $operation, array $state, array $parameters, ?array $replaces = null ): array|\WP_Error {
	$plan = plan( $operation, $state, $parameters );
	if ( is_wp_error( $plan ) ) {
		return 'church_connector_rest_pdf_target' === $plan->get_error_code() ? offer_pdf_correction( $plan, $state, $replaces ) : $plan;
	}
	$publication = in_array( $operation, array( 'publish', 'publish_page', 'schedule' ), true );
	$body        = $state['post']->post_content;
	$body_guard  = array();
	$comparison  = null;
	if ( $publication ) {
		$body_guard['publication_wording_reviewed'] = true;

		$patterns = wp_list_pluck( $state['review']['synced_patterns'] ?? array(), 'content_hash', 'id' );
		if ( null !== $replaces && ( $replaces['publication_patterns'] ?? array() ) !== $patterns ) {
			return RestTools\error( 'pattern_review', 'A referenced synced pattern changed. Review its current content in WordPress, then prepare a new publication preview.', 409 );
		}
		if ( $patterns ) {
			$body_guard['publication_patterns'] = $patterns;
		}
		$body_guard['publication_body_hash'] = hash( 'sha256', $body );
		if ( strlen( $body ) <= 16000 ) {
			$body_guard['publication_body'] = $body;
		}
		if ( null !== $replaces && ( $replaces['publication_body_hash'] ?? null ) !== $body_guard['publication_body_hash'] ) {
			if ( ! isset( $replaces['publication_body'], $body_guard['publication_body'] ) ) {
				$message = 'The previous body comparison is incomplete. Review the current content in WordPress before requesting a fresh publication preview.';
				return RestTools\review_error(
					'church_connector_rest_publication_body_review',
					$message,
					$message,
					array(
						'status'   => 409,
						'edit_url' => get_edit_post_link( $state['post']->ID, 'raw' ),
					)
				);
			}
			$comparison = array(
				'before' => $replaces['publication_body'],
				'after'  => $body,
			);
		}
	}
	$block_hash = 'replace_pdf' === $operation ? file_block_hash( $state, $parameters['file_block'] ) : null;
	$saved      = RestTools\save_preview(
		array(
			'operation'  => $operation,
			'kind'       => $state['review']['kind'],
			'id'         => $state['review']['id'],
			'state_hash' => $state['review']['state_hash'],
			'changes'    => $parameters,
			'review'     => hash( 'sha256', wp_json_encode( $plan['review'] ) ),
			'block_hash' => $block_hash,
		) + $body_guard,
		$replaces
	);
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$message = 'Preview only. Review the exact change with the user before applying. A new preview replaces your previous post or metadata preview.';
	if ( $publication ) {
		$plan['review']['wording_review'] = publication_wording( $state['post'] );

		$message .= 'inline' === $plan['review']['wording_review']['mode']
			? ' Show the complete saved wording and any saved excerpt in readable form, together with the publication details, before asking for approval.'
			: ' Give the user the signed-in WordPress preview link to check the complete draft, including embedded or shared content. Show any supplied excerpt. Use the editor link to check the saved excerpt or if the preview is unavailable. Ask for approval of that review.';
	}
	if ( null !== $comparison ) {
		$plan['review']['body_comparison'] = $comparison;
	}
	return array(
		'site_url'  => Connector\bound_site(),
		'operation' => $operation,
		'post'      => $plan['review'],
		'message'   => $message . ( empty( $plan['review']['synced_patterns'] ) ? '' : ' Review the File blocks in each named synced pattern as well as the post’s own File blocks.' ),
	) + $saved;
}

function file_block_hash( array $state, int $number ): ?string {
	$file = $state['ranges'][ $number - 1 ] ?? null;
	if ( ! $file ) {
		return null;
	}
	foreach ( $state['ranges'] as $index => $other ) {
		if ( $index !== $number - 1 && $file['attrs'] === $other['attrs'] && $file['html'] === $other['html'] ) {
			return null;
		}
	}
	return hash( 'sha256', wp_json_encode( array( $file['attrs'], $file['html'] ) ) );
}

function offer_pdf_correction( \WP_Error $error, array $state, ?array $replaces ): \WP_Error {
	$private = RestTools\review_response( $error );
	if ( ! $private ) {
		return $error;
	}
	$data = $private['data'];
	$file = $state['review']['file_blocks'][ $data['file_block'] - 1 ];
	$args = array(
		'file_block'    => $file['file_block'],
		'attachment_id' => $file['attachment_id'],
		'label'         => $file['label'],
	);
	$next = prepare_review( 'replace_pdf', $state, $args, $replaces );
	if ( is_wp_error( $next ) ) {
		$data['edit_url'] = get_edit_post_link( $state['post']->ID, 'raw' );
		return RestTools\review_error( $error->get_error_code(), $error->get_error_message(), $private['message'] . ' Review this block in the WordPress editor. ' . $next->get_error_message(), $data );
	}
	$data['correction_preview'] = $next;
	$message                    = ' Confirm which document is intended. Review the separate correction preview before applying it with apply-post-edit. If the preview is missing or another file is intended, use preview-pdf-replacement with its Media Library ID. Review publication again after correction.';
	return RestTools\review_error( $error->get_error_code(), $error->get_error_message(), $private['message'] . $message, $data );
}

function refresh_review( string $operation, array $state, array $saved ): \WP_Error {
	$error = RestTools\error( 'stale', 'The post or its reviewed files changed after preview.', 409 );
	if ( 'replace_pdf' === $operation && ( empty( $saved['block_hash'] ) || file_block_hash( $state, $saved['changes']['file_block'] ) !== $saved['block_hash'] ) ) {
		RestTools\consume_preview( $saved );
		return new \WP_Error( $error->get_error_code(), 'The selected File block changed or moved. Identify the intended block again before preparing a replacement.', array( 'status' => 409 ) );
	}
	return RestTools\refreshed_error( $error, prepare_review( $operation, $state, $saved['changes'], $saved ), $saved );
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
	$editorial = in_array( $operation, array( 'replace_pdf', 'publish_page', 'schedule', 'cancel_schedule', 'restore_revision' ), true );
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
			return refresh_review( $operation, $state, $saved );
		}
		// Pre-upgrade publication previews did not ask the user to review the wording.
		if ( in_array( $operation, array( 'publish', 'publish_page', 'schedule' ), true ) && empty( $saved['publication_wording_reviewed'] ) ) {
			return refresh_review( $operation, $state, $saved );
		}
		$plan = plan( $operation, $state, $saved['changes'] );
		if ( is_wp_error( $plan ) ) {
			return 'church_connector_rest_pdf_target' === $plan->get_error_code() ? offer_pdf_correction( $plan, $state, $saved ) : $plan;
		}
		if ( ! hash_equals( $saved['review'], hash( 'sha256', wp_json_encode( $plan['review'] ) ) ) ) {
			return refresh_review( $operation, $state, $saved );
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

/** Reopen the actual saved patterns and Media Library files at the native write boundary. */
function file_dependencies_match( array $plan, string $content ): bool {
	if ( isset( $plan['pdf'] ) ) {
		$id = $plan['pdf']['attachment_id'];
		wp_cache_delete( $id, 'posts' );
		wp_cache_delete( $id, 'post_meta' );
		return PostFiles\attachment( $id ) === $plan['pdf'];
	}
	if ( empty( $plan['verify_files'] ) || ( empty( $plan['review']['file_blocks'] ) && empty( $plan['review']['synced_patterns'] ) ) ) {
		return true;
	}
	$files = PostFiles\content_files( $content );
	if ( is_wp_error( $files ) ) {
		return false;
	}
	$current = review_files( array( 'review' => PostFiles\describe_content( $files ) ) + $files );
	return ! is_wp_error( $current ) && $current['file_blocks'] === $plan['review']['file_blocks'] && ( $current['synced_patterns'] ?? array() ) === ( $plan['review']['synced_patterns'] ?? array() );
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

	$conflict = false;
	$blocked  = false;
	$veto     = static function ( $sql ) use ( $id, $expected, $plan, &$blocked, &$conflict ) {
		if ( ! str_starts_with( ltrim( $sql ), 'UPDATE ' ) || ! RestTools\is_post_update_query( $id ) ) {
			return $sql;
		}
		if ( $conflict || ! file_dependencies_match( $plan, $expected['post_content'] ) || ( isset( $plan['preserve_event'] ) && wp_next_scheduled( 'publish_future_post', array( $id ) ) !== $plan['preserve_event'] ) ) {
			$conflict = true;
			$blocked  = true;
			return '';
		}
		return $sql;
	};
	$verify   = static function ( $post_id, $data ) use ( $id, $post, $expected, $plan, $veto, &$conflict ) {
		if ( $id !== $post_id ) {
			return;
		}
		wp_cache_delete( $id, 'posts' );
		$stored   = get_post( $id );
		$conflict = ! $stored || get_object_vars( $stored ) !== get_object_vars( $post )
			|| wp_get_post_categories( $id ) !== $plan['review']['categories']
			|| ( isset( $plan['minimum_schedule_timestamp'] ) && $plan['minimum_schedule_timestamp'] < time() + MINUTE_IN_SECONDS );
		foreach ( $expected as $key => $value ) {
			if ( (string) ( $data[ $key ] ?? '' ) !== (string) $value ) {
				$conflict = true;
				break;
			}
		}
		add_filter( 'query', $veto, PHP_INT_MAX );
	};

	$prepare_hook = 'rest_pre_insert_' . $post->post_type;
	add_filter( $prepare_hook, $prepare, PHP_INT_MAX, 2 );
	add_filter( 'wp_insert_post_data', $preserve_blocks, PHP_INT_MAX, 2 );
	add_action( 'pre_post_update', $verify, PHP_INT_MAX, 2 );
	try {
		$response = rest_do_request( $request );
	} catch ( \Throwable $e ) {
		return RestTools\error( 'post_save', 'WordPress did not complete the save cleanly. Read the post before retrying.', 500 );
	} finally {
		remove_filter( $prepare_hook, $prepare, PHP_INT_MAX );
		remove_filter( 'wp_insert_post_data', $preserve_blocks, PHP_INT_MAX );
		remove_action( 'pre_post_update', $verify, PHP_INT_MAX );
		remove_filter( 'query', $veto, PHP_INT_MAX );
	}
	if ( $conflict ) {
		return $blocked
			? RestTools\error( 'post_guard', 'WordPress would save a different result or the post changed. Check the post and prepare a fresh preview.', 409 )
			: RestTools\error( 'post_read_back', 'A customised save could not be verified. Inspect the stored post before retrying.', 409 );
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
	if ( ! file_dependencies_match( $plan, $after['post']->post_content ) ) {
		return RestTools\error( 'post_read_back', 'A reviewed file or synced pattern changed during the save. Inspect the post and its publication status before retrying.', 409 );
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
			'Preview updating one numbered core/file block using a same-site Media Library PDF ID and a descriptive plain-text label. Preserve preview height, other blocks and post status. Use the latest get-post-workflow hash for posts or get-publication-workflow hash for pages and scheduled items.',
			'preview_pdf',
			$state + array(
				'kind'          => array(
					'type' => 'string',
					'enum' => array( 'post', 'page' ),
				),
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
			'Preview saving an explicit site-local date YYYY-MM-DD (midnight) or YYYY-MM-DDTHH:MM:SS on a draft. Past dates are allowed. The draft stays a draft. Use the latest get-post-workflow hash for posts or get-publication-workflow hash for pages and scheduled items.',
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
			'Preview publishing one draft immediately using its saved site-local date. Follow wording_review for the complete wording and saved excerpt. Show the site, post ID, title, categories, date and File blocks. Obtain explicit approval for this preview.',
			'preview_publish',
			$state,
		),
		'apply-draft-publish'     => array(
			'Publish the explicitly confirmed draft post or page',
			'PUBLISH: Immediately publish the exact draft post or page after approval of the wording, any saved excerpt, publication date and files shown in its preview. Rechecks current WordPress permissions and reviewed state. Returns the verified published item and permalink.',
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
					'required'             => array_values( array_diff( array_keys( $properties ), array( 'kind' ) ) ),
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
