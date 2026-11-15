<?php
/** Administrator status, safe diagnostics, native update defaults and existing activity. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Maintenance;

use ChurchWebsiteConnector as Connector;
use ChurchWebsiteConnector\Updates;

defined( 'ABSPATH' ) || exit;

/** Load WordPress administrator update-policy helpers for activation and CLI requests. */
function update_api(): void {
	if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}
}

/** Apply a native preference once, only when the caller confirmed a new installation. */
function initial_auto_update( string $file ): void {
	$markers = array(
		Updates\plugin_file()                    => 'church_connector_auto_default_connector',
		Connector\AdapterSetup\PLUGIN_FILE       => 'church_connector_auto_default_adapter',
		Connector\GeneratePressSetup\PLUGIN_FILE => 'church_connector_auto_default_generatepress',
	);
	if ( ! isset( $markers[ $file ] ) || ! add_option( $markers[ $file ], 'attempted', '', false ) ) {
		return;
	}
	update_api();
	if ( ! current_user_can( 'update_plugins' ) || ! wp_is_file_mod_allowed( 'plugins' ) || ! wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
		update_option( $markers[ $file ], 'blocked', false );
		return;
	}
	$item = (object) array(
		'plugin'      => $file,
		'slug'        => dirname( $file ),
		'new_version' => '',
	);
	if ( false === wp_is_auto_update_forced_for_item( 'plugin', null, $item ) ) {
		update_option( $markers[ $file ], 'blocked', false );
		return;
	}
	$selected = (array) get_site_option( 'auto_update_plugins', array() );
	if ( ! in_array( $file, $selected, true ) ) {
		$selected[] = $file;
		update_site_option( 'auto_update_plugins', array_values( array_unique( $selected ) ) );
	}
	update_option( $markers[ $file ], in_array( $file, (array) get_site_option( 'auto_update_plugins', array() ), true ) ? 'enabled' : 'failed', false );
}

/** Read preference and policy separately; do not claim that an installation is guaranteed. */
function auto_status( string $file ): array {
	update_api();
	$selected  = in_array( $file, (array) get_site_option( 'auto_update_plugins', array() ), true );
	$available = wp_is_auto_update_enabled_for_type( 'plugin' );
	$forced    = wp_is_auto_update_forced_for_item(
		'plugin',
		null,
		(object) array(
			'plugin'      => $file,
			'slug'        => dirname( $file ),
			'new_version' => '',
		)
	);
	$message   = $selected ? __( 'Enabled', 'church-website-connector' ) : __( 'Disabled', 'church-website-connector' );
	if ( ! $available || false === $forced ) {
		$message = __( 'Blocked by website update policy', 'church-website-connector' );
	} elseif ( true === $forced ) {
		$message = __( 'Enabled by website update policy', 'church-website-connector' );
	}
	$markers = array(
		Updates\plugin_file()                    => 'church_connector_auto_default_connector',
		Connector\AdapterSetup\PLUGIN_FILE       => 'church_connector_auto_default_adapter',
		Connector\GeneratePressSetup\PLUGIN_FILE => 'church_connector_auto_default_generatepress',
	);
	$marker  = get_option( $markers[ $file ] ?? 'church_connector_unknown_auto_default', '' );
	if ( ! $selected && $available && null === $forced && in_array( $marker, array( 'blocked', 'failed', 'attempted' ), true ) ) {
		$message = __( 'Disabled; the initial preference could not be enabled. Review Installed Plugins.', 'church-website-connector' );
	}
	if ( Connector\AdapterSetup\PLUGIN_FILE === $file && defined( 'WP_PLUGIN_DIR' ) && ! is_file( WP_PLUGIN_DIR . '/' . $file ) ) {
		$message = Connector\AdapterSetup\status()['available'] ? __( 'Managed by the plugin providing the adapter', 'church-website-connector' ) : __( 'Not installed separately yet', 'church-website-connector' );
	}
	return array(
		'selected'  => $selected,
		'available' => $available,
		'policy'    => true === $forced ? 'enabled' : ( false === $forced ? 'disabled' : 'default' ),
		'message'   => $message,
	);
}

/** Strict version-only fields prevent arbitrary plugin strings entering copied diagnostics. */
function version( $value ): ?string {
	return is_string( $value ) && strlen( $value ) <= 32 && 1 === preg_match( '/\A[0-9]+(?:\.[0-9]+){1,3}\z/', $value ) ? $value : null;
}

