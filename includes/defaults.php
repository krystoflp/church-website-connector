<?php
/** Explicit administrator restoration of connector settings and update preferences. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Defaults;

use ChurchWebsiteConnector as Connector;

defined( 'ABSPATH' ) || exit;

const ACTION = 'church_connector_restore_defaults';

/** Limit update changes to the connector and the plugins named on its settings page. */
function plugins(): array {
	return array(
		Connector\Updates\plugin_file()          => 'ChurchWeb Connector',
		Connector\AdapterSetup\PLUGIN_FILE       => 'MCP Adapter',
		Connector\GeneratePressSetup\PLUGIN_FILE => 'GeneratePress AI',
		'generateblocks/generateblocks.php'      => 'GenerateBlocks',
		'wp-rocket/wp-rocket.php'                => 'WP Rocket',
		'imagify/imagify.php'                    => 'Imagify',
		'redirection/redirection.php'            => 'Redirection',
		'autodescription/autodescription.php'    => 'The SEO Framework',
		'seo-by-rank-math/rank-math.php'         => 'Rank Math',
		'code-snippets/code-snippets.php'        => 'Code Snippets',
		'ws-form-pro/ws-form.php'                => 'WS Form Pro',
	);
}

/** Restore every current connector flag, leaving only alternative Codex trust off. */
function flags(): array {
	return array(
		'enabled'                => true,
		'setup_pending'          => false,
		'compatibility'          => true,
		'codex'                  => false,
		'used_css'               => true,
		'rest_tools'             => true,
		'redirection_tools'      => true,
		'generatepress_bridge'   => true,
		'tsf_tools'              => true,
		'rankmath_tools'         => true,
		'snippets_tools'         => true,
		'wsform_tools'           => true,
		'imagify_pdf_protection' => true,
		'disable_comments'       => true,
	);
}

/** Validate before any mutation; opening settings and ordinary upgrades do not reset choices. */
function restore_request( array $input ) {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return new \WP_Error( 'church_connector_defaults_method', __( 'Use Restore to defaults in ChurchWeb Connector settings.', 'church-website-connector' ), array( 'status' => 405 ) );
	}
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) || ! Connector\is_bound() ) {
		return new \WP_Error( 'church_connector_defaults_permission', __( 'An administrator with plugin update rights must review and save this website before restoring defaults.', 'church-website-connector' ), array( 'status' => 403 ) );
	}
	if ( ( $input['action'] ?? '' ) !== ACTION || ! is_string( $input['_wpnonce'] ?? null ) || ! wp_verify_nonce( $input['_wpnonce'], ACTION )
		|| array_diff( array_keys( $input ), array( 'action', '_wpnonce', '_wp_http_referer', 'cwc-restore-defaults' ) ) ) {
		return new \WP_Error( 'church_connector_defaults_nonce', __( 'This restore request is invalid or expired. Reload ChurchWeb Connector settings.', 'church-website-connector' ), array( 'status' => 403 ) );
	}
	$result = Connector\configure( flags() );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$saved = Connector\settings();
	foreach ( flags() as $flag => $value ) {
		if ( ( $saved[ $flag ] ?? null ) !== $value ) {
			return new \WP_Error( 'church_connector_defaults_storage', __( 'WordPress could not save all connector defaults. Check the settings before retrying.', 'church-website-connector' ), array( 'status' => 500 ) );
		}
	}
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	Connector\Maintenance\update_api();
	$installed = get_plugins();
	$selected  = (array) get_site_option( 'auto_update_plugins', array() );
	$eligible  = array();
	$blocked   = array();
	foreach ( plugins() as $file => $label ) {
		if ( ! isset( $installed[ $file ] ) ) {
			continue;
		}
		$item = (object) array(
			'plugin'      => $file,
			'slug'        => dirname( $file ),
			'new_version' => '',
		);
		if ( ! wp_is_file_mod_allowed( 'plugins' ) || ! wp_is_auto_update_enabled_for_type( 'plugin' ) || false === wp_is_auto_update_forced_for_item( 'plugin', null, $item ) ) {
			$blocked[] = $label;
			continue;
		}
		$eligible[ $file ] = $label;
		if ( ! in_array( $file, $selected, true ) ) {
			$selected[] = $file;
		}
	}
	if ( $eligible ) {
		update_site_option( 'auto_update_plugins', array_values( array_unique( $selected ) ) );
	}
	$selected = (array) get_site_option( 'auto_update_plugins', array() );
	$enabled  = array();
	foreach ( $eligible as $file => $label ) {
		if ( in_array( $file, $selected, true ) ) {
			$enabled[] = $label;
		} else {
			$blocked[] = $label;
		}
	}
	return array(
		'enabled' => $enabled,
		'blocked' => $blocked,
	);
}

