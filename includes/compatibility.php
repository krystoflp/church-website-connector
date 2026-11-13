<?php
declare(strict_types=1);

// Native OAuth compatibility; never replace the providers' permission checks.
namespace ChurchWebsiteConnector\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Yield only the two exact root requests already routed to WP Rocket.
 * This changes metadata routing, not authentication, tokens, or capabilities.
 */
function allow_rocket_discovery( $wp ) {
	if ( ! \ChurchWebsiteConnector\feature_enabled( 'compatibility' ) ) {
		return;
	}
	if ( ! defined( 'WP_ROCKET_VERSION' ) || ! class_exists( '\GeneratePress_AI_OAuth' ) ) {
		return;
	}
	$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	$documents = array(
		'/.well-known/oauth-protected-resource' => 'protected-resource',
		'/.well-known/oauth-protected-resource/' => 'protected-resource',
		'/.well-known/oauth-authorization-server' => 'authorization-server',
		'/.well-known/oauth-authorization-server/' => 'authorization-server',
	);
	if ( ! is_string( $path ) || ! isset( $documents[ $path ] ) ) {
		return;
	}
	if ( ( $wp->query_vars['mcp_oauth_discovery'] ?? '' ) !== $documents[ $path ] ) {
		return;
	}
	remove_action( 'parse_request', array( \GeneratePress_AI_OAuth::get_instance(), 'serve_well_known_metadata' ), 0 );
}

add_action( 'parse_request', __NAMESPACE__ . '\\allow_rocket_discovery', -1 );

/**
 * Add the exact official Codex public client through WP Rocket's native filter.
 * The native resolver still fetches and validates the document, and the native
 * authorization flow still checks the redirect, PKCE, consent and permissions.
 */
function trust_official_codex( array $publishers ): array {
	if ( ! \ChurchWebsiteConnector\feature_enabled( 'codex' ) ) { return $publishers; }
	$publishers['church-website-connector-codex'] = array(
		'client_ids' => array( 'https://chatgpt.com/oauth/codex/client.json' ),
		'host' => 'chatgpt.com',
	);
	return $publishers;
}

add_filter( 'wpmedia_mcp_oauth_trusted_publishers', __NAMESPACE__ . '\\trust_official_codex' );