/** Read only the allowlisted scalar fields retained by the release checker. */
function update_status(): array {
	$saved = get_option( Updates\CHECK_STATUS, array() );
	$saved = is_array( $saved ) ? $saved : array();
	return array(
		'last_attempt' => max( 0, (int) ( $saved['last_attempt'] ?? 0 ) ),
		'last_success' => max( 0, (int) ( $saved['last_success'] ?? 0 ) ),
		'latest'       => Updates\stable_version( $saved['latest'] ?? null ) ? $saved['latest'] : null,
		'result'       => in_array( $saved['result'] ?? '', array( 'success', 'failed' ), true ) ? $saved['result'] : 'not_checked',
	);
}

/** Deliberately exclude addresses, native error messages, content, accounts and audit rows. */
function diagnostic_report() {
	$status = Connector\connection_status();
	if ( is_wp_error( $status ) ) {
		return $status;
	}
	$report = array(
		'connector'             => Connector\VERSION,
		'wordpress'             => version( $GLOBALS['wp_version'] ?? null ),
		'php'                   => PHP_VERSION,
		'website_binding_valid' => (bool) $status['enabled_on_current_site'],
		'primary_connection'    => $status['generatepress_oauth_bridge']['enabled'] && $status['generatepress_oauth_bridge']['compatible'] ? 'generatepress' : 'separate_connections',
		'dependencies'          => array(),
		'helpers'               => array(),
		'integrations'          => array(),
		'updates'               => update_status(),
		'auto_updates'          => array(),
		'verification'          => 'Local readiness only; native client sign-in must be checked from that client.',
	);
	foreach ( array( 'wordpress_abilities', 'mcp_adapter', 'wp_rocket_oauth' ) as $key ) {
		$report['dependencies'][ $key ] = (bool) $status['dependencies'][ $key ];
	}
	foreach ( array( 'generatepress_ai_version', 'generateblocks_version', 'wp_rocket_version' ) as $key ) {
		$report['dependencies'][ $key ] = version( $status['dependencies'][ $key ] );
	}
	$report['dependencies']['mcp_adapter_version'] = version( Connector\AdapterSetup\status()['version'] );
	foreach ( array( 'compatibility', 'codex', 'used_css', 'rest_tools', 'redirection_tools', 'generatepress_bridge', 'tsf_tools', 'rankmath_tools', 'snippets_tools', 'wsform_tools' ) as $flag ) {
		$report['helpers'][ $flag ] = Connector\feature_enabled( $flag );
	}
	$pdf = $status['imagify_pdf_protection'];
	$report['configuration']['imagify_pdf_protection'] = array(
		'enabled'          => $pdf['enabled'],
		'effective'        => $pdf['effective'],
		'imagify_active'   => $pdf['imagify_active'],
		'imagify_version'  => version( $pdf['imagify_version'] ),
		'filter_available' => $pdf['filter_available'],
		'state'            => $pdf['state'],
	);
	foreach ( $status['integrations'] as $integration ) {
		$report['integrations'][ $integration['integration'] ] = array(
			'enabled'    => (bool) $integration['enabled'],
			'version'    => version( $integration['installed_version'] ),
			'compatible' => (bool) $integration['compatible'],
		);
	}
	foreach ( array(
		'connector'        => Updates\plugin_file(),
		'mcp_adapter'      => Connector\AdapterSetup\PLUGIN_FILE,
		'generatepress_ai' => Connector\GeneratePressSetup\PLUGIN_FILE,
	) as $key => $file ) {
		$auto = auto_status( $file );
		unset( $auto['message'] );
		$report['auto_updates'][ $key ] = $auto;
	}
	return $report;
}

function format_time( int $timestamp ): string {
	return $timestamp > 0 ? wp_date( 'Y-m-d H:i:s T', $timestamp ) : __( 'Not recorded yet', 'church-website-connector' );
}