/** Use a normal form so restoration works without JavaScript. */
function admin_post(): void {
	$result = restore_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The method, permissions and action nonce are checked in restore_request().
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => is_array( $data ) ? ( $data['status'] ?? 400 ) : 400 ) );
	}
	set_transient( 'church_connector_defaults_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'options-general.php?page=church-website-connector' ) );
	exit;
}

/** Show the outcome once to the administrator who requested it. */
function render_notice(): void {
	$key    = 'church_connector_defaults_result_' . get_current_user_id();
	$result = get_transient( $key );
	if ( ! is_array( $result ) || ! isset( $result['enabled'], $result['blocked'] ) || ! is_array( $result['enabled'] ) || ! is_array( $result['blocked'] ) ) {
		return;
	}
	delete_transient( $key );
	$labels  = array_values( plugins() );
	$enabled = array_intersect( $labels, $result['enabled'] );
	$blocked = array_intersect( $labels, $result['blocked'] );
	echo '<div class="notice ' . ( $blocked ? 'notice-warning' : 'notice-success' ) . '"><p>' . esc_html__( 'Defaults restored. All connector settings and configuration options are enabled; alternative WP Rocket Codex sign-in is disabled.', 'church-website-connector' ) . '</p>';
	if ( $enabled ) {
		echo '<p>' . esc_html__( 'Automatic updates enabled for: ', 'church-website-connector' ) . esc_html( implode( ', ', $enabled ) ) . '.</p>';
	}
	if ( $blocked ) {
		echo '<p>' . esc_html__( 'Automatic updates could not be enabled because of website policy or a saving error. Review Installed Plugins for: ', 'church-website-connector' ) . esc_html( implode( ', ', $blocked ) ) . '.</p>';
	}
	echo '</div>';
}

/** Keep the deliberate restore action visible beside the connector summary. */
function render(): void {
	echo '<section aria-labelledby="cwc-defaults-title"><h3 id="cwc-defaults-title">' . esc_html__( 'Restore connector defaults', 'church-website-connector' ) . '</h3><p id="cwc-defaults-help">' . esc_html__( 'Enable all connector settings and configuration options, including tools for existing authorised GeneratePress connections. Leave “Allow official Codex sign-in through the alternative WP Rocket connection” disabled. Enable automatic updates for ChurchWeb Connector itself and the installed plugins listed below, subject to website policy. This does not install plugins, activate licences or run updates now.', 'church-website-connector' ) . '</p><p>' . esc_html( implode( ', ', plugins() ) ) . '.</p>';
	if ( Connector\is_bound() && current_user_can( 'update_plugins' ) ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ACTION ) . '">';
		submit_button( __( 'Restore to defaults', 'church-website-connector' ), 'secondary', 'cwc-restore-defaults', false, array( 'aria-describedby' => 'cwc-defaults-help' ) );
		echo '</form>';
	} else {
		echo '<p>' . esc_html__( 'Review and save this website first. An administrator with plugin update rights can then restore defaults.', 'church-website-connector' ) . '</p>';
	}
	echo '</section>';
}

add_action( 'admin_post_' . ACTION, __NAMESPACE__ . '\\admin_post' );
