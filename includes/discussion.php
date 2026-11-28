<?php
/** Sitewide policy for native WordPress public discussion. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Discussion;

use ChurchWebsiteConnector as Connector;

defined( 'ABSPATH' ) || exit;

function open_for_discussion( bool $open ): bool {
	return $open && ! Connector\feature_enabled( 'disable_comments' );
}

/** Native dashboard replies can submit a comment without checking comments_open(). */
function allow_submission( $approved, array $comment ) {
	if ( ! is_wp_error( $approved ) && in_array( $comment['comment_type'] ?? '', array( '', 'comment', 'pingback', 'trackback' ), true )
		&& Connector\feature_enabled( 'disable_comments' ) ) {
		return new \WP_Error( 'comment_closed', __( 'Public discussion is closed on this website.', 'church-website-connector' ), 403 );
	}
	return $approved;
}

function xmlrpc_methods( array $methods ): array {
	if ( Connector\feature_enabled( 'disable_comments' ) ) {
		unset( $methods['pingback.ping'] );
	}
	return $methods;
}

/** WordPress passes discovered pingback links by reference immediately before sending. */
function outgoing_pingbacks( array &$links ): void {
	if ( $links && Connector\feature_enabled( 'disable_comments' ) ) {
		$links = array();
	}
}

/** Editable page data must round-trip the stored choices even while public discussion is closed. */
function editable_page( array $data, \WP_Post $page ): array {
	if ( Connector\feature_enabled( 'disable_comments' ) ) {
		$data['mt_allow_comments'] = (int) ( 'open' === $page->comment_status );
		$data['mt_allow_pings']    = (int) ( 'open' === $page->ping_status );
	}
	return $data;
}

/** Do not enqueue outbound notifications for content published during closure. */
function queue_notification( $result, $post_id, $key ) {
	if ( null === $result && in_array( $key, array( '_pingme', '_trackbackme' ), true ) && Connector\feature_enabled( 'disable_comments' ) ) {
		return true;
	}
	return $result;
}

/** Leave the pending markers in storage when core asks for its batch of notification jobs. */
function pending_notifications( \WP_Query $query ): void {
	if ( in_array( $query->get( 'meta_key' ), array( '_pingme', '_trackbackme' ), true )
		&& 'ids' === $query->get( 'fields' ) && $query->get( 'nopaging' ) && get_post_types() === ( $query->query['post_type'] ?? null )
		&& Connector\feature_enabled( 'disable_comments' ) ) {
		// A distinct query also keeps a persistent object cache from hiding the queue after reopening.
		$query->set( 'post__in', array( 0 ) );
	}
}

function refresh_cache(): void {
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
}

function policy_changed( bool $closed ): void {
	// Upgrade migrations can run before WP Rocket loads its cache functions.
	if ( did_action( 'wp_loaded' ) ) {
		refresh_cache();
	} else {
		add_action( 'wp_loaded', __NAMESPACE__ . '\\refresh_cache' );
	}
	if ( ! $closed && ! wp_next_scheduled( 'do_pings' ) ) {
		wp_schedule_single_event( time(), 'do_pings' );
	}
}

function settings_changed( $previous, $next ): void {
	// The previous release had no discussion policy if this choice was missing.
	$before = is_array( $previous ) && true === ( $previous['disable_comments'] ?? false ) && Connector\settings_are_bound( $previous );
	$after  = is_array( $next ) && true === ( $next['disable_comments'] ?? true ) && Connector\settings_are_bound( $next );
	if ( $before !== $after ) {
		policy_changed( $after );
	}
}

function deactivating(): void {
	if ( Connector\feature_enabled( 'disable_comments' ) ) {
		policy_changed( false );
	}
}

function reactivated( $plugin ): void {
	if ( Connector\Updates\plugin_file() === $plugin && ! did_action( 'add_option_' . Connector\SETTINGS ) && Connector\feature_enabled( 'disable_comments' ) ) {
		policy_changed( true );
	}
}

