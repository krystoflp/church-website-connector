<?php
declare(strict_types=1);

namespace ChurchWebsiteConnector;

defined( 'ABSPATH' ) || exit;

function admin_menu(): void {
	add_options_page( 'Website Connector', 'Website Connector', 'manage_options', 'church-website-connector', __NAMESPACE__ . '\\admin_page' );
}

function plugin_links( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=church-website-connector' ) ) . '">Settings</a>' );
	return $links;
}

function status_permission( $input = null ) {
	return is_user_logged_in() && current_user_can( 'manage_options' ) ? true
		: new \WP_Error( 'church_connector_forbidden', 'An administrator must read connection status.', array( 'status' => 403 ) );
}

function dependency_issues(): array {
	$issues = array();
	if ( ! defined( 'GENERATEPRESS_AI_VERSION' ) || ! class_exists( '\GeneratePress_AI_MCP' ) ) {
		$issues['generatepress_ai'] = array(
			'message' => 'GeneratePress AI setup needs an administrator. Unattended connector updates do not install it or access 1Password. Open Website Connector to authorise setup, or review the existing GeneratePress AI licence.',
			'url'     => admin_url( 'options-general.php?page=church-website-connector#cwc-gp-setup' ),
			'action'  => 'Open GeneratePress AI setup',
		);
	}
	$adapter = AdapterSetup\status();
	if ( ! $adapter['compatible'] ) {
		$presentation          = AdapterSetup\presentation();
		$issues['mcp_adapter'] = array(
			'message' => $presentation['message'],
			'level'   => $presentation['level'],
			'url'     => admin_url( 'options-general.php?page=church-website-connector' ),
			'action'  => 'Set up MCP Adapter',
		);
	}
	if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_get_ability' ) || ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_register_ability_category' ) ) {
		$issues['wordpress'] = array(
			'message' => 'The WordPress Abilities API is unavailable or incomplete. Update WordPress to a supported release.',
			'url'     => admin_url( 'update-core.php' ),
			'action'  => 'WordPress updates',
		);
	}
	if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
		$issues['wp_rocket'] = array(
			'message' => 'WP Rocket is missing or inactive. Install and activate your licensed WP Rocket plugin.',
			'url'     => admin_url( 'plugins.php' ),
			'action'  => 'Manage plugins',
		);
	} elseif ( ! class_exists( '\WPMedia\MCP\OAuth\Bootstrap' ) ) {
		$issues['wp_rocket_oauth'] = array(
			'message' => 'WP Rocket’s connection library is unavailable. Update WP Rocket or complete its MCP setup. The primary GeneratePress connection uses GeneratePress sign-in.',
			'url'     => admin_url( 'options-general.php?page=wprocket' ),
			'action'  => 'WP Rocket setup',
		);
	}
	if ( feature_enabled( 'redirection_tools' ) ) {
		$redirection = RedirectionTools\readiness();
		if ( ! $redirection['compatible'] ) {
			$issues['redirection'] = array(
				'message' => $redirection['message'],
				'url'     => admin_url( 'plugins.php' ),
				'action'  => 'Manage Redirection',
			);
		}
	}
	if ( feature_enabled( 'generatepress_bridge' ) && ! GeneratePressBridge\environment_ready() ) {
		$issues['generatepress_bridge'] = array(
			'message' => 'The optional GeneratePress OAuth tool bridge is paused. It requires GeneratePress AI 1.0.x and the native OAuth and MCP classes.',
			'url'     => admin_url( 'options-general.php?page=church-website-connector' ),
			'action'  => 'Review the OAuth bridge',
		);
	}
	return $issues;
}

