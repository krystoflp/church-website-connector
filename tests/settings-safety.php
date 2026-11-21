<?php
/** Exercise copied-site reactivation, settings writes and timezone conversion in real WordPress. */
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
use ChurchWebsiteConnector as C;

wp_set_current_user( 1 );
$cases = array();
function safety_check( $condition, $description ) {
	if ( ! $condition ) {
		throw new RuntimeException( $description );
	}
	$GLOBALS['cases'][] = $description;
}
/** Read the text that is pasted into the assistant, excluding adjacent admin notices. */
function safety_copied_prompt( string $screen ): string {
	$tags = new WP_HTML_Tag_Processor( $screen );
	while ( $tags->next_tag( 'textarea' ) ) {
		if ( 'cwc-setup-prompt' === $tags->get_attribute( 'id' ) ) {
			return $tags->get_modifiable_text();
		}
	}
	throw new RuntimeException( 'Missing setup message textarea' );
}
function safety_reactivate() {
	$file = C\Updates\plugin_file();
	deactivate_plugins( $file );
	$result = activate_plugin( $file );
	if ( is_wp_error( $result ) || ! is_plugin_active( $file ) ) {
		throw new RuntimeException( 'WordPress could not reactivate the fixture plugin' );
	}
}
function safety_submit( $choices, $confirmation = '', $review = '' ) {
	$review = $review ?: C\settings_review_action( get_option( C\SETTINGS, null ), C\current_identity() );
	$_POST = array( '_wpnonce' => wp_create_nonce( $confirmation ?: $review ), 'church_connector_review' => $review );
	if ( $confirmation ) { $_POST['confirm_saved'] = '1'; $_POST['church_connector_rebind_nonce'] = wp_create_nonce( $confirmation ); }
	foreach ( $choices as $key => $enabled ) {
		if ( true === $enabled ) {
			$_POST[ $key ] = '1';
		}
	}
	$_REQUEST = $_POST;
	$redirect = static function () { throw new RuntimeException( 'success redirect' ); };
	$die = static function () {
		return static function ( $message, $title, $args ) { throw new RuntimeException( $message, $args['response'] ); };
	};
	add_filter( 'wp_redirect', $redirect );
	add_filter( 'wp_die_handler', $die );
	try {
		C\admin_save();
	} catch ( RuntimeException $result ) {
		return $result;
	} finally {
		remove_filter( 'wp_redirect', $redirect );
		remove_filter( 'wp_die_handler', $die );
		$_POST = array();
		$_REQUEST = array();
	}
	throw new RuntimeException( 'Settings form did not finish' );
}

$original = C\settings();
$original_timezone = get_option( 'timezone_string' );
$original_offset = get_option( 'gmt_offset' );
$previous_grants = get_option( C\UsedCss\GRANTEE );
$settings = array_replace( $original, array( 'used_css' => false, 'rest_tools' => false, 'wsform_tools' => false, 'generatepress_bridge' => true, 'tsf_tools' => true, 'imagify_pdf_protection' => false, 'setup_pending' => false ) );
safety_check( true === C\configure( $settings ), 'A changed configuration is saved successfully' );
$saved = C\settings();
safety_check( true === C\configure( $saved ) && C\settings() === $saved, 'Submitting identical settings is a successful no-op' );

// Vendor plugins are absent in this fixture. Exercise the actual copy control for each saved route.
foreach ( array( 'none', 'generatepress', 'separate_connections' ) as $route ) {
	$route_choices = static function ( $choices ) use ( $route ) {
		$choices['generatepress_bridge'] = 'generatepress' === $route;
		$choices['codex'] = 'separate_connections' === $route;
		return $choices;
	};
	add_filter( 'option_' . C\SETTINGS, $route_choices );
	try {
		$before_prompt = C\settings();
		ob_start();
		C\admin_page();
		$prompt = safety_copied_prompt( ob_get_clean() );
		safety_check( $before_prompt === C\settings(), "Copying setup instructions preserves the settings for $route" );
		safety_check( str_contains( $prompt, 'Settings > Website Connector' ) && ! str_contains( $prompt, 'church-connector/connection-status' ) && ! str_contains( $prompt, '/wp-json/' ), "An unavailable $route connection gives the administrator a next step before client discovery" );
		if ( 'none' === $route ) {
			safety_check( str_contains( strtolower( $prompt ), 'disabled' ), 'The copied message identifies an intentionally disabled connection' );
		} elseif ( 'generatepress' === $route ) {
			safety_check( str_contains( $prompt, 'GeneratePress' ) && ! str_contains( $prompt, 'use separate named' ), 'A missing GeneratePress provider keeps the selected route' );
		} else {
			safety_check( str_contains( $prompt, 'WP Rocket installation' ), 'The unavailable alternative route identifies its missing WP Rocket dependency' );
		}
	} finally {
		remove_filter( 'option_' . C\SETTINGS, $route_choices );
	}
}

