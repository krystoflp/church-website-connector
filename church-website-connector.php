<?php
/**
 * Plugin Name: Church Website Connector
 * Description: Prepares GeneratePress AI and WP Rocket connections, allows the official Codex client, adds protected tools through MCP, and provides website configuration including Imagify PDF protection.
 * Version: 1.13.6
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

const VERSION  = '1.13.6';
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

/** Bind to a reviewed address pair even when a migration rewrites URL strings in options. */
function identity_fingerprint( array $identity ): string {
	return hash( 'sha256', $identity['home'] . "\0" . $identity['wordpress'] );
}

function reviewed_identity( array $saved ): array {
	$encoded = $saved['reviewed_addresses'] ?? '';
	$decoded = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
	$parts   = false === $decoded ? array() : explode( "\0", $decoded );
	if ( 2 !== count( $parts ) ) {
		return array();
	}
	$identity = array(
		'home'      => $parts[0],
		'wordpress' => $parts[1],
	);
	return valid_identity( $identity ) && identity_fingerprint( $identity ) === ( $saved['binding_hash'] ?? '' ) ? $identity : array();
}

/** Legacy URL-only settings need one explicit review since their origin cannot be distinguished after migration. */
function is_bound(): bool {
	if ( is_multisite() ) {
		return false;
	}
	$saved    = settings();
	$identity = current_identity();
	return 1 === ( $saved['schema'] ?? null ) && true === ( $saved['enabled'] ?? false ) && empty( $saved['grant_cleanup_pending'] )
		&& valid_identity( $identity ) && settings_match( $saved, $identity ) && reviewed_identity( $saved ) === $identity;
}

function legacy_used_css(): array {
	$equivalents = array(
		'all-saints-used-css/all-saints-used-css.php',
		'church-toolkit-used-css/church-toolkit-used-css.php',
	);
	return array_values( array_intersect( $equivalents, (array) get_option( 'active_plugins', array() ) ) );
}

function feature_enabled( string $feature ): bool {
	return is_bound() && true === ( settings()[ $feature ] ?? in_array( $feature, array( 'rest_tools', 'imagify_pdf_protection' ), true ) );
}

/** Grant only the current administrator, and only when native Used CSS is ready. */
function maybe_grant_used_css() {
	if ( feature_enabled( 'used_css' ) && current_user_can( 'manage_options' ) && current_user_can( 'rocket_remove_unused_css' ) && ! is_wp_error( UsedCss\environment() ) ) {
		return UsedCss\activate();
	}
	return true;
}

function revoke_recorded_grants() {
	return UsedCss\revoke_grants();
}

/** Compare only the connector's requested keys, allowing additional stored metadata. */
function settings_match( $values, array $requested ): bool {
	if ( ! is_array( $values ) ) {
		return false;
	}
	foreach ( $requested as $key => $value ) {
		if ( ( $values[ $key ] ?? null ) !== $value ) {
			return false;
		}
	}
	return true;
}

/** The WordPress nonce binds an entire rendered settings form to these inputs. */
function settings_review_action( $stored, array $identity ): string {
	return 'church_connector_save_' . hash( 'sha256', maybe_serialize( array( $stored, $identity ) ) );
}

function settings_conflict(): \WP_Error {
	return new \WP_Error( 'church_connector_settings_changed', 'The saved settings or website address changed during review. Reload Website Connector settings to review them again.', array( 'status' => 409 ) );
}

