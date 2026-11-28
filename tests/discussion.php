<?php
/** Native WordPress public discussion and outgoing notification checks. */
require_once '/wordpress/wp-load.php';

use ChurchWebsiteConnector as Connector;

$checks = array();
function rocket_clean_domain() {
	++$GLOBALS['discussion_purges'];
}
$GLOBALS['discussion_purges'] = 0;
function discussion_check( $ok, $description ) {
	if ( ! $ok ) {
		throw new RuntimeException( $description );
	}
	$GLOBALS['checks'][] = $description;
}

wp_set_current_user( 1 );
$saved = Connector\settings();
$flags = $saved;
$flags['disable_comments'] = false;
discussion_check( true === Connector\configure( $flags ), 'Administrator can opt out' );
$seed = array( 'post_status' => 'publish', 'post_title' => 'Discussion fixture', 'comment_status' => 'open', 'ping_status' => 'open' );
$id   = wp_insert_post( $seed );
$old  = wp_insert_comment( array( 'comment_post_ID' => $id, 'comment_content' => 'Previously published reply', 'comment_approved' => '1' ) );
$comment_route = new WP_REST_Request( 'POST', '/wp/v2/comments' );
$comment_route->set_body_params( array( 'post' => $id, 'content' => 'A new public reply', 'parent' => $old ) );
$targets = 'https://notification.example/older-post/';
wp_update_post( array( 'ID' => $id, 'to_ping' => $targets ) );
discussion_check( comments_open( $id ) && pings_open( $id ) && array( $targets ) === get_to_ping( $id ), 'Post accepts discussion and has an outgoing trackback before enabling' );

