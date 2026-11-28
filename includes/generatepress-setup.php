<?php
/** Administrator browser setup of the separate licensed plugin. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\GeneratePressSetup;

use ChurchWebsiteConnector as Connector;

defined( 'ABSPATH' ) || exit;

const PLUGIN_FILE  = 'generatepress-ai/generatepress-ai.php';
const STORE        = 'https://updates.generatepress.com/';
const ITEM_ID      = 2936664;
const STATE        = 'church_connector_generatepress_setup';
const LOCK         = 'church_connector_generatepress_lock';
const MAX_BYTES    = 32 * 1024 * 1024;
const ADMIN_ACTION = 'church_connector_setup_generatepress';

/** Return only connector-owned messages; never forward a vendor response or URL. */
function error( string $code, string $message, int $status = 400 ): \WP_Error {
	return new \WP_Error( 'church_connector_gp_' . $code, $message, array( 'status' => $status ) );
}

/** Recheck the approving administrator, configured website and filesystem policy. */
function owner_allowed( int $owner ): bool {
	return Connector\is_bound() && $owner > 0 && user_can( $owner, 'manage_options' )
		&& user_can( $owner, 'install_plugins' ) && user_can( $owner, 'activate_plugins' )
		&& wp_is_file_mod_allowed( 'plugins' );
}

/** Retire saved helper authorisations and expiry jobs after an upgrade. */
function remove_legacy_authorisations(): void {
	if ( ! is_user_logged_in() || ! user_can( get_current_user_id(), 'manage_options' ) ) {
		return;
	}
	delete_option( 'church_connector_generatepress_pair' );
	wp_unschedule_hook( 'church_connector_expire_generatepress_pair' );
}

/** Persist a bounded local outcome without licence values, URLs or transcripts. */
function finish( string $status, string $message, string $version = '' ): array {
	$version = Connector\Maintenance\version( $version ) ?? '';
	$result  = array(
		'status'  => $status,
		'message' => $message,
		'version' => $version,
		'site'    => Connector\current_identity(),
	);
	update_option( STATE, $result, false );
	return $result;
}

/** Inspect plugin headers only; status views must not contact the licence server. */
function status( ?bool $licence_active = null ): array {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugins   = get_plugins();
	$version   = $plugins[ PLUGIN_FILE ]['Version'] ?? '';
	$installed = isset( $plugins[ PLUGIN_FILE ] );
	$active    = $installed && is_plugin_active( PLUGIN_FILE );
	$saved     = get_option( STATE, array() );
	$same      = is_array( $saved ) && ( $saved['site'] ?? null ) === Connector\current_identity();
	$message   = $same ? (string) ( $saved['message'] ?? '' ) : __( 'Ready to install. Enter your GeneratePress One licence key, then choose Install and activate.', 'church-website-connector' );
	if ( $same && 'complete' === ( $saved['status'] ?? '' ) ) {
		if ( ! $installed ) {
			$message = __( 'GeneratePress AI is currently missing. Install it to resume setup.', 'church-website-connector' );
		} elseif ( ! $active ) {
			$message = __( 'GeneratePress AI is installed but currently inactive.', 'church-website-connector' );
		} elseif ( true !== $licence_active ) {
			$message = __( 'GeneratePress AI is active. Check the current licence status in its settings.', 'church-website-connector' );
		}
	}
	return array(
		'installed' => $installed,
		'active'    => $active,
		'version'   => Connector\Maintenance\version( $version ),
		'message'   => $message,
	);
}

/** The native licensing contract has been inspected for the 1.0 release line. */
function supported_version( string $version ): bool {
	return 1 === preg_match( '/\A[0-9]+(?:\.[0-9]+){1,3}\z/', $version )
		&& version_compare( $version, '1.0.0', '>=' ) && version_compare( $version, '1.1.0', '<' );
}

/** Fixed HTTPS origin only; signed package addresses never leave this request. */
function package_url( $url ): bool {
	if ( ! is_string( $url ) ) {
		return false;
	}
	$parts = wp_parse_url( $url );
	return is_array( $parts ) && ( $parts['scheme'] ?? '' ) === 'https'
		&& ( $parts['host'] ?? '' ) === 'updates.generatepress.com'
		&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
		&& ! isset( $parts['port'] ) && ! isset( $parts['fragment'] )
		&& ! preg_match( '/[\x00-\x20\\\\]/', $url );
}