/** Core omits this archive link once replies close, even when the post has approved comments. */
function comment_link_block( array $args, string $name ): array {
	if ( 'core/post-comments-link' !== $name || 'render_block_core_post_comments_link' !== ( $args['render_callback'] ?? null ) ) {
		return $args;
	}
	$args['render_callback'] = static function ( $attributes, $content, $block ) {
		$html = render_block_core_post_comments_link( $attributes, $content, $block );
		$id   = $block->context['postId'] ?? 0;
		if ( '' !== $html || ! $id || ! Connector\feature_enabled( 'disable_comments' ) || post_password_required( $id ) ) {
			return $html;
		}
		$count = (int) get_comments_number( $id );
		if ( ! $count ) {
			return $html;
		}
		$classes = isset( $attributes['textAlign'] ) ? 'has-text-align-' . sanitize_html_class( $attributes['textAlign'] ) : '';
		if ( isset( $attributes['style']['elements']['link']['color']['text'] ) ) {
			$classes .= ' has-link-color';
		}
		/* translators: 1: Number of published comments, 2: Post title. */
		$label = sprintf( _n( '%1$s comment<span class="screen-reader-text"> on %2$s</span>', '%1$s comments<span class="screen-reader-text"> on %2$s</span>', $count, 'church-website-connector' ), esc_html( number_format_i18n( $count ) ), esc_html( get_the_title( $id ) ) );
		return '<div ' . get_block_wrapper_attributes( array( 'class' => trim( $classes ) ) ) . '><a href="' . esc_url( get_comments_link( $id ) ) . '">' . $label . '</a></div>';
	};
	return $args;
}

/** Restore the classic product review list when WooCommerce would hide its tab with the form. */
function product_tabs( array $tabs ): array {
	global $product;
	if ( isset( $tabs['reviews'] ) || ! Connector\feature_enabled( 'disable_comments' ) || ! function_exists( 'wc_reviews_enabled' ) || ! wc_reviews_enabled()
		|| ! $product instanceof \WC_Product || ! $product->get_reviews_allowed() || ! $product->get_review_count() || post_password_required( $product->get_id() ) ) {
		return $tabs;
	}
	$tabs['reviews'] = array(
		/* translators: %s: Number of product reviews. */
		'title'    => sprintf( __( 'Reviews (%s)', 'church-website-connector' ), number_format_i18n( $product->get_review_count() ) ),
		'priority' => 30,
		'callback' => __NAMESPACE__ . '\\product_reviews',
	);
	return $tabs;
}

function product_reviews(): void {
	$template = static function () {
		return __DIR__ . '/discussion-reviews.php';
	};
	add_filter( 'comments_template', $template, PHP_INT_MAX );
	try {
		comments_template();
	} finally {
		remove_filter( 'comments_template', $template, PHP_INT_MAX );
	}
}

add_filter( 'comments_open', __NAMESPACE__ . '\open_for_discussion', PHP_INT_MAX );
add_filter( 'pings_open', __NAMESPACE__ . '\open_for_discussion', PHP_INT_MAX );
add_filter( 'pre_comment_approved', __NAMESPACE__ . '\allow_submission', PHP_INT_MAX, 2 );
add_filter( 'xmlrpc_methods', __NAMESPACE__ . '\xmlrpc_methods', PHP_INT_MAX );
add_action( 'pre_ping', __NAMESPACE__ . '\outgoing_pingbacks', PHP_INT_MAX );
add_filter( 'xmlrpc_prepare_page', __NAMESPACE__ . '\editable_page', 10, 2 );
add_filter( 'add_post_metadata', __NAMESPACE__ . '\queue_notification', 10, 3 );
add_action( 'pre_get_posts', __NAMESPACE__ . '\pending_notifications' );
add_action( 'update_option_' . Connector\SETTINGS, __NAMESPACE__ . '\settings_changed', 10, 2 );
add_action( 'add_option_' . Connector\SETTINGS, __NAMESPACE__ . '\settings_changed', 10, 2 );
add_action( 'deactivate_church-website-connector/church-website-connector.php', __NAMESPACE__ . '\deactivating' );
add_action( 'activated_plugin', __NAMESPACE__ . '\reactivated' );
add_filter( 'register_block_type_args', __NAMESPACE__ . '\comment_link_block', 10, 2 );
add_filter( 'woocommerce_product_tabs', __NAMESPACE__ . '\product_tabs', 9 );