$flags['disable_comments'] = true;
$purges = $GLOBALS['discussion_purges'];
discussion_check( true === Connector\configure( $flags ), 'Administrator can close public discussion' );
discussion_check( $purges + 1 === $GLOBALS['discussion_purges'], 'Changing the effective setting refreshes the page cache' );
Connector\configure( $flags );
discussion_check( $purges + 1 === $GLOBALS['discussion_purges'], 'Saving the same setting leaves the page cache alone' );
$page = wp_insert_post( array( 'post_type' => 'page' ) + $seed );
$attachment = wp_insert_attachment( array( 'post_title' => 'Poster', 'comment_status' => 'open', 'ping_status' => 'open' ), false, $page );
foreach ( array( $id, $page, $attachment ) as $target ) {
	discussion_check( ! comments_open( $target ) && ! pings_open( $target ) && 'open' === get_post( $target )->comment_status, 'Effective closure preserves the stored setting for ' . get_post_type( $target ) );
}
wp_set_current_user( 0 );
ob_start();
comment_form( array(), $id );
$form = ob_get_clean();
discussion_check( ! str_contains( $form, '<form' ), 'Visitor comment form is closed' );
$read = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/comments/' . $old ) );
discussion_check( 200 === $read->get_status() && str_contains( $read->get_data()['content']['rendered'], 'Previously published reply' ), 'Published reply remains readable to visitors' );
$list = wp_list_comments( array( 'echo' => false ), get_comments( array( 'post_id' => $id, 'status' => 'approve' ) ) );
discussion_check( str_contains( $list, 'Previously published reply' ), 'WordPress renders the existing discussion on the public page' );
$block = new WP_Block( parse_blocks( '<!-- wp:post-comments-link /-->' )[0], array( 'postId' => $id, 'postType' => 'post' ) );
discussion_check( str_contains( $block->render(), esc_url( get_comments_link( $id ) ) ), 'Archive link still leads to the approved comment while closed' );
$submission = wp_handle_comment_submission( array( 'comment_post_ID' => $id, 'comment' => 'Visitor reply', 'author' => 'Visitor', 'email' => 'visitor@example.org' ) );
discussion_check( is_wp_error( $submission ) && 'comment_closed' === $submission->get_error_code(), 'WordPress rejects new visitor comments' );
wp_set_current_user( 1 );
$response = rest_do_request( $comment_route );
discussion_check( 403 === $response->get_status() && 'rest_comment_closed' === $response->get_data()['code'], 'REST refuses a new public reply from an administrator' );
foreach ( array( 'comment', 'pingback', 'trackback' ) as $type ) {
	$reply = wp_new_comment( array( 'comment_post_ID' => $id, 'comment_parent' => $old, 'comment_type' => $type, 'user_id' => 1, 'comment_author' => 'Editor', 'comment_author_email' => 'editor@example.org', 'comment_content' => 'New ' . $type ), true );
	discussion_check( is_wp_error( $reply ) && 'comment_closed' === $reply->get_error_code(), 'New native submission refused for ' . $type );
}
$methods = apply_filters( 'xmlrpc_methods', array( 'pingback.ping' => 1, 'pingback.extensions.getPingbacks' => 1, 'wp.getPost' => 1 ) );
discussion_check( ! isset( $methods['pingback.ping'] ) && isset( $methods['pingback.extensions.getPingbacks'], $methods['wp.getPost'] ), 'XML-RPC retains reads and closes incoming pingbacks' );
$attempts = 0;
$block_http = static function () use ( &$attempts ) {
	++$attempts;
	return new WP_Error( 'test_http_disabled' );
};
add_filter( 'pre_http_request', $block_http );
$result = pingback( '<a href="' . $targets . '">Earlier post</a>', $id );
remove_action( 'do_all_pings', 'generic_ping' );
remove_action( 'do_all_pings', 'do_all_enclosures' );
remove_action( 'do_all_pings', 'wp_maybe_disable_outgoing_pings_for_environment', 1 );
discussion_check( metadata_exists( 'post', $id, '_trackbackme' ), 'A previously queued trackback exists before the worker runs' );
do_all_pings();
remove_filter( 'pre_http_request', $block_http );
discussion_check( array() === $result && 0 === $attempts, 'Native outgoing pingback and queued trackback perform no HTTP requests' );
clean_post_cache( $id );
discussion_check( metadata_exists( 'post', $id, '_trackbackme' ) && array( $targets ) === get_to_ping( $id ), 'The native worker preserves both the old job and its destination during closure' );
update_option( 'default_pingback_flag', 1 );
$during = wp_insert_post( array( 'post_content' => '<a href="https://notification.example/during/">Link</a>', 'to_ping' => $targets ) + $seed );
discussion_check( ! metadata_exists( 'post', $during, '_pingme' ) && ! metadata_exists( 'post', $during, '_trackbackme' ), 'Publishing during closure queues neither outgoing notification' );
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . WPINC . '/class-IXR.php';
require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
$password = wp_generate_password( 40 );
$editor   = wp_insert_user( array( 'user_login' => 'discussion-editor', 'user_pass' => $password, 'role' => 'editor' ) );
$server   = new wp_xmlrpc_server();
$editable = $server->wp_getPage( array( 1, $page, 'discussion-editor', $password ) );
discussion_check( 1 === $editable['mt_allow_comments'] && 1 === $editable['mt_allow_pings'], 'Legacy page editor receives the stored choices' );
$editable['title'] = 'Edited page title';
discussion_check( true === $server->wp_editPage( array( 1, $page, 'discussion-editor', $password, $editable, true ) ), 'Legacy editor saves an ordinary title change' );
wp_set_current_user( 1 );
wp_delete_user( $editor, 1 );
discussion_check( 1 === (int) get_comments( array( 'post_id' => $id, 'count' => true ) ), 'Only the previously published comment exists' );
$flags['disable_comments'] = false;
$purges = $GLOBALS['discussion_purges'];
Connector\configure( $flags );
discussion_check( $purges + 1 === $GLOBALS['discussion_purges'] && wp_next_scheduled( 'do_pings' ), 'Reopening refreshes cached forms and schedules pending notifications' );
discussion_check( comments_open( $id ) && pings_open( $id ) && comments_open( $page ), 'Opt-out restores the local choices on older and newer posts' );
$closed = wp_insert_post( array( 'comment_status' => 'closed', 'ping_status' => 'closed' ) + $seed );
discussion_check( ! comments_open( $closed ) && ! pings_open( $closed ), 'A locally closed post stays closed after opt-out' );
discussion_check( 201 === rest_do_request( $comment_route )->get_status(), 'Public replies work again where the post accepts them' );
Connector\configure( $saved );
file_put_contents( '/reports/discussion.json', wp_json_encode( array( 'checks' => count( $checks ), 'cases' => $checks ) ) );
