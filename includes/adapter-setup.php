<?php
/**
 * Set up the separate WordPress.org MCP Adapter during administrator onboarding.
 *
 * @package ChurchWebsiteConnector
 */

declare(strict_types=1);

namespace ChurchWebsiteConnector\AdapterSetup;

use ChurchWebsiteConnector as Connector;

defined( 'ABSPATH' ) || exit;

const SLUG        = 'mcp-adapter';
const PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';
const STATE       = 'church_connector_adapter_setup';
const LOCK        = 'church_connector_adapter_setup_lock';
const ACTION      = 'church_connector_setup_adapter';

const RECOMMENDED_VERSION = '0.7.2';

/** Return the minimum adapter version required by GeneratePress. */
function minimum_version(): string {
	return defined( '\GeneratePress_AI_MCP::MIN_ADAPTER_VERSION' ) ? \GeneratePress_AI_MCP::MIN_ADAPTER_VERSION : '0.6.1';
}

/** Inspect loaded native interfaces without installing or changing anything. */
function status(): array {
	$class      = '\WP\MCP\Core\McpAdapter';
	$available  = class_exists( $class );
	$version    = defined( $class . '::VERSION' ) ? (string) constant( $class . '::VERSION' ) : ( defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : '' );
	$compatible = $available && ( '' === $version || version_compare( $version, minimum_version(), '>=' ) );
	$message    = __( 'MCP Adapter is missing or inactive. Open ChurchWeb Connector settings to set up the separate WordPress.org plugin.', 'church-website-connector' );
	if ( $available ) {
		$message = $compatible ? __( 'A compatible MCP Adapter is available. No additional installation is needed.', 'church-website-connector' ) : __( 'The active MCP Adapter is too old. Update its providing plugin before continuing.', 'church-website-connector' );
	}
	$recommend_update = $compatible && '' !== $version && version_compare( $version, RECOMMENDED_VERSION, '<' );
	if ( $recommend_update ) {
		$message = sprintf(
			/* translators: 1: active MCP Adapter version, 2: recommended minimum version. */
			__( 'Update recommended. MCP Adapter %1$s is active. Update to %2$s or later.', 'church-website-connector' ),
			$version,
			RECOMMENDED_VERSION
		);
	}
	return array(
		'available'          => $available,
		'compatible'         => $compatible,
		'update_recommended' => $recommend_update,
		'level'              => $recommend_update ? 'yellow' : ( $compatible ? 'green' : 'red' ),
		'version'            => $version,
		'message'            => $message,
	);
}

/** Presentation state; actual native readiness stays separate until the next request. */
function presentation(): array {
	$native = status();
	if ( $native['compatible'] || $native['available'] ) {
		return array(
			'level'   => 'green' === $native['level'] ? 'success' : 'warning',
			'message' => $native['message'],
		);
	}
	$state = get_option( STATE, array() );
	$same  = is_array( $state ) && ( $state['site'] ?? null ) === Connector\current_identity();
	if ( $same && 'failed' === ( $state['status'] ?? '' ) ) {
		return array(
			'level'   => 'warning',
			'message' => (string) ( $state['message'] ?? __( 'Adapter setup failed. Review the Plugins screen before retrying.', 'church-website-connector' ) ),
		);
	}
	if ( $same && 'complete' === ( $state['status'] ?? '' ) ) {
		// A saved setup outcome is history, not proof of current readiness.
		$installed = defined( 'WP_PLUGIN_DIR' ) && is_file( WP_PLUGIN_DIR . '/' . PLUGIN_FILE );
		$message   = __( 'MCP Adapter is not installed. Install and activate it to complete setup.', 'church-website-connector' );
		if ( $installed ) {
			// This connector supports single-site WordPress; do not load adapter code here.
			$active  = in_array( PLUGIN_FILE, (array) get_option( 'active_plugins', array() ), true );
			$message = $active
				? __( 'MCP Adapter is marked active, but its interfaces are unavailable. Check the plugin for loading errors or compatibility issues.', 'church-website-connector' )
				: __( 'MCP Adapter is installed but inactive. Activate it to complete setup.', 'church-website-connector' );
		}
		return array(
			'level'   => 'warning',
			'message' => $message,
		);
	}
	if ( $same && 'running' === ( $state['status'] ?? '' ) ) {
		return array(
			'level'   => 'info',
			'message' => __( 'MCP Adapter setup was started. Reload to check the result before retrying an interrupted request.', 'church-website-connector' ),
		);
	}
	if ( automatic_allowed() && ! is_wp_error( permission() ) ) {
		return array(
			'level'   => 'info',
			'message' => __( 'MCP Adapter will be installed or activated automatically when you open ChurchWeb Connector settings.', 'church-website-connector' ),
		);
	}
	return array(
		'level'   => 'warning',
		'message' => $native['message'],
	);
}

