<?php
/** Native regression checks for metadata saves and the PDF shown in publication previews. */
require_once '/wordpress/wp-load.php';

if ( file_exists( '/provider-fixtures/GeneratePress-AI-MCP.php' ) ) {
	require_once '/provider-fixtures/GeneratePress-AI-MCP.php';
	require_once '/provider-fixtures/GeneratePress-AI-OAuth.php';
} else {
	// Public CI exercises registered abilities without distributing the provider.
	class GeneratePress_AI_MCP {}
	class GeneratePress_AI_OAuth {}
}
define( 'GENERATEPRESS_AI_VERSION', '1.0.0' );
wp_set_current_user( 1 );
$original_zone = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Europe/London' );
$checks = array();
function guard_assert( $ok, $label ) {
	if ( ! $ok ) {
		throw new RuntimeException( 'PUBLICATION: ' . $label );
	}
	$GLOBALS['checks'][] = $label;
}
function guard_call( $ability, $args ) {
	return wp_get_ability( 'church-connector/' . $ability )->execute( $args + array( 'site_url' => home_url() ) );
}
function guard_ok( $result ) {
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() );
	}
	return $result;
}
function guard_error( $result, $code ) {
	return is_wp_error( $result ) && $result->get_error_code() === 'church_connector_rest_' . $code && $result->get_error_data()['status'] === 409;
}
function guard_state( $fixture_id, $metadata = false ) {
	$target = array( 'kind' => get_post_type( $fixture_id ), 'id' => $fixture_id );
	$read   = guard_ok( guard_call( $metadata ? 'get-item-details' : 'get-publication-workflow', $target ) );
	return $target + array( 'state_hash' => $read[ $metadata ? 'item' : 'post' ]['state_hash'] );
}
function guard_title( $fixture_id, $title ) {
	return guard_call( 'preview-item-update', guard_state( $fixture_id, true ) + array( 'changes' => array( 'title' => $title ) ) );
}
function guard_apply( $preview, $tool = 'apply-item-update', $confirmation = 'approved' ) {
	return guard_call( $tool, array( 'preview_token' => guard_ok( $preview )['preview_token'], $confirmation => true ) );
}
function guard_scheduled( $kind, $timestamp ) {
	// Seed a genuinely due item as it would be stored while WP-Cron is delayed.
	$seed = static function ( $data ) {
		$data['post_status'] = 'future';
		return $data;
	};
	add_filter( 'wp_insert_post_data', $seed );
	try {
		$fixture_date = gmdate( 'Y-m-d H:i:s', $timestamp );
		return wp_insert_post( array( 'post_type' => $kind, 'post_title' => 'Original title', 'post_status' => 'future', 'post_date' => get_date_from_gmt( $fixture_date ), 'post_date_gmt' => $fixture_date ), true );
	} finally {
		remove_filter( 'wp_insert_post_data', $seed );
	}
}
foreach ( array( 'post', 'page' ) as $kind ) {
	$when = time() + DAY_IN_SECONDS;
	$fixture_id   = guard_scheduled( $kind, $when );
	$saved = guard_ok( guard_apply( guard_title( $fixture_id, 'New title' ) ) );
	guard_assert( $saved['updated'] && get_post( $fixture_id )->post_title === 'New title' && get_post_status( $fixture_id ) === 'future' && get_post( $fixture_id )->post_date_gmt === gmdate( 'Y-m-d H:i:s', $when ) && wp_next_scheduled( 'publish_future_post', array( $fixture_id ) ) === $when, 'Upcoming ' . $kind . ' accepts the title and keeps the exact date and cron event' );
	$due = time() - 300;
	$fixture_id  = guard_scheduled( $kind, $due );
	guard_assert( guard_error( guard_title( $fixture_id, 'Unapproved publication' ), 'metadata_status' ), 'Overdue ' . $kind . ' refuses a title-only preview' );
	guard_assert( get_post( $fixture_id )->post_title === 'Original title' && get_post_status( $fixture_id ) === 'future' && wp_next_scheduled( 'publish_future_post', array( $fixture_id ) ) === $due, 'Overdue ' . $kind . ' retains title, status and cron event' );
	do_action( 'publish_future_post', $fixture_id );
	guard_assert( get_post_status( $fixture_id ) === 'publish' && get_post( $fixture_id )->post_title === 'Original title', 'WordPress can still publish the due ' . $kind . ' through its native cron hook' );
}
$when    = time() + 65;
$fixture_id      = guard_scheduled( 'post', $when );
$preview = guard_ok( guard_title( $fixture_id, 'Title reviewed before due window' ) );
sleep( max( 0, $when - 59 - time() ) );
guard_assert( guard_error( guard_apply( $preview ), 'metadata_status' ) && get_post_status( $fixture_id ) === 'future' && get_post( $fixture_id )->post_title === 'Original title' && wp_next_scheduled( 'publish_future_post', array( $fixture_id ) ) === $when, 'Crossing the publication threshold after preview stops apply without writing' );

