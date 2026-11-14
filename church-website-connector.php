<?php
/**
 * Plugin Name: Church Website Connector
 * Description: Prepares GeneratePress AI and WP Rocket connections, allows the official Codex client, and adds protected Used CSS, PDF upload, REST-backed metadata and Redirection management tools through MCP.
 * Version: 1.7.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Author: Church Website Toolkit
 * License: GPL-2.0-or-later
 * Text Domain: church-website-connector
 * Update URI: https://github.com/krystoflp/church-website-connector
 */

declare(strict_types=1);

namespace ChurchWebsiteConnector;

defined( 'ABSPATH' ) || exit;

const VERSION  = '1.7.0';
const SETTINGS = 'church_website_connector_settings';

function settings(): array {
	$value = get_option( SETTINGS, array() );
	return is_array( $value ) ? $value : array();
}

/** Accept canonical HTTPS WordPress addresses; never use HTTP_HOST or request input. */
function valid_address( string $url ): bool {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] ) ) {
		return false;
	}
	if ( isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || preg_match( '/[\x00-\x20\\\\]/', $url ) ) {
		return false;
	}
	if ( ! preg_match( '/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\.[a-z]{2,63}\z/', $parts['host'] ) ) {
		return false;
	}
	$path = $parts['path'] ?? '';
	if ( '' !== $path && ( '/' !== $path[0] || preg_match( '~(?:^|/)(?:\.|\.\.)(?:/|$)~', rawurldecode( $path ) ) || str_contains( rawurldecode( $path ), '\\' ) ) ) {
		return false;
	}
	return 'https://' . $parts['host'] . $path === $url && untrailingslashit( $url ) === $url;
}

function current_identity(): array {
	// site_url() can inherit the current request's scheme, including CLI/proxy
	// requests. Bind to configured addresses so a request cannot change identity.
	return array(
		'home'      => untrailingslashit( (string) get_option( 'home', '' ) ),
		'wordpress' => untrailingslashit( (string) get_option( 'siteurl', '' ) ),
	);
}

function valid_identity( array $identity ): bool {
	return valid_address( $identity['home'] ?? '' ) && valid_address( $identity['wordpress'] ?? '' )
		&& wp_parse_url( $identity['home'], PHP_URL_HOST ) === wp_parse_url( $identity['wordpress'], PHP_URL_HOST );
}

function bound_site(): string {
	$saved = settings();
	return is_string( $saved['home'] ?? null ) ? $saved['home'] : '';
}

/** A moved or cloned database must be explicitly enabled again by an administrator. */
function is_bound(): bool {
	if ( is_multisite() ) {
		return false;
	}
	$saved    = settings();
	$identity = current_identity();
	return ( $saved['schema'] ?? null ) === 1 && ( $saved['enabled'] ?? false ) === true
		&& valid_identity( $identity ) && ( $saved['home'] ?? '' ) === $identity['home']
		&& ( $saved['wordpress'] ?? '' ) === $identity['wordpress'];
}

function legacy_used_css(): array {
	$equivalents = array(
		'all-saints-used-css/all-saints-used-css.php',
		'church-toolkit-used-css/church-toolkit-used-css.php',
	);
	return array_values( array_intersect( $equivalents, (array) get_option( 'active_plugins', array() ) ) );
}

function feature_enabled( string $feature ): bool {
	return is_bound() && true === ( settings()[ $feature ] ?? ( 'rest_tools' === $feature ) );
}

/** Grant only the current administrator, and only when native Used CSS is ready. */
function maybe_grant_used_css(): void {
	if ( feature_enabled( 'used_css' ) && current_user_can( 'manage_options' ) && current_user_can( 'rocket_remove_unused_css' ) && ! is_wp_error( UsedCss\environment() ) ) {
		UsedCss\activate();
	}
}