/** Queue one initial setup attempt, scoped to this configured website. */
function schedule(): void {
	update_option(
		STATE,
		array(
			'status'  => 'pending',
			'site'    => Connector\current_identity(),
			'expires' => time() + 7 * DAY_IN_SECONDS,
		),
		false
	);
}

/** Require installation rights and a matching, enabled website. */
function permission() {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) || ! wp_is_file_mod_allowed( 'plugins' ) || ! Connector\is_bound() ) {
		return new \WP_Error( 'church_connector_adapter_forbidden', __( 'An administrator with plugin installation and activation permissions must set up the adapter on this website.', 'church-website-connector' ) );
	}
	return true;
}

/** An automatic attempt is allowed only during the original setup window. */
function automatic_allowed(): bool {
	$state = get_option( STATE, array() );
	return is_array( $state ) && 'pending' === ( $state['status'] ?? '' ) && ( $state['site'] ?? null ) === Connector\current_identity() && ( $state['expires'] ?? 0 ) >= time();
}

/** Accept only the exact official package matching repository metadata. */
function package_url( $info ) {
	if ( ! is_object( $info ) || ( $info->slug ?? '' ) !== SLUG || ! is_string( $info->version ?? null ) || 1 !== preg_match( '/\A[0-9]+(?:\.[0-9]+){1,3}\z/', $info->version ) || version_compare( $info->version, minimum_version(), '<' ) ) {
		return new \WP_Error( 'church_connector_adapter_metadata', __( 'The repository did not return a supported MCP Adapter release.', 'church-website-connector' ) );
	}
	$expected = 'https://downloads.wordpress.org/plugin/' . SLUG . '.' . $info->version . '.zip';
	if ( ( $info->download_link ?? '' ) !== $expected ) {
		return new \WP_Error( 'church_connector_adapter_package', __( 'The repository returned an unexpected adapter download address.', 'church-website-connector' ) );
	}
	return $expected;
}

/** Save a bounded outcome, with no credentials or native installer transcript. */
function finish( string $outcome, string $message ): array {
	$result = array(
		'status'  => $outcome,
		'message' => substr( wp_strip_all_tags( $message ), 0, 1600 ),
		'site'    => Connector\current_identity(),
	);
	update_option( STATE, $result, false );
	return $result;
}

