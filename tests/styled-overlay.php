<?php
/** Exercise the released connector's overlay save against the WordPress REST controller. */
require_once '/wordpress/wp-load.php';

$native_pro = defined( 'GENERATEBLOCKS_PRO_VERSION' );
if ( $native_pro ) {
    // Private suite supplies the real Pro plugin and the provider classes.
    require_once '/provider-fixtures/GeneratePress-AI-MCP.php';
    require_once '/provider-fixtures/GeneratePress-AI-OAuth.php';
    define( 'GENERATEPRESS_AI_VERSION', '1.0.0' );
    if ( ! post_type_exists( 'gblocks_overlay' ) || ! class_exists( 'GenerateBlocks_Pro_Overlays' ) ) {
        throw new RuntimeException( 'GenerateBlocks Pro overlay module missing from native test.' );
    }
} else {
    // Public release CI has no licensed plugins. Supply only their registration and capability interfaces.
    define( 'GENERATEPRESS_AI_VERSION', '1.0.0' );
    define( 'GENERATEBLOCKS_PRO_VERSION', '2.8.0' );
    class GeneratePress_AI_MCP {}
    class GeneratePress_AI_OAuth {}
    class GenerateBlocks_Pro_Overlays {
        public static function current_user_can_use_overlays( $action ) {
            return 'manage' === $action && current_user_can( 'manage_options' );
        }
    }
    register_post_type( 'gblocks_overlay', [ 'show_in_rest' => true, 'supports' => [ 'title', 'editor', 'custom-fields' ] ] );
    register_post_meta( 'gblocks_overlay', '_gb_overlay_accessible_label', [
        'type' => 'string', 'single' => true, 'show_in_rest' => true,
        'auth_callback' => static fn() => current_user_can( 'manage_options' ),
    ] );
}
wp_set_current_user( 1 );

function overlay_result( $result, string $operation ): array {
    if ( ! is_array( $result ) ) {
        throw new RuntimeException( $operation . ' failed: ' . $result->get_error_message() );
    }
    return $result;
}

function overlay_change( int $id, string $operation, array $changes = [] ) {
    $target = [ 'site_url' => home_url(), 'kind' => 'overlay', 'id' => $id ];
    $read = overlay_result( wp_get_ability( 'church-connector/get-design-item' )->execute( $target ), 'Read overlay' );
    $preview = overlay_result( wp_get_ability( 'church-connector/preview-design-change' )->execute(
        $target + [ 'state_hash' => $read['item']['state_hash'], 'operation' => $operation, 'changes' => $changes ]
    ), 'Preview ' . $operation );
    return wp_get_ability( 'church-connector/apply-design-change' )->execute( [
        'site_url' => home_url(), 'approved' => true, 'preview_token' => $preview['preview_token'],
    ] );
}

$attrs = [ 'tagName' => 'a', 'styles' => [ '&:hover' => [ 'color' => 'red' ], '&:focus-visible' => [ 'outline' => '2px solid' ] ] ];
$markup = get_comment_delimited_block_content( 'generateblocks/element', $attrs, '<a href="#details">Details</a>' );
$id = wp_insert_post( wp_slash( [ 'post_type' => 'gblocks_overlay', 'post_title' => 'Styled overlay', 'post_status' => 'draft', 'post_content' => $markup ] ), true );
if ( is_wp_error( $id ) || substr_count( $markup, '\u0026' ) !== 2 || get_post_field( 'post_content', $id, 'raw' ) !== $markup ) {
    throw new RuntimeException( 'Fixture must store both escaped style selectors verbatim.' );
}

$changes = [ 'label' => 'Event details' ];
if ( $native_pro ) {
    $condition = wp_insert_post( [ 'post_type' => 'gblocks_condition', 'post_status' => 'publish', 'post_title' => 'Overlay display condition' ], true );
    if ( is_wp_error( $condition ) ) {
        throw new RuntimeException( $condition->get_error_message() );
    }
    $changes['condition_id'] = $condition;
}
overlay_result( overlay_change( $id, 'update_overlay', $changes ), 'Save overlay settings' );
if ( $native_pro && (string) $condition !== get_post_meta( $id, '_gb_overlay_display_condition', true ) ) {
    throw new RuntimeException( 'The selected display condition must be attached to the styled overlay.' );
}
if ( 'Event details' !== get_post_meta( $id, '_gb_overlay_accessible_label', true ) || get_post_field( 'post_content', $id, 'raw' ) !== $markup ) {
    throw new RuntimeException( 'Overlay settings save must preserve reviewed content.' );
}
foreach ( [ 'post_content' => '<p>Unexpected edit</p>', 'post_status' => 'private' ] as $field => $unexpected ) {
    $change_before_save = static function ( $data, $post ) use ( $id, $field, $unexpected ) {
        if ( (int) ( $post['ID'] ?? 0 ) === $id ) {
            $data[ $field ] = $unexpected;
        }
        return $data;
    };
    add_filter( 'wp_insert_post_data', $change_before_save, 100, 2 );
    try {
        $result = overlay_change( $id, 'publish' );
    } finally {
        remove_filter( 'wp_insert_post_data', $change_before_save, 100 );
    }
    if ( ! is_wp_error( $result ) || 'church_connector_rest_design_save' !== $result->get_error_code()
        || 'draft' !== get_post_status( $id ) || get_post_field( 'post_content', $id, 'raw' ) !== $markup ) {
        throw new RuntimeException( 'The save guard must reject the unexpected change to ' . $field );
    }
}
overlay_result( overlay_change( $id, 'publish' ), 'Publish styled overlay' );
if ( 'publish' !== get_post_status( $id ) || $markup !== get_post_field( 'post_content', $id, 'raw' ) ) {
    throw new RuntimeException( 'Publishing must preserve the exact reviewed block content.' );
}
file_put_contents( '/reports/styled-overlay.json', wp_json_encode( [ 'native_pro' => $native_pro, 'published' => true ] ) );
