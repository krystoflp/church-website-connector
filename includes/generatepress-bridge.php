<?php
declare(strict_types=1);

namespace ChurchWebsiteConnector\GeneratePressBridge;

defined( 'ABSPATH' ) || exit;

/** Existing MCP abilities only; never expose every future third-party ability. */
const ABILITIES = [
    'church-connector/connection-status', 'church-connector/used-css-status', 'church-connector/clear-used-css',
    'church-connector/upload-pdf', 'church-connector/rest-capabilities', 'church-connector/get-item-details',
    'church-connector/preview-item-update', 'church-connector/apply-item-update', 'church-connector/discard-item-update',
    'church-connector/redirection-capabilities', 'church-connector/list-redirections',
    'church-connector/get-redirection', 'church-connector/list-redirection-groups',
    'church-connector/preview-redirection-change', 'church-connector/apply-redirection-change',
    'church-connector/discard-redirection-change',
    'church-connector/integration-capabilities', 'church-connector/list-integration-items',
    'church-connector/get-integration-item', 'church-connector/preview-integration-change',
    'church-connector/apply-integration-change', 'church-connector/discard-integration-change',
    'wp-rocket/get-options', 'wp-rocket/set-option', 'wp-rocket/get-insights-scores',
    'wp-rocket/add-page-insights', 'wp-rocket/retest-page-insights', 'wp-rocket/remove-page-insights',
    'wp-rocket/get-recommendations', 'wp-rocket/get-page-insights-score',
    'wp-rocket/clear-url-cache', 'wp-rocket/clear-website-cache',
    'wp-rocket/get-cache-status', 'wp-rocket/get-cache-health',
];

function supported_version( string $version ): bool {
    return version_compare( $version, '1.0.0', '>=' ) && version_compare( $version, '1.1.0', '<' );
}

function environment_ready(): bool {
    return defined( 'GENERATEPRESS_AI_VERSION' ) && supported_version( (string) GENERATEPRESS_AI_VERSION )
        && class_exists( '\GeneratePress_AI_MCP' ) && class_exists( '\GeneratePress_AI_OAuth' )
        && function_exists( 'wp_get_ability' );
}

/** Use the provider's public extension filter; retain each native callback. */
function ability_ids( $ids ) {
    if ( ! is_array( $ids ) || ! \ChurchWebsiteConnector\feature_enabled( 'generatepress_bridge' ) || ! environment_ready() ) {
        return $ids;
    }
    foreach ( ABILITIES as $name ) {
        $ability = wp_get_ability( $name );
        if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
            continue;
        }
        $meta = $ability->get_meta();
        if ( is_array( $meta ) && ( $meta['mcp']['public'] ?? null ) === true && ! in_array( $name, $ids, true ) ) {
            $ids[] = $name;
        }
    }
    return $ids;
}

/** Repair WP Rocket 3.24's sparse enum without changing values or callbacks. */
function option_schema_args( $args, $name ) {
    if ( $name !== 'wp-rocket/set-option' || ! is_array( $args )
        || ! \ChurchWebsiteConnector\feature_enabled( 'generatepress_bridge' ) || ! environment_ready()
        || ! defined( 'WP_ROCKET_VERSION' ) || ! is_string( WP_ROCKET_VERSION )
        || version_compare( WP_ROCKET_VERSION, '3.24', '<' ) || version_compare( WP_ROCKET_VERSION, '3.25', '>=' ) ) {
        return $args;
    }
    $enum = $args['input_schema']['properties']['option_name']['enum'] ?? null;
    if ( ! is_array( $enum ) || ! $enum || array_is_list( $enum ) ) {
        return $args;
    }
    foreach ( $enum as $key => $value ) {
        if ( ! is_int( $key ) || $key < 0 || ! is_string( $value ) || $value === '' ) {
            return $args;
        }
    }
    // array_diff() removed a read-only option but retained the original keys.
    // Only reindex: never add/remove options or replace native permission checks.
    $args['input_schema']['properties']['option_name']['enum'] = array_values( $enum );
    return $args;
}

function status(): array {
    return [
        'enabled' => \ChurchWebsiteConnector\feature_enabled( 'generatepress_bridge' ),
        'compatible' => environment_ready(),
        'supported_generatepress_ai' => '>=1.0.0 <1.1.0',
        'credential_lifetime' => 'GeneratePress AI issues a non-expiring key, valid until revoked or its administrator loses access.',
        'permissions' => 'Only registered, MCP-public abilities in a fixed allowlist are added. Native account permissions, previews and operation checks remain in force.',
        'scope' => 'Enabling this adds the selected connector and WP Rocket tools to the GeneratePress AI endpoint for every authorised GeneratePress AI client on this website.',
        'revocation' => 'Use Settings → GeneratePress AI to revoke issued connections. Switching this helper off removes its extra tools without revoking the native key.',
    ];
}

add_filter( 'generatepress_ai_mcp_ability_ids', __NAMESPACE__ . '\\ability_ids' );
add_filter( 'wp_register_ability_args', __NAMESPACE__ . '\\option_schema_args', 10, 2 );