/** Apply native option filters, then condition the write on the exact reviewed database row. */
function save_reviewed_settings( array $saved, $previous, array $identity ) {
	global $wpdb;
	$filtered = sanitize_option( SETTINGS, $saved );
	$filtered = apply_filters( 'pre_update_option_' . SETTINGS, $filtered, $previous, SETTINGS );
	$filtered = apply_filters( 'pre_update_option', $filtered, SETTINGS, $previous );
	if ( ! settings_match( $filtered, $saved ) ) {
		return new \WP_Error( 'church_connector_settings_storage', 'WordPress could not save all the requested settings. Review the stored choices before trying again.', array( 'status' => 500 ) );
	}
	if ( current_identity() !== $identity || get_option( SETTINGS, null ) !== $previous ) {
		return settings_conflict();
	}
	if ( $filtered === $previous ) {
		return true;
	}
	$creating = null === $previous;
	if ( $creating ) {
		do_action( 'add_option', SETTINGS, $filtered );
	} else {
		do_action( 'update_option', SETTINGS, $previous, $filtered );
	}
	if ( current_identity() !== $identity ) {
		return settings_conflict();
	}
	if ( $creating ) {
		$changed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", SETTINGS, maybe_serialize( $filtered ) ) );
		wp_cache_delete( 'notoptions', 'options' );
	} else {
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND HEX(option_value) = HEX(%s)", maybe_serialize( $filtered ), SETTINGS, maybe_serialize( $previous ) ) );
	}
	wp_cache_delete( SETTINGS, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	if ( false === $changed ) {
		return new \WP_Error( 'church_connector_settings_storage', 'WordPress could not store the reviewed settings.', array( 'status' => 500 ) );
	}
	if ( 1 !== $changed ) {
		return get_option( SETTINGS, null ) !== $previous ? settings_conflict() : new \WP_Error( 'church_connector_settings_storage', 'WordPress could not store the reviewed settings.', array( 'status' => 500 ) );
	}
	if ( $creating ) {
		do_action( 'add_option_' . SETTINGS, SETTINGS, $filtered );
		do_action( 'added_option', SETTINGS, $filtered );
	} else {
		do_action( 'update_option_' . SETTINGS, $previous, $filtered, SETTINGS );
		do_action( 'updated_option', SETTINGS, $previous, $filtered );
	}
	return true;
}

function configure( array $flags, string $reviewed_binding = '' ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'church_connector_forbidden', 'Only an administrator can configure website connections.', array( 'status' => 403 ) );
	}
	$identity = current_identity();
	if ( is_multisite() || ! valid_identity( $identity ) ) {
		return new \WP_Error( 'church_connector_site', 'Use a single WordPress site with HTTPS Site Address and WordPress Address on the same host, without custom ports.', array( 'status' => 400 ) );
	}
	$stored   = get_option( SETTINGS, null );
	$previous = is_array( $stored ) ? $stored : array();
	if ( '' !== $reviewed_binding && settings_review_action( $stored, $identity ) !== $reviewed_binding ) {
		return settings_conflict();
	}
	$saved = array(
		'schema'             => 1,
		'home'               => $identity['home'],
		'wordpress'          => $identity['wordpress'],
		'binding_hash'       => identity_fingerprint( $identity ),
		'reviewed_addresses' => base64_encode( $identity['home'] . "\0" . $identity['wordpress'] ),
	);
	foreach ( array( 'enabled', 'compatibility', 'codex', 'used_css' ) as $key ) {
		$saved[ $key ] = ( $flags[ $key ] ?? false ) === true;
	}
	// Missing flags in older callers preserve this configuration choice; new or legacy absence receives the enabled default.
	$saved['imagify_pdf_protection'] = ( $flags['imagify_pdf_protection'] ?? $previous['imagify_pdf_protection'] ?? true ) === true;
	$saved['rest_tools']             = ( $flags['rest_tools'] ?? true ) === true;
	$saved['redirection_tools']      = ( $flags['redirection_tools'] ?? false ) === true;
	foreach ( array( 'tsf_tools', 'rankmath_tools', 'snippets_tools', 'wsform_tools' ) as $key ) {
		$saved[ $key ] = ( $flags[ $key ] ?? false ) === true; }
	// Save the actual choice; rendering never invents a different checkbox value.
	$saved['generatepress_bridge'] = ( $flags['generatepress_bridge'] ?? false ) === true;
	$saved['setup_pending']        = ( $flags['setup_pending'] ?? $previous['setup_pending'] ?? false ) === true;
	if ( 1 === ( $previous['schema'] ?? null ) ) {
		// Other registered settings filters may have persisted additional metadata.
		$saved += array_diff_key( $previous, array( 'grant_cleanup_pending' => true ) );
	}
	// Rebinding a copy or an unverifiable legacy installation must clear inherited grants before any helper resumes.
	$cleanup = null !== $stored && ( reviewed_identity( $previous ) !== $identity || ! settings_match( $previous, $identity ) || ! empty( $previous['grant_cleanup_pending'] ) );
	if ( $cleanup ) {
		$saved['grant_cleanup_pending'] = true;
	}
	if ( '' !== $reviewed_binding ) {
		$result = save_reviewed_settings( $saved, $stored, $identity );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	} else {
		$all_choices = static function ( $proposed, $option, $old ) use ( $saved ) {
			return SETTINGS === $option && ! settings_match( $proposed, $saved ) ? $old : $proposed;
		};
		add_filter( 'pre_update_option', $all_choices, PHP_INT_MAX, 3 );
		try {
			update_option( SETTINGS, $saved, false );
		} finally {
			remove_filter( 'pre_update_option', $all_choices, PHP_INT_MAX );
		}
	}
	if ( ! settings_match( settings(), $saved ) ) {
		return new \WP_Error( 'church_connector_settings_storage', 'WordPress could not save all the requested settings. Review the stored choices before trying again.', array( 'status' => 500 ) );
	}
	if ( $cleanup ) {
		$result = revoke_recorded_grants();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$pending = settings();
		if ( ! settings_match( $pending, $saved ) ) {
			return settings_conflict();
		}
		unset( $saved['grant_cleanup_pending'] );
		$result = save_reviewed_settings( $saved, $pending, $identity );
		if ( is_wp_error( $result ) || ! settings_match( settings(), $saved ) || ! empty( settings()['grant_cleanup_pending'] ) ) {
			return is_wp_error( $result ) ? $result : settings_conflict();
		}
	}
	$result = maybe_grant_used_css();
	return is_wp_error( $result ) ? $result : true;
}

