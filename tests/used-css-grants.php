<?php
/** Run inside an isolated WordPress installation with a minimal WP Rocket service fixture. */
if ( ! defined( 'CWC_TEST_USED_CSS_GRANTS' ) || true !== CWC_TEST_USED_CSS_GRANTS ) {
	return;
}
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use ChurchWebsiteConnector as C;

define( 'WP_ROCKET_VERSION', '3.24.1' );
function get_rocket_option( $key, $default = null ) {
	return 'remove_unused_css' === $key ? 1 : $default;
}
function rocket_clean_domain() {}
$services = static fn() => new class() {
	public function get( $name ) { return $this; }
	public function truncate( $scope ) {}
	public function clear_url_usedcss( $url ) {}
	public function get_rows_by_url( $url ) {}
	public function get_completed_count() {}
};
add_filter( 'rocket_container', $services );

$checks = array();
function grant_check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
	$GLOBALS['checks'][] = $label;
}
function stored_grant_caps( $id ) {
	global $wpdb;
	$key = $wpdb->get_blog_prefix() . 'capabilities';
	$row = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $id, $key ) );
	return maybe_unserialize( $row );
}

/** Run the second editor immediately before the actual native usermeta write. */
function grant_with_intervening_edit( $operation, $editor ) {
	global $wpdb;
	$observed = false;
	$table = preg_quote( $wpdb->usermeta, '/' );
	$hook = static function ( $query ) use ( &$observed, $table, $editor ) {
		if ( ! $observed && preg_match( '/^\s*UPDATE\s+`?' . $table . '`?\s/i', $query ) && str_contains( $query, 'meta_value' ) && str_contains( $query, 'calendar_fixture_reader' ) ) {
			$observed = true;
			$editor();
		}
		return $query;
	};
	add_filter( 'query', $hook, PHP_INT_MIN );
	try {
		$result = $operation();
	} finally {
		remove_filter( 'query', $hook, PHP_INT_MIN );
	}
	grant_check( $observed, 'The second edit ran during the physical ' . $operation . ' update' );
	return $result;
}

