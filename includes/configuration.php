<?php
/** Website configuration that runs independently of the connection tools. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Configuration;

use ChurchWebsiteConnector as Connector;

defined( 'ABSPATH' ) || exit;

/** Initialise the new setting once on existing installations, preserving every saved choice. */
function initialise(): void {
	$saved = Connector\settings();
	if ( 1 === ( $saved['schema'] ?? null ) && ! array_key_exists( 'imagify_pdf_protection', $saved ) ) {
		$saved['imagify_pdf_protection'] = true;
		update_option( Connector\SETTINGS, $saved, false );
	}
}

/** Preserve every other format and every exclusion made by another filter. */
function imagify_mime_types( array $mimes ): array {
	if ( Connector\feature_enabled( 'imagify_pdf_protection' ) ) {
		unset( $mimes['pdf'] );
	}
	return $mimes;
}

/** Local readiness only: older Imagify releases do not provide this documented hook. */
function pdf_protection_status(): array {
	$enabled = true === ( Connector\settings()['imagify_pdf_protection'] ?? true );
	$bound   = Connector\is_bound();
	$active  = defined( 'IMAGIFY_VERSION' ) && function_exists( 'imagify_get_mime_types' );
	$version = $active ? (string) IMAGIFY_VERSION : null;
	$hook    = $active && version_compare( $version, '2.3.1', '>=' );
	$state   = 'disabled';
	$message = __( 'Switched off. Imagify may optimise PDFs according to its own settings.', 'church-website-connector' );
	if ( $enabled ) {
		if ( ! $bound ) {
			$state   = 'binding_paused';
			$message = __( 'Enabled in saved settings, but paused. Review and save settings for this website address.', 'church-website-connector' );
		} elseif ( ! $active ) {
			$state   = 'imagify_inactive';
			$message = __( 'Enabled; waiting because Imagify is absent or inactive.', 'church-website-connector' );
		} elseif ( ! $hook ) {
			$state   = 'hook_unavailable';
			$message = __( 'Enabled but ineffective. Update Imagify to 2.3.1 or newer for the supported-format filter.', 'church-website-connector' );
		} elseif ( array_key_exists( 'pdf', \imagify_get_mime_types() ) ) {
			$state   = 'overridden';
			$message = __( 'Enabled but ineffective. Another filter has restored PDF support; review the website code.', 'church-website-connector' );
		} else {
			$state   = 'protected';
			$message = __( 'Enabled and effective: PDFs are excluded from Imagify optimisation, including uploads, bulk and manual processing.', 'church-website-connector' );
		}
	}
	return array(
		'enabled'          => $enabled,
		'site_bound'       => $bound,
		'imagify_active'   => $active,
		'imagify_version'  => $version,
		'filter_available' => $hook,
		'effective'        => 'protected' === $state,
		'state'            => $state,
		'message'          => $message,
	);
}

/** Part of the existing nonce-protected administrator settings form. */
function render(): void {
	$status = pdf_protection_status();
	echo '<p><label for="cwc-imagify-pdf"><input type="checkbox" id="cwc-imagify-pdf" name="imagify_pdf_protection" value="1" aria-describedby="cwc-imagify-pdf-help"' . checked( $status['enabled'], true, false ) . '> ' . esc_html__( 'Prevent Imagify from optimising PDFs', 'church-website-connector' ) . '</label></p>';
	echo '<p id="cwc-imagify-pdf-help">' . esc_html__( 'Helps preserve each PDF’s language declaration and accessibility information by excluding PDFs from Imagify. Images continue to follow Imagify’s settings and other exclusions. Applies to normal WordPress activity and background processing without an MCP connection. Enabled on new installations and once on upgrade when no choice is saved. Manually switching it off is remembered on later upgrades. Protection pauses if the website address changes until an administrator saves settings again.', 'church-website-connector' ) . '</p>';
	echo '<p>' . esc_html__( 'Prevention only: previously optimised PDFs keep their current contents until separately restored or replaced. This setting does not assign a language or rewrite documents. It requires Imagify 2.3.1 or newer and does nothing while Imagify is inactive.', 'church-website-connector' ) . '</p>';
}

// Persist the default before Imagify initialises. The read default protects earlier calls too.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\initialise', -100 );

// Register immediately, including public, REST, CLI, cron and worker requests.
// Run after ordinary exclusions without rebuilding or reintroducing their MIME entries.
add_filter( 'imagify_get_mime_types', __NAMESPACE__ . '\\imagify_mime_types', PHP_INT_MAX );