/** Compact summary: local reads only; opening settings never starts an update lookup. */
function render_summary( array $status ): void {
	if ( is_wp_error( Connector\status_permission() ) ) {
		return;
	}
	$updates = update_status();
	$result  = match ( $updates['result'] ) {
		'success' => __( 'Successful', 'church-website-connector' ), 'failed' => __( 'Failed; last successful check shown below', 'church-website-connector' ), default => __( 'Not checked yet', 'church-website-connector' ) };
	$rows = array(
		__( 'Installed connector', 'church-website-connector' ) => Connector\VERSION,
		__( 'Latest known stable release', 'church-website-connector' ) => $updates['latest'] ?? __( 'Not checked yet', 'church-website-connector' ),
		__( 'Connector automatic updates', 'church-website-connector' ) => auto_status( Updates\plugin_file() )['message'],
		__( 'MCP Adapter automatic updates', 'church-website-connector' ) => auto_status( Connector\AdapterSetup\PLUGIN_FILE )['message'],
		__( 'GeneratePress AI automatic updates', 'church-website-connector' ) => auto_status( Connector\GeneratePressSetup\PLUGIN_FILE )['message'],
		__( 'Last release check', 'church-website-connector' ) => format_time( $updates['last_attempt'] ) . ' — ' . $result,
		__( 'Last successful release check', 'church-website-connector' ) => format_time( $updates['last_success'] ),
		__( 'Primary connection', 'church-website-connector' ) => $status['generatepress_oauth_bridge']['enabled'] && $status['generatepress_oauth_bridge']['compatible'] ? __( 'GeneratePress', 'church-website-connector' ) : __( 'Separate GeneratePress and WP Rocket connections', 'church-website-connector' ),
		__( 'MCP Adapter setup', 'church-website-connector' ) => Connector\AdapterSetup\presentation()['message'],
	);
	echo '<section aria-labelledby="cwc-summary"><h2 id="cwc-summary">' . esc_html__( 'Connector status', 'church-website-connector' ) . '</h2><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Connector and update status', 'church-website-connector' ) . '</caption><tbody>';
	foreach ( $rows as $label => $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td' . ( __( 'MCP Adapter setup', 'church-website-connector' ) === $label ? ' data-cwc-adapter-message' : '' ) . '>' . esc_html( $value ) . '</td></tr>';
	}
	echo '</tbody></table><p>' . esc_html__( 'Automatic updates follow WordPress scheduling, compatibility checks and hosting policy. Connection status is local readiness, not proof of client sign-in.', 'church-website-connector' ) . '</p>';
	$issues = array_filter( Connector\dependency_issues(), static fn( array $issue ): bool => 'info' !== ( $issue['level'] ?? 'warning' ) );
	foreach ( $status['integrations'] as $integration ) {
		if ( $integration['enabled'] && $integration['installed_version'] && ! $integration['compatible'] ) {
			$issues[] = array( 'message' => $integration['label'] . ': ' . __( 'installed version or native interfaces are unsupported; this helper is paused.', 'church-website-connector' ) );
		}
	}
	if ( ! $status['enabled_on_current_site'] ) {
		$issues[] = array( 'message' => __( 'The website address needs administrator review before helpers can run.', 'church-website-connector' ) );
	}
	if ( $issues ) {
		echo '<p><strong>' . esc_html__( 'Needs attention', 'church-website-connector' ) . '</strong></p><ul>';
		foreach ( $issues as $issue ) {
			echo '<li>' . esc_html( $issue['message'] ) . '</li>';
		}
		echo '</ul>';
	}
	echo '<p><a href="#cwc-update-check">' . esc_html__( 'Check for updates', 'church-website-connector' ) . '</a> · <a href="#cwc-diagnostics">' . esc_html__( 'Diagnostic report', 'church-website-connector' ) . '</a> · <a href="' . esc_url( admin_url( 'tools.php?page=church-connector-activity' ) ) . '">' . esc_html__( 'Recent connector changes', 'church-website-connector' ) . '</a></p></section>';
}