$initial_options = C\settings();
$initial_grantees = get_option( C\UsedCss\GRANTEE, false );
$initial_user = get_current_user_id();
$initial_direct = array();
foreach ( get_users( array( 'capability' => C\UsedCss\CAP ) ) as $existing ) {
	if ( ! empty( $existing->caps[ C\UsedCss\CAP ] ) ) {
		$initial_direct[] = $existing->ID;
	}
}
$subject = wp_insert_user( array( 'user_login' => 'grant-safety-editor', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
grant_check( ! is_wp_error( $subject ), 'Created an isolated administrator for grant, revoke and retry tests' );
$current = wp_set_current_user( $subject );
$current->add_cap( 'rocket_remove_unused_css' );

$settings = $initial_options;
$settings['used_css'] = true;
update_option( C\SETTINGS, $settings, false );
delete_option( C\UsedCss\GRANTEE );
$database = $wpdb;
$peer = null;
if ( ! ( $wpdb instanceof WP_SQLite_DB ) ) {
	$peer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$peer->set_prefix( $table_prefix );
	grant_check( $peer->get_var( 'SELECT CONNECTION_ID()' ) !== $wpdb->get_var( 'SELECT CONNECTION_ID()' ), 'The native account editor has an independent MySQL connection' );
}
$edit = static function ( $callback ) use ( $database, $peer, $subject ) {
	global $wpdb;
	if ( $peer ) {
		$wpdb = $peer;
	}
	try {
		wp_cache_delete( $subject, 'user_meta' );
		$callback( new WP_User( $subject ) );
	} finally {
		$wpdb = $database;
	}
};

try {
	// Keep the original audit reproducer. Writing the ownership option invokes a separate WordPress user editor.
	$recorded = false;
	$after_record = static function ( $key ) use ( $subject, $edit, &$recorded ) {
		if ( C\UsedCss\GRANTEE === $key ) {
			$edit( static function ( $user ) { $user->add_cap( 'calendar_fixture_reader' ); } );
			$recorded = ! empty( stored_grant_caps( $subject )['calendar_fixture_reader'] );
		}
	};
	add_action( 'added_option', $after_record );
	try {
		$result = C\UsedCss\activate();
	} finally {
		remove_action( 'added_option', $after_record );
	}
	grant_check( $recorded, 'Native WordPress saved the unrelated calendar permission during grant recording' );
	grant_check( true === $result && in_array( $subject, get_option( C\UsedCss\GRANTEE ), true ), 'Successful grant records the intended grantee' );
	grant_check( ! empty( stored_grant_caps( $subject )[ C\UsedCss\CAP ] ) && ! empty( stored_grant_caps( $subject )['calendar_fixture_reader'] ), 'The grant preserves the independent account edit on disk' );
	grant_check( $current->has_cap( C\UsedCss\CAP ) && $current->has_cap( 'calendar_fixture_reader' ), 'The existing current-user object sees both capabilities after the grant' );

	$current->add_cap( 'shift_A' );
	$case_change = static function () use ( $edit, $subject ) {
		$edit( static function ( $user ) {
			$user->remove_cap( 'shift_A' );
			$user->add_cap( 'shift_a' );
		} );
		grant_check( ! empty( stored_grant_caps( $subject )['shift_a'] ), 'The second editor committed a case-sensitive permission change during revocation' );
	};
	$result = grant_with_intervening_edit( 'ChurchWebsiteConnector\\UsedCss\\revoke_grants', $case_change );
	$caps = stored_grant_caps( $subject );
	grant_check( true === $result && ! isset( $caps[ C\UsedCss\CAP ] ) && false === get_option( C\UsedCss\GRANTEE, false ), 'Revocation removes the direct connector grant and clears its ownership' );
	grant_check( ! isset( $caps['shift_A'] ) && ! empty( $caps['shift_a'] ) && ! empty( $caps['calendar_fixture_reader'] ), 'Revocation preserves the case-sensitive independent edit and unrelated calendar grant' );
	grant_check( ! $current->has_cap( C\UsedCss\CAP ) && $current->has_cap( 'shift_a' ), 'Current account permissions are refreshed after revocation' );

	$remove_calendar = static function () use ( $edit, $subject ) {
		$edit( static function ( $user ) {
			$user->remove_cap( 'calendar_fixture_reader' );
			$user->add_cap( 'calendar_fixture_rota' );
		} );
		grant_check( ! isset( stored_grant_caps( $subject )['calendar_fixture_reader'] ), 'The native calendar revocation committed before the connector grant' );
	};
	$result = grant_with_intervening_edit( 'ChurchWebsiteConnector\\UsedCss\\activate', $remove_calendar );
	$caps = stored_grant_caps( $subject );
	grant_check( true === $result && ! empty( $caps[ C\UsedCss\CAP ] ) && ! empty( $caps['calendar_fixture_rota'] ) && ! isset( $caps['calendar_fixture_reader'] ), 'Granting at the physical write boundary preserves added and removed permissions' );
	grant_check( true === C\UsedCss\activate() && array( $subject ) === get_option( C\UsedCss\GRANTEE ), 'Repeated activation keeps a single ownership record' );
	grant_check( true === C\UsedCss\revoke_grants() && true === C\UsedCss\revoke_grants(), 'Revocation is safe to retry' );

	// A revoked prerequisite or continually changing account must never be overridden by a retry.
	$current->add_cap( 'calendar_fixture_reader' );
	$result = grant_with_intervening_edit(
		'ChurchWebsiteConnector\\UsedCss\\activate',
		static function () use ( $edit ) {
			$edit( static function ( $user ) { $user->remove_cap( 'rocket_remove_unused_css' ); } );
		}
	);
	$caps = stored_grant_caps( $subject );
	grant_check( is_wp_error( $result ) && ! isset( $caps[ C\UsedCss\CAP ] ) && ! isset( $caps['rocket_remove_unused_css'] ), 'Grant retry respects a concurrent native revocation of its WP Rocket prerequisite' );
	$current->add_cap( 'rocket_remove_unused_css' );

	$writer_count = 0;
	$editing = false;
	$churn = static function ( $query ) use ( &$editing, &$writer_count, $edit, $database ) {
		if ( ! $editing && preg_match( '/^\s*UPDATE\s+`?' . preg_quote( $database->usermeta, '/' ) . '`?\s/i', $query ) && str_contains( $query, 'calendar_fixture_reader' ) ) {
			if ( ++$writer_count > 10 ) {
				throw new RuntimeException( 'Unbounded retries for a repeatedly changed account' );
			}
			$editing = true;
			try {
				$edit( static function ( $user ) use ( $writer_count ) { $user->add_cap( 'retained_shift_' . $writer_count ); } );
			} finally {
				$editing = false;
			}
		}
		return $query;
	};
	add_filter( 'query', $churn, PHP_INT_MIN );
	try {
		$result = C\UsedCss\activate();
	} finally {
		remove_filter( 'query', $churn, PHP_INT_MIN );
	}
	$caps = stored_grant_caps( $subject );
	grant_check( is_wp_error( $result ) && $writer_count > 1 && ! isset( $caps[ C\UsedCss\CAP ] ), 'Sustained write conflicts stop the grant without claiming success' );
	$all_saved = true;
	for ( $attempt = 1; $attempt <= $writer_count; ++$attempt ) {
		$all_saved = $all_saved && ! empty( $caps[ 'retained_shift_' . $attempt ] );
	}
	grant_check( $all_saved && $current->has_cap( 'retained_shift_' . $writer_count ), 'All independent grants survive sustained contention on disk and in the current-user cache' );
	grant_check( true === C\UsedCss\activate() && true === C\UsedCss\revoke_grants(), 'A fresh attempt succeeds after the other editor finishes' );

	add_role( 'cwc_grant_fixture_role', 'Fixture role', array( C\UsedCss\CAP => true ) );
	$current->add_role( 'cwc_grant_fixture_role' );
	grant_check( true === C\UsedCss\activate() && ! isset( stored_grant_caps( $subject )[ C\UsedCss\CAP ] ) && ! get_option( C\UsedCss\GRANTEE ), 'A role-supplied permission is never adopted as a direct connector grant' );
	grant_check( true === C\UsedCss\revoke_grants() && user_can( $subject, C\UsedCss\CAP ), 'Connector revocation preserves role-provided access' );
} finally {
	remove_filter( 'rocket_container', $services );
	wp_set_current_user( $initial_user );
	wp_delete_user( $subject );
	remove_role( 'cwc_grant_fixture_role' );
	foreach ( $initial_direct as $id ) {
		( new WP_User( $id ) )->add_cap( C\UsedCss\CAP );
	}
	if ( false === $initial_grantees ) {
		delete_option( C\UsedCss\GRANTEE );
	} else {
		update_option( C\UsedCss\GRANTEE, $initial_grantees, false );
	}
	update_option( C\SETTINGS, $initial_options, false );
	if ( $peer ) {
		$peer->close();
	}
}
file_put_contents( '/reports/used-css-grants.json', wp_json_encode( array( 'checks' => count( $checks ), 'cases' => $checks, 'independent_database_connection' => (bool) $peer, 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION ) ) );
echo 'USED CSS GRANT CHECKS PASSED ' . count( $checks );
