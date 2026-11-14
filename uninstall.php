<?php
declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( (array) get_option( 'church_connector_used_css_grantee', [] ) as $user_id ) {
    $user = get_user_by( 'id', (int) $user_id );
    if ( $user ) {
        $user->remove_cap( 'church_connector_clear_used_css' );
    }
}
foreach ( [ 'church_website_connector_settings', 'church_connector_used_css_grantee', 'church_connector_used_css_log', 'church_connector_used_css_lock', 'church_connector_used_css_last_clear' ] as $option ) {
    delete_option( $option );
}

global $wpdb;
if ( isset( $wpdb ) && is_callable( [ $wpdb, 'get_col' ] ) ) {
    $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'church_connector_rest_preview_' ) . '%', $wpdb->esc_like( 'church_connector_rest_lock_' ) . '%', $wpdb->esc_like( 'church_connector_redirect_preview_' ) . '%' ) );
    foreach ( $names as $name ) { delete_option( $name ); }
    $update_cache = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_church_connector_release_' ) . '%', $wpdb->esc_like( '_transient_timeout_church_connector_release_' ) . '%' ) );
    foreach ( $update_cache as $name ) { delete_option( $name ); }
}
delete_option( 'church_connector_rest_audit' );
if ( function_exists( 'wp_unschedule_hook' ) ) { wp_unschedule_hook( 'church_connector_expire_rest_preview' ); }

foreach ( [ 'church_connector_redirect_lock', 'church_connector_redirect_audit' ] as $option ) { delete_option( $option ); }
if ( function_exists( 'wp_unschedule_hook' ) ) { wp_unschedule_hook( 'church_connector_expire_redirect_preview' ); }

// Remove bounded integration previews, locks and expiry events; preserve vendor data.
if ( isset( $wpdb ) && is_callable( [ $wpdb, 'get_col' ] ) ) {
    $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'church_connector_integration_preview_' ) . '%', $wpdb->esc_like( 'church_connector_integration_lock_' ) . '%' ) );
    foreach ( $names as $name ) { delete_option( $name ); }
}
delete_option( 'church_connector_integration_audit' );
if ( function_exists( 'wp_unschedule_hook' ) ) { wp_unschedule_hook( 'church_connector_expire_integration_preview' ); }