/** Appears immediately after activation and clears when dependencies are available. */
function dependency_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$issues = dependency_issues();
	if ( isset( $issues['mcp_adapter'] ) ) {
		$adapter = $issues['mcp_adapter'];
		echo '<div data-cwc-adapter-notice class="notice notice-' . esc_attr( $adapter['level'] ) . '"><p data-cwc-adapter-message>' . esc_html( $adapter['message'] ) . '</p><p><a href="' . esc_url( $adapter['url'] ) . '">' . esc_html( $adapter['action'] ) . '</a></p></div>';
		unset( $issues['mcp_adapter'] );
	}
	if ( ! $issues ) {
		return; }
	echo '<div class="notice notice-warning"><p><strong>Website Connector needs setup</strong></p><ul>';
	foreach ( $issues as $issue ) {
		echo '<li>' . esc_html( $issue['message'] ) . ' <a' . ( 'Open GeneratePress AI setup' === $issue['action'] ? ' class="button button-primary"' : '' ) . ' href="' . esc_url( $issue['url'] ) . '">' . esc_html( $issue['action'] ) . '</a></li>';
	}
	echo '</ul><p><a href="' . esc_url( admin_url( 'options-general.php?page=church-website-connector' ) ) . '">Open Website Connector</a></p></div>';
}

function connection_details(): array {
	return array(
		'generatepress_mcp'            => rest_url( 'generatepress-ai/mcp' ),
		'generatepress_oauth_metadata' => rest_url( 'generatepress-ai/oauth/metadata/authorization-server' ),
		'wp_rocket_mcp'                => rest_url( 'mcp/mcp-oauth-server' ),
	);
}

/** Local checks only. No licence key, token, password or user audit data is returned. */
function connection_status( $input = array() ) {
	$permission = status_permission();
	if ( is_wp_error( $permission ) ) {
		return $permission;
	}
	$license = null;
	if ( class_exists( '\GeneratePress_AI_License' ) ) {
		try {
			$instance = \GeneratePress_AI_License::get_instance();
			if ( is_callable( array( $instance, 'is_active_cached' ) ) ) {
				$license = (bool) $instance->is_active_cached();
			}
		} catch ( \Throwable $e ) {
			$license = null;
		}
	}
	$ready = UsedCss\environment();
	return array(
		'plugin_version'                  => VERSION,
		'current_site_url'                => current_identity()['home'],
		'bound_site_url'                  => bound_site(),
		'enabled_on_current_site'         => is_bound(),
		'dependencies'                    => array(
			'wordpress_abilities'                 => function_exists( 'wp_register_ability' ) && function_exists( 'wp_get_ability' ),
			'mcp_adapter'                         => class_exists( '\WP\MCP\Core\McpAdapter' ),
			'generatepress_ai_version'            => defined( 'GENERATEPRESS_AI_VERSION' ) ? GENERATEPRESS_AI_VERSION : null,
			'generatepress_license_cached_active' => $license,
			'generateblocks_version'              => defined( 'GENERATEBLOCKS_VERSION' ) ? GENERATEBLOCKS_VERSION : null,
			'wp_rocket_version'                   => defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : null,
			'wp_rocket_oauth'                     => class_exists( '\WPMedia\MCP\OAuth\Bootstrap' ),
		),
		'codex_client_allowed'            => feature_enabled( 'codex' ),
		'discovery_compatibility_enabled' => feature_enabled( 'compatibility' ),
		'rest_tools_enabled'              => feature_enabled( 'rest_tools' ),
		'pdf_upload'                      => array(
			'enabled'                 => feature_enabled( 'rest_tools' ),
			'current_user_can_upload' => ! is_wp_error( PdfUpload\permission() ),
			'max_bytes'               => min( PdfUpload\MAX_BYTES, wp_max_upload_size() ),
		),
		'imagify_pdf_protection'          => Configuration\pdf_protection_status(),
		'generatepress_oauth_bridge'      => GeneratePressBridge\status(),
		'primary_connection'              => Maintenance\primary_connection(),
		'redirection'                     => RedirectionTools\readiness(),
		'integrations'                    => array_map( '\ChurchWebsiteConnector\Integrations\status', array_keys( Integrations\FLAGS ) ),
		'used_css'                        => array(
			'enabled'                   => feature_enabled( 'used_css' ),
			'ready'                     => ! is_wp_error( $ready ),
			'waiting_for_configuration' => feature_enabled( 'used_css' ) && is_wp_error( $ready ) && 'church_connector_disabled' === $ready->get_error_code() && function_exists( 'get_rocket_option' ),
			'message'                   => is_wp_error( $ready ) ? $ready->get_error_message() : 'Native Used CSS services are ready.',
			'current_user_can_clear'    => ! is_wp_error( UsedCss\permission() ) && ! is_wp_error( $ready ),
			'legacy_helpers_active'     => legacy_used_css(),
		),
		'dependency_issues'               => array_map( static fn( array $issue ): string => $issue['message'], dependency_issues() ),
		'connections'                     => connection_details(),
		'verification'                    => 'Local readiness only. Sign in and verify each connection from the intended AI client. Used CSS regeneration is a separate asynchronous process.',
	);
}