$fixture_id      = wp_insert_post( array( 'post_title' => 'Original draft', 'post_status' => 'draft' ) );
$term    = wp_insert_term( 'Publication guard', 'category' )['term_id'];
$preview = guard_call( 'preview-item-update', guard_state( $fixture_id, true ) + array( 'changes' => array( 'title' => 'Reviewed draft title', 'categories' => array( $term ) ) ) );
$categories_before = wp_get_post_categories( $fixture_id );
$force   = static function ( $data, $args ) use ( $fixture_id ) {
	if ( ( $args['ID'] ?? 0 ) === $fixture_id ) {
		$data['post_status'] = 'publish';
	}
	return $data;
};
add_filter( 'wp_insert_post_data', $force, 100, 2 );
$decorate_query = static function ( $query ) {
	return str_starts_with( $query, 'UPDATE ' ) ? $query . ' /* fixture query monitor */' : $query;
};
add_filter( 'query', $decorate_query, PHP_INT_MIN );
$other_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Separate item' ) );
$other_write = static function ( $target ) use ( $fixture_id, $other_page ) {
	if ( $target === $fixture_id ) {
		wp_update_post( array( 'ID' => $other_page, 'post_title' => 'Separate save completed' ) );
	}
};
$attach_other_write = static function ( $prepared ) use ( $other_write ) {
	add_action( 'pre_post_update', $other_write, PHP_INT_MAX );
	return $prepared;
};
add_filter( 'rest_pre_insert_post', $attach_other_write );
try {
	guard_assert( guard_error( guard_apply( $preview ), 'metadata_status' ) && get_post_status( $fixture_id ) === 'draft' && get_post( $fixture_id )->post_title === 'Original draft' && wp_get_post_categories( $fixture_id ) === $categories_before, 'A late status change blocks the entire metadata save, including title and categories' );
	guard_assert( ! doing_action( 'pre_post_update' ) && ! rest_get_server()->is_dispatching(), 'Refusing a late change leaves the WordPress save hook and REST request finished' );
	guard_assert( get_post( $other_page )->post_title === 'Separate save completed', 'An unrelated nested page save completes while the conflicting write is refused' );
} finally {
	remove_filter( 'rest_pre_insert_post', $attach_other_write );
	remove_action( 'pre_post_update', $other_write, PHP_INT_MAX );
	remove_filter( 'query', $decorate_query, PHP_INT_MIN );
	remove_filter( 'wp_insert_post_data', $force, 100 );
}
guard_ok( guard_apply( guard_title( $fixture_id, 'Reviewed draft title' ) ) );
guard_assert( get_post_status( $fixture_id ) === 'draft' && get_post( $fixture_id )->post_title === 'Reviewed draft title', 'A fresh draft title edit works after a refused save' );
$approved = guard_title( $fixture_id, 'Title before an independent publication' );
$publish_separately = static function ( $post ) use ( $fixture_id ) {
	if ( $post->ID === $fixture_id ) {
		wp_publish_post( $fixture_id );
	}
};
add_action( 'rest_after_insert_post', $publish_separately );
try {
	guard_assert( guard_error( guard_apply( $approved ), 'update_unverified' ) && get_post_status( $fixture_id ) === 'publish', 'An independent publication after the save is reported on read-back' );
} finally {
	remove_action( 'rest_after_insert_post', $publish_separately );
}

