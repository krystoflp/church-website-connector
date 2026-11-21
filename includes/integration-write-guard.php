<?php
/** Keep native SEO and snippet saves tied to the storage values that were reviewed. */
declare(strict_types=1);
namespace ChurchWebsiteConnector\Integrations\WriteGuard;

use ChurchWebsiteConnector\Integrations as I;
defined( 'ABSPATH' ) || exit;

function option_name( array $target ): string {
	return 'seo-framework' === $target['provider'] ? THE_SEO_FRAMEWORK_SITE_OPTIONS : 'rank-math-options-' . $target['section'];
}

/** Read physical rows independently of vendor and WordPress request caches. */
function storage( array $target, bool $lock = false ) {
	global $wpdb;
	if ( 'snippet' === $target['kind'] ) {
		$table = \Code_Snippets\code_snippets()->db->get_table_name( false );
		$sql   = $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $target['id'] );
	} elseif ( 'settings' === $target['kind'] ) {
		$table = $wpdb->options;
		$name  = option_name( $target );
		$sql   = $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, $name );
	} else {
		$table = $wpdb->postmeta;
		$keys  = array_values( 'seo-framework' === $target['provider'] ? I\Seo\tsf_map() : I\Seo\rank_map() );
		$in    = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %s placeholders are generated. The table, ID and every key from the fixed provider map are bound separately.
		$sql = $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM %i WHERE post_id = %d AND meta_key IN ($in) ORDER BY meta_id", array_merge( array( $table, $target['id'] ), $keys ) );
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- All query values are prepared above. The sole optional clause is the literal FOR UPDATE.
	$rows = $wpdb->get_results( $sql . ( $lock ? ' FOR UPDATE' : '' ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error ) {
		return I\error( 'storage', 'The native storage values could not be verified. Read the item again.', 503 );
	}
	$values = array();
	foreach ( $rows as $row ) {
		$key              = $row['meta_key'] ?? 'record';
		$values[ $key ][] = $row;
	}
	return array(
		'table'  => $table,
		'values' => $values,
	);
}

/** Identify the vendor's own write. A save initiated by a nested WordPress callback is independent. */
function native_write_key( array $target ): ?string {
	global $wpdb;
	$in_query = false;
	$key      = null;
	foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT ) as $frame ) {
		$function = $frame['function'];
		$class    = $frame['class'] ?? '';
		if ( ! $in_query ) {
			$in_query = 'query' === $function && ( $frame['object'] ?? null ) === $wpdb;
			continue;
		}
		if ( 'WP_Hook' === $class ) {
			break;
		}
		if ( 'snippet' === $target['kind'] && 'update' === $function && ( $frame['object'] ?? null ) === $wpdb ) {
			$id = $frame['args'][2]['id'] ?? null;
			if ( (int) ( $id['value'] ?? $id ) !== $target['id'] ) {
				return null;
			}
			$key = 'record';
		}
		if ( 'settings' === $target['kind'] && '' === $class && in_array( $function, array( 'add_option', 'delete_option', 'update_option' ), true ) ) {
			$name = option_name( $target );
			if ( $name !== $frame['args'][0] ) {
				return null;
			}
			$key = 'record';
		}
		if ( 'post' === $target['kind'] && '' === $class && in_array( $function, array( 'add_metadata', 'delete_metadata', 'update_metadata' ), true ) ) {
			if ( 'post' !== $frame['args'][0] || $target['id'] !== (int) $frame['args'][1] ) {
				return null;
			}
			$key = $frame['args'][2];
		}
		if ( ( 'The_SEO_Framework\Data\Plugin\Post' === $class && 'save_meta' === $function )
			|| ( 'RankMath\Rest\Shared' === $class && 'update_metadata' === $function )
			|| ( 'The_SEO_Framework\Data\Plugin' === $class && 'update_option' === $function )
			|| ( 'RankMath\Admin\Option_Center' === $class && 'save_settings' === $function )
			|| ( 'Code_Snippets\REST_API\Snippets\Snippets_REST_Controller' === $class && in_array( $function, array( 'update_item', 'activate_item', 'deactivate_item' ), true ) )
			|| ( 'Code_Snippets\trash_snippet' === $function && 'trash' === ( $target['operation'] ?? '' ) ) ) {
			return $key;
		}
	}
	return null;
}

function run( array $target, array $item, string $operation, callable $write ) {
	global $wpdb;
	$baseline = $item['write_storage'];
	$table    = $baseline['table'];
	$failure  = I\error( 'storage', 'The database could not protect the reviewed native change. Read the current values before retrying.', 503 );
	if ( ! ( $wpdb instanceof \WP_SQLite_DB ) ) {
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
		// SET TRANSACTION fails inside an existing transaction, keeping its ownership intact.
		if ( ! in_array( $engine, array( 'InnoDB', 'XtraDB' ), true ) || false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) ) {
			return $failure;
		}
	}
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		return $failure;
	}
	$committed = false;
	$stale     = I\error( 'stale', 'The native item changed during approval. Read its current values and review the change again.', 409 );
	try {
		$current = storage( $target, true );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$changed = $current !== $baseline;
		if ( ! $changed ) {
			$current_item = I\read( $target );
			if ( is_wp_error( $current_item ) ) {
				return $current_item;
			}
			$changed = I\fingerprint( $current_item['state'] ) !== I\fingerprint( $item['state'] );
		}
		if ( $changed ) {
			// The connector has not written anything. Preserve updates made by native read hooks.
			$committed = false !== $wpdb->query( 'COMMIT' );
			return $committed ? $stale : $failure;
		}
		// Row and range locks protect other connections. Native hooks in this connection
		// can still edit the same item, so compare the specific key at its actual SQL write.
		$conflict = false;
		$writes   = 0;
		$guard    = static function ( $sql ) use ( $target, $operation, $table, $baseline, &$conflict, &$writes ) {
			if ( ! is_string( $sql ) || ! preg_match( '/^\s*(?:UPDATE\s+|INSERT\s+INTO\s+|DELETE\s+FROM\s+)`?' . preg_quote( $table, '/' ) . '`?(?:\s|\()/i', $sql ) ) {
				return $sql;
			}
			$key = native_write_key( $target + array( 'operation' => $operation ) );
			if ( null === $key ) {
				return $sql;
			}
			$current = storage( $target );
			if ( $conflict || is_wp_error( $current ) || ( $current['values'][ $key ] ?? array() ) !== ( $baseline['values'][ $key ] ?? array() ) ) {
				$conflict = true;
				return '';
			}
			++$writes;
			return $sql;
		};
		add_filter( 'query', $guard, PHP_INT_MAX );
		try {
			$result = $write();
		} finally {
			remove_filter( 'query', $guard, PHP_INT_MAX );
		}
		// An independent native hook may have saved a newer value on this connection.
		// Preserve that save even when it caused our own write to be refused.
		$committed = false !== $wpdb->query( 'COMMIT' );
		if ( ! $committed ) {
			return $failure;
		}
		if ( $conflict && $writes ) {
			return I\error( 'stale', 'The native item changed during the save. Earlier approved fields may already be saved. Read the item before reviewing the remaining changes.', 409 );
		}
		return $conflict ? $stale : $result;
	} finally {
		if ( ! $committed ) {
			$wpdb->query( 'ROLLBACK' );
		}
	}
}