/** Use the same EDD service and fixed product as GeneratePress AI itself. */
function vendor_request( string $action, string $key ) {
	if ( ! in_array( $action, array( 'get_version', 'activate_license' ), true ) ) {
		return error( 'action', __( 'Unsupported setup operation.', 'church-website-connector' ) );
	}
	$body = array(
		'edd_action' => $action,
		'license'    => $key,
		'item_id'    => ITEM_ID,
		'url'        => Connector\current_identity()['home'],
	);
	if ( 'get_version' === $action ) {
		$body += array(
			'slug'        => 'generatepress-ai',
			'version'     => '0.0.0',
			'author'      => 'Tom Usborne',
			'beta'        => 0,
			'php_version' => PHP_VERSION,
			'wp_version'  => get_bloginfo( 'version' ),
		);
	} else {
		$body['item_name'] = rawurlencode( 'GeneratePress AI' );
	}
	$response = wp_safe_remote_post(
		STORE,
		array(
			'body'                => $body,
			'timeout'             => 20,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 512 * 1024,
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return error( 'service', __( 'The GeneratePress service could not be reached. Check the local setup result before authorising another attempt.', 'church-website-connector' ) );
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : error( 'response', __( 'GeneratePress returned an unexpected response.', 'church-website-connector' ) );
}

/** Translate only known EDD verdicts; never echo a licence or remote message. */
function licence_failure( $reason ): string {
	$messages = array(
		'missing'             => __( 'The licence key was not recognised. Check the selected 1Password field.', 'church-website-connector' ),
		'invalid'             => __( 'The licence key is invalid. Check the selected 1Password field.', 'church-website-connector' ),
		'expired'             => __( 'The GeneratePress One licence has expired.', 'church-website-connector' ),
		'disabled'            => __( 'The licence key has been disabled.', 'church-website-connector' ),
		'revoked'             => __( 'The licence key has been revoked.', 'church-website-connector' ),
		'no_activations_left' => __( 'The licence has reached its website activation limit.', 'church-website-connector' ),
		'item_name_mismatch'  => __( 'This key does not include GeneratePress AI. Select an active GeneratePress One licence.', 'church-website-connector' ),
		'site_inactive'       => __( 'The licence is not active for this website.', 'church-website-connector' ),
		'license_unavailable' => __( 'The GeneratePress licence service is temporarily unavailable. Check the result before retrying.', 'church-website-connector' ),
		'license_save_failed' => __( 'WordPress could not save the licence. Check GeneratePress AI settings before retrying.', 'church-website-connector' ),
	);
	return is_string( $reason ) && isset( $messages[ $reason ] ) ? $messages[ $reason ]
		: __( 'Licence activation could not be confirmed. Check GeneratePress AI settings and your licence before retrying.', 'church-website-connector' );
}

/** Keep the native licence manager's requests on the same verified HTTPS origin. */
function native_request_policy( array $arguments, string $url ): array {
	if ( untrailingslashit( $url ) === untrailingslashit( STORE ) ) {
		$arguments['redirection']         = 0;
		$arguments['sslverify']           = true;
		$arguments['reject_unsafe_urls']  = true;
		$arguments['limit_response_size'] = 512 * 1024;
	}
	return $arguments;
}

/** Validate the current stable release, before downloading or executing it. */
function release( string $key ) {
	$data = vendor_request( 'get_version', $key );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$version = $data['new_version'] ?? $data['version'] ?? '';
	if ( ( $data['slug'] ?? '' ) !== 'generatepress-ai' || ! is_string( $version ) || ! supported_version( $version ) ) {
		return error( 'version', __( 'The latest GeneratePress AI release requires a connector compatibility update. No older release was substituted.', 'church-website-connector' ) );
	}
	foreach ( array(
		'requires'     => get_bloginfo( 'version' ),
		'requires_php' => PHP_VERSION,
	) as $field => $current ) {
		$minimum = $data[ $field ] ?? '';
		if ( ! is_string( $minimum ) || ! preg_match( '/\A[0-9]+(?:\.[0-9]+){1,3}\z/', $minimum ) || version_compare( $current, $minimum, '<' ) ) {
			return error( 'requirements', __( 'This website does not meet the latest GeneratePress AI requirements.', 'church-website-connector' ) );
		}
	}
	$url = $data['package'] ?? '';
	if ( ! $url ) {
		$url = $data['download_link'] ?? '';
	}
	if ( ! $url ) {
		return error( 'package_missing', __( 'GeneratePress has not authorised a download for this licence and website.', 'church-website-connector' ) );
	}
	return package_url( $url ) ? array(
		'version' => $version,
		'url'     => $url,
	)
		: error( 'package_origin', __( 'GeneratePress returned a package address outside the verified update service. No download was followed.', 'church-website-connector' ) );
}

/** Bound ZIP inventory using WordPress's bundled ZIP reader. */
function check_archive( string $file ): bool {
	require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
	$zip     = new \PclZip( $file );
	$entries = $zip->listContent();
	if ( ! is_array( $entries ) || ! $entries || count( $entries ) > 2000 ) {
		return false;
	}
	$total = 0;
	$main  = false;
	foreach ( $entries as $entry ) {
		$name = $entry['filename'] ?? '';
		if ( ! is_string( $name ) || ! str_starts_with( $name, 'generatepress-ai/' ) || str_contains( $name, '\\' )
			|| preg_match( '~(?:^|/)\.\.?(/|$)|[\x00-\x1f]~', $name ) ) {
			return false;
		}
		$total += (int) ( $entry['size'] ?? MAX_BYTES * 4 );
		$main   = $main || 'generatepress-ai/generatepress-ai.php' === $name;
	}
	return $main && $total <= MAX_BYTES * 3;
}

/** Let WordPress unpack the ZIP, then verify headers before moving plugin files. */
function install_release( array $release, array $identity ) {
	$file = wp_tempnam( 'generatepress-ai.zip' );
	if ( ! $file ) {
		return error( 'temporary_file', __( 'WordPress could not create a temporary download file.', 'church-website-connector' ) );
	}
	$guard = static function ( $source ) use ( $release, $identity ) {
		if ( ! Connector\is_bound() || Connector\current_identity() !== $identity || ! owner_allowed( get_current_user_id() ) ) {
			return error( 'site_changed', __( 'The website or setup permissions changed. No plugin files were installed.', 'church-website-connector' ) );
		}
		$header = get_file_data(
			trailingslashit( $source ) . 'generatepress-ai.php',
			array(
				'name'    => 'Plugin Name',
				'version' => 'Version',
				'php'     => 'Requires PHP',
				'wp'      => 'Requires at least',
			)
		);
		if ( 'generatepress-ai' !== basename( untrailingslashit( $source ) ) || ( $header['name'] ?? '' ) !== 'GeneratePress AI'
			|| ! preg_match( '/\A[0-9]+(?:\.[0-9]+){1,3}\z/', $header['php'] ?? '' )
			|| ! preg_match( '/\A[0-9]+(?:\.[0-9]+){1,3}\z/', $header['wp'] ?? '' )
			|| ( $header['version'] ?? '' ) !== $release['version'] || version_compare( PHP_VERSION, $header['php'] ?? '999', '<' )
			|| version_compare( get_bloginfo( 'version' ), $header['wp'] ?? '999', '<' ) ) {
			return error( 'archive_identity', __( 'The downloaded package did not match the expected GeneratePress AI release.', 'church-website-connector' ) );
		}
		return $source;
	};
	try {
		$response = wp_safe_remote_get(
			$release['url'],
			array(
				'stream'              => true,
				'filename'            => $file,
				'timeout'             => 45,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => MAX_BYTES + 1,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || ! is_file( $file )
			|| filesize( $file ) > MAX_BYTES || ! check_archive( $file ) ) {
			return error( 'download', __( 'The licensed package could not be downloaded and verified. Inspect the setup result before retrying.', 'church-website-connector' ) );
		}
		add_filter( 'upgrader_source_selection', $guard );
		$skin      = new \WP_Ajax_Upgrader_Skin();
		$installer = new \Plugin_Upgrader( $skin );
		$installed = $installer->install( $file, array( 'overwrite_package' => false ) );
		if ( true !== $installed || $skin->get_errors()->has_errors() ) {
			return error( 'installation', __( 'WordPress could not install GeneratePress AI. Inspect Installed Plugins before retrying; the plugin may already be present.', 'church-website-connector' ) );
		}
		wp_clean_plugins_cache();
		return true;
	} finally {
		remove_filter( 'upgrader_source_selection', $guard );
		wp_delete_file( $file );
	}
}

/** Install only a missing plugin; never overwrite an administrator's installation. */
function run( string $key ) {
	if ( ! owner_allowed( get_current_user_id() ) || ! is_user_logged_in() ) {
		return error( 'forbidden', __( 'This setup is no longer authorised on this website.', 'church-website-connector' ), 403 );
	}
	if ( 1 !== preg_match( '/\A[a-zA-Z0-9_-]{1,256}\z/', $key )
		|| ( defined( 'GENERATEPRESS_AI_LICENSE_KEY' ) && ! hash_equals( trim( (string) GENERATEPRESS_AI_LICENSE_KEY ), $key ) ) ) {
		return error( 'managed_key', __( 'The supplied licence must match any licence already managed in the website configuration.', 'church-website-connector' ) );
	}
	$previous = get_option( LOCK, false );
	if ( is_array( $previous ) && ( $previous['expires'] ?? PHP_INT_MAX ) < time() ) {
		Connector\RestTools\conditional_delete( LOCK, $previous );
	}
	$holder = array(
		'token'   => wp_generate_uuid4(),
		'expires' => time() + 5 * MINUTE_IN_SECONDS,
	);
	if ( ! add_option( LOCK, $holder, '', false ) ) {
		return error( 'busy', __( 'GeneratePress AI setup is already running. Inspect its result before trying again.', 'church-website-connector' ), 409 );
	}
	$version  = '';
	$identity = Connector\current_identity();
	try {
		$started = finish( 'running', __( 'GeneratePress AI setup started. Refresh to check the result before retrying an interrupted request.', 'church-website-connector' ) );
		if ( get_option( STATE ) !== $started ) {
			return error( 'storage', __( 'WordPress could not save the setup state. No installation was attempted.', 'church-website-connector' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$plugins    = get_plugins();
		$registered = false;
		if ( ! isset( $plugins[ PLUGIN_FILE ] ) ) {
			if ( 'direct' !== get_filesystem_method( array(), WP_PLUGIN_DIR ) ) {
				return finish( 'failed', __( 'WordPress requires filesystem credentials. Install GeneratePress AI through Installed Plugins before retrying the licence form.', 'church-website-connector' ) );
			}
			$release = release( $key );
			if ( is_wp_error( $release ) && 'church_connector_gp_package_missing' === $release->get_error_code() ) {
				$activation = vendor_request( 'activate_license', $key );
				if ( is_wp_error( $activation ) || empty( $activation['success'] ) || ( $activation['license'] ?? '' ) !== 'valid' ) {
					$reason = is_wp_error( $activation ) ? 'license_unavailable' : ( $activation['error'] ?? $activation['license'] ?? '' );
					return finish( 'failed', licence_failure( $reason ) );
				}
				$registered = true;
				$release    = release( $key );
			}
			if ( is_wp_error( $release ) ) {
				return finish( 'failed', $release->get_error_message() . ( $registered ? __( ' The licence registration completed; no automatic deactivation was attempted.', 'church-website-connector' ) : '' ) );
			}
			$version = $release['version'];
			if ( Connector\current_identity() !== $identity || ! owner_allowed( get_current_user_id() ) ) {
				return finish( 'failed', __( 'The website or setup permissions changed. Review and authorise setup again.', 'church-website-connector' ) );
			}
			$installed = install_release( $release, $identity );
			if ( is_wp_error( $installed ) ) {
				return finish( 'failed', $installed->get_error_message() . ( $registered ? __( ' The licence registration completed; no automatic deactivation was attempted.', 'church-website-connector' ) : '' ), $version );
			}
			Connector\Maintenance\initial_auto_update( PLUGIN_FILE );
			$plugins = get_plugins();
		}
		if ( Connector\current_identity() !== $identity || ! owner_allowed( get_current_user_id() ) ) {
			return finish( 'failed', __( 'The website or setup permissions changed. Inspect the installed plugin before authorising setup again.', 'church-website-connector' ) );
		}
		$version = (string) ( $plugins[ PLUGIN_FILE ]['Version'] ?? '' );
		if ( ! supported_version( $version ) || is_wp_error( validate_plugin_requirements( PLUGIN_FILE ) ) ) {
			return finish( 'failed', __( 'The installed GeneratePress AI version needs a compatibility review before this setup can continue.', 'church-website-connector' ), $version );
		}
		if ( ! is_plugin_active( PLUGIN_FILE ) && ( is_wp_error( activate_plugin( PLUGIN_FILE, '', false, false ) ) || ! is_plugin_active( PLUGIN_FILE ) ) ) {
			return finish( 'failed', __( 'GeneratePress AI is installed but could not be activated. Review Installed Plugins.', 'church-website-connector' ), $version );
		}
		if ( ! class_exists( '\GeneratePress_AI_License' ) ) {
			require_once WP_PLUGIN_DIR . '/generatepress-ai/includes/class-license.php';
		}
		add_filter( 'http_request_args', __NAMESPACE__ . '\\native_request_policy', 10, 2 );
		$license = \GeneratePress_AI_License::get_instance();
		$current = $license->get_license_key();
		if ( '' !== $current && ! hash_equals( $current, $key ) ) {
			$existing_active  = $license->is_active();
			$invalid_statuses = array( 'missing', 'invalid', 'expired', 'disabled', 'revoked', 'no_activations_left', 'item_name_mismatch', 'site_inactive' );
			if ( $existing_active || ! in_array( $license->get_status(), $invalid_statuses, true ) ) {
				return finish( 'failed', __( 'GeneratePress AI already has a different licence that is valid or could not be conclusively checked. Its key and update preference were preserved. Review the licence in GeneratePress AI settings.', 'church-website-connector' ), $version );
			}
		}
		$activation_result = null;
		if ( $registered ) {
			$active = $license->save_license_key( $key ) && $license->refresh();
		} elseif ( hash_equals( $current, $key ) && $license->is_active_cached() ) {
			$active = $license->is_active();
		} else {
			$activation_result = $license->activate( $key );
			$active            = true === $activation_result;
		}
		if ( ! $active ) {
			$reason = is_wp_error( $activation_result ) && in_array( $activation_result->get_error_code(), array( 'license_unavailable', 'license_save_failed' ), true )
				? $activation_result->get_error_code() : $license->get_status();
			return finish( 'failed', __( 'GeneratePress AI is installed and active. ', 'church-website-connector' ) . licence_failure( $reason ), $version );
		}
		$auto = Connector\Maintenance\auto_status( PLUGIN_FILE );
		return finish( 'complete', __( 'GeneratePress AI is installed and its licence is active. Reload ChurchWeb Connector settings to check connection readiness. Automatic updates: ', 'church-website-connector' ) . $auto['message'], $version );
	} catch ( \Throwable $exception ) {
		return finish( 'failed', __( 'Setup did not finish. Check Installed Plugins and GeneratePress AI licence status before authorising another attempt. A licence registration may already have completed.', 'church-website-connector' ), $version );
	} finally {
		remove_filter( 'http_request_args', __NAMESPACE__ . '\\native_request_policy', 10 );
		Connector\RestTools\conditional_delete( LOCK, $holder );
	}
}

/** Require the administrator session, permissions and action nonce for setup. */
function admin_request( array $input ) {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return error( 'method', __( 'Use the installation button in ChurchWeb Connector settings.', 'church-website-connector' ), 405 );
	}
	if ( ! is_user_logged_in() || ! owner_allowed( get_current_user_id() ) ) {
		return error( 'forbidden', __( 'An administrator with plugin installation rights must run this setup.', 'church-website-connector' ), 403 );
	}
	$nonce = $input['_wpnonce'] ?? null;
	if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, ADMIN_ACTION ) ) {
		return error( 'nonce', __( 'This setup page has expired. Reload ChurchWeb Connector before trying again.', 'church-website-connector' ), 403 );
	}
	if ( array_diff( array_keys( $input ), array( 'action', '_wpnonce', '_wp_http_referer', 'license_key', 'cwc-gp-install-submit' ) )
		|| ( $input['action'] ?? '' ) !== ADMIN_ACTION || ! is_string( $input['license_key'] ?? null ) ) {
		return error( 'input', __( 'Enter the GeneratePress One licence key in the licence field.', 'church-website-connector' ) );
	}
	$key = trim( wp_unslash( $input['license_key'] ) );
	if ( 1 !== preg_match( '/\A[a-zA-Z0-9_-]{1,256}\z/', $key ) ) {
		return error( 'input', __( 'The licence key is empty or malformed. Select the licence field in 1Password or paste the key again.', 'church-website-connector' ) );
	}
	return run( $key );
}

/** AJAX response contains only connector-owned messages, never submitted fields. */
function admin_ajax(): void {
	$result = admin_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The method, permissions and action nonce are checked in admin_request().
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		wp_send_json_error( array( 'message' => $result->get_error_message() ), is_array( $data ) ? ( $data['status'] ?? 400 ) : 400 );
	}
	wp_send_json_success( $result );
}

/** The normal form still works when JavaScript is unavailable. */
function admin_post(): void {
	$result = admin_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Validation is shared with the AJAX handler above.
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		wp_die(
			esc_html( $result->get_error_message() ),
			'',
			array(
				'response'  => is_array( $data ) ? ( $data['status'] ?? 400 ) : 400,
				'back_link' => true,
			)
		);
	}
	wp_safe_redirect( admin_url( 'options-general.php?page=church-website-connector#cwc-gp-setup' ) );
	exit;
}

/** Put the licence field and installation action beside the top setup warning. */
function render_callout( array $status ): void {
	$dependencies = $status['dependencies'];
	if ( $dependencies['generatepress_ai_version'] && true === $dependencies['generatepress_license_cached_active'] ) {
		return;
	}
	$state = status( $dependencies['generatepress_license_cached_active'] );
	echo '<section id="cwc-gp-setup" class="notice notice-warning inline" aria-labelledby="cwc-gp-setup-title"><h2 id="cwc-gp-setup-title">' . esc_html__( 'Finish GeneratePress AI setup', 'church-website-connector' ) . '</h2>';
	echo '<p>' . esc_html__( 'Fill the licence key using 1Password in your browser, or paste it here. Then install and activate GeneratePress AI in one step.', 'church-website-connector' ) . '</p>';
	if ( owner_allowed( get_current_user_id() ) ) {
		echo '<form id="cwc-gp-install-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-running="' . esc_attr__( 'Installing GeneratePress AI and activating the licence…', 'church-website-connector' ) . '" data-invalid="' . esc_attr__( 'Enter a valid GeneratePress One licence key.', 'church-website-connector' ) . '" data-failure="' . esc_attr__( 'The request was interrupted. Check Installed Plugins and licence status before retrying; setup may have completed.', 'church-website-connector' ) . '">';
		wp_nonce_field( ADMIN_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ADMIN_ACTION ) . '">';
		echo '<p><label for="cwc-generatepress-license-key"><strong>' . esc_html__( 'GeneratePress One licence key', 'church-website-connector' ) . '</strong></label><br><input id="cwc-generatepress-license-key" name="license_key" type="text" class="regular-text code" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="256" required aria-describedby="cwc-gp-licence-help" style="max-width:100%"></p>';
		echo '<p id="cwc-gp-licence-help">' . esc_html__( 'Choose your GeneratePress licence item in 1Password. This is a software licence, not your WordPress login password. A saved custom field may need to be mapped once; pasting also works.', 'church-website-connector' ) . '</p>';
		submit_button( __( 'Install and activate GeneratePress AI', 'church-website-connector' ), 'primary', 'cwc-gp-install-submit', false );
		echo '</form>';
	} else {
		echo '<p>' . esc_html__( 'Save the website settings below if this website is not yet enabled. An administrator with plugin installation rights can then continue here.', 'church-website-connector' ) . '</p>';
	}
	echo '<p id="cwc-gp-setup-progress" role="status" aria-live="polite">' . esc_html( $state['message'] ) . '</p>';
	echo '<p>' . esc_html__( 'New installations enable automatic updates when website policy allows. Unattended connector updates do not install it or access 1Password. Nothing is installed until this form is submitted.', 'church-website-connector' ) . '</p></section>';
}

add_action( 'admin_init', __NAMESPACE__ . '\\remove_legacy_authorisations' );
add_action( 'wp_ajax_' . ADMIN_ACTION, __NAMESPACE__ . '\\admin_ajax' );
add_action( 'admin_post_' . ADMIN_ACTION, __NAMESPACE__ . '\\admin_post' );