// Model the providers' ready reports with distinct endpoints to identify which route was recommended.
$ready_status = C\connection_status();
$urls = $ready_status['connections'];
foreach ( array( 'generatepress', 'separate_connections' ) as $route ) {
	$ready_status['primary_connection'] = array_replace(
		$ready_status['primary_connection'],
		array( 'selected' => $route, 'state' => 'generatepress' === $route ? 'ready' : 'separate_connections', 'bridge_ready' => 'generatepress' === $route, 'level' => 'green' )
	);
	$prompt = C\setup_prompt( $ready_status );
	safety_check( str_contains( $prompt, 'Codex desktop' ) && str_contains( $prompt, 'ChatGPT web' ) && str_contains( $prompt, 'church-connector/connection-status' ), "The ready $route prompt distinguishes client support and starts with an identity read" );
	safety_check( str_contains( $prompt, $urls['generatepress_mcp'] ) && ( 'separate_connections' === $route ) === str_contains( $prompt, $urls['wp_rocket_mcp'] ), "The $route prompt names only its selected connection endpoints" );
	if ( 'separate_connections' === $route ) {
		safety_check( str_contains( $prompt, 'authorised for this project' ) && str_contains( $prompt, 'ask which setup to use' ), 'The alternative route requires a decision when the local project requires GeneratePress' );
	}
}
$ready_status['primary_connection']['state'] = 'setup_required';
$ready_status['dependencies']['wp_rocket_version'] = '3.24.1';
$ready_status['dependencies']['wp_rocket_oauth'] = false;
$library_prompt = C\setup_prompt( $ready_status );
safety_check( str_contains( $library_prompt, 'WP Rocket connection library' ) && ! str_contains( $library_prompt, $urls['wp_rocket_mcp'] ), 'A missing alternative-route OAuth library is identified before sign-in' );
$ready_status['dependencies']['wp_rocket_oauth'] = true;
$adapter_prompt = C\setup_prompt( $ready_status );
safety_check( str_contains( $adapter_prompt, 'MCP Adapter' ) && ! str_contains( $adapter_prompt, $urls['wp_rocket_mcp'] ), 'An alternative connection missing its compatible MCP Adapter gives the administrator that next step' );
$deny = static fn( $proposed, $old ) => $old;
$filter = 'pre_update_option_' . C\SETTINGS;
$requested = array_replace( $saved, array( 'rest_tools' => true ) );
add_filter( $filter, $deny, 10, 2 );
$failure = safety_submit( $requested );
remove_filter( $filter, $deny, 10 );
safety_check( 500 === $failure->getCode() && str_contains( $failure->getMessage(), 'could not save' ) && C\settings() === $saved, 'Refused settings POST reports a storage error and retains the prior values' );
// Make the native database update affect zero rows, independently of option-value filters.
$table = $GLOBALS['wpdb']->options;
$no_rows = static function ( $query ) use ( $table ) {
	if ( str_starts_with( $query, 'UPDATE ' . $table . ' ' ) && str_contains( $query, "'" . C\SETTINGS . "'" ) ) {
		return "UPDATE `$table` SET option_value = option_value WHERE 1 = 0";
	}
	return $query;
};
add_filter( 'query', $no_rows );
try {
	$failure = safety_submit( $requested );
} finally {
	remove_filter( 'query', $no_rows );
}
safety_check( 500 === $failure->getCode() && C\settings() === $saved, 'A database write affecting zero rows reports failure through the ordinary settings form' );
$partial = static function ( $proposed ) { $proposed['rest_tools'] = false; return $proposed; };
add_filter( $filter, $partial );
$failure = C\configure( array_replace( $requested, array( 'codex' => ! $saved['codex'] ) ) );
remove_filter( $filter, $partial );
safety_check( is_wp_error( $failure ) && C\settings() === $saved, 'A filter refusing one switch cannot commit half the configuration' );
C\configure( $saved );

