<?php
/** Native metadata, hierarchy, scheduling and concurrent activity regressions. */
require_once '/wordpress/wp-load.php';

use ChurchWebsiteConnector as Connector;
use ChurchWebsiteConnector\RestTools as RestTools;

wp_set_current_user( 1 );
$checks = array();
function rest_check( $condition, $description ) {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $description );
	}
	$checks[] = $description;
}

function preview_item( $kind, $id, $changes ) {
	$args = array( 'site_url' => Connector\bound_site(), 'kind' => $kind, 'id' => $id );
	$read = wp_get_ability( 'church-connector/get-item-details' )->execute( $args );
	rest_check( ! is_wp_error( $read ), 'Read current metadata for ' . $kind . ' ' . $id );
	return wp_get_ability( 'church-connector/preview-item-update' )->execute(
		$args + array( 'state_hash' => $read['item']['state_hash'], 'changes' => $changes )
	);
}

function apply_token( $preview ) {
	return wp_get_ability( 'church-connector/apply-item-update' )->execute(
		array( 'site_url' => Connector\bound_site(), 'approved' => true, 'preview_token' => $preview['preview_token'] )
	);
}

// Review concrete destination URLs, native slug collisions and affected descendants.
global $wp_rewrite, $wpdb;
$old_structure = get_option( 'permalink_structure' );
$wp_rewrite->set_permalink_structure( '/%postname%/' );
function hierarchy_page( $label, $parent = 0 ) {
	return wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $label, 'post_parent' => $parent ) );
}
$from   = hierarchy_page( 'Original parish' );
$to     = hierarchy_page( 'New parish' );
$moving = hierarchy_page( 'Services', $from );
$clash  = hierarchy_page( 'Services', $to );
$child  = hierarchy_page( 'Choir', $moving );
$move   = preview_item( 'page', $moving, array( 'parent' => $to ) );
rest_check( ! is_wp_error( $move ) && 'services-2' === $move['diff']['slug']['after'], 'Review includes the exact native slug for a parent-only move' );
rest_check( array_column( $move['permalink_changes'], 'id' ) === array( $moving, $child ) && str_ends_with( $move['permalink_changes'][1]['after'], '/new-parish/services-2/choir/' ), 'Move previews the inherited child permalink' );
$grandchild = hierarchy_page( 'Practice', $child );
rest_check( is_wp_error( apply_token( $move ) ) && get_post( $moving )->post_parent === $from, 'Adding a descendant invalidates the reviewed URL effects' );
$move = preview_item( 'page', $moving, array( 'parent' => $to ) );
$done = apply_token( $move );
rest_check( ! is_wp_error( $done ) && in_array( 'slug', $done['changed_fields'], true ) && get_permalink( $grandchild ) === $move['permalink_changes'][2]['after'], 'Approved move stores and verifies target and descendant links' );
wp_set_current_user( 0 );
foreach ( $move['permalink_changes'] as $reviewed_link ) {
	rest_check( url_to_postid( $reviewed_link['after'] ) === $reviewed_link['id'], 'The published target and each affected descendant resolve to the reviewed page after a move' );
}
wp_set_current_user( 1 );
$draft_parent = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Unpublished parent' ) );
$trashed_parent = hierarchy_page( 'Archived parent' );
wp_trash_post( $trashed_parent );
foreach ( array( $draft_parent, $trashed_parent, hierarchy_page( 'Child of unpublished parent', $draft_parent ) ) as $unavailable_parent ) {
	$refused = preview_item( 'page', $moving, array( 'parent' => $unavailable_parent ) );
	rest_check( is_wp_error( $refused ) && get_post( $moving )->post_parent === $to && url_to_postid( get_permalink( $moving ) ) === $moving, 'A public page cannot be moved through a draft, trashed or otherwise unpublished ancestor' );
}
// A custom link filter can leave the current URL looking valid even though the future path differs.
$previous_url = get_permalink( $moving );
$keep_old_url = static fn( $url, $page_id ) => $moving === $page_id ? $previous_url : $url;
add_filter( 'page_link', $keep_old_url, 10, 2 );
$refused = preview_item( 'page', $moving, array( 'parent' => $from ) );
remove_filter( 'page_link', $keep_old_url, 10 );
rest_check( is_wp_error( $refused ) && get_post( $moving )->post_parent === $to, 'A custom filter cannot approve a valid current URL that would cease to route after the move' );
$during_move = preview_item( 'page', $moving, array( 'parent' => $from ) );
$parent_unpublished = static function ( $pre, $hook ) use ( $from ) {
	if ( RestTools\EXPIRE_HOOK === $hook ) { wp_update_post( array( 'ID' => $from, 'post_status' => 'draft' ) ); }
	return $pre;
};
add_filter( 'pre_clear_scheduled_hook', $parent_unpublished, 10, 2 );
$refused = apply_token( $during_move );
remove_filter( 'pre_clear_scheduled_hook', $parent_unpublished, 10 );
rest_check( is_wp_error( $refused ) && get_post( $moving )->post_parent === $to && url_to_postid( get_permalink( $moving ) ) === $moving, 'A parent becoming unpublished during approval leaves the live page in its reviewed original location' );
$draft_child = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Draft outline' ) );
$draft_plan  = preview_item( 'page', $draft_child, array( 'parent' => $draft_parent ) );
rest_check( ! is_wp_error( $draft_plan ) && ! is_wp_error( apply_token( $draft_plan ) ) && get_post( $draft_child )->post_parent === $draft_parent, 'A draft without public descendants can still be organised under a draft parent' );