function register_status_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) || ! is_bound() ) {
		return;
	}
	wp_register_ability(
		'church-connector/connection-status',
		array(
			'label'               => 'Website Connector status',
			'description'         => 'Read-only website identity, dependency and connection-helper status. Does not sign in, grant access, change settings or clear caches.',
			'category'            => 'church-connector',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			),
			'permission_callback' => __NAMESPACE__ . '\\status_permission',
			'execute_callback'    => __NAMESPACE__ . '\\connection_status',
			'meta'                => array(
				'show_in_rest' => false,
				'mcp'          => array( 'public' => true ),
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
				),
			),
		)
	);
}

function register_category(): void {
	if ( function_exists( 'wp_register_ability_category' ) ) {
		wp_register_ability_category(
			'church-connector',
			array(
				'label'       => 'Website Connector',
				'description' => 'Connection readiness, protected cache operations and REST-backed metadata tools.',
			)
		);
	}
}

function admin_save(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'An administrator must change connection settings.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'church_connector_save' );
	$flags = array(
		'enabled'       => true,
		'setup_pending' => false,
	);
	foreach ( array( 'compatibility', 'codex', 'used_css', 'rest_tools', 'redirection_tools', 'generatepress_bridge', 'tsf_tools', 'rankmath_tools', 'snippets_tools', 'wsform_tools', 'imagify_pdf_protection' ) as $key ) {
		$flags[ $key ] = isset( $_POST[ $key ] ) && '1' === $_POST[ $key ];
	}
	$result = configure( $flags );
	if ( is_wp_error( $result ) ) {
		wp_die(
			esc_html( $result->get_error_message() ),
			'Website Connector',
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}
	wp_safe_redirect( admin_url( 'options-general.php?page=church-website-connector&saved=1' ) );
	exit;
}

function field( string $id, string $label, string $value ): void {
	echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
	echo '<input class="large-text" id="' . esc_attr( $id ) . '" type="text" readonly value="' . esc_attr( $value ) . '"></p>';
}

function admin_page(): void {
	if ( is_wp_error( status_permission() ) ) {
		wp_die( 'An administrator must open this page.', '', array( 'response' => 403 ) );
	}
	$status          = connection_status();
	$saved           = settings();
	$deps            = $status['dependencies'];
	$bridge_selected = 'generatepress' === $status['primary_connection']['selected'];
	$bridge_ready    = $status['primary_connection']['bridge_ready'];
	$mcp_disabled    = 'none' === $status['primary_connection']['selected'];
	$pdf_limit       = rtrim( rtrim( number_format( $status['pdf_upload']['max_bytes'] / 1048576, 2, '.', '' ), '0' ), '.' ) . ' MiB';

	echo '<div class="wrap"><h1>Website Connector</h1>';
	Updates\render_notice();
	Defaults\render_notice();
	GeneratePressSetup\render_callout( $status );
	echo '<section aria-labelledby="cwc-status-title"><h2 id="cwc-status-title">Status</h2>';
	Maintenance\render_summary( $status );
	echo '<p>Connect your assistant to this website using the setup selected below. Reuse an existing connection for this website where available; a new connection needs native website sign-in and your consent.</p>';
	echo '<p><strong>Plugin version:</strong> ' . esc_html( VERSION ) . '</p>';
	echo '<p><strong>Website:</strong> ' . esc_html( $status['current_site_url'] ) . '</p>';
	if ( isset( $_GET['saved'] ) && '1' === $_GET['saved'] ) {
		echo '<div class="notice notice-success"><p>Website settings saved.</p></div>';
	}
	if ( ! $status['enabled_on_current_site'] ) {
		echo '<div class="notice notice-warning"><p>The helpers are paused or this website address has changed. Review the settings below and save to enable them on this website.</p></div>';
	}
	if ( $status['used_css']['legacy_helpers_active'] ) {
		echo '<div class="notice notice-warning"><p>An older Used CSS helper is active. Its existing tools remain available. This plugin waits before adding its replacement clearing tools. Keep an administrator browser session open while deactivating the old helper, then save these settings to grant your replacement clearing permission. Do not delete the old helper until the replacement is verified.</p></div>';
	}
	if ( ! empty( $saved['setup_pending'] ) ) {
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Initial setup uses the saved settings shown below. The saved connection choices already apply; save only when you change an option. Finish any missing plugin or licence setup, and review existing authorised GeneratePress clients. Optional helpers wait when their plugin is unavailable.', 'church-website-connector' ) . '</p></div>';
	}
	if ( $mcp_disabled ) {
		echo '<div class="notice notice-warning" data-cwc-mcp-warning><p><strong>' . esc_html__( 'WordPress and plugin tools through MCP need a connection', 'church-website-connector' ) . '</strong></p><p>' . esc_html( $status['primary_connection']['message'] ) . ' <a href="#cwc-settings-title">' . esc_html__( 'Choose a connection in Settings', 'church-website-connector' ) . '</a></p></div>';
	} elseif ( $bridge_selected ) {
		echo $bridge_ready
			? '<p><strong>Current setup:</strong> A single primary GeneratePress connection provides GeneratePress authoring, the enabled Website Connector features and WP Rocket tools. A separate WP Rocket sign-in is not needed for these tools.</p>'
			: '<p><strong>Current setup:</strong> GeneratePress is selected as the primary connection. Complete its setup or resolve the compatibility or website warning below before using the extra tools. The selection has not changed to separate connections.</p>';
	} else {
		echo '<p><strong>Current setup:</strong> ' . esc_html( $status['primary_connection']['message'] ) . ( 'green' !== $status['primary_connection']['level'] ? ' ' . esc_html__( 'Complete the WP Rocket and MCP Adapter setup shown in Status before using that connection.', 'church-website-connector' ) : '' ) . '</p>';
	}

	Status\render( $status );
	echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=generatepress-ai' ) ) . '">Open GeneratePress AI setup</a> · <a href="' . esc_url( admin_url( 'options-general.php?page=wprocket' ) ) . '">Open WP Rocket</a> · <a href="' . esc_url( admin_url( 'plugin-install.php?s=mcp-adapter&tab=search&type=term' ) ) . '">Find MCP Adapter</a> · <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Manage installed plugins</a></p>';

	AdapterSetup\render();
	echo '</section><section aria-labelledby="cwc-settings-title"><h2 id="cwc-settings-title">Settings</h2>';
	Defaults\render();
	echo '<form id="cwc-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'church_connector_save' );
	echo '<input type="hidden" name="action" value="church_connector_save"><fieldset><legend class="screen-reader-text">Website settings and PDF protection</legend>';
	$options = array(
		'compatibility'        => 'Keep GeneratePress and WP Rocket sign-in discovery compatible',
		'codex'                => 'Allow official Codex sign-in through the alternative WP Rocket connection',
		'used_css'             => 'Enable protected Used CSS clearing for an authorised administrator',
		'rest_tools'           => 'Enable page, post and media details and PDF uploads through this website connection',
		'redirection_tools'    => 'Enable Redirection management through MCP (requires the Redirection plugin; changes use previews and approval)',
		'tsf_tools'            => 'Enable The SEO Framework page/post and site-wide SEO tools (changes require approval)',
		'rankmath_tools'       => 'Enable Rank Math page/post and site-wide SEO tools (changes require approval)',
		'snippets_tools'       => 'Enable Code Snippets management (code execution requires explicit approval)',
		'wsform_tools'         => 'Enable WS Form Pro design and settings tools (submission records are excluded)',
		'generatepress_bridge' => 'Use one GeneratePress connection for the enabled connector and WP Rocket tools',
	);
	foreach ( $options as $key => $label ) {
		$description = 'codex' === $key ? ' aria-describedby="cwc-codex-access"' : ( 'generatepress_bridge' === $key ? ' aria-describedby="cwc-gp-bridge-access"' : '' );
		echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . $description . checked( true === ( $saved[ $key ] ?? ( 'rest_tools' === $key ) ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
	}
	Configuration\render();
	echo '</fieldset>';
	echo '<p id="cwc-codex-access">Optional alternative connection only: this permits Codex sign-in directly through WP Rocket. It adds no tools to the single GeneratePress connection. This helper applies to WP Rocket’s native client trust checks. The primary GeneratePress connection uses GeneratePress’s own sign-in. The official Codex identity represents the shared application. It does not grant access to everyone using Codex or restrict access to a particular person, device, project or chat. Each connection needs an authorised WordPress account and native sign-in/consent. Other chats with access to your authorised connection can use its permissions.</p>';
	echo '<p id="cwc-gp-bridge-access">The single GeneratePress method is enabled on a fresh installation. This checkbox shows the saved choice and takes effect when changed and saved. Existing websites retain their saved choice during updates. GeneratePress AI keys do not expire: protect them in your client’s secure credential store and revoke unused connections in Settings → GeneratePress AI. Enabling this adds the fixed connector and WP Rocket tool list to every authorised GeneratePress AI client on this website, including existing connections. Review those connections first. Native WordPress permissions and operation checks still apply. Disabling this checkbox removes the extra tools; it does not revoke native keys. Supported: GeneratePress AI 1.0.x.</p>';
	echo '<p>Saving applies these choices to the website address shown above. If the address changed, previous helper-specific clearing grants are removed and only the current eligible administrator is granted access again. The official Codex client still uses native sign-in, permission checks and your consent. To revoke existing connections, use the providers’ own connection controls; switching off a helper does not revoke previously issued access.</p>';
	submit_button( 'Save website settings' );
	echo '</form></section><h2>' . ( $mcp_disabled ? 'Choose a connection for WordPress and plugin tools' : ( $bridge_selected ? 'Use the primary GeneratePress connection' : 'Set up the website connections' ) ) . '</h2>';
	echo $mcp_disabled ? '<p>' . esc_html( $status['primary_connection']['message'] ) . '</p>' : ( $bridge_selected
		? '<p>Use the GeneratePress address below as the single primary connection for GeneratePress, enabled connector features (including PDF uploads when enabled) and WP Rocket tools. Reuse an existing connection for this website where possible. Its native key stays valid until revoked; keep it in your client’s secure credential store. The WP Rocket address is an alternative for clients using a separate WP Rocket connection.</p>'
		: '<p>Use separate GeneratePress and WP Rocket connections. GeneratePress supplies authoring tools; the WP Rocket connection supplies its cache tools and enabled connector features, including PDF uploads. Resolve any setup warnings in Status, reuse existing connections where available and use each provider’s native website sign-in.</p>' );
	$urls = $status['connections'];
	field( 'cwc-gp-url', $bridge_selected ? 'Primary MCP server — GeneratePress' : 'GeneratePress MCP server', $urls['generatepress_mcp'] );
	field( 'cwc-gp-oauth', 'GeneratePress OAuth metadata', $urls['generatepress_oauth_metadata'] );
	field( 'cwc-rocket-url', $mcp_disabled ? 'WP Rocket MCP server — Codex sign-in option is disabled' : ( $bridge_selected ? 'Alternative WP Rocket MCP server' : 'WP Rocket MCP server (separate connection)' ), $urls['wp_rocket_mcp'] );
	$prompt = "Help me connect this exact website using its native HTTP MCP servers and OAuth sign-in.\n\nWebsite: " . $status['current_site_url'] . "\nGeneratePress MCP: " . $urls['generatepress_mcp'] . "\nGeneratePress authorization-server metadata: " . $urls['generatepress_oauth_metadata'] . "\nWP Rocket MCP: " . $urls['wp_rocket_mcp'] . "\n\nChurch Website Connector " . VERSION . ' is installed. Its GeneratePress tool bridge is off or unavailable, so use separate named GeneratePress and WP Rocket connections. Reuse existing connections for this exact website where possible. Let me complete native website sign-in and consent. Start with church-connector/connection-status through the WP Rocket connection, verify the exact website, and discover only the tools needed. Check PDF upload readiness and whether church-connector/upload-pdf is available. For authorised PDF uploads, use that tool, respect the reported size limit, and return the public file URL. Updates to existing metadata and Redirection rules need their reviewed previews; new PDF uploads do not use metadata preview tokens. Do not upload files, edit content or clear caches as a connection test. Rediscover tools after an update; if the client retains an old list, refresh or restart it and verify again. Keep passwords, tokens and licence keys out of chat. Report authentication errors without weakening native verification or permissions.';
	if ( $bridge_selected ) {
		$prompt = "Connect this exact website through its native GeneratePress AI HTTP MCP server and OAuth sign-in.\n\nWebsite: " . $status['current_site_url'] . "\nPrimary MCP server: " . $urls['generatepress_mcp'] . "\nAuthorization-server metadata: " . $urls['generatepress_oauth_metadata'] . "\n\nChurch Website Connector " . VERSION . ( $bridge_ready ? ' has its compatible tool bridge enabled.' : ' selects GeneratePress as the primary connection; complete its setup or resolve its compatibility warning before using the extra tools.' ) . ' Use this single primary connection for GeneratePress, enabled connector features (including PDF uploads) and WP Rocket tools. Do not create a separate WP Rocket connection for those tools. Reuse an existing connection for this exact website where possible. GeneratePress issues a non-expiring, revocable key; keep it in macOS Keychain or the client’s secure credential store. Use native website sign-in and consent. Start with church-connector/connection-status, verify the exact website and discover only the tools needed through this same connection. Check PDF upload readiness and whether church-connector/upload-pdf is available. For authorised PDF uploads, use that tool, respect the reported size limit, and return the public file URL. Updates to existing metadata and Redirection rules need their reviewed previews; new PDF uploads do not use metadata preview tokens. Do not upload files, edit content or clear caches as a connection test. Rediscover tools after an update; if the client retains an old list, refresh or restart it and verify again. Keep passwords, tokens and licence keys out of chat. Preserve native authentication and permissions.';
	}
	if ( $mcp_disabled ) {
		$prompt = 'Church Website Connector ' . VERSION . ' is installed on ' . $status['current_site_url'] . ', but both connection options are disabled. Before attempting new Codex access to the additional WordPress and plugin tools through MCP, ask me to enable the single GeneratePress connection (recommended), or alternative WP Rocket Codex sign-in, in Settings → Website Connector. Then use the updated connection instructions and complete native sign-in and consent. GeneratePress’s own authoring connection is independent. Existing authorised connections are not revoked by these switches. Do not create new credentials or use direct REST access as a workaround. Do not edit content, upload files or clear caches as a connection test. Keep credentials out of chat.';
	}
	echo '<p><label for="cwc-setup-prompt"><strong>Copy this message into your website project chat</strong></label></p><textarea class="large-text" id="cwc-setup-prompt" rows="12" readonly>' . esc_textarea( $prompt ) . '</textarea>';
	echo '<p>The tested client is native Codex desktop. The Codex trust helper above applies to the separate WP Rocket route; the primary connection uses GeneratePress’s native sign-in. Other clients must support the selected provider’s OAuth flow. This page checks local readiness, not completed sign-in, tool discovery or a successful upload.</p>';
	echo '<h2 id="cwc-update-check">Automatic updates</h2><p>Updates are available from the connector’s published GitHub releases. To receive them automatically, open <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Installed Plugins</a> and review the auto-update setting for Church Website Connector. New installations select it once when website policy permits. Your website’s update policy and schedule apply.</p>';
	Updates\render_check();
	echo '<h2>Optional plugin integrations</h2><p>The SEO Framework, Rank Math, Code Snippets and WS Form Pro each have a separate switch above. New setups select all four; missing or incompatible plugins leave their helper waiting. Both SEO switches can stay selected when the website uses only one SEO plugin. Updates preserve existing choices. After changing a switch or activating its plugin, refresh your connection and start with integration-capabilities. Read the item, review a preview, then apply only after approval. Code-executing changes require a separate acknowledgement. WS Form tools cover form design and selected settings; submission records and notification credentials are excluded.</p>';
	echo '<h2>Page, post and media details</h2><p>Use the selected website connection to inspect supported details, featured images, categories and existing media descriptions or alt text. Changes to existing details require a reviewed preview belonging to the signed-in account, and are rejected if the item changed. GeneratePress handles block content and draft authoring. WordPress permissions still apply; no separate REST connection or password is needed.</p>';
	echo '<h2>PDF uploads</h2><p>Give your assistant a PDF file and ask it to upload it through this website connection. The current website limit is <strong>' . esc_html( $pdf_limit ) . ' per PDF</strong>; client request limits may be lower. The connected account must have permission to upload files, and the page, post and media details and PDF uploads checkbox must be enabled.</p>';
	echo '<p>PDFs are stored as public Media Library files. You can supply a title, caption and description, or ask to attach the file to a page or post your account can edit. The assistant returns the file’s public address for linking. A new upload creates an attachment directly when requested; it does not use a metadata preview token or publish a page. If an upload result is uncertain, check the Media Library before retrying to avoid a duplicate.</p>';
	echo '<h2>Redirection (optional)</h2><p>Supported: Redirection 5.10.x, minimum 5.10.1; tested on 5.10.1. New installations enable this helper by default; existing settings, including disabled or missing legacy choices, are preserved on upgrade and reactivation. The separate Redirection plugin is required. When enabled, it checks equivalent plain sources and bounded local redirect chains before previewing and applying a change. Regex/conditional rules, other plugins, server redirects and other domain aliases are outside those checks. Large or complex rule sets may need the native Redirection editor.</p>';
	echo '<p>Metadata and Redirection previews expire after 15 minutes and belong to the signed-in WordPress user. The assistant must obtain approval within your task. An approval flag is a client acknowledgement, not a separate confirmation clicked by you on this website. Permanent redirect deletion has no undo.</p>';
	echo '<h2>Used CSS</h2><p>Prefer clearing the affected page. Whole-site clearing needs explicit scope confirmation. WP Rocket regenerates CSS asynchronously after clearing.</p>';
	if ( $status['used_css']['current_user_can_clear'] ) {
		echo '<p><a class="button" href="' . esc_url( admin_url( 'tools.php?page=church-connector-used-css' ) ) . '">Open Used CSS tool</a></p>';
	}
	Maintenance\render_diagnostics();
	echo '<p>Used CSS support in this release: WP Rocket 3.24.x. Later versions pause clearing until a compatible plugin update is installed. No content or cache is changed by opening this page.</p></div>';
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_status_ability' );
add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_category' );