/** Install or activate only the preferred adapter, using WordPress core APIs. */
function run( bool $automatic = false ) {
	$allowed = permission();
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}
	if ( $automatic && ! automatic_allowed() ) {
		return new \WP_Error( 'church_connector_adapter_not_pending', __( 'Automatic adapter setup is no longer pending. Use the setup button to retry explicitly.', 'church-website-connector' ) );
	}
	$previous = get_option( LOCK, false );
	if ( is_array( $previous ) && ( $previous['expires'] ?? PHP_INT_MAX ) < time() ) {
		Connector\RestTools\conditional_delete( LOCK, $previous );
	}
	$holder = array(
		'expires' => time() + 5 * MINUTE_IN_SECONDS,
		'token'   => wp_generate_uuid4(),
	);
	if ( ! add_option( LOCK, $holder, '', false ) ) {
		return new \WP_Error( 'church_connector_adapter_busy', __( 'Adapter setup is already running. Please wait before trying again.', 'church-website-connector' ) );
	}
	try {
		// Consume the automatic attempt before downloading or activating anything.
		$started = finish( 'running', __( 'Setting up MCP Adapter.', 'church-website-connector' ) );
		if ( get_option( STATE ) !== $started ) {
			return new \WP_Error( 'church_connector_adapter_storage', __( 'The setup state could not be saved. No installation was attempted.', 'church-website-connector' ) );
		}
		$adapter = status();
		if ( $adapter['compatible'] ) {
			return finish( 'complete', $adapter['message'] );
		}
		if ( $adapter['available'] ) {
			return finish( 'failed', $adapter['message'] );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$plugins         = get_plugins();
		$newly_installed = false;
		if ( ! isset( $plugins[ PLUGIN_FILE ] ) ) {
			if ( 'direct' !== get_filesystem_method( array(), WP_PLUGIN_DIR ) ) {
				return finish( 'failed', __( 'WordPress needs filesystem credentials to install plugins. Use the manual WordPress installation link below.', 'church-website-connector' ) );
			}
			$info = plugins_api(
				'plugin_information',
				array(
					'slug'   => SLUG,
					'fields' => array( 'sections' => false ),
				)
			);
			if ( is_wp_error( $info ) ) {
				return finish( 'failed', __( 'The WordPress.org repository could not be reached. Retry setup or use the manual installation link.', 'church-website-connector' ) );
			}
			$url = package_url( $info );
			if ( is_wp_error( $url ) ) {
				return finish( 'failed', $url->get_error_message() );
			}
			$skin      = new \WP_Ajax_Upgrader_Skin();
			$upgrader  = new \Plugin_Upgrader( $skin );
			$installed = $upgrader->install( $url, array( 'overwrite_package' => false ) );
			if ( true !== $installed || $skin->get_errors()->has_errors() ) {
				return finish( 'failed', __( 'WordPress could not install MCP Adapter. Check available disk space and plugin-folder permissions, then retry or install it manually.', 'church-website-connector' ) );
			}
			$newly_installed = true;
			wp_clean_plugins_cache();
			$plugins = get_plugins();
		}
		if ( ! isset( $plugins[ PLUGIN_FILE ] ) || version_compare( (string) $plugins[ PLUGIN_FILE ]['Version'], minimum_version(), '<' ) ) {
			return finish( 'failed', __( 'The installed MCP Adapter needs an update before it can be activated. Manage its update through WordPress.', 'church-website-connector' ) );
		}
		$requirements = validate_plugin_requirements( PLUGIN_FILE );
		if ( is_wp_error( $requirements ) ) {
			return finish( 'failed', $requirements->get_error_message() );
		}
		if ( $newly_installed ) {
			Connector\Maintenance\initial_auto_update( PLUGIN_FILE );
		}
		$activated = activate_plugin( PLUGIN_FILE, '', false, false );
		if ( is_wp_error( $activated ) || ! is_plugin_active( PLUGIN_FILE ) ) {
			return finish( 'failed', __( 'MCP Adapter was installed but WordPress could not activate it. Review it on the Plugins screen before retrying.', 'church-website-connector' ) );
		}
		return finish( 'complete', __( 'MCP Adapter is installed and active. Reload the settings page to check its native interfaces.', 'church-website-connector' ) );
	} catch ( \Throwable $error ) {
		return finish( 'failed', __( 'Adapter setup did not complete. Inspect the Plugins screen before retrying; the plugin may already be installed.', 'church-website-connector' ) );
	} finally {
		Connector\RestTools\conditional_delete( LOCK, $holder );
	}
}