$wp_rewrite->set_permalink_structure( $old_structure );

// Native draft inserts can store empty discussion defaults. The first metadata edit must retain them.
$poster_category = wp_insert_term( 'Service posters', 'category' )['term_id'];
$upload          = wp_upload_bits( 'service-poster.png', null, file_get_contents( ABSPATH . 'wp-includes/images/w-logo-blue-white-bg.png' ) );
$poster_media    = wp_insert_attachment( array( 'post_title' => 'Service poster', 'post_mime_type' => 'image/png' ), $upload['file'] );
rest_check( ! $upload['error'] && $poster_media > 0, 'Native image fixture available for the draft' );
add_filter( 'get_default_comment_status', '__return_empty_string' );
$poster_draft = wp_insert_post(
	array(
		'post_title'    => 'Draft service poster',
		'post_status'   => 'draft',
		'post_name'     => 'draft-service-poster',
		'post_content'  => '<!-- wp:paragraph --><p>Service times</p><!-- /wp:paragraph -->',
		'post_category' => array( $poster_category ),
	),
	true
);
remove_filter( 'get_default_comment_status', '__return_empty_string' );
$original = RestTools\stored_post( $poster_draft );
rest_check( '' === $original->comment_status && '' === $original->ping_status && '0000-00-00 00:00:00' === $original->post_modified_gmt, 'New undated draft has both saved discussion settings empty' );
$image_change = array( 'featured_media' => $poster_media );
$review       = preview_item( 'post', $poster_draft, $image_change );
rest_check( array( 'featured_media' ) === array_keys( $review['diff'] ) && 0 === get_post_thumbnail_id( $poster_draft ), 'Draft review proposes only the featured image' );
$refused = wp_get_ability( 'church-connector/apply-item-update' )->execute(
	array( 'site_url' => Connector\bound_site(), 'approved' => false, 'preview_token' => $review['preview_token'] )
);
rest_check( is_wp_error( $refused ) && 0 === get_post_thumbnail_id( $poster_draft ), 'Draft image still requires approval' );

// A change after core prepares its defaults must reach the final check.
$change_ping = static function ( $data, $postarr ) use ( $poster_draft ) {
	if ( (int) $postarr['ID'] === $poster_draft ) {
		$data['ping_status'] = 'closed';
	}
	return $data;
};
add_filter( 'wp_insert_post_data', $change_ping, 10, 2 );
$refused = apply_token( $review );
remove_filter( 'wp_insert_post_data', $change_ping );
rest_check( is_wp_error( $refused ) && 'church_connector_rest_substituted' === $refused->get_error_code() && str_contains( $refused->get_error_message(), 'ping_status' ) && 0 === get_post_thumbnail_id( $poster_draft ) && '' === get_post( $poster_draft )->ping_status, 'A subsequent discussion substitution stops the write and identifies the field' );

