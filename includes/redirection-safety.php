<?php
declare(strict_types=1);

namespace ChurchWebsiteConnector\RedirectionTools;

defined( 'ABSPATH' ) || exit;

const MIN_REDIRECTION = '5.10.1';
const NEXT_REDIRECTION = '5.11';
const MAX_RULES = 1000;
const MAX_HOPS = 20;
const MAX_MATCHES = 20000;

function supported_version( string $version ): bool {
    return preg_match( '/\A5\.10\.[0-9]+\z/', $version ) === 1
        && version_compare( $version, MIN_REDIRECTION, '>=' ) && version_compare( $version, NEXT_REDIRECTION, '<' );
}

function required_interfaces(): bool {
	$interfaces = array(
		'\\Redirection_Capabilities'        => array( 'has_access' ),
		'\\Red_Item_Sanitize'               => array( 'get', 'is_valid_error_code', 'is_valid_redirect_code' ),
		'\\Red_Item'                        => array( '__construct', 'to_json' ),
		'\\Red_Options'                     => array( 'get', 'reset' ),
		'\\Redirection\\Settings\\Settings' => array( 'reset' ),
		'\\Redirection\\Url\\Url'           => array( 'is_match' ),
		'\\Redirection\\Url\\SourceFlags'   => array( '__construct', 'get_json' ),
		'\\Redirection\\Url\\Path'          => array( 'is_match', 'to_lower' ),
		'\\Redirection\\Url\\Matcher'       => array( 'get_url', 'get_url_with_params' ),
		'\\Redirection\\Url\\Query'         => array( 'add_to_target', 'get', 'build_url', 'is_match' ),
		'\\Redirection\\Url\\Request'       => array( 'get_decoded_url', 'get_original_url' ),
	);
	foreach ( $interfaces as $class => $methods ) {
		foreach ( $methods as $method ) {
			if ( ! method_exists( $class, $method ) ) {
				return false;
			}
		}
	}
	if ( ! defined( '\\Red_Options::OPTION_KEY' ) ) {
		return false;
	}
    foreach ( [ 'CAP_REDIRECT_MANAGE', 'CAP_REDIRECT_ADD', 'CAP_REDIRECT_DELETE' ] as $cap ) {
        if ( ! defined( '\\Redirection_Capabilities::' . $cap ) ) { return false; }
    }
    return true;
}

function source_flags( array $fields ): \Redirection\Url\SourceFlags {
    return new \Redirection\Url\SourceFlags( [ 'flag_regex' => false, 'flag_case' => $fields['ignore_case'],
        'flag_trailing' => $fields['ignore_trailing_slash'], 'flag_query' => $fields['query_parameters'] ] );
}

/** Match an actual browser request using the native decoder, path and query rules. */
function matching_index( string $url ): string {
    return \Redirection\Url\Path::to_lower( ( new \Redirection\Url\Matcher( $url ) )->get_url_with_params() );
}

function matches_request( array $fields, string $request ): bool {
    $native = new \Redirection\Url\Request( $request );
    // exactorder is enforced by native database candidate selection, before is_match().
    if ( $fields['query_parameters'] === 'exactorder' && matching_index( $fields['source'] ) !== matching_index( $native->get_decoded_url() ) ) { return false; }
    return ( new \Redirection\Url\Url( $fields['source'] ) )->is_match( $native->get_decoded_url(), source_flags( $fields ) );
}

/** Intersect plain patterns, respecting both rules' case, slash and query flags. */
function sources_overlap( array $first, array $second ): bool {
    $broad = [ 'ignore', 'pass' ];
    if ( $first['query_parameters'] === 'exactorder' && $second['query_parameters'] === 'exactorder'
        && matching_index( $first['source'] ) !== matching_index( $second['source'] ) ) { return false; }
    $case = $first['ignore_case'] || $second['ignore_case'];
    $flags = source_flags( [ 'ignore_case' => $case, 'ignore_trailing_slash' => $first['ignore_trailing_slash'] || $second['ignore_trailing_slash'], 'query_parameters' => 'ignore' ] );
    if ( ! ( new \Redirection\Url\Path( $first['source'] ) )->is_match( $second['source'], $flags ) ) { return false; }
    $first_flags = source_flags( array_replace( $first, [ 'ignore_case' => $case ] ) );
    $second_flags = source_flags( array_replace( $second, [ 'ignore_case' => $case ] ) );
    $first_query = new \Redirection\Url\Query( $first['source'], $first_flags );
    $second_query = new \Redirection\Url\Query( $second['source'], $second_flags );
    // Native ignore/pass modes still require query values specified in the source.
    if ( ! in_array( $first['query_parameters'], $broad, true ) ) { $candidate = $first['source']; }
    elseif ( ! in_array( $second['query_parameters'], $broad, true ) ) { $candidate = $second['source']; }
    else { $candidate = \Redirection\Url\Query::build_url( '/', array_replace_recursive( $first_query->get(), $second_query->get() ) ); }
    return $first_query->is_match( $candidate, $first_flags ) && $second_query->is_match( $candidate, $second_flags );
}