function render_diagnostics(): void {
	$report = diagnostic_report();
	if ( is_wp_error( $report ) ) {
		return;
	}
	echo '<h2 id="cwc-diagnostics">' . esc_html__( 'Diagnostic report', 'church-website-connector' ) . '</h2><p>' . esc_html__( 'Copy technical status for troubleshooting. This report excludes website addresses, credentials, licence keys, content and account details.', 'church-website-connector' ) . '</p><p><label for="cwc-diagnostic-report">' . esc_html__( 'Connector diagnostic report', 'church-website-connector' ) . '</label></p><textarea id="cwc-diagnostic-report" class="large-text code" rows="10" readonly>' . esc_textarea( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</textarea><p><button type="button" class="button" id="cwc-copy-diagnostics" data-success="' . esc_attr__( 'Diagnostic report copied.', 'church-website-connector' ) . '" data-failure="' . esc_attr__( 'Select the report and copy it with your keyboard.', 'church-website-connector' ) . '">' . esc_html__( 'Copy diagnostic report', 'church-website-connector' ) . '</button></p><p id="cwc-copy-status" role="status" aria-live="polite"></p>';
}

/** Reuse the same credential-free report on native Site Health Info. */
function site_health( array $info ): array {
	$report = diagnostic_report();
	if ( ! is_wp_error( $report ) ) {
		$info['church-website-connector'] = array(
			'label'  => 'Church Website Connector',
			'fields' => array(
				'status' => array(
					'label' => __( 'Diagnostic report', 'church-website-connector' ),
					'value' => (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				),
			),
		);
	}
	return $info;
}

/** Read existing bounded audit stores; never expose stored URLs, values or code. */
function recent_changes() {
	if ( is_wp_error( Connector\status_permission() ) ) {
		return new \WP_Error( 'church_connector_activity_forbidden', __( 'An administrator must read connector activity.', 'church-website-connector' ) );
	}
	$sources    = array(
		'church_connector_rest_audit'        => 'Metadata',
		'church_connector_redirect_audit'    => 'Redirection',
		'church_connector_integration_audit' => 'Plugin integration',
		'church_connector_used_css_log'      => 'Used CSS',
	);
	$operations = array( 'create', 'update', 'activate', 'deactivate', 'trash', 'publish', 'unpublish', 'delete', 'enable', 'disable', 'add_field', 'add_section', 'add_tab', 'update_field', 'update_section', 'update_tab', 'delete_field', 'delete_section', 'delete_tab' );
	$results    = array( 'error', 'completed', 'updated', 'verified', 'verification_unavailable', 'normalised', 'cleared' );
	$rows       = array();
	foreach ( $sources as $option => $label ) {
		foreach ( array_slice( (array) get_option( $option, array() ), 0, 50 ) as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['time_utc'] ?? null ) || 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $row['time_utc'] ) ) {
				continue;
			}
			$timestamp = strtotime( $row['time_utc'] );
			if ( false === $timestamp ) {
				continue; }
			$operation = in_array( $row['operation'] ?? '', $operations, true ) ? $row['operation'] : ( 'Used CSS' === $label ? 'clear' : 'update' );
			$result    = $row['result'] ?? $row['status'] ?? '';
			$labels    = array(
				'seo-framework' => 'The SEO Framework',
				'rank-math'     => 'Rank Math',
				'code-snippets' => 'Code Snippets',
				'ws-form'       => 'WS Form Pro',
			);
			$source    = 'Plugin integration' === $label ? ( $labels[ $row['integration'] ?? '' ] ?? $label ) : $label;
			$rows[]    = array(
				'timestamp' => $timestamp,
				'user_id'   => max( 0, (int) ( $row['user_id'] ?? 0 ) ),
				'source'    => $source,
				'operation' => $operation,
				'item_id'   => max( 0, (int) ( $row['id'] ?? 0 ) ),
				'result'    => in_array( $result, $results, true ) ? $result : 'unknown',
			);
		}
	}
	usort( $rows, static fn( array $a, array $b ): int => $b['timestamp'] <=> $a['timestamp'] );
	return array_slice( $rows, 0, 50 );
}

function activity_page(): void {
	$rows = recent_changes();
	if ( is_wp_error( $rows ) ) {
		wp_die( esc_html( $rows->get_error_message() ), '', array( 'response' => 403 ) ); }
	echo '<div class="wrap"><h1>' . esc_html__( 'Recent connector changes', 'church-website-connector' ) . '</h1><p>' . esc_html__( 'Up to 50 recent recorded changes, newest first. These are connector operations, not a complete WordPress activity log. Existing retention limits apply. Times use the website timezone. Content, URLs, code and form responses are omitted.', 'church-website-connector' ) . '</p><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Recorded connector changes', 'church-website-connector' ) . '</caption><thead><tr>';
	foreach ( array( 'Time', 'WordPress account', 'Helper', 'Operation', 'Item ID', 'Result' ) as $label ) {
		echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
	echo '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$user    = get_user_by( 'id', $row['user_id'] );
		$account = $user ? $user->display_name . ' (#' . $row['user_id'] . ')' : '#' . $row['user_id'];
		echo '<tr>';
		foreach ( array( format_time( $row['timestamp'] ), $account, $row['source'], str_replace( '_', ' ', $row['operation'] ), $row['item_id'] ? $row['item_id'] : '—', str_replace( '_', ' ', $row['result'] ) ) as $value ) {
			echo '<td>' . esc_html( (string) $value ) . '</td>'; }
		echo '</tr>';
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="6">' . esc_html__( 'No connector changes have been recorded yet.', 'church-website-connector' ) . '</td></tr>'; }
	echo '</tbody></table><p><a href="' . esc_url( admin_url( 'options-general.php?page=church-website-connector' ) ) . '">' . esc_html__( 'Website Connector settings', 'church-website-connector' ) . '</a></p></div>';
}

function menu(): void {
	add_management_page( __( 'Recent connector changes', 'church-website-connector' ), __( 'Connector changes', 'church-website-connector' ), 'manage_options', 'church-connector-activity', __NAMESPACE__ . '\\activity_page' );
}

add_action( 'admin_menu', __NAMESPACE__ . '\\menu' );
add_filter( 'debug_information', __NAMESPACE__ . '\\site_health' );