$old_review = C\settings_review_action( $saved, C\current_identity() );
safety_check( 'success redirect' === safety_submit( $requested )->getMessage() && C\feature_enabled( 'rest_tools' ), 'The first administrator’s reviewed form saves' );
safety_check( 409 === safety_submit( $saved, '', $old_review )->getCode() && C\feature_enabled( 'rest_tools' ), 'An older settings tab cannot overwrite a newer save' );
C\configure( $saved );
$race = static function ( $option, $old ) {
	if ( C\SETTINGS === $option ) {
		$other = $old;
		$other['wsform_tools'] = true;
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->options, array( 'option_value' => maybe_serialize( $other ) ), array( 'option_name' => $option ) );
	}
};
add_action( 'update_option', $race, 10, 2 );
$failure = safety_submit( $requested );
remove_action( 'update_option', $race, 10 );
safety_check( 409 === $failure->getCode() && C\feature_enabled( 'wsform_tools' ) && ! C\feature_enabled( 'rest_tools' ), 'A concurrent save after nonce validation survives the conditional database write' );
C\configure( $saved );

// Carry an unfinished first-run review across the copy as well.
$saved['setup_pending'] = true;
C\configure( $saved );
$adapter_before = get_option( C\AdapterSetup\STATE );
$auto_before = get_site_option( 'auto_update_plugins' );
$old_user_id = wp_insert_user( array( 'user_login' => 'settings-safety-grantee', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
$old_user = get_user_by( 'id', $old_user_id );
$old_user->add_cap( C\UsedCss\CAP );
update_option( C\UsedCss\GRANTEE, array( $old_user_id ) );
$copy = static fn() => 'https://copied.example';
$path = static fn() => 'https://connector.example/wordpress';
$before_path_change = C\settings_review_action( $saved, C\current_identity() );
add_filter( 'option_siteurl', $path );
safety_check( 409 === safety_submit( $saved, '', $before_path_change )->getCode() && ! C\is_bound() && C\settings() === $saved, 'An ordinary form opened before a path change cannot authorise an unseen installation address' );
safety_reactivate();
safety_check( ! C\is_bound() && C\settings() === $saved, 'Changing only the WordPress installation path keeps reactivation paused' );
remove_filter( 'option_siteurl', $path );
add_filter( 'option_home', $copy );
add_filter( 'option_siteurl', $copy );
safety_reactivate();
safety_check( ! C\is_bound() && $saved === C\settings() && ! C\feature_enabled( 'tsf_tools' ), 'Actual deactivation and reactivation on a copy preserves every choice and pauses enabled tools' );
safety_check( $adapter_before === get_option( C\AdapterSetup\STATE ) && $auto_before === get_site_option( 'auto_update_plugins' ) && ! C\AdapterSetup\automatic_allowed(), 'The copy creates no onboarding attempt and keeps its previous update preferences' );
ob_start();
C\admin_page();
$screen = ob_get_clean();
$copy_prompt = safety_copied_prompt( $screen );
safety_check( str_contains( $copy_prompt, 'Settings > Website Connector' ) && str_contains( $copy_prompt, 'both the Site Address and WordPress Address' ) && ! str_contains( $copy_prompt, '/wp-json/' ) && ! str_contains( $copy_prompt, 'sign-in' ) && ! str_contains( $copy_prompt, 'church-connector/connection-status' ), 'The copied message for an unbound site prioritises reviewing both addresses before sign-in or discovery' );
$off_on_copy = static fn( $choices ) => array_replace( $choices, array( 'generatepress_bridge' => false, 'codex' => false ) );
add_filter( 'option_' . C\SETTINGS, $off_on_copy );
try {
	ob_start();
	C\admin_page();
	$off_prompt = safety_copied_prompt( ob_get_clean() );
	safety_check( $copy_prompt === $off_prompt, 'A copied site calls for address review even if both stored connection switches are off' );
} finally {
	remove_filter( 'option_' . C\SETTINGS, $off_on_copy );
}
$tags = new WP_HTML_Tag_Processor( $screen );
$checked = array();
while ( $tags->next_tag( 'input' ) ) {
	if ( 'checkbox' === $tags->get_attribute( 'type' ) ) {
		$checked[ $tags->get_attribute( 'name' ) ] = null !== $tags->get_attribute( 'checked' );
	}
}
safety_check( str_contains( $screen, 'The helpers are paused' ) && ! str_contains( $screen, 'The saved connection choices already apply' ) && false === $checked['wsform_tools'] && true === $checked['tsf_tools'], 'The review screen shows the saved choices and explains that helpers on the copy are paused' );
add_filter( $filter, $deny, 10, 2 );
$failure = safety_submit( $saved );
remove_filter( $filter, $deny, 10 );
safety_check( 500 === $failure->getCode() && ! C\is_bound() && C\settings() === $saved && get_option( C\UsedCss\GRANTEE ) === array( $old_user_id ) && user_can( $old_user_id, C\UsedCss\CAP ), 'Failed rebinding leaves the copy paused and preserves recorded grants' );
add_filter( $filter, $partial );
$failure = safety_submit( $requested );
remove_filter( $filter, $partial );
safety_check( 500 === $failure->getCode() && ! C\is_bound() && C\settings() === $saved && get_option( C\UsedCss\GRANTEE ) === array( $old_user_id ), 'Refusing one reviewed choice cannot bind a copy or revoke its recorded grants' );
$rewritten = array_replace( $saved, C\current_identity() );
update_option( C\SETTINGS, $rewritten );
safety_reactivate();
ob_start();
C\admin_page();
$rewritten_screen = ob_get_clean();
safety_check( ! C\is_bound() && str_contains( $rewritten_screen, $saved['home'] ) && str_contains( $rewritten_screen, C\current_identity()['home'] ), 'A migration rewriting both plaintext addresses remains paused and shows the original reviewed addresses' );
update_option( C\SETTINGS, $saved );
$action = C\rebind_action( $saved, C\current_identity() );
safety_check( $action && str_contains( $screen, 'Confirm this address and keep my settings' ) && str_contains( $screen, esc_html( $saved['wordpress'] ) ) && str_contains( $screen, esc_html( C\current_identity()['wordpress'] ) ), 'The copied installation shows its old and current addresses together with one confirmation' );
safety_check( '' === C\rebind_action( array_replace( $saved, array( 'home' => array() ) ), C\current_identity() ), 'Malformed stored addresses do not offer quick confirmation' );
$old_action = C\rebind_action( array_replace( $saved, array( 'rest_tools' => ! $saved['rest_tools'] ) ), C\current_identity() );
safety_check( 403 === safety_submit( array(), $old_action )->getCode() && ! C\is_bound(), 'An earlier set of choices cannot authorise the current binding' );
add_filter( $filter, $deny, 10, 2 );
$failure = safety_submit( array(), $action );
remove_filter( $filter, $deny, 10 );
safety_check( 500 === $failure->getCode() && C\settings() === $saved && ! C\is_bound(), 'A refused one-click save leaves all settings and the paused binding intact' );
$deny_revoke = static function ( $decision, $id, $key ) use ( $old_user_id ) {
	return (int) $id === $old_user_id && $GLOBALS['wpdb']->prefix . 'capabilities' === $key ? false : $decision;
};
add_filter( 'update_user_metadata', $deny_revoke, 10, 3 );
$failed_cleanup = safety_submit( array(), $action );
remove_filter( 'update_user_metadata', $deny_revoke, 10 );
safety_check( 500 === $failed_cleanup->getCode() && ! C\is_bound() && ! empty( C\settings()['grant_cleanup_pending'] ) && user_can( $old_user_id, C\UsedCss\CAP ), 'A failed grant cleanup keeps the confirmed copy paused for an explicit retry' );
$action = C\rebind_action( C\settings(), C\current_identity() );
$succeeded = safety_submit( array( 'wsform_tools' => true, 'rest_tools' => true ), $action );
safety_check( C\settings_match( C\settings(), array_diff_key( $saved, array_flip( array( 'home', 'wordpress', 'binding_hash', 'reviewed_addresses' ) ) ) ) && C\reviewed_identity( C\settings() ) === C\current_identity(), 'One click reuses every stored helper choice and ignores posted switches' );
safety_check( 'success redirect' === $succeeded->getMessage() && C\is_bound() && C\bound_site() === 'https://copied.example' && ! C\feature_enabled( 'wsform_tools' ) && C\feature_enabled( 'tsf_tools' ), 'Reviewed settings POST binds the new address and applies the chosen switches' );
safety_check( ! get_option( C\UsedCss\GRANTEE ) && ! user_can( $old_user_id, C\UsedCss\CAP ), 'Successful rebinding removes the previous site’s recorded grant' );
$rebound = C\settings();
safety_reactivate();
safety_check( C\settings() === $rebound && C\is_bound(), 'Subsequent same-site reactivation preserves the reviewed binding' );
remove_filter( 'option_home', $copy );
remove_filter( 'option_siteurl', $copy );
$legacy = $saved;
unset( $legacy['binding_hash'], $legacy['reviewed_addresses'] );
update_option( C\SETTINGS, $legacy );
safety_reactivate();
safety_check( ! C\is_bound() && C\settings() === $legacy, 'Upgrading a URL-only configuration cannot silently approve the current site' );
safety_check( 'success redirect' === safety_submit( array(), C\rebind_action( $legacy, C\current_identity() ) )->getMessage() && C\is_bound(), 'One explicit review restores a legacy connection with its saved choices' );
foreach ( array( array(), array( 'schema' => 2, 'used_css' => false ), 'invalid' ) as $unknown ) {
	update_option( C\SETTINGS, $unknown );
	safety_reactivate();
	safety_check( get_option( C\SETTINGS ) === $unknown && ! C\is_bound(), 'Existing empty or unrecognised settings require review, case ' . gettype( $unknown ) . wp_json_encode( $unknown ) );
}
update_option( C\SETTINGS, $original );
if ( false === $previous_grants ) { delete_option( C\UsedCss\GRANTEE ); } else { update_option( C\UsedCss\GRANTEE, $previous_grants ); }

set_error_handler( static function ( $level, $message ) { throw new ErrorException( $message, 0, $level ); }, E_WARNING | E_NOTICE );
try {
	update_option( 'timezone_string', '' );
	foreach ( array( array( 0, '10:00:00' ), array( 1, '09:00:00' ), array( 5.75, '04:15:00' ), array( -3.5, '13:30:00' ) ) as list( $offset, $gmt ) ) {
		update_option( 'gmt_offset', $offset );
		$date = C\PostTools\local_date( '2026-11-21T10:00:00' );
		safety_check( is_array( $date ) && '2026-11-21 ' . $gmt === $date['gmt'], 'Warning-free fixed UTC offset ' . $offset . ' converts to ' . $gmt . ' UTC' );
	}
	update_option( 'timezone_string', 'Europe/London' );
	foreach ( array( '2026-03-29T01:30:00', '2026-10-25T01:30:00' ) as $invalid ) {
		safety_check( is_wp_error( C\PostTools\local_date( $invalid ) ), 'London clock-change time is refused: ' . $invalid );
	}
	safety_check( '2026-03-29 01:30:00' === C\PostTools\local_date( '2026-03-29T02:30:00' )['gmt'] && '2026-10-25 02:30:00' === C\PostTools\local_date( '2026-10-25T02:30:00' )['gmt'], 'Valid London times on both transition dates retain the correct offset' );
} finally {
	restore_error_handler();
	update_option( 'gmt_offset', $original_offset );
	update_option( 'timezone_string', $original_timezone );
}
file_put_contents( '/reports/settings-safety.json', wp_json_encode( array( 'checks' => count( $cases ), 'cases' => $cases, 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION ) ) );