function guard_pdf( $filename ) {
	$upload = wp_upload_bits( $filename, null, "%PDF-1.4\n" . $filename . "\n%%EOF" );
	$fixture_id     = wp_insert_attachment( array( 'post_title' => $filename, 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $upload['file'] );
	return array( 'id' => $fixture_id, 'href' => wp_get_attachment_url( $fixture_id ) );
}
$current = guard_pdf( 'current-guard-notice.pdf' );
$old     = guard_pdf( 'earlier-guard-notice.pdf' );
$url     = $current['href'];
$path    = wp_parse_url( $url, PHP_URL_PATH );
$link    = '<a href="' . $url . '">Notice</a>';
$wrong   = '<a href="' . $old['href'] . '">Older notice</a>';
$button  = '<a download href="' . $url . '">Download</a>';
$embed   = '<object type="application/pdf" data="' . $url . '"></object>';
$fixture_date    = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
$date_local = str_replace( ' ', 'T', get_date_from_gmt( $fixture_date ) );
$future_local = wp_date( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS );
function guard_block( $attrs, $html ) {
	return get_comment_delimited_block_content( 'core/file', $attrs, '<div class="wp-block-file">' . $html . '</div>' );
}
function guard_draft( $kind, $content ) {
	global $fixture_date;
	return wp_insert_post( wp_slash( array( 'post_title' => 'Publication test', 'post_type' => $kind, 'post_status' => 'draft', 'post_date' => get_date_from_gmt( $fixture_date ), 'post_date_gmt' => $fixture_date, 'post_content' => $content ) ), true );
}
function guard_publish_preview( $fixture_id ) {
	if ( 'page' === get_post_type( $fixture_id ) ) {
		return guard_call( 'preview-page-publication', guard_state( $fixture_id ) + array( 'date_local' => $GLOBALS['date_local'] ) );
	}
	// The immediate post workflow uses its own state reader.
	$post = guard_ok( guard_call( 'get-post-workflow', array( 'id' => $fixture_id ) ) )['post'];
	return guard_call( 'preview-draft-publish', array( 'id' => $fixture_id, 'state_hash' => $post['state_hash'] ) );
}
$valid = guard_block( $current, $link );
foreach ( array(
	'visible link' => array( $current, $wrong . $button . $embed ),
	'download button' => array( $current, $link . str_replace( $url, $old['href'], $button ) ),
	'embedded preview' => array( $current, $link . str_replace( $url, $old['href'], $embed ) ),
	'first of multiple links' => array( $current, $wrong . $link ),
	'present embed with missing data' => array( $current, $link . '<object type="application/pdf"></object>' ),
	'present link with missing href' => array( $current, '<a>Notice</a>' . $button ),
	'empty markup with only a saved URL' => array( $current, '' ),
	'query selecting another resource' => array( $current, str_replace( $url, $url . '?file=older', $link ) ),
	'cross-site URL with same path' => array( $current, str_replace( $url, 'https://another.example' . $path, $link ) ),
	'network-path URL on another host' => array( $current, str_replace( $url, '//another.example' . $path, $link ) ),
	'custom iframe with separately supplied content' => array( $current, $link . '<iframe src="' . $url . '" srcdoc="other content"></iframe>' ),
	'stored href' => array( array( 'href' => $old['href'] ) + $current, $link ),
	'stored textLinkHref' => array( $current + array( 'textLinkHref' => $old['href'] ), $link ),
) as $label => list( $attrs, $html ) ) {
	$bad = guard_block( $attrs, $html );
	$fixture_id  = guard_draft( 'post', $valid . '<!-- wp:group --><div>' . $bad . '</div><!-- /wp:group -->' );
	$r   = guard_publish_preview( $fixture_id );
	guard_assert( guard_error( $r, 'pdf_target' ) && str_contains( $r->get_error_message(), 'File block 2' ) && get_post_status( $fixture_id ) === 'draft', 'Publishing identifies the affected nested block for ' . $label );
}
foreach ( array(
	'link only' => $link,
	'link and download' => $link . $button,
	'link and preview' => $link . $embed,
	'root-relative link and viewer fragment' => str_replace( $url, $path . '#page=2', $link ) . $embed . $button,
	'same host with default port and different capitalisation' => str_replace( $url, 'https://CONNECTOR.EXAMPLE:443' . $path, $link ),
	'same-origin network-path link' => str_replace( $url, '//connector.example' . $path, $link ),
) as $label => $html ) {
	$fixture_id     = guard_draft( 'post', guard_block( $current, $html ) );
	$before = get_post( $fixture_id )->post_content;
	$p      = guard_ok( guard_publish_preview( $fixture_id ) );
	$file   = $p['post']['file_blocks'][0];
	if ( 'link only' === $label ) {
		$previous_review                      = guard_ok( guard_call( 'get-post-workflow', array( 'id' => $fixture_id ) ) )['post']['file_blocks'][0];
		$previous_review['media_library_pdf'] = $file['media_library_pdf'];
		guard_assert( wp_json_encode( $previous_review ) === wp_json_encode( $file ), 'A previously valid PDF preview retains its original hash input across the upgrade' );
	}
	guard_ok( guard_apply( $p, 'apply-draft-publish', 'confirm_publish' ) );
	guard_assert( $file['link_url'] === $url && $file['media_library_pdf']['sha256'] === hash_file( 'sha256', get_attached_file( $current['id'] ) ) && get_post_status( $fixture_id ) === 'publish' && get_post( $fixture_id )->post_content === $before, 'Valid ' . $label . ' publishes with the reviewed PDF bytes and original markup' );
}
$fixture_page = guard_draft( 'page', guard_block( $current, $wrong ) );
guard_assert( guard_error( guard_publish_preview( $fixture_page ), 'pdf_target' ), 'Immediate page publication rejects a mismatched PDF' );
guard_assert( guard_error( guard_call( 'preview-publication-schedule', guard_state( $fixture_page ) + array( 'date_local' => $future_local ) ), 'pdf_target' ) && ! wp_next_scheduled( 'publish_future_post', array( $fixture_page ) ), 'Scheduling a mismatched page creates no event' );
wp_update_post( wp_slash( array( 'ID' => $fixture_page, 'post_content' => $valid ) ) );
$schedule = guard_call( 'preview-publication-schedule', guard_state( $fixture_page ) + array( 'date_local' => $future_local ) );
$pdf_path = get_attached_file( $current['id'] );
$bytes    = file_get_contents( $pdf_path );
file_put_contents( $pdf_path, $bytes . 'changed' );
$stale = guard_apply( $schedule, 'apply-publication-schedule', 'confirm_schedule_change' );
guard_assert( guard_error( $stale, 'stale' ) && get_post_status( $fixture_page ) === 'draft', 'The schedule apply rechecks PDF bytes even at the same URL' );
$fresh = \ChurchWebsiteConnector\RestTools\review_details( $stale )['refreshed_preview'];
guard_assert( $fresh['preview_token'] !== $schedule['preview_token'] && $fresh['post']['file_blocks'][0]['media_library_pdf']['sha256'] === hash_file( 'sha256', $pdf_path ) && is_wp_error( guard_apply( $schedule, 'apply-publication-schedule', 'confirm_schedule_change' ) ), 'The refresh reviews the new PDF bytes and invalidates the earlier approval' );
file_put_contents( $pdf_path, $bytes );
$restored = guard_apply( $fresh, 'apply-publication-schedule', 'confirm_schedule_change' );
guard_assert( guard_error( $restored, 'stale' ) && ! wp_next_scheduled( 'publish_future_post', array( $fixture_page ) ), 'A second change to the attachment still requires a fresh schedule approval' );
guard_ok( guard_apply( \ChurchWebsiteConnector\RestTools\review_details( $restored )['refreshed_preview'], 'apply-publication-schedule', 'confirm_schedule_change' ) );
wp_update_post( wp_slash( array( 'ID' => $fixture_page, 'post_content' => guard_block( $current, $wrong ) ) ) );
guard_assert( guard_error( guard_call( 'preview-publication-schedule', guard_state( $fixture_page ) + array( 'date_local' => $future_local ) ), 'pdf_target' ), 'Rescheduling checks links edited since the original schedule' );
$schedule_error = guard_call( 'preview-publication-schedule', guard_state( $fixture_page ) + array( 'date_local' => $future_local ) );
$cron_before = wp_next_scheduled( 'publish_future_post', array( $fixture_page ) );
$repaired = guard_ok( guard_apply( \ChurchWebsiteConnector\RestTools\review_details( $schedule_error )['correction_preview'], 'apply-post-edit' ) );
guard_assert( 'future' === $repaired['post']['status'] && $cron_before === wp_next_scheduled( 'publish_future_post', array( $fixture_page ) ) && ! str_contains( get_post( $fixture_page )->post_content, $old['href'] ), 'Repairing a scheduled page preserves the exact native publication event' );
$cancel = guard_call( 'preview-cancel-schedule', guard_state( $fixture_page ) );
guard_ok( guard_apply( $cancel, 'apply-publication-schedule', 'confirm_schedule_change' ) );
guard_assert( get_post_status( $fixture_page ) === 'draft' && ! wp_next_scheduled( 'publish_future_post', array( $fixture_page ) ), 'A mistaken PDF never prevents cancelling the existing schedule' );

$simple = guard_draft( 'post', guard_block( $current, $wrong ) );
$mismatch = guard_publish_preview( $simple );
$details = \ChurchWebsiteConnector\RestTools\review_details( $mismatch );
guard_assert( 'earlier-guard-notice.pdf' === $details['linked_filename'] && 'current-guard-notice.pdf' === $details['expected_filename'] && 'replace_pdf' === $details['correction_preview']['operation'], 'A publication mismatch identifies both files and prepares a separate repair' );
$repair = $details['correction_preview'];
guard_assert( is_wp_error( guard_apply( $repair, 'apply-draft-publish', 'confirm_publish' ) ) && get_post_status( $simple ) === 'draft' && str_contains( get_post( $simple )->post_content, $old['href'] ), 'A repair token cannot publish the notice or change its content during review' );
guard_ok( guard_apply( $repair, 'apply-post-edit' ) );
guard_assert( get_post_status( $simple ) === 'draft' && ! str_contains( get_post( $simple )->post_content, $old['href'] ), 'Approving only the correction repairs the link and preserves draft status' );
$protected = guard_draft( 'page', guard_block( $current, $wrong . '<iframe srcdoc="custom"></iframe>' ) );
$manual_issue = \ChurchWebsiteConnector\RestTools\review_details( guard_publish_preview( $protected ) );
guard_assert( empty( $manual_issue['correction_preview'] ) && $manual_issue['edit_url'] === get_edit_post_link( $protected, 'raw' ), 'Custom PDF markup returns its editor link and no repair token' );
$moved = guard_draft( 'post', guard_block( $current, $wrong ) );
$repair = \ChurchWebsiteConnector\RestTools\review_details( guard_publish_preview( $moved ) )['correction_preview'];
wp_update_post( wp_slash( array( 'ID' => $moved, 'post_content' => $valid . guard_block( $current, $wrong ) ) ) );
$changed = guard_apply( $repair, 'apply-post-edit' );
guard_assert( guard_error( $changed, 'stale' ) && empty( \ChurchWebsiteConnector\RestTools\review_details( $changed )['refreshed_preview'] ) && str_contains( get_post( $moved )->post_content, $old['href'] ), 'Moving the selected File block asks for identification and cannot retarget the edit' );
$title = guard_ok( guard_title( $simple, 'Requested title' ) );
wp_update_post( array( 'ID' => $simple, 'post_title' => 'Another editor’s title' ) );
$stale = guard_apply( $title );
$fresh = \ChurchWebsiteConnector\RestTools\review_details( $stale )['refreshed_preview'];
guard_assert( guard_error( $stale, 'stale' ) && 'Another editor’s title' === $fresh['diff']['title']['before'] && 'Requested title' === $fresh['diff']['title']['after'] && 'Another editor’s title' === get_the_title( $simple ), 'A stale title edit shows the current and proposed titles without writing' );
$no_approval = guard_call( 'apply-item-update', array( 'preview_token' => $fresh['preview_token'], 'approved' => false ) );
guard_assert( is_wp_error( $no_approval ) && is_wp_error( guard_apply( $title ) ), 'Fresh approval is required and the superseded title token cannot be reused' );
guard_ok( guard_apply( $fresh ) );
guard_assert( 'Requested title' === get_the_title( $simple ) && get_post_status( $simple ) === 'draft', 'Only the newly approved title is saved' );
$prior = guard_ok( guard_title( $simple, 'First tab' ) );
$slot = \ChurchWebsiteConnector\RestTools\stage_name( get_current_user_id() );
$prior_record = get_option( $slot );
$other_tab = guard_ok( guard_title( $simple, 'Second tab' ) );
$blocked = \ChurchWebsiteConnector\RestTools\preview( guard_state( $simple, true ) + array( 'site_url' => home_url(), 'changes' => array( 'title' => 'First tab' ) ), $prior_record );
guard_assert( guard_error( $blocked, 'token' ) && get_option( $slot )['token_hash'] === hash( 'sha256', $other_tab['preview_token'] ), 'Refreshing a superseded request preserves the new preview in another tab' );
guard_ok( guard_apply( $other_tab ) );
$notice_id = guard_draft( 'post', $valid );
$reviewed  = guard_publish_preview( $notice_id );
wp_update_post( wp_slash( array( 'ID' => $notice_id, 'post_content' => guard_block( $current, $wrong ) ) ) );
guard_assert( guard_error( guard_apply( $reviewed, 'apply-draft-publish', 'confirm_publish' ), 'stale' ) && get_post_status( $notice_id ) === 'draft', 'Changing the visitor link after approval invalidates the publication token' );
$state  = guard_ok( guard_call( 'get-post-workflow', array( 'id' => $notice_id ) ) )['post'];
$repair = guard_call( 'preview-pdf-replacement', array( 'id' => $notice_id, 'state_hash' => $state['state_hash'], 'file_block' => 1, 'attachment_id' => $current['id'], 'label' => 'Corrected weekly notice' ) );
$before_repair = get_post( $notice_id )->post_content;
foreach ( array( 'missing', 'different' ) as $digest_case ) {
	$record = get_option( $slot );
	if ( 'missing' === $digest_case ) {
		unset( $record['replacement_content_hash'] ); // An approval staged on 1.13.7.
	} else {
		$record['replacement_content_hash'] = hash( 'sha256', 'Different saved markup with the same review summary' );
	}
	update_option( $slot, $record, false );
	$refused = guard_apply( $repair, 'apply-post-edit' );
	guard_assert( guard_error( $refused, 'stale' ) && get_post( $notice_id )->post_content === $before_repair, 'A ' . $digest_case . ' PDF output digest cannot authorise an edit' );
	$refreshed = \ChurchWebsiteConnector\RestTools\review_response( $refused );
	guard_assert( str_contains( $refreshed['message'], 'exact replacement markup' ) && is_wp_error( guard_apply( $repair, 'apply-post-edit' ) ), 'The refreshed PDF approval explains the markup check and supersedes the old token' );
	$repair = $refreshed['data']['refreshed_preview'];
}
$expected_digest = get_option( $slot )['replacement_content_hash'];
guard_ok( guard_apply( $repair, 'apply-post-edit' ) );
guard_assert( hash( 'sha256', get_post( $notice_id )->post_content ) === $expected_digest, 'Saved replacement bytes match the exact approved output' );
guard_ok( guard_apply( guard_publish_preview( $notice_id ), 'apply-draft-publish', 'confirm_publish' ) );
guard_assert( get_post_status( $notice_id ) === 'publish' && ! str_contains( get_post( $notice_id )->post_content, $old['href'] ), 'Replacing the mismatched PDF restores the normal notice publication workflow' );
$manual_id = guard_draft( 'post', guard_block( array(), '<a href="https://external.example/notice.pdf">Other document</a>' ) );
$manual = guard_ok( guard_publish_preview( $manual_id ) )['post']['file_blocks'][0];
guard_assert( empty( $manual['attachment_id'] ) && ! isset( $manual['media_library_pdf'] ) && $manual['link_url'] === 'https://external.example/notice.pdf', 'An unlinked external File block stays available for manual review without a verified hash' );

// A token created before publication previews included wording must require a new decision.
foreach ( array( 'publish' => 'post', 'publish_page' => 'page', 'schedule' => 'post' ) as $operation => $kind ) {
	$source     = '<!-- wp:paragraph --><p>Morning worship begins at ten.</p><!-- /wp:paragraph -->';
	$wording_id = guard_draft( $kind, $source );
	wp_update_post( array( 'ID' => $wording_id, 'post_excerpt' => 'Sunday service' ) );
	$schedule = 'schedule' === $operation;
	$first    = guard_ok(
		$schedule
			? guard_call( 'preview-publication-schedule', guard_state( $wording_id ) + array( 'date_local' => $future_local ) )
			: guard_publish_preview( $wording_id )
	);
	$wording = $first['post']['wording_review'];
	guard_assert( 'inline' === $wording['mode'] && $source === $wording['body_source'] && 'Sunday service' === $wording['excerpt_source'], 'First ' . $operation . ' approval includes the complete short saved wording and excerpt' );
	$legacy = get_option( $slot );
	unset( $legacy['publication_wording_reviewed'] );
	update_option( $slot, $legacy, false );
	$tool    = $schedule ? 'apply-publication-schedule' : 'apply-draft-publish';
	$confirm = $schedule ? 'confirm_schedule_change' : 'confirm_publish';
	$refused = guard_apply( $first, $tool, $confirm );
	guard_assert( is_wp_error( $refused ) && 'draft' === get_post_status( $wording_id ) && ! wp_next_scheduled( 'publish_future_post', array( $wording_id ) ), 'A legacy ' . $operation . ' approval cannot release unreviewed wording' );
	guard_assert( is_wp_error( guard_apply( $first, $tool, $confirm ) ), 'Reusing the legacy ' . $operation . ' token cannot release the draft' );
}

$wording_id = guard_draft( 'post', '<p>Same visitor-facing body throughout.</p>' );
wp_update_post( array( 'ID' => $wording_id, 'post_excerpt' => 'For the first service.' ) );
$draft_state = guard_ok( guard_call( 'get-post-workflow', array( 'id' => $wording_id ) ) )['post'];
$date_review = guard_ok( guard_call( 'preview-draft-date', array( 'id' => $wording_id, 'state_hash' => $draft_state['state_hash'], 'date_local' => wp_date( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) ) ) );
guard_assert( ! isset( $date_review['post']['wording_review'] ), 'Separately saving a draft date does not ask for publication wording approval' );
wp_set_post_categories( $wording_id, array( $term ) );
$refused_date = guard_apply( $date_review, 'apply-post-edit' );
$date_message = \ChurchWebsiteConnector\RestTools\review_response( $refused_date );
guard_assert( guard_error( $refused_date, 'stale' ) && str_contains( $date_message['message'], 'categories' ) && ! str_contains( $date_message['message'], 'post content' ) && isset( $date_message['data']['refreshed_preview'] ), 'Date reapproval identifies the actual intervening field without exposing draft content' );
$first = guard_ok( guard_publish_preview( $wording_id ) );
wp_update_post( array( 'ID' => $wording_id, 'post_excerpt' => 'For both services.' ) );
$stale  = guard_apply( $first, 'apply-draft-publish', 'confirm_publish' );
guard_assert( guard_error( $stale, 'stale' ) && 'draft' === get_post_status( $wording_id ) && 'For both services.' === get_post( $wording_id )->post_excerpt, 'An excerpt-only edit invalidates publication and preserves the independently saved excerpt' );
$fresh = \ChurchWebsiteConnector\RestTools\review_details( $stale )['refreshed_preview'];
guard_assert( $first['preview_token'] !== $fresh['preview_token'] && 'For both services.' === $fresh['post']['wording_review']['excerpt_source'] && '<p>Same visitor-facing body throughout.</p>' === $fresh['post']['wording_review']['body_source'], 'After an excerpt-only edit the fresh approval contains the new excerpt and current body' );

$ran_shortcode = false;
add_shortcode(
	'wording_test',
	static function () use ( &$ran_shortcode ) {
		$ran_shortcode = true;
		return 'WordPress rendered this shortcode';
	}
);
try {
	$complex_id = guard_draft( 'post', '[wording_test]' );
	wp_update_post( array( 'ID' => $complex_id, 'post_excerpt' => 'Service details' ) );
	$complex = guard_ok( guard_publish_preview( $complex_id ) )['post']['wording_review'];
	guard_assert( ! $ran_shortcode && 'wordpress' === $complex['mode'] && ! isset( $complex['body_source'] ) && 'Service details' === $complex['excerpt_source'], 'A shortcode draft directs the user to WordPress without executing the shortcode or claiming its source is visitor-facing wording' );
	guard_assert( get_preview_post_link( $complex_id ) === $complex['preview_url'] && get_edit_post_link( $complex_id, 'raw' ) === $complex['edit_url'], 'Complex wording links to the exact native preview and editor for the draft' );
} finally {
	remove_shortcode( 'wording_test' );
}

$long_excerpt_id = guard_draft( 'post', '<p>Short body</p>' );
wp_update_post( array( 'ID' => $long_excerpt_id, 'post_excerpt' => str_repeat( 'Additional detail. ', 2000 ) ) );
$long_excerpt = guard_ok( guard_publish_preview( $long_excerpt_id ) )['post']['wording_review'];
guard_assert( 'wordpress' === $long_excerpt['mode'] && ! isset( $long_excerpt['excerpt_source'] ) && ! isset( $long_excerpt['body_source'] ) && get_preview_post_link( $long_excerpt_id ) === $long_excerpt['preview_url'], 'A long excerpt on a short post stays out of the response and points to the complete WordPress review' );

$notice = guard_draft( 'post', '<p>The fair opens at 11am.</p>' );
$first  = guard_ok( guard_publish_preview( $notice ) );
wp_update_post( array( 'ID' => $notice, 'post_content' => '<p>The fair has been cancelled.</p>' ) );
$stale  = guard_apply( $first, 'apply-draft-publish', 'confirm_publish' );
$latest = \ChurchWebsiteConnector\RestTools\review_details( $stale )['refreshed_preview'];
guard_assert( array( 'before' => '<p>The fair opens at 11am.</p>', 'after' => '<p>The fair has been cancelled.</p>' ) === $latest['post']['body_comparison'] && 'draft' === get_post_status( $notice ), 'A refreshed publication review shows the complete changed body before publication' );
guard_ok( guard_apply( $latest, 'apply-draft-publish', 'confirm_publish' ) );

$notice = guard_draft( 'page', str_repeat( 'Paragraph ', 2000 ) );
$first  = guard_ok( guard_call( 'preview-publication-schedule', guard_state( $notice ) + array( 'date_local' => $future_local ) ) );
$long   = $first['post']['wording_review'];
guard_assert( 'wordpress' === $long['mode'] && ! isset( $long['body_source'] ) && get_preview_post_link( $notice ) === $long['preview_url'], 'First scheduling review of a lengthy page links to its full native preview instead of returning truncated wording' );
wp_update_post( array( 'ID' => $notice, 'post_content' => 'Short final text' ) );
$stale = guard_apply( $first, 'apply-publication-schedule', 'confirm_schedule_change' );
$issue = \ChurchWebsiteConnector\RestTools\review_details( $stale );
guard_assert( 'church_connector_rest_publication_body_review' === $issue['review_issue']['code'] && ! isset( $issue['refreshed_preview'] ) && ! wp_next_scheduled( 'publish_future_post', array( $notice ) ), 'An oversized changed body requires review in the editor and offers no new schedule token' );

$missing = guard_pdf( 'missing-after-review.pdf' );
$block   = guard_block( $missing, '<a href="' . $missing['href'] . '">Notice</a>' );
$notice  = guard_draft( 'post', $block );
$before  = guard_ok( guard_publish_preview( $notice ) );
wp_delete_attachment( $missing['id'], true );
guard_assert( guard_error( guard_publish_preview( $notice ), 'file_reference' ) && guard_error( guard_apply( $before, 'apply-draft-publish', 'confirm_publish' ), 'file_reference' ) && 'draft' === get_post_status( $notice ), 'A deleted positive attachment ID blocks both fresh and outstanding publication approvals' );
guard_assert( guard_error( guard_call( 'preview-publication-schedule', guard_state( $notice ) + array( 'date_local' => $future_local ) ), 'file_reference' ), 'A notice with a deleted local file cannot be scheduled' );

foreach ( array( 'publish', 'future' ) as $status ) {
	$when    = time() + ( 'future' === $status ? DAY_IN_SECONDS : -DAY_IN_SECONDS );
	$post_id = wp_insert_post( wp_slash( array(
		'post_title' => 'Revision file check', 'post_type' => 'post', 'post_status' => $status,
		'post_date' => wp_date( 'Y-m-d H:i:s', $when ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $when ),
		'post_content' => guard_block( $current, $wrong ),
	) ) );
	$revision_id = _wp_put_post_revision( get_post( $post_id ) );
	wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $valid ) ) );
	$input = guard_state( $post_id ) + array( 'revision_id' => $revision_id );
	$diff  = guard_ok( guard_call( 'compare-post-revision', array_diff_key( $input, array( 'state_hash' => true ) ) ) );
	guard_assert( isset( $diff['post']['file_review_issue'] ) && $old['href'] === $diff['post']['file_blocks'][0]['link_url'], 'The revision comparison identifies its own proposed mismatched link on a ' . $status . ' post' );
	guard_assert( guard_error( guard_call( 'preview-revision-restore', $input ), 'revision_file' ) && $valid === get_post( $post_id )->post_content && $status === get_post_status( $post_id ), 'Restoring the mismatched revision to a ' . $status . ' post cannot issue an approval or change the saved file' );
	$correct_revision = _wp_put_post_revision( get_post( $post_id ) );
	wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => '<p>Different text</p>' ) ) );
	$before_event = wp_next_scheduled( 'publish_future_post', array( $post_id ) );
	$good = guard_ok( guard_call( 'preview-revision-restore', guard_state( $post_id ) + array( 'revision_id' => $correct_revision ) ) );
	guard_assert( $current['id'] === $good['post']['file_blocks'][0]['media_library_pdf']['attachment_id'], 'A valid ' . $status . ' revision previews its actual attachment' );
	guard_ok( guard_apply( $good, 'apply-revision-restore', 'confirm_restore' ) );
	guard_assert( $valid === get_post( $post_id )->post_content && $before_event === wp_next_scheduled( 'publish_future_post', array( $post_id ) ), 'A valid ' . $status . ' restore preserves the publication event' );

}