$review = preview_item( 'post', $poster_draft, $image_change );
wp_set_post_categories( $poster_draft, array( (int) get_option( 'default_category' ) ) );
$refused = apply_token( $review );
rest_check( is_wp_error( $refused ) && 'church_connector_rest_stale' === $refused->get_error_code() && 0 === get_post_thumbnail_id( $poster_draft ) && array( (int) get_option( 'default_category' ) ) === wp_get_post_categories( $poster_draft ), 'A category change invalidates the image approval and survives' );
wp_set_post_categories( $poster_draft, array( $poster_category ) );
$review = preview_item( 'post', $poster_draft, $image_change );
$result = apply_token( $review );
$stored = RestTools\stored_post( $poster_draft );
rest_check( ! is_wp_error( $result ) && array( 'featured_media' ) === $result['changed_fields'] && $poster_media === get_post_thumbnail_id( $poster_draft ), 'Approved image is stored and read back on an undated draft' );
rest_check( RestTools\metadata_columns( $stored ) === RestTools\metadata_columns( $original ) && array( $poster_category ) === wp_get_post_categories( $poster_draft ), 'Draft body, dates, slug, status, author, discussion settings and category survive the first image save' );
rest_check( is_wp_error( apply_token( $review ) ), 'Successful draft image approval is single use' );
$review = preview_item( 'post', $poster_draft, array( 'ping_status' => 'closed' ) );
$result = apply_token( $review );
rest_check( ! is_wp_error( $result ) && 'closed' === get_post( $poster_draft )->ping_status && '' === get_post( $poster_draft )->comment_status, 'An explicitly reviewed discussion change saves while the other empty setting is preserved' );

// Substitutions before storage, concurrent saves at claim, and unavailable read-back.
$target = hierarchy_page( 'Reviewed metadata' );
$plan   = preview_item( 'page', $target, array( 'title' => 'Approved title' ) );
$replace_title = static function ( $data, $args ) use ( $target ) {
	if ( ( $args['ID'] ?? 0 ) === $target ) {
		$data['post_title'] = 'Substituted title';
	}
	return $data;
};
add_filter( 'wp_insert_post_data', $replace_title, 10, 2 );
$result = apply_token( $plan );
remove_filter( 'wp_insert_post_data', $replace_title );
rest_check( is_wp_error( $result ) && 'Reviewed metadata' === get_post( $target )->post_title && 'updated' !== get_option( \ChurchWebsiteConnector\RestTools\AUDIT )[0]['result'], 'A substituted title is refused and never recorded as a successful edit' );
$plan = preview_item( 'page', $target, array( 'title' => 'Approved title' ) );
$during_claim = static function ( $pre, $hook ) use ( $target ) {
	if ( \ChurchWebsiteConnector\RestTools\EXPIRE_HOOK === $hook ) {
		wp_update_post( array( 'ID' => $target, 'post_title' => 'Native editor title' ) );
	}
	return $pre;
};
add_filter( 'pre_clear_scheduled_hook', $during_claim, 10, 2 );
$result = apply_token( $plan );
remove_filter( 'pre_clear_scheduled_hook', $during_claim );
rest_check( is_wp_error( $result ) && 'Native editor title' === get_post( $target )->post_title, 'A save after claim retains the independent editor change' );
$plan = preview_item( 'page', $target, array( 'title' => 'Another approved title' ) );
$race = static function ( $sql ) use ( &$race, $target, $wpdb ) {
	if ( str_starts_with( $sql, "UPDATE `{$wpdb->posts}` SET" ) && str_contains( $sql, 'Another approved title' ) ) {
		wp_update_post( array( 'ID' => $target, 'post_title' => 'Independent final save' ) );
	}
	return $sql;
};
add_filter( 'query', $race, 100 );
$result = apply_token( $plan );
remove_filter( 'query', $race, 100 );
rest_check( is_wp_error( $result ) && 'Independent final save' === get_post( $target )->post_title, 'The guarded SQL write preserves a native save after the last WordPress action' );
$plan = preview_item( 'page', $target, array( 'title' => 'Saved before read was interrupted' ) );
$no_read = static function ( $response, $server, $request ) use ( $target ) {
	return 'GET' === $request->get_method() && '/wp/v2/pages/' . $target === $request->get_route() ? new WP_Error( 'fixture', 'Fixture read unavailable', array( 'status' => 503 ) ) : $response;
};
$lose_read = static function () use ( $no_read ) {
	add_filter( 'rest_pre_dispatch', $no_read, 10, 3 );
};
add_action( 'rest_after_insert_page', $lose_read, PHP_INT_MAX );
$result = apply_token( $plan );
remove_action( 'rest_after_insert_page', $lose_read, PHP_INT_MAX );
remove_filter( 'rest_pre_dispatch', $no_read );
rest_check( is_wp_error( $result ) && 'verification_unavailable' === get_option( \ChurchWebsiteConnector\RestTools\AUDIT )[0]['result'], 'Read-back failure produces an unverified outcome after a write' );

