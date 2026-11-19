<?php
/** Registration for explicitly declared connector abilities. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\ScopedAbilities;

use ChurchWebsiteConnector as Connector;

defined( 'ABSPATH' ) || exit;

function register( array $tools, string $callback_namespace, string $permission ): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$site = array(
		'site_url' => array(
			'type' => 'string',
			'enum' => array( Connector\bound_site() ),
		),
	);
	foreach ( $tools as $name => $spec ) {
		list( $label, $description, $callback, $required ) = $spec;
		$readonly = ! str_starts_with( $name, 'preview-' ) && ! str_starts_with( $name, 'apply-' );
		wp_register_ability(
			'church-connector/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'church-connector',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $site + $required + ( $spec[4] ?? array() ),
					'required'             => array_merge( array( 'site_url' ), array_keys( $required ) ),
					'additionalProperties' => false,
				),
				'permission_callback' => $permission,
				'execute_callback'    => $callback_namespace . '\\' . $callback,
				'meta'                => array(
					'show_in_rest' => false,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => $readonly,
						'destructive' => str_starts_with( $name, 'apply-' ),
						'idempotent'  => $readonly,
					),
				),
			)
		);
	}
}