function revoke_recorded_grants(): void {
	foreach ( (array) get_option( UsedCss\GRANTEE, array() ) as $user_id ) {
		$user = get_current_user_id() === (int) $user_id ? wp_get_current_user() : get_user_by( 'id', (int) $user_id );
		if ( $user ) {
			$user->remove_cap( UsedCss\CAP );
		}
	}
	delete_option( UsedCss\GRANTEE );
}

function configure( array $flags ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'church_connector_forbidden', 'Only an administrator can configure website connections.', array( 'status' => 403 ) );
	}
	$identity = current_identity();
	if ( is_multisite() || ! valid_identity( $identity ) ) {
		return new \WP_Error( 'church_connector_site', 'Use a single WordPress site with HTTPS Site Address and WordPress Address on the same host, without custom ports.', array( 'status' => 400 ) );
	}
	$previous = settings();
	if ( $previous && ( ( $previous['home'] ?? '' ) !== $identity['home'] || ( $previous['wordpress'] ?? '' ) !== $identity['wordpress'] ) ) {
		revoke_recorded_grants();
	}
	$saved = array(
		'schema'    => 1,
		'home'      => $identity['home'],
		'wordpress' => $identity['wordpress'],
	);
	foreach ( array( 'enabled', 'compatibility', 'codex', 'used_css' ) as $key ) {
		$saved[ $key ] = ( $flags[ $key ] ?? false ) === true;
	}
	$saved['rest_tools']        = ( $flags['rest_tools'] ?? true ) === true;
	$saved['redirection_tools'] = ( $flags['redirection_tools'] ?? false ) === true;
	foreach ( array( 'tsf_tools', 'rankmath_tools', 'snippets_tools', 'wsform_tools' ) as $key ) {
		$saved[ $key ] = ( $flags[ $key ] ?? false ) === true; }
	// Existing choices remain explicit; new setups preselect the bridge in the form.
	$saved['generatepress_bridge'] = ( $flags['generatepress_bridge'] ?? false ) === true;
	$saved['setup_pending']        = ( $flags['setup_pending'] ?? $previous['setup_pending'] ?? false ) === true;
	update_option( SETTINGS, $saved, false );
	maybe_grant_used_css();
	return true;
}

function activate( $network_wide = false ): void {
	if ( $network_wide || is_multisite() ) {
		wp_die( 'Church Website Connector currently supports single-site WordPress only.' );
	}
	if ( ! current_user_can( 'activate_plugins' ) || ! current_user_can( 'manage_options' ) ) {
		wp_die( 'An administrator must activate Church Website Connector.' );
	}
	// Preserve explicit choices when reactivating on the same installation.
	$saved            = settings();
	$identity         = current_identity();
	$same_site        = ( $saved['schema'] ?? null ) === 1 && ( $saved['home'] ?? '' ) === $identity['home'] && ( $saved['wordpress'] ?? '' ) === $identity['wordpress'];
	$flags            = $same_site ? $saved : array(
		'compatibility'        => true,
		'codex'                => false,
		'used_css'             => true,
		'rest_tools'           => true,
		'tsf_tools'            => true,
		'rankmath_tools'       => true,
		'snippets_tools'       => true,
		'wsform_tools'         => true,
		'generatepress_bridge' => false,
		'setup_pending'        => true,
	);
	$flags['enabled'] = true;
	$result           = configure( $flags );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ) );
	}
	if ( ! $same_site ) {
		AdapterSetup\schedule();
	}
}

require_once __DIR__ . '/includes/used-css.php';
require_once __DIR__ . '/includes/compatibility.php';
require_once __DIR__ . '/includes/rest-tools.php';
require_once __DIR__ . '/includes/pdf-upload.php';
require_once __DIR__ . '/includes/redirection-tools.php';
require_once __DIR__ . '/includes/generatepress-bridge.php';
require_once __DIR__ . '/includes/integration-tools.php';
require_once __DIR__ . '/includes/adapter-setup.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/updates.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu' );
add_action( 'admin_notices', __NAMESPACE__ . '\\dependency_notice' );
add_action( 'admin_post_church_connector_save', __NAMESPACE__ . '\\admin_save' );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\\plugin_links' );
