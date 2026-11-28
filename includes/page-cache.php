<?php
/** Clear the page cache for one address on the bound site through GeneratePress MCP. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\PageCache;

use ChurchWebsiteConnector as Connector;
use ChurchWebsiteConnector\UsedCss;

defined( 'ABSPATH' ) || exit;

function available(): bool {
	return Connector\feature_enabled( 'generatepress_bridge' ) && Connector\GeneratePressBridge\environment_ready()
		&& defined( 'WP_ROCKET_VERSION' ) && version_compare( WP_ROCKET_VERSION, '3.24', '>=' ) && version_compare( WP_ROCKET_VERSION, '3.25', '<' );
}

function permission( $input ) {
	if ( ! available() || ! is_user_logged_in() || ! current_user_can( 'rocket_manage_options' ) ) {
		return new \WP_Error( 'church_connector_cache_denied', __( 'This account needs WP Rocket cache permission on the connected website.', 'church-website-connector' ), array( 'status' => 403 ) );
	}
	$native = wp_get_abilities()['wp-rocket/clear-url-cache'] ?? null;
	return $native ? $native->check_permissions( array( 'url' => $input['url'] ?? '' ) ) : false;
}

function clear( $input ) {
	$allowed = permission( $input );
	if ( true !== $allowed ) {
		return is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'church_connector_cache_denied', __( 'WP Rocket declined this cache operation.', 'church-website-connector' ), array( 'status' => 403 ) );
	}
	$site       = Connector\bound_site();
	$input_site = $input['site_url'] ?? null;
	$url        = $input['url'] ?? null;
	$parts      = is_string( $url ) ? wp_parse_url( $url ) : false;
	$path       = is_array( $parts ) ? ( $parts['path'] ?? '/' ) : '';
	if ( $input_site !== $site || ! $parts || ( $site !== $url && ! str_starts_with( $url, $site . '/' ) )
		|| array_diff( array_keys( $parts ), array( 'scheme', 'host', 'path' ) ) || ! UsedCss\canonical_path_allowed( $path )
		|| preg_match( '~%(?:2f|5c|25)|%(?![0-9a-f]{2})~i', $path ) || preg_match( '~[%*?#\\\\\x00-\x1f\x7f]~', rawurldecode( $path ) )
		|| str_starts_with( trailingslashit( $url ), admin_url() ) || wp_login_url() === $url ) {
		return new \WP_Error( 'church_connector_cache_url', __( 'Choose one public page address on the verified website.', 'church-website-connector' ), array( 'status' => 400 ) );
	}
	if ( ( function_exists( 'get_rocket_cache_query_string' ) && get_rocket_cache_query_string() ) || ( '' === (string) get_option( 'permalink_structure' ) && untrailingslashit( $url ) === $site ) ) {
		return new \WP_Error( 'church_connector_shared_cache_path', __( 'This address shares a cache location with other query URLs.', 'church-website-connector' ), array( 'status' => 409 ) );
	}
	try {
		$container = apply_filters( 'rocket_container', null );
		$purge     = $container ? $container->get( 'purge' ) : null;
		if ( ! is_callable( array( $purge, 'purge_url' ) ) ) {
			return new \WP_Error( 'church_connector_cache_unavailable', __( 'The WP Rocket page purge service is unavailable.', 'church-website-connector' ), array( 'status' => 503 ) );
		}
		UsedCss\with_exact_cache_url(
			$url,
			static function () use ( $purge, $url ) {
				do_action( 'before_rocket_clean_files', array( $url ) );
				do_action( 'before_rocket_clean_file', $url );
				$purge->purge_url( $url, false );
				do_action( 'after_rocket_clean_file', $url );
				do_action( 'after_rocket_clean_files', array( $url ) );
			}
		);
	} catch ( \Throwable $error ) {
		return new \WP_Error( 'church_connector_cache_incomplete', __( 'The page purge was interrupted. Check the named page before retrying.', 'church-website-connector' ), array( 'status' => 503 ) );
	}
	return array(
		'site_url' => $site,
		'url'      => $url,
		'status'   => 'clear_requested',
	);
}

function register(): void {
	if ( ! available() || ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability(
		'church-connector/clear-page-cache',
		array(
			'label'               => __( 'Clear one page cache', 'church-website-connector' ),
			'description'         => __( 'Request a WP Rocket cache clear for one named public URL on this verified site. Use an existing website-project standing authorisation when it covers the task. Check the page after clearing. Preload and regeneration run separately.', 'church-website-connector' ),
			'category'            => 'church-connector',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'site_url' => array( 'type' => 'string' ),
					'url'      => array( 'type' => 'string' ),
				),
				'required'             => array( 'site_url', 'url' ),
				'additionalProperties' => false,
			),
			'permission_callback' => __NAMESPACE__ . '\permission',
			'execute_callback'    => __NAMESPACE__ . '\clear',
			'meta'                => array(
				'show_in_rest' => false,
				'mcp'          => array( 'public' => true ),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register' );