$when = time() + DAY_IN_SECONDS;
$date = gmdate( 'Y-m-d H:i:s', $when );
$future = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'future', 'post_title' => 'Future service', 'post_date_gmt' => $date, 'post_date' => get_date_from_gmt( $date ) ) );
wp_clear_scheduled_hook( 'publish_future_post', array( $future ) );
rest_check( is_wp_error( preview_item( 'post', $future, array( 'title' => 'Updated future service' ) ) ) && false === wp_next_scheduled( 'publish_future_post', array( $future ) ), 'Metadata review preserves a missing native publication event' );
wp_schedule_single_event( $when + HOUR_IN_SECONDS, 'publish_future_post', array( $future ) );
rest_check( is_wp_error( preview_item( 'post', $future, array( 'title' => 'Updated future service' ) ) ) && $when + HOUR_IN_SECONDS === wp_next_scheduled( 'publish_future_post', array( $future ) ), 'Metadata review also preserves a mismatched publication event' );

$store = \ChurchWebsiteConnector\RestTools\AUDIT;
$entry = array( 'id' => $target, 'kind' => 'page', 'changes' => array( 'title' => 'example' ) );
$other_entry = array_merge( $entry, array( 'id' => $moving ) );
$overlap = static function ( $sql ) use ( &$overlap, $other_entry, $store ) {
	if ( str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, $store ) && str_contains( $sql, 'HEX(option_value)' ) ) {
		remove_filter( 'query', $overlap );
		\ChurchWebsiteConnector\RestTools\audit( $other_entry, 'updated' );
	}
	return $sql;
};
add_filter( 'query', $overlap );
\ChurchWebsiteConnector\RestTools\audit( $entry, 'updated' );
remove_filter( 'query', $overlap );
rest_check( array_slice( array_column( get_option( $store ), 'id' ), 0, 2 ) === array( $target, $moving ), 'A conditional retry retains both overlapping activity rows' );
$old_css = get_option( 'church_connector_used_css_log', array() );
update_option( 'church_connector_used_css_log', array( array( 'time_utc' => gmdate( 'c' ), 'post_id' => $target, 'status' => 'cleared' ) ) );
$css_rows = array_values( array_filter( \ChurchWebsiteConnector\Maintenance\recent_changes(), static fn( $row ) => 'Used CSS' === $row['source'] ) );
rest_check( $target === $css_rows[0]['item_id'], 'Recent activity uses the page ID recorded by Used CSS' );
update_option( 'church_connector_used_css_log', $old_css );

$roster_id = wp_insert_post( array( 'post_title' => 'Parish duty rota', 'post_status' => 'publish' ) );
$term = wp_insert_term( 'Roster', 'category' )['term_id'];
$other = wp_insert_term( 'Community roster', 'category' )['term_id'];
$plan = preview_item( 'post', $roster_id, array( 'title' => 'Approved roster title', 'categories' => array( $term ) ) );
$independent = static function ( $sql ) use ( $roster_id, $other, $wpdb ) {
	if ( str_starts_with( $sql, "UPDATE `{$wpdb->posts}` SET" ) && str_contains( $sql, 'Approved roster title' ) ) {
		wp_set_post_categories( $roster_id, array( $other ) );
	}
	return $sql;
};
add_filter( 'query', $independent, 100 );
$result = apply_token( $plan );
remove_filter( 'query', $independent, 100 );
rest_check( is_wp_error( $result ) && get_post( $roster_id )->post_title === 'Parish duty rota' && wp_get_post_categories( $roster_id ) === array( $other ), 'A concurrent category assignment survives with the original title and stops all approved fields' );

$late = wp_insert_term( 'Choir roster', 'category' )['term_id'];
$plan = preview_item( 'post', $roster_id, array( 'title' => 'New roster title', 'categories' => array( $term ) ) );
$after_post = static function ( $id ) use ( $roster_id, $late ) {
	if ( $id === $roster_id ) {
		wp_set_post_categories( $id, array( $late ) );
	}
};
add_action( 'save_post_post', $after_post, 11 );
$result = apply_token( $plan );
remove_action( 'save_post_post', $after_post, 11 );
rest_check( is_wp_error( $result ) && 'New roster title' === get_post( $roster_id )->post_title && wp_get_post_categories( $roster_id ) === array( $late ), 'A category assigned by a native post-save callback survives the remaining REST write and is reported as partial' );

file_put_contents( '/reports/metadata-guards.json', wp_json_encode( array( 'checks' => count( $checks ), 'cases' => $checks ) ) );
