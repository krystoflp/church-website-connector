<?php
/** Read-only classic WooCommerce product reviews. WordPress prepares and paginates the comments query. */
defined( 'ABSPATH' ) || exit;

if ( post_password_required() || ! have_comments() ) {
	return;
}
?>
<div id="reviews" class="woocommerce-Reviews">
	<div id="comments">
		<h2 class="woocommerce-Reviews-title"><?php esc_html_e( 'Reviews', 'church-website-connector' ); ?></h2>
		<ol class="commentlist">
			<?php wp_list_comments( apply_filters( 'woocommerce_product_review_list_args', array( 'callback' => 'woocommerce_comments' ) ) ); ?>
		</ol>
		<?php the_comments_pagination(); ?>
	</div>
</div>