function activate( $network_wide = false ): void {
	if ( $network_wide || is_multisite() ) {
		wp_die( 'Church Website Connector currently supports single-site WordPress only.' );
	}
	if ( ! current_user_can( 'activate_plugins' ) || ! current_user_can( 'manage_options' ) ) {
		wp_die( 'An administrator must activate Church Website Connector.' );
	}
	// Only a missing settings option identifies a new installation. A copy or
	// unrecognised configuration must be reviewed in Settings before rebinding.
	$saved    = get_option( SETTINGS, null );
	$identity = current_identity();
	if ( null !== $saved && ( ! is_array( $saved ) || 1 !== ( $saved['schema'] ?? null )
		|| ! settings_match( $saved, $identity ) || reviewed_identity( $saved ) !== $identity || ! empty( $saved['grant_cleanup_pending'] ) ) ) {
		return;
	}
	$flags            = $saved ?? array(
		'compatibility'          => true,
		'codex'                  => false,
		'used_css'               => true,
		'rest_tools'             => true,
		'imagify_pdf_protection' => true,
		'redirection_tools'      => true,
		'tsf_tools'              => true,
		'rankmath_tools'         => true,
		'snippets_tools'         => true,
		'wsform_tools'           => true,
		'generatepress_bridge'   => true,
		'setup_pending'          => true,
	);
	$flags['enabled'] = true;
	$result           = configure( $flags );
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ) );
	}
	if ( null === $saved ) {
		Maintenance\initial_auto_update( Updates\plugin_file() );
		AdapterSetup\schedule();
	}
}

// Configuration hooks load on every request, independently of MCP readiness or sign-in.
require_once __DIR__ . '/includes/configuration.php';
require_once __DIR__ . '/includes/used-css.php';
require_once __DIR__ . '/includes/compatibility.php';
require_once __DIR__ . '/includes/rest-tools.php';
require_once __DIR__ . '/includes/post-files.php';
require_once __DIR__ . '/includes/post-tools.php';
require_once __DIR__ . '/includes/editorial.php';
require_once __DIR__ . '/includes/navigation.php';
require_once __DIR__ . '/includes/elements.php';
require_once __DIR__ . '/includes/editorial-abilities.php';
require_once __DIR__ . '/includes/scoped-abilities.php';
require_once __DIR__ . '/includes/design-tools.php';
require_once __DIR__ . '/includes/pdf-upload.php';
require_once __DIR__ . '/includes/redirection-tools.php';
require_once __DIR__ . '/includes/redirection-admin.php';
require_once __DIR__ . '/includes/generatepress-bridge.php';
require_once __DIR__ . '/includes/integration-tools.php';
require_once __DIR__ . '/includes/adapter-setup.php';
require_once __DIR__ . '/includes/generatepress-setup.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/updates.php';
require_once __DIR__ . '/includes/maintenance.php';
require_once __DIR__ . '/includes/defaults.php';
require_once __DIR__ . '/includes/status.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
add_action( 'admin_menu', __NAMESPACE__ . '\\admin_menu' );
add_action( 'admin_notices', __NAMESPACE__ . '\\dependency_notice' );
add_action( 'admin_post_church_connector_save', __NAMESPACE__ . '\\admin_save' );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\\plugin_links' );
