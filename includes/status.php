<?php
/** Local readiness rows shared by the administrator traffic-light display. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Status;

use ChurchWebsiteConnector as Connector;

defined( 'ABSPATH' ) || exit;

/** Translate readiness without relying on colour alone. */
function badge( string $level, string $label = '' ): string {
	$colours = array(
		'green'  => '#006b1b',
		'yellow' => '#8a6000',
		'red'    => '#b32d2e',
	);
	$labels  = array(
		'green'  => __( 'Ready', 'church-website-connector' ),
		'yellow' => __( 'Waiting', 'church-website-connector' ),
		'red'    => __( 'Action needed', 'church-website-connector' ),
	);
	$level   = isset( $colours[ $level ] ) ? $level : 'red';
	return '<span class="cwc-status cwc-status-' . esc_attr( $level ) . '" style="color:' . esc_attr( $colours[ $level ] ) . ';display:inline-flex;align-items:center;gap:6px;font-weight:600;margin-right:10px"><span aria-hidden="true" style="width:10px;height:10px;flex-shrink:0;border-radius:50%;background:currentColor"></span><span>' . esc_html( '' !== $label ? $label : $labels[ $level ] ) . '</span></span>';
}

/** An optional feature switched off deliberately needs no corrective action. */
function optional_level( array $item, string $version_key = 'installed_version' ): string {
	return ! $item['enabled'] || $item['compatible'] ? 'green' : ( empty( $item[ $version_key ] ) ? 'yellow' : 'red' );
}