/** Handle the nonce-protected request from the initial setup screen. */
function ajax(): void {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_send_json_error( array( 'message' => __( 'Use a POST request for adapter setup.', 'church-website-connector' ) ), 405 );
	}
	check_ajax_referer( ACTION, 'nonce' );
	$automatic = isset( $_POST['automatic'] ) && '1' === $_POST['automatic'];
	$result    = run( $automatic );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
	}
	wp_send_json_success( $result );
}

/** Initial activation confirmation is also an administrator setup screen. */
function activation_screen(): bool {
	global $pagenow;
	return 'plugins.php' === $pagenow && ( isset( $_GET['activate'] ) || isset( $_GET['activate-multi'] ) )
		&& automatic_allowed() && ! is_wp_error( permission() );
}

/** Automatic requests remain limited to the existing administrator setup screens. */
function automatic_screen(): bool {
	global $pagenow;
	return ( 'options-general.php' === $pagenow && 'church-website-connector' === ( $_GET['page'] ?? '' ) ) || activation_screen();
}

/** Enqueue setup and dismissible-notice clients only where needed. */
function enqueue( string $hook ): void {
	if ( 'settings_page_church-website-connector' === $hook || ( current_user_can( 'manage_options' ) && ( ! status()['compatible'] || ( ! defined( 'WP_ROCKET_VERSION' ) && ! Connector\rocket_notice_dismissed() ) ) ) ) {
		wp_enqueue_script( 'church-connector-setup', plugins_url( 'assets/setup.js', dirname( __DIR__ ) . '/church-website-connector.php' ), array( 'wp-a11y' ), Connector\VERSION, true );
	}
}

/** Show progress, explicit retry and the native manual installation route. */
function render( bool $allow_automatic = true ): void {
	if ( status()['compatible'] ) {
		return;
	}
	$permitted    = ! is_wp_error( permission() );
	$automatic    = $allow_automatic && $permitted && automatic_allowed();
	$presentation = presentation();
	$installed    = defined( 'WP_PLUGIN_DIR' ) && is_file( WP_PLUGIN_DIR . '/' . PLUGIN_FILE );
	$running      = $installed ? __( 'Activating MCP Adapter automatically. Please wait.', 'church-website-connector' ) : __( 'Installing MCP Adapter automatically. Please wait.', 'church-website-connector' );
	echo '<div id="cwc-adapter-setup" data-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-action="' . esc_attr( ACTION ) . '" data-nonce="' . esc_attr( wp_create_nonce( ACTION ) ) . '" data-automatic="' . esc_attr( $automatic ? '1' : '0' ) . '" data-running-automatic="' . esc_attr( $running ) . '" data-running="' . esc_attr__( 'Installing or activating MCP Adapter. Please wait.', 'church-website-connector' ) . '" data-failure="' . esc_attr__( 'The request did not return a result. Reload this page and inspect the Plugins screen before retrying.', 'church-website-connector' ) . '">';
	echo '<noscript><p>' . esc_html__( 'Automatic MCP Adapter setup needs JavaScript. Enable it, or install and activate MCP Adapter through Installed Plugins.', 'church-website-connector' ) . '</p></noscript>';
	echo '<p id="cwc-adapter-progress" role="status" aria-live="polite">';
	echo esc_html( $presentation['message'] );
	echo '</p>';
	if ( $permitted ) {
		echo '<p><button type="button" id="cwc-adapter-retry" class="button" aria-describedby="cwc-adapter-progress">' . esc_html__( 'Set up MCP Adapter', 'church-website-connector' ) . '</button> ';
	} else {
		echo '<p>' . esc_html__( 'Ask an administrator with plugin installation permissions to complete setup.', 'church-website-connector' ) . ' ';
	}
	echo '<a href="' . esc_url( admin_url( 'plugin-install.php?tab=search&type=term&s=mcp-adapter' ) ) . '">' . esc_html__( 'Install through WordPress', 'church-website-connector' ) . '</a></p></div>';
}

add_action( 'wp_ajax_' . ACTION, __NAMESPACE__ . '\ajax' );
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue' );