/** Resolve browser dot segments; retain encoding for native request decoding. */
function browser_path( string $path ): string {
    $parts = explode( '/', $path ?: '/' ); $resolved = []; $last = count( $parts ) - 1;
    foreach ( $parts as $index => $part ) {
        $dot = str_ireplace( '%2e', '.', $part );
        if ( $dot === '.' || $dot === '..' ) {
            if ( $dot === '..' && count( $resolved ) > 1 ) { array_pop( $resolved ); }
            if ( $index === $last ) { $resolved[] = ''; }
        } else { $resolved[] = $part; }
    }
    return implode( '/', $resolved ) ?: '/';
}

/** No network requests. Other hosts and custom ports end local chain analysis. */
function local_request( string $url ): ?string {
    $parts = wp_parse_url( $url );
    if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || str_starts_with( $url, '//' ) ) { return null; }
    if ( isset( $parts['host'] ) ) {
        $host = (string) wp_parse_url( \ChurchWebsiteConnector\bound_site(), PHP_URL_HOST );
        $scheme = $parts['scheme'] ?? '';
        if ( strtolower( $parts['host'] ) !== strtolower( $host ) || ! in_array( $scheme, [ 'http', 'https' ], true )
            || ( isset( $parts['port'] ) && $parts['port'] !== ( $scheme === 'https' ? 443 : 80 ) ) ) { return null; }
    } elseif ( ! str_starts_with( $url, '/' ) || isset( $parts['scheme'] ) ) { return null; }
    return browser_path( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
}

function next_request( array $fields, string $request ): ?string {
    $native = new \Redirection\Url\Request( $request );
    $target = \Redirection\Url\Query::add_to_target( $fields['target'], $native->get_original_url(), source_flags( $fields ) );
    return local_request( $target );
}

/** Read bounded native data afresh at preview and apply; never cache across writes. */
function safety_rules( int $additional = 0 ) {
    $groups = dispatch( 'GET', '/redirection/v1/group', [ 'filterBy' => [ 'module' => 1 ], 'per_page' => 200, 'page' => 0 ] );
    if ( is_wp_error( $groups ) ) { return $groups; }
    if ( ! isset( $groups['total'], $groups['items'] ) || ! is_array( $groups['items'] ) || $groups['total'] > 200 ) {
        return error( 'analysis_limit', 'WordPress groups could not be checked within the safety limit. Use the native editor.', 409 );
    }
	// Redirection 5.10 dispatches by item status. A member enabled after its group was disabled still serves visitors.
	$group_map = array();
	foreach ( $groups['items'] as $group ) {
		if ( 1 === (int) ( $group['module_id'] ?? 0 ) ) {
			$group_map[ (int) $group['id'] ] = true;
		}
	}
    $rules = []; $seen = []; $total = null;
    for ( $page = 0; $page < MAX_RULES / 200; $page++ ) {
        $data = dispatch( 'GET', '/redirection/v1/redirect', [ 'per_page' => 200, 'page' => $page, 'orderby' => 'id', 'direction' => 'asc' ] );
        if ( is_wp_error( $data ) ) { return $data; }
        if ( ! isset( $data['total'], $data['items'] ) || ! is_array( $data['items'] ) || $data['total'] > MAX_RULES
            || ( $total !== null && $total !== (int) $data['total'] ) ) {
            return error( 'analysis_limit', 'Redirects changed or exceed the 1000-rule safety limit. Read again or use the native editor.', 409 );
        }
		$total = (int) $data['total'];
		if ( $total + $additional > MAX_RULES ) {
			return error( 'analysis_limit', 'The proposed addition would exceed the 1000-rule review limit.', 409 );
		}
        foreach ( $data['items'] as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( isset( $seen[ $id ] ) || $id < 1 ) { return error( 'stale', 'Redirects changed during the safety check. Try again.', 409 ); }
            $seen[ $id ] = true; $group_id = (int) ( $row['group_id'] ?? 0 );
            if ( ! array_key_exists( $group_id, $group_map ) || ! plain_rule( $row ) ) { continue; }
			$rules[] = array(
				'id'     => $id,
				'active' => ! empty( $row['enabled'] ),
				'fields' => rule_fields( $row ),
			);
        }
        if ( count( $seen ) >= $total ) { break; }
    }
    if ( count( $seen ) !== $total ) { return error( 'stale', 'Redirects changed during the safety check. Try again.', 409 ); }
    return $rules;
}