/** Build only from the same local facts used by feature permissions and diagnostics. */
function rows( array $status ): array {
	$d       = $status['dependencies'];
	$adapter = Connector\AdapterSetup\status();
	$rows    = array();
	$add     = static function ( string $id, string $label, string $message, string $level, bool $off = false ) use ( &$rows ): void {
		$rows[ $id ] = array(
			'label'   => $label,
			'message' => $message,
			'level'   => $level,
			'off'     => $off,
		);
	};
	$add( 'WordPress', 'WordPress Abilities API', $d['wordpress_abilities'] ? 'Available' : 'Update WordPress to a supported release.', $d['wordpress_abilities'] ? 'green' : 'red' );
	$add( 'adapter', 'MCP Adapter', Connector\AdapterSetup\presentation()['message'], $adapter['level'] );
	$add( 'generatepress', 'GeneratePress AI', $d['generatepress_ai_version'] ? 'Active: ' . $d['generatepress_ai_version'] : 'Install and activate GeneratePress AI.', $d['generatepress_ai_version'] && Connector\GeneratePressBridge\supported_version( $d['generatepress_ai_version'] ) ? 'green' : 'red' );
	$add( 'licence', 'GeneratePress One licence', true === $d['generatepress_license_cached_active'] ? 'Active in the cached local check' : 'Check or activate in Settings → GeneratePress AI.', true === $d['generatepress_license_cached_active'] ? 'green' : 'red' );
	$blocks = $d['generateblocks_version'] && version_compare( $d['generateblocks_version'], '2.3', '>=' );
	$add( 'generateblocks', 'GenerateBlocks', $blocks ? 'Active: ' . $d['generateblocks_version'] : 'Install or update GenerateBlocks to 2.3 or newer.', $blocks ? 'green' : 'red' );
	$primary          = $status['primary_connection'];
	$requires_rocket  = 'separate_connections' === $primary['selected'];
	$rocket_available = ! empty( $d['wp_rocket_version'] );
	$library          = $d['wp_rocket_oauth'] && $adapter['compatible'];
	$rocket_missing   = $requires_rocket ? 'Install WP Rocket for the selected connection.' : 'Optional for this connection. Install WP Rocket to use its cache tools.';
	$library_missing  = $requires_rocket ? 'Finish WP Rocket and MCP Adapter setup for the selected connection.' : 'The alternative WP Rocket connection is optional.';

	$add( 'rocket', 'WP Rocket', $rocket_available ? 'Active: ' . $d['wp_rocket_version'] : $rocket_missing, $rocket_available ? 'green' : ( $requires_rocket ? 'red' : 'yellow' ) );
	$add( 'rocket_connection', 'WP Rocket connection library', $library ? 'Available. Separate WP Rocket sign-in is only needed for the alternative connection.' : $library_missing, $library ? 'green' : ( $requires_rocket ? 'red' : 'yellow' ) );
	$add( 'primary', 'WordPress and plugin tools through MCP', $primary['label'], $primary['level'] );
	$pdf   = $status['pdf_upload'];
	$limit = rtrim( rtrim( number_format( $pdf['max_bytes'] / 1048576, 2, '.', '' ), '0' ), '.' ) . ' MiB';
	$add( 'uploads', 'PDF uploads', ! $pdf['enabled'] ? 'Switched off in Settings.' : ( $pdf['current_user_can_upload'] ? 'Enabled for this administrator. Up to ' . $limit . ' per PDF; client limits may be lower.' : 'Enabled, but this account does not have permission to upload files.' ), ! $pdf['enabled'] || $pdf['current_user_can_upload'] ? 'green' : 'red', ! $pdf['enabled'] );
	$protection = $status['imagify_pdf_protection'];
	$level      = match ( $protection['state'] ) {
		'protected', 'disabled' => 'green', 'imagify_inactive' => 'yellow', default => 'red',
	};
	$add( 'pdf_protection', 'PDF protection (Imagify)', $protection['message'], $level, ! $protection['enabled'] );
	$redirect = $status['redirection'];
	$add( 'redirection', 'Redirection (optional)', ! $redirect['enabled'] ? 'Switched off in Settings.' : ( empty( $redirect['plugin_version'] ) ? 'Helper enabled; waiting for Redirection to be installed and activated.' : 'Installed: ' . $redirect['plugin_version'] . '. ' . $redirect['message'] ), optional_level( $redirect, 'plugin_version' ), ! $redirect['enabled'] );
	$css     = $status['used_css'];
	$waiting = ! $css['ready'] && ! empty( $css['waiting_for_configuration'] );
	$message = ! $css['enabled'] ? 'Switched off in Settings.' : ( $css['ready'] ? ( $css['current_user_can_clear'] ? 'Ready for this administrator' : 'Save Settings as an eligible administrator to grant clearing permission.' ) : ( $waiting ? 'WP Rocket Remove Unused CSS is switched off for this website. Clearing will be available if you choose to enable it in WP Rocket.' : $css['message'] ) );
	$level   = ! $css['enabled'] || ( $css['ready'] && $css['current_user_can_clear'] ) ? 'green' : ( $waiting || $css['legacy_helpers_active'] ? 'yellow' : 'red' );
	$add( 'used_css', 'Used CSS clearing', $message, $level, ! $css['enabled'] );
	foreach ( $status['integrations'] as $integration ) {
		$message = ! $integration['enabled'] ? 'Switched off in Settings.' : ( $integration['compatible'] ? 'Enabled and ready. Active version: ' . $integration['installed_version'] . '.' : ( $integration['installed_version'] ? 'Installed: ' . $integration['installed_version'] . '. Version or native interfaces are unsupported. Requires ' . $integration['supported_versions'] . '.' : 'Not active—helper waiting. Install and activate this optional plugin only if you want to use it.' ) );
		$add( $integration['integration'], $integration['label'] . ' (optional)', $message, optional_level( $integration ), ! $integration['enabled'] );
	}
	if ( 'green' !== $primary['level'] ) {
		$mcp_features = array_merge( array( 'uploads', 'redirection', 'used_css' ), array_column( $status['integrations'], 'integration' ) );
		foreach ( $mcp_features as $id ) {
			if ( isset( $rows[ $id ] ) && ! $rows[ $id ]['off'] && 'green' === $rows[ $id ]['level'] ) {
				$rows[ $id ]['level']    = 'yellow';
				$rows[ $id ]['message'] .= ' ' . __( 'MCP access needs connection setup; see “WordPress and plugin tools through MCP” above.', 'church-website-connector' );
			}
		}
	}
	return $rows;
}

/** Keep results in Status, and editable choices together in Settings. */
function render( array $status ): void {
	echo '<h3>' . esc_html__( 'Website plugins and features', 'church-website-connector' ) . '</h3><p>' . esc_html__( 'Green: ready or deliberately switched off. Yellow: waiting for an optional plugin or a non-blocking setup step. Red: a required plugin is missing or an important issue needs attention.', 'church-website-connector' ) . '</p><table class="widefat striped" id="cwc-plugin-status"><caption class="screen-reader-text">' . esc_html__( 'Website plugin and feature status', 'church-website-connector' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Item', 'church-website-connector' ) . '</th><th scope="col">' . esc_html__( 'Status', 'church-website-connector' ) . '</th></tr></thead><tbody>';
	foreach ( rows( $status ) as $id => $row ) {
		echo '<tr data-cwc-status="' . esc_attr( $id ) . '"><th scope="row">' . esc_html( $row['label'] ) . '</th><td>' . badge( $row['level'], $row['off'] ? __( 'Off (optional)', 'church-website-connector' ) : '' ) . '<span' . ( 'adapter' === $id ? ' data-cwc-adapter-message' : '' ) . '>' . esc_html( $row['message'] ) . '</span></td></tr>';
	}
	echo '</tbody></table>';
}
