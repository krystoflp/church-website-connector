<?php
/** Bounded SEO fields and site settings, using native vendor data and REST interfaces. */
declare(strict_types=1);
namespace ChurchWebsiteConnector\Integrations\Seo;
use ChurchWebsiteConnector\Integrations as I;
defined( 'ABSPATH' ) || exit;

function definition( string $provider ): array {
    return $provider === 'seo-framework'
        ? [ 'label' => 'The SEO Framework', 'constant' => 'THE_SEO_FRAMEWORK_VERSION', 'minimum' => '5.2.1', 'before' => '5.3', 'tested' => '5.2.1', 'interfaces' => [ 'tsf', '\\The_SEO_Framework\\Data\\Filter\\Post::filter_meta_update', '\\The_SEO_Framework\\Data\\Filter\\Plugin::filter_settings_update' ], 'operations' => [ 'update' ] ]
        : [ 'label' => 'Rank Math', 'constant' => 'RANK_MATH_VERSION', 'minimum' => '1.0.282', 'before' => '1.1', 'tested' => '1.0.282', 'interfaces' => [ '\\RankMath\\Helper::get_settings', '\\RankMath\\Rest\\Rest_Helper::get_post_permissions_check', '\\RankMath\\Admin\\Sanitize_Settings::sanitize' ], 'operations' => [ 'update' ] ];
}
function text( int $length = 2000 ): array { return [ 'type' => 'string', 'maxLength' => $length ]; }
function toggle(): array { return [ 'type' => 'boolean' ]; }
function url(): array { return [ 'type' => 'string', 'maxLength' => 2000, 'format' => 'uri' ]; }
function robots(): array { return [ 'type' => 'array', 'maxItems' => 8, 'uniqueItems' => true, 'items' => [ 'type' => 'string', 'enum' => [ 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'notranslate' ] ] ]; }
function schemas( string $provider ): array {
    if ( $provider === 'seo-framework' ) {
        $post = [ 'title' => text(), 'description' => text( 5000 ), 'canonical_url' => url(), 'noindex' => [ 'type' => 'integer', 'enum' => [ -1, 0, 1 ] ], 'nofollow' => [ 'type' => 'integer', 'enum' => [ -1, 0, 1 ] ], 'noarchive' => [ 'type' => 'integer', 'enum' => [ -1, 0, 1 ] ], 'og_title' => text(), 'og_description' => text( 5000 ), 'twitter_title' => text(), 'twitter_description' => text( 5000 ), 'social_image_url' => url(), 'social_image_id' => [ 'type' => 'integer', 'minimum' => 0 ], 'exclude_local_search' => toggle(), 'exclude_from_archive' => toggle() ];
        $settings = [ 'site_title' => text(), 'homepage_title' => text(), 'homepage_description' => text( 5000 ), 'homepage_og_title' => text(), 'homepage_og_description' => text( 5000 ), 'homepage_twitter_title' => text(), 'homepage_twitter_description' => text( 5000 ), 'title_separator' => [ 'type' => 'string', 'enum' => [ 'hyphen', 'ndash', 'mdash', 'bull', 'middot', 'pipe', 'raquo', 'laquo', 'gt', 'lt' ] ], 'title_rem_additions' => toggle(), 'title_rem_prefixes' => toggle(), 'auto_description' => toggle(), 'site_noindex' => toggle(), 'site_nofollow' => toggle(), 'site_noarchive' => toggle(), 'homepage_noindex' => toggle(), 'homepage_nofollow' => toggle(), 'homepage_noarchive' => toggle(), 'author_noindex' => toggle(), 'date_noindex' => toggle(), 'search_noindex' => toggle(), 'sitemaps_output' => toggle(), 'sitemap_query_limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50000 ], 'sitemaps_modified' => toggle(), 'sitemaps_robots' => toggle(), 'og_tags' => toggle(), 'twitter_tags' => toggle() ];
        return [ 'post' => $post, 'settings' => $settings ];
    }
    $post = [ 'title' => text(), 'description' => text( 5000 ), 'canonical_url' => url(), 'focus_keyword' => text(), 'robots' => robots(), 'og_title' => text(), 'og_description' => text( 5000 ), 'twitter_title' => text(), 'twitter_description' => text( 5000 ), 'og_image' => url(), 'twitter_image' => url() ];
    $general = [ 'breadcrumbs' => toggle(), 'breadcrumbs_home' => toggle(), 'breadcrumbs_separator' => text( 30 ), 'nofollow_external_links' => toggle(), 'nofollow_image_links' => toggle(), 'new_window_external_links' => toggle() ];
    $titles = [ 'homepage_title' => text(), 'homepage_description' => text( 5000 ), 'title_separator' => text( 20 ), 'capitalize_titles' => toggle(), 'noindex_empty_taxonomies' => toggle(), 'robots_global' => robots(), 'twitter_card_type' => [ 'type' => 'string', 'enum' => [ 'summary', 'summary_large_image' ] ] ];
    $sitemap = [ 'items_per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50000 ], 'include_images' => toggle(), 'include_featured_image' => toggle(), 'authors_sitemap' => toggle(), 'exclude_posts' => [ 'type' => 'string', 'maxLength' => 5000, 'pattern' => '^([0-9]+(,[ ]*[0-9]+)*)?$' ], 'exclude_terms' => [ 'type' => 'string', 'maxLength' => 5000, 'pattern' => '^([0-9]+(,[ ]*[0-9]+)*)?$' ] ];
    return [ 'post' => $post, 'general' => $general, 'titles' => $titles, 'sitemap' => $sitemap ];
}
function tsf_map(): array {
    return [ 'title' => '_genesis_title', 'description' => '_genesis_description', 'canonical_url' => '_genesis_canonical_uri', 'noindex' => '_genesis_noindex', 'nofollow' => '_genesis_nofollow', 'noarchive' => '_genesis_noarchive', 'og_title' => '_open_graph_title', 'og_description' => '_open_graph_description', 'twitter_title' => '_twitter_title', 'twitter_description' => '_twitter_description', 'social_image_url' => '_social_image_url', 'social_image_id' => '_social_image_id', 'exclude_local_search' => 'exclude_local_search', 'exclude_from_archive' => 'exclude_from_archive' ];
}
function rank_map(): array {
    return [ 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'canonical_url' => 'rank_math_canonical_url', 'focus_keyword' => 'rank_math_focus_keyword', 'robots' => 'rank_math_robots', 'og_title' => 'rank_math_facebook_title', 'og_description' => 'rank_math_facebook_description', 'twitter_title' => 'rank_math_twitter_title', 'twitter_description' => 'rank_math_twitter_description', 'og_image' => 'rank_math_facebook_image', 'twitter_image' => 'rank_math_twitter_image' ];
}
function permission( array $target ) {
    if ( $target['kind'] === 'post' ) {
        $post = get_post( $target['id'] );
        if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) || $post->post_status === 'trash' || ! current_user_can( 'edit_post', $post->ID ) ) { return I\error( 'forbidden', 'Your account must be allowed to edit this existing post or page.', 403 ); }
        if ( $target['provider'] === 'seo-framework' ) {
            if ( ! \The_SEO_Framework\Helper\Post_Type::is_supported( $post->post_type ) ) { return I\error( 'forbidden', 'The SEO Framework does not permit SEO editing for this item.', 403 ); }
        } else {
            $request = new \WP_REST_Request( 'POST', '/rankmath/v1/updateMeta' ); $request->set_body_params( [ 'objectID' => $post->ID, 'objectType' => 'post' ] );
            $allowed = \RankMath\Rest\Rest_Helper::get_post_permissions_check( $request ); if ( is_wp_error( $allowed ) ) { return $allowed; } if ( $allowed !== true ) { return I\error( 'forbidden', 'Rank Math does not permit editing this item.', 403 ); }
        }
    } else {
        $cap = $target['provider'] === 'seo-framework' ? THE_SEO_FRAMEWORK_SETTINGS_CAP : 'rank_math_' . $target['section'];
        if ( ! current_user_can( $cap ) ) { return I\error( 'forbidden', 'Your account does not have the plugin’s native settings permission.', 403 ); }
    }
    return true;
}
function schema( array $target ): array { return schemas( $target['provider'] )[ $target['kind'] === 'post' ? 'post' : ( $target['provider'] === 'rank-math' ? $target['section'] : 'settings' ) ]; }
function raw( array $target ): array {
    if ( $target['provider'] === 'seo-framework' ) { return $target['kind'] === 'post' ? tsf()->data()->plugin()->post()->get_meta( $target['id'] ) : tsf()->data()->plugin()->get_options(); }
    if ( $target['kind'] === 'settings' ) { return (array) \RankMath\Helper::get_settings( $target['section'] ); }
    $result = []; foreach ( rank_map() as $key ) { $result[ $key ] = get_post_meta( $target['id'], $key, true ); } return $result;
}
function visible( array $target, array $raw ): array {
    $fields = schema( $target ); $out = []; $map = $target['kind'] === 'post' ? ( $target['provider'] === 'seo-framework' ? tsf_map() : rank_map() ) : array_combine( array_keys( $fields ), array_keys( $fields ) );
    foreach ( $fields as $name => $spec ) {
        $value = $raw[ $map[ $name ] ] ?? null;
        $out[ $name ] = match ( $spec['type'] ) { 'boolean' => in_array( $value, [ true, 1, '1', 'on' ], true ), 'integer' => (int) $value, 'array' => is_array( $value ) ? $value : [], default => is_string( $value ) ? $value : '' };
    }
    return $out;
}
function read( array $target ) {
    $ok = permission( $target ); if ( is_wp_error( $ok ) ) { return $ok; }
    $raw = raw( $target ); $state = $raw;
    if ( $target['kind'] === 'post' ) { $post = get_post( $target['id'] ); $state = [ $raw, $post->post_type, $post->post_status, $post->post_modified_gmt, hash( 'sha256', $post->post_content ) ]; }
    return [ 'data' => visible( $target, $raw ), 'state' => $state, 'raw' => $raw ];
}
function rank_field_types( array $fields ): array {
    $types = []; foreach ( $fields as $key => $spec ) { $types[ $key ] = match ( $spec['type'] ) { 'boolean' => 'toggle', 'integer' => 'number', 'array' => 'checkboxlist', default => 'text' }; } return $types;
}
function prepare( array $target, array $item, string $operation, array $changes ) {
    if ( $operation !== 'update' ) { return I\error( 'operation', 'SEO integrations support reviewed field updates.' ); }
    $schema = schema( $target ); $clean = I\validate_fields( $changes, $schema ); if ( is_wp_error( $clean ) ) { return $clean; }
    foreach ( [ 'robots', 'robots_global' ] as $robots_key ) { if ( isset( $clean[$robots_key] ) && ( ( in_array( 'index', $clean[$robots_key], true ) && in_array( 'noindex', $clean[$robots_key], true ) ) || ( in_array( 'follow', $clean[$robots_key], true ) && in_array( 'nofollow', $clean[$robots_key], true ) ) ) ) { return I\error( 'robots', 'Do not combine contradictory robots directives.' ); } }
    if ( $target['provider'] === 'seo-framework' ) {
        $merged = $item['raw']; $map = $target['kind'] === 'post' ? tsf_map() : array_combine( array_keys( $schema ), array_keys( $schema ) );
        foreach ( $clean as $name => $value ) { $merged[ $map[ $name ] ] = is_bool( $value ) ? (int) $value : $value; }
        $normalised = $target['kind'] === 'post' ? \The_SEO_Framework\Data\Filter\Post::filter_meta_update( $merged ) : \The_SEO_Framework\Data\Filter\Plugin::filter_settings_update( $merged, THE_SEO_FRAMEWORK_SITE_OPTIONS, $merged );
        $clean = array_intersect_key( visible( $target, $normalised ), $clean );
    } elseif ( $target['kind'] === 'settings' ) {
        $normalised = \RankMath\Admin\Sanitize_Settings::sanitize( $clean, rank_field_types( $schema ) );
        $clean = array_intersect_key( visible( $target, $normalised ), $clean );
    } else {
        $sanitizer = \RankMath\Rest\Sanitize::get(); $normalised = [];
        foreach ( $clean as $name => $value ) { $key = rank_map()[ $name ]; $normalised[ $key ] = $value === '' || $value === [] ? $value : $sanitizer->sanitize( $key, $value ); }
        $clean = array_intersect_key( visible( $target, $normalised ), $clean );
    }
    return [ 'changes' => $clean, 'diff' => I\diff( $item['data'], $clean ), 'warning' => $target['kind'] === 'settings' ? 'Preview only. This changes site-wide SEO behaviour; review indexing and sitemap effects before approval.' : 'Preview only. Review the SEO metadata diff before approval.' ];
}
function write( array $target, array $item, string $operation, array $changes ) {
    $ok = permission( $target ); if ( is_wp_error( $ok ) ) { return $ok; }
    if ( $target['provider'] === 'seo-framework' ) {
        $values = $target['kind'] === 'post' ? $item['raw'] : [];
        foreach ( $changes as $name => $value ) { $key = $target['kind'] === 'post' ? tsf_map()[ $name ] : $name; $values[ $key ] = is_bool( $value ) ? (int) $value : $value; }
        if ( $target['kind'] === 'post' ) { tsf()->data()->plugin()->post()->save_meta( $target['id'], $values ); }
        else { tsf()->data()->plugin()->update_option( $values ); \The_SEO_Framework\Sitemap\Registry::refresh_sitemaps(); \The_SEO_Framework\Helper\Query\Exclusion::clear_excluded_post_ids_cache(); }
    } elseif ( $target['kind'] === 'post' ) {
        $meta = []; foreach ( $changes as $name => $value ) { $meta[ rank_map()[ $name ] ] = $value; }
        $result = I\native_rest( 'POST', '/rankmath/v1/updateMeta', [ 'objectID' => $target['id'], 'objectType' => 'post', 'meta' => $meta ] ); if ( is_wp_error( $result ) ) { return $result; }
    } else {
        $result = I\native_rest( 'POST', '/rankmath/v1/updateSettings', [ 'type' => $target['section'], 'settings' => $changes, 'fieldTypes' => rank_field_types( schema( $target ) ), 'updated' => array_keys( $changes ), 'isReset' => false ] ); if ( is_wp_error( $result ) ) { return $result; }
    }
    $after = visible( $target, raw( $target ) ); foreach ( $changes as $key => $value ) { if ( I\canonical( $after[ $key ] ?? null ) !== I\canonical( $value ) ) { return I\error( 'readback', 'The plugin normalised or refused an approved field. Inspect the saved SEO values.', 502 ); } }
    return [ 'verified_fields' => array_keys( $changes ) ];
}
function listing( string $provider, int $page, int $limit, $type ) {
    if ( ! in_array( $type, [ 'post', 'page' ], true ) ) { return I\error( 'post_type', 'Choose post or page.' ); }
    $query = new \WP_Query( [ 'post_type' => $type, 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'posts_per_page' => $limit, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ] ); $items = [];
    foreach ( $query->posts as $post ) { $target = [ 'provider' => $provider, 'kind' => 'post', 'id' => $post->ID, 'section' => '' ]; if ( ! is_wp_error( permission( $target ) ) ) { $items[] = [ 'id' => $post->ID, 'post_type' => $post->post_type, 'title' => $post->post_title, 'status' => $post->post_status ]; } }
    return $items;
}
