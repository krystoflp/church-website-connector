<?php
/** Website configuration that runs independently of the connection tools. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\Configuration;

use ChurchWebsiteConnector as Connector;

defined( 'ABSPATH' ) || exit;

/** Add missing configuration defaults without replacing an intervening settings save. */
function initialise(): void {
	$saved    = Connector\settings();
	$defaults = array(
		'imagify_pdf_protection' => true,
		'disable_comments'       => true,
	);
	if ( 1 === ( $saved['schema'] ?? null ) && array_diff_key( $defaults, $saved ) ) {
		Connector\save_reviewed_settings( $saved + $defaults, $saved, Connector\current_identity() );
	}
}

/** Keep this local exclusion active during review of an older same-address installation. */
function pdf_address_matches( array $saved, bool $bound ): bool {
	if ( $bound ) {
		return true;
	}
	if ( is_multisite() || 1 !== ( $saved['schema'] ?? null ) || true !== ( $saved['enabled'] ?? false )
		|| array_key_exists( 'binding_hash', $saved ) || array_key_exists( 'reviewed_addresses', $saved )
		|| ! empty( $saved['grant_cleanup_pending'] ) ) {
		return false;
	}
	$identity = Connector\current_identity();
	return Connector\valid_identity( $identity ) && Connector\settings_match( $saved, $identity );
}

/** Preserve every other format and every exclusion made by another filter. */
function imagify_mime_types( array $mimes ): array {
	$saved = Connector\settings();
	if ( true !== ( $saved['imagify_pdf_protection'] ?? true ) || ! pdf_address_matches( $saved, Connector\is_bound() ) ) {
		return $mimes;
	}
	$allowed = array();
	foreach ( $mimes as $extensions => $type ) {
		if ( 'application/pdf' === $type ) {
			continue;
		}
		// WordPress accepts pipe-separated extension aliases. Preserve the order of unrelated formats.
		if ( is_string( $extensions ) && preg_match( '/^[a-z0-9]+(?:\|[a-z0-9]+)*$/iD', $extensions ) ) {
			$parts      = array_filter( explode( '|', $extensions ), static fn( $part ) => 0 !== strcasecmp( $part, 'pdf' ) );
			$extensions = implode( '|', $parts );
			if ( '' === $extensions ) {
				continue;
			}
		}
		$allowed[ $extensions ] ??= $type;
	}
	// An empty map makes wp_check_filetype() restore every WordPress upload format.
	// This pattern matches no extension and keeps a fully excluded list effective.
	return $allowed ? $allowed : array( '^$' => 'application/x-church-connector-no-formats' );
}

/** Local readiness only: older Imagify releases do not provide this documented hook. */
function pdf_protection_status(): array {
	$saved   = Connector\settings();
	$enabled = true === ( $saved['imagify_pdf_protection'] ?? true );
	$bound   = Connector\is_bound();
	$allowed = pdf_address_matches( $saved, $bound );
	$active  = defined( 'IMAGIFY_VERSION' ) && function_exists( 'imagify_get_mime_types' );
	$version = $active ? (string) IMAGIFY_VERSION : null;
	$hook    = $active && version_compare( $version, '2.3.1', '>=' );
	$mimes   = $enabled && $allowed && $hook ? \imagify_get_mime_types() : array();
	$state   = 'disabled';
	$message = __( 'Switched off. Imagify may optimise PDFs according to its own settings.', 'church-website-connector' );
	if ( $enabled ) {
		if ( ! $allowed ) {
			$state   = 'binding_paused';
			$message = __( 'Enabled in saved settings, but paused. Review and save settings for this website address.', 'church-website-connector' );
		} elseif ( ! $active ) {
			$state   = 'imagify_inactive';
			$message = __( 'Enabled; waiting because Imagify is absent or inactive.', 'church-website-connector' );
		} elseif ( ! $hook ) {
			$state   = 'hook_unavailable';
			$message = __( 'Enabled but ineffective. Update Imagify to 2.3.1 or newer for the supported-format filter.', 'church-website-connector' );
		} elseif ( in_array( 'application/pdf', $mimes, true ) || wp_check_filetype( 'document.pdf', $mimes )['type'] ) {
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
	echo '<p><label for="cwc-disable-comments"><input type="checkbox" id="cwc-disable-comments" name="disable_comments" value="1" aria-describedby="cwc-discussion-help"' . checked( Connector\settings()['disable_comments'] ?? true, true, false ) . '> ' . esc_html__( 'Disable comments, pingbacks and trackbacks sitewide', 'church-website-connector' ) . '</label></p>';
	echo '<p id="cwc-discussion-help">' . esc_html__( 'Closes new public comments and replies on existing and future content, and stops incoming and outgoing pingbacks and trackbacks. Published comments stay visible in WordPress and GeneratePress, including the classic WooCommerce reviews list. Post editor checkboxes show the saved per-post choices. Turn this off to use those choices again.', 'church-website-connector' ) . '</p>';
	$status = pdf_protection_status();
	echo '<p><label for="cwc-imagify-pdf"><input type="checkbox" id="cwc-imagify-pdf" name="imagify_pdf_protection" value="1" aria-describedby="cwc-imagify-pdf-help"' . checked( $status['enabled'], true, false ) . '> ' . esc_html__( 'Prevent Imagify from optimising PDFs', 'church-website-connector' ) . '</label></p>';
	echo '<p id="cwc-imagify-pdf-help">' . esc_html__( 'Helps preserve each PDF’s language declaration and accessibility information by excluding PDFs from Imagify. Images continue to follow Imagify’s settings and other exclusions. Applies to normal WordPress activity and background processing without an MCP connection. Enabled on new installations and once on upgrade when no choice is saved. Manually switching it off is remembered on later upgrades. PDF protection continues through a same-address upgrade while the connection tools await review. A changed address requires an administrator to review and save settings.', 'church-website-connector' ) . '</p>';
	echo '<p>' . esc_html__( 'Prevention only: previously optimised PDFs keep their current contents until separately restored or replaced. This setting does not assign a language or rewrite documents. It requires Imagify 2.3.1 or newer and does nothing while Imagify is inactive.', 'church-website-connector' ) . '</p>';
}

// Persist the default before Imagify initialises. The read default protects earlier calls too.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\initialise', -100 );

// Register immediately, including public, REST, CLI, cron and worker requests.
// Run after ordinary exclusions without rebuilding or reintroducing their MIME entries.
add_filter( 'imagify_get_mime_types', __NAMESPACE__ . '\\imagify_mime_types', PHP_INT_MAX );