// Shared patterns are separate editable objects. Their nested files must never be repaired by replacing bytes in this page.
$pattern_ref = static fn( $value ) => get_comment_delimited_block_content( 'core/block', array( 'ref' => $value ), '' );
$nested = wp_insert_post( wp_slash( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Nested notice', 'post_content' => $valid ) ) );
$outer = wp_insert_post( wp_slash( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Weekly notices', 'post_content' => $pattern_ref( $nested ) ) ) );
$shared_page = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Shared pattern test', 'post_content' => $pattern_ref( $outer ) ) ) );
$pattern_state = guard_state( $shared_page );
$preview = guard_ok( guard_publish_preview( $shared_page ) );
$wording = $preview['post']['wording_review'];
guard_assert( 'wordpress' === $wording['mode'] && ! isset( $wording['body_source'] ) && get_preview_post_link( $shared_page ) === $wording['preview_url'], 'A short page containing nested synced content directs the user to its native preview' );
$patterns = array_column( $preview['post']['synced_patterns'], null, 'id' );
guard_assert( array() === $preview['post']['file_blocks'] && $current['id'] === $patterns[ $nested ]['file_blocks'][0]['media_library_pdf']['attachment_id'], 'Nested shared patterns identify the verified PDF and keep its index local to its source pattern' );
wp_update_post( wp_slash( array( 'ID' => $nested, 'post_content' => $valid . '<p>Additional shared notice</p>' ) ) );
$stale = guard_apply( $preview, 'apply-draft-publish', 'confirm_publish' );
$detail = \ChurchWebsiteConnector\RestTools\review_details( $stale );
guard_assert( $pattern_state['state_hash'] !== guard_state( $shared_page )['state_hash'] && guard_error( $stale, 'stale' ) && empty( $detail['refreshed_preview'] ) && $detail['review_issue']['code'] === 'church_connector_rest_pattern_review' && 'draft' === get_post_status( $shared_page ), 'Changing a nested pattern invalidates the approval and requests a native read before another publication preview' );
wp_update_post( wp_slash( array( 'ID' => $nested, 'post_content' => guard_block( $current, '<a href="' . esc_url( $old['href'] ) . '">Wrong notice</a>' ) ) ) );
$mismatch = guard_publish_preview( $shared_page );
$detail = \ChurchWebsiteConnector\RestTools\review_details( $mismatch );
guard_assert( guard_error( $mismatch, 'pattern_file' ) && $nested === $detail['pattern_id'] && empty( $detail['correction_preview'] ), 'Shared-file mismatches identify the pattern and never offer to edit it through the containing page' );
wp_update_post( wp_slash( array( 'ID' => $nested, 'post_content' => $pattern_ref( $outer ) ) ) );
guard_assert( guard_error( guard_publish_preview( $shared_page ), 'pattern_review' ), 'A circular shared reference cannot issue a publication approval' );
wp_update_post( wp_slash( array( 'ID' => $nested, 'post_content' => $valid ) ) );
$overridden = get_comment_delimited_block_content( 'core/block', array( 'ref' => $nested, 'content' => array( 'Notice' => array( 'content' => 'Different instance text' ) ) ), '' );
wp_update_post( wp_slash( array( 'ID' => $outer, 'post_content' => $overridden ) ) );
guard_assert( guard_error( guard_publish_preview( $shared_page ), 'pattern_review' ), 'A pattern instance with unverified overrides needs native review' );
wp_update_post( wp_slash( array( 'ID' => $outer, 'post_content' => str_repeat( $pattern_ref( $nested ), 51 ) ) ) );
guard_assert( guard_error( guard_publish_preview( $shared_page ), 'pattern_review' ), 'Repeated references cannot exceed the pattern traversal budget' );
wp_update_post( wp_slash( array( 'ID' => $outer, 'post_content' => $pattern_ref( $nested ) ) ) );
wp_trash_post( $nested );
guard_assert( guard_error( guard_publish_preview( $shared_page ), 'pattern_review' ), 'A trashed shared pattern requires native review before publication' );
wp_untrash_post( $nested );
wp_update_post( array( 'ID' => $nested, 'post_status' => 'publish' ) );
$cancel_id = guard_scheduled( 'page', time() + 2 * DAY_IN_SECONDS );
wp_update_post( wp_slash( array( 'ID' => $cancel_id, 'post_content' => $pattern_ref( 99999999 ) ) ) );
$cancel = guard_ok( guard_call( 'preview-cancel-schedule', guard_state( $cancel_id ) ) );
guard_assert( ! isset( $cancel['post']['wording_review'] ), 'Stopping an existing schedule does not ask the user to approve wording for publication' );
guard_ok( guard_apply( $cancel, 'apply-publication-schedule', 'confirm_schedule_change' ) );
guard_assert( 'draft' === get_post_status( $cancel_id ) && ! wp_next_scheduled( 'publish_future_post', array( $cancel_id ) ), 'A broken synced reference does not prevent stopping an existing publication schedule' );
$shared_good = guard_ok( guard_publish_preview( $shared_page ) );
guard_ok( guard_apply( $shared_good, 'apply-draft-publish', 'confirm_publish' ) );
guard_assert( 'publish' === get_post_status( $shared_page ) && str_contains( do_blocks( get_post( $shared_page )->post_content ), $current['href'] ), 'Unchanged reviewed nested patterns publish the expected visitor PDF' );

$late_pdf = guard_pdf( 'final-write-guard.pdf' );
$protected_page = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Final media check', 'post_content' => guard_block( $late_pdf, '<a href="' . esc_url( $late_pdf['href'] ) . '">Leaflet</a>' ) ) ) );
$preview = guard_ok( guard_publish_preview( $protected_page ) );
$swap_file = static function ( $pre, $hook ) use ( $late_pdf, $old ) {
	if ( \ChurchWebsiteConnector\RestTools\EXPIRE_HOOK === $hook ) { update_attached_file( $late_pdf['id'], get_attached_file( $old['id'] ) ); }
	return $pre;
};
add_filter( 'pre_clear_scheduled_hook', $swap_file, 10, 2 );
$refused = guard_apply( $preview, 'apply-draft-publish', 'confirm_publish' );
remove_filter( 'pre_clear_scheduled_hook', $swap_file, 10 );
guard_assert( guard_error( $refused, 'post_guard' ) && 'draft' === get_post_status( $protected_page ) && $old['href'] === wp_get_attachment_url( $late_pdf['id'] ), 'An attachment repointed while claiming approval cannot publish its previously reviewed link' );
$protected_page = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Final bytes', 'post_content' => $valid ) ) );
$preview = guard_ok( guard_call( 'preview-publication-schedule', guard_state( $protected_page ) + array( 'date_local' => $future_local ) ) );
$changed_pdf = static function ( $id ) use ( $protected_page, $current ) {
	if ( $id === $protected_page ) { file_put_contents( get_attached_file( $current['id'] ), '%PDF-1.4 replaced at the same URL' ); }
};
add_action( 'pre_post_update', $changed_pdf );
$refused = guard_apply( $preview, 'apply-publication-schedule', 'confirm_schedule_change' );
remove_action( 'pre_post_update', $changed_pdf );
guard_assert( guard_error( $refused, 'post_guard' ) && 'draft' === get_post_status( $protected_page ) && ! wp_next_scheduled( 'publish_future_post', array( $protected_page ) ), 'Replacing PDF bytes at the same filename immediately before storage refuses scheduling and leaves no public event' );

$stamp = time() + 3 * DAY_IN_SECONDS;
$event_post = guard_scheduled( 'page', $stamp );
$revision = _wp_put_post_revision( get_post( $event_post ) );
wp_update_post( array( 'ID' => $event_post, 'post_title' => 'Newer event title' ) );
foreach ( array( false, $stamp + HOUR_IN_SECONDS ) as $event ) {
	wp_clear_scheduled_hook( 'publish_future_post', array( $event_post ) );
	if ( $event ) { wp_schedule_single_event( $event, 'publish_future_post', array( $event_post ) ); }
	$refused = guard_call( 'preview-revision-restore', guard_state( $event_post ) + array( 'revision_id' => $revision ) );
	guard_assert( guard_error( $refused, 'revision_schedule' ) && $event === wp_next_scheduled( 'publish_future_post', array( $event_post ) ) && get_post( $event_post )->post_title === 'Newer event title', 'A revision cannot recreate or advance an inconsistent native schedule' );
}
wp_clear_scheduled_hook( 'publish_future_post', array( $event_post ) );
wp_schedule_single_event( $stamp, 'publish_future_post', array( $event_post ) );
$preview = guard_ok( guard_call( 'preview-revision-restore', guard_state( $event_post ) + array( 'revision_id' => $revision ) ) );
$remove_event = static function ( $pre, $hook ) use ( $event_post ) {
	if ( \ChurchWebsiteConnector\RestTools\EXPIRE_HOOK === $hook ) { wp_clear_scheduled_hook( 'publish_future_post', array( $event_post ) ); }
	return $pre;
};
add_filter( 'pre_clear_scheduled_hook', $remove_event, 10, 2 );
$refused = guard_apply( $preview, 'apply-revision-restore', 'confirm_restore' );
remove_filter( 'pre_clear_scheduled_hook', $remove_event, 10 );
guard_assert( guard_error( $refused, 'post_guard' ) && ! wp_next_scheduled( 'publish_future_post', array( $event_post ) ) && get_post( $event_post )->post_title === 'Newer event title', 'Revision restore leaves an independently removed publication event alone during the final save' );

// An editor's draft view may supply a transient local date without saving a publication date.
function guard_date_preview( $id, $date ) {
	$state = guard_ok( guard_call( 'get-post-workflow', array( 'id' => $id ) ) )['post'];
	return guard_ok( guard_call( 'preview-draft-date', array( 'id' => $id, 'state_hash' => $state['state_hash'], 'date_local' => $date ) ) );
}
$draft = wp_insert_post( array( 'post_title' => 'Draft date review', 'post_status' => 'draft' ) );
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_date' => '2026-01-02 09:00:00' ), array( 'ID' => $draft ) );
clean_post_cache( $draft );
$saved = guard_ok( guard_call( 'get-post-workflow', array( 'id' => $draft ) ) )['post'];
$view  = new WP_Query( array( 'p' => $draft ) );
guard_assert( $view->is_preview() && $view->post->post_date !== '2026-01-02 09:00:00' && null === $saved['date_local'], 'The signed-in draft preview assigns a temporary date while the saved draft is undated' );
wp_cache_set( $draft, clone $view->post, 'posts' );
$read = guard_ok( guard_call( 'get-post-workflow', array( 'id' => $draft ) ) )['post'];
guard_assert( $read === $saved, 'A cached signed-in preview object gives the same workflow review as the saved draft' );
// Repopulate the preview object even after the native edit-context read, before either hash is calculated.
$recache = static function ( $response, $handler, $request ) use ( $draft, $view ) {
	if ( 'GET' === $request->get_method() && '/wp/v2/posts/' . $draft === $request->get_route() ) {
		wp_cache_set( $draft, clone $view->post, 'posts' );
	}
	return $response;
};
add_filter( 'rest_request_after_callbacks', $recache, 10, 3 );
try {
	$preview = guard_ok( guard_call( 'preview-draft-date', array( 'id' => $draft, 'state_hash' => $saved['state_hash'], 'date_local' => '2026-02-01T11:30:00' ) ) );
	$dated   = guard_ok( guard_apply( $preview, 'apply-post-edit', 'approved' ) );
} finally {
	remove_filter( 'rest_request_after_callbacks', $recache, 10 );
}
guard_assert( 'draft' === $dated['post']['status'] && '2026-02-01T11:30:00Z' === $dated['post']['date_gmt'] && is_wp_error( guard_apply( $preview, 'apply-post-edit', 'approved' ) ), 'One approval sets the exact draft date despite the cached preview and remains single use' );
$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => '0000-00-00 00:00:00' ), array( 'ID' => $draft ) );
clean_post_cache( $draft );
$preview = guard_date_preview( $draft, '2026-06-05' );
$wpdb->update( $wpdb->posts, array( 'post_date' => '2026-05-04 12:00:00' ), array( 'ID' => $draft ) );
$result = guard_apply( $preview, 'apply-post-edit', 'approved' );
guard_assert( guard_error( $result, 'stale' ) && '2026-05-04 12:00:00' === get_post( $draft )->post_date && '0000-00-00 00:00:00' === get_post( $draft )->post_date_gmt, 'A genuine local-only edit with zero GMT and unchanged modified time still invalidates the approval' );

// A native editor can save independently after pre_post_update, even within a callback on this request.
$other_category = wp_insert_term( 'Late date review category', 'category' )['term_id'];
foreach ( array( 'wording', 'category' ) as $change ) {
	$old_date = get_post( $draft )->post_date;
	$preview  = guard_date_preview( $draft, '2026-06-05' );
	$edited   = false;
	$race     = static function ( $sql ) use ( $draft, $change, $other_category, $wpdb, &$edited, &$race ) {
		if ( ! $edited && str_starts_with( $sql, "UPDATE `{$wpdb->posts}` SET" ) && str_contains( $sql, '2026-06-05' ) ) {
			$edited = true;
			remove_filter( 'query', $race, 100 );
			if ( 'wording' === $change ) {
				wp_update_post( array( 'ID' => $draft, 'post_content' => 'Independent editor wording', 'edit_date' => true ) );
			} else {
				wp_set_post_categories( $draft, array( $other_category ) );
			}
		}
		return $sql;
	};
	add_filter( 'query', $race, 100 );
	try {
		$result = guard_apply( $preview, 'apply-post-edit', 'approved' );
	} finally {
		remove_filter( 'query', $race, 100 );
	}
	clean_post_cache( $draft );
	$row = get_post( $draft );
	guard_assert( $edited && guard_error( $result, 'post_guard' ) && $old_date === $row->post_date && '0000-00-00 00:00:00' === $row->post_date_gmt, "A last-moment $change edit stops the conditional date write" );
	guard_assert( 'wording' === $change ? 'Independent editor wording' === $row->post_content : array( $other_category ) === wp_get_post_categories( $draft ), "A last-moment $change edit survives the refused date change" );
}
$before  = guard_ok( guard_call( 'get-post-workflow', array( 'id' => $draft ) ) )['post'];
$preview = guard_date_preview( $draft, '2026-06-05' );
$new_category = wp_insert_term( 'Actual reassignment', 'category' )['term_id'];
wp_set_post_categories( $draft, array( $new_category ) );
wp_cache_set( $draft, $before['categories'], 'category_relationships' );
$cached = guard_ok( guard_call( 'get-post-workflow', array( 'id' => $draft ) ) )['post'];
guard_assert( $cached['state_hash'] === $before['state_hash'], 'A stale relationship cache can reproduce the earlier native REST hash after a real category change' );
$refused = guard_apply( $preview, 'apply-post-edit', 'approved' );
clean_object_term_cache( $draft, 'post' );
guard_assert( guard_error( $refused, 'post_guard' ) && '0000-00-00 00:00:00' === get_post( $draft )->post_date_gmt && array( $new_category ) === wp_get_post_categories( $draft ), 'An apparently unchanged REST review cannot approve a date over different saved term relationships' );
update_option( 'timezone_string', $original_zone );
file_put_contents( '/reports/publication-guards.json', wp_json_encode( array( 'checks' => count( $checks ), 'cases' => $checks, 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION ) ) );