/** Check all plain overlaps (including disabled rules), then active local chains. */
function redirect_safety( array $fields, int $id, ?array $projected = null ) {
	$rules = $projected ?? safety_rules( 0 === $id ? 1 : 0 );
	if ( is_wp_error( $rules ) ) {
		return $rules;
	}
    $active = [];
    foreach ( $rules as $rule ) {
        if ( $rule['id'] === $id ) { continue; }
        if ( sources_overlap( $fields, $rule['fields'] ) ) { return error( 'duplicate', 'Another plain redirect can match the same source. Inspect rule ' . $rule['id'] . ' in Redirection before changing this rule.', 409 ); }
        if ( $rule['active'] ) { $active[] = $rule['fields']; }
    }
    $source = local_request( $fields['source'] );
    if ( $source === null ) { return error( 'url', 'Use a local source path.' ); }
    $seeds = [ $source => true ];
    // Query forwarding can form a cycle only for some incoming query values.
    if ( $fields['query_parameters'] === 'pass' ) {
        $path = explode( '?', $source, 2 )[0];
        $constraints = ( new \Redirection\Url\Query( $source, source_flags( $fields ) ) )->get();
        foreach ( $active as $rule ) {
            foreach ( [ $rule['source'], $rule['target'] ] as $url ) {
                $query = wp_parse_url( $url, PHP_URL_QUERY );
                if ( is_string( $query ) ) {
                    $incoming = ( new \Redirection\Url\Query( $url, source_flags( $fields ) ) )->get();
                    $candidate = \Redirection\Url\Query::build_url( $path, array_replace_recursive( $incoming, $constraints ) );
                    if ( matches_request( $fields, $candidate ) ) { $seeds[ $candidate ] = true; }
                }
            }
        }
    }
	$checks = 0;
	$queue  = array_keys( $seeds );
	for ( $index = 0; isset( $queue[ $index ] ); ++$index ) {
		$seed    = $queue[ $index ];
        $first = next_request( $fields, $seed );
        $pending = $first === null ? [] : [ [ $first, 1, [ $seed => true ] ] ];
        while ( $pending ) {
            [ $request, $hops, $trail ] = array_pop( $pending );
            if ( ++$checks > MAX_MATCHES ) { return error( 'analysis_limit', 'The redirect chain is too complex to check safely. Use the native editor.', 409 ); }
            if ( isset( $trail[ $request ] ) || matches_request( $fields, $request ) ) { return error( 'loop', 'The target would create a local redirect loop. Inspect the destination and existing rules.', 409 ); }
            $trail[ $request ] = true;
            foreach ( $active as $rule ) {
                if ( ++$checks > MAX_MATCHES ) { return error( 'analysis_limit', 'The redirect chain is too complex to check safely. Use the native editor.', 409 ); }
				if ( ! matches_request( $rule, $request ) ) {
					// A later hop can require parameters in addition to those that reached it.
					// Retry the whole chain with the combined request, using native matching
					// at every hop. Conflicting or stripped values cannot produce a false loop.
					if ( 'pass' === $fields['query_parameters'] && str_contains( $rule['source'], '?' ) ) {
						$flags      = source_flags( $rule );
						$path_match = ( new \Redirection\Url\Path( $rule['source'] ) )->is_match( ( new \Redirection\Url\Request( $request ) )->get_decoded_url(), $flags );
						if ( $path_match ) {
							$seed_query = ( new \Redirection\Url\Query( $seed, source_flags( $fields ) ) )->get();
							$required   = ( new \Redirection\Url\Query( $rule['source'], $flags ) )->get();
							if ( ! $required && '' !== (string) wp_parse_url( $rule['source'], PHP_URL_QUERY ) ) {
								return error( 'analysis_limit', 'A forwarded query uses native raw-string matching and cannot be combined safely. Review this chain in Redirection.', 409 );
							}
							// Keep both spellings and key orders. A case-insensitive
							// earlier hop can forward a value to a case-sensitive one.
							foreach ( array( array_replace_recursive( $seed_query, $required ), array_replace_recursive( $required, $seed_query ) ) as $combined ) {
								$candidate = \Redirection\Url\Query::build_url( $path, $combined );
								if ( ! isset( $seeds[ $candidate ] ) && matches_request( $fields, $candidate ) ) {
									$seeds[ $candidate ] = true;
									$queue[]             = $candidate;
								}
							}
						}
					}
					continue;
				}
                $next = next_request( $rule, $request );
                if ( $next !== null ) {
                    if ( $hops >= MAX_HOPS ) { return error( 'analysis_limit', 'The local redirect chain exceeds 20 hops. Shorten it in the native editor.', 409 ); }
                    $pending[] = [ $next, $hops + 1, $trail ];
                }
            }
        }
    }
    return true;
}
