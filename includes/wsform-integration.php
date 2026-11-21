<?php
/** WS Form Pro design operations behind the connector's authenticated REST/preview API. */
declare(strict_types=1);
namespace ChurchWebsiteConnector\Integrations\Forms;
use ChurchWebsiteConnector\Integrations as I;
defined( 'ABSPATH' ) || exit;
const SETTINGS = [ 'label_render_off', 'submit_on_enter', 'invalid_field_focus' ];
function definition(): array { return [ 'label' => 'WS Form Pro', 'constant' => 'WS_FORM_VERSION', 'minimum' => '1.12.20', 'before' => '1.13', 'tested' => '1.12.20 Pro', 'interfaces' => [ '\\WS_Form_Common::can_user' ], 'operations' => [ 'create', 'update', 'publish', 'unpublish', 'add_field', 'update_field', 'delete_field', 'add_section', 'update_section', 'delete_section', 'add_tab', 'update_tab', 'delete_tab' ] ]; }
function model( int $id = 0 ): \WS_Form_Form { $model = new \WS_Form_Form(); $model->id = $id; return $model; }
function ai( int $id = 0 ): \WS_Form_Form_AI {
    if ( ! class_exists( '\\WS_Form_Form_AI' ) && defined( 'WS_FORM_PLUGIN_DIR_PATH' ) ) { require_once WS_FORM_PLUGIN_DIR_PATH . 'includes/core/class-ws-form-form-ai.php'; }
    $ai = new \WS_Form_Form_AI(); $ai->id = $id; return $ai;
}
function permission( string $operation = 'read' ) {
    $caps = match ( $operation ) { 'create' => [ 'read_form', 'create_form', 'edit_form' ], 'publish', 'unpublish' => [ 'read_form', 'publish_form' ], 'read' => [ 'read_form' ], default => [ 'read_form', 'edit_form' ] };
    foreach ( $caps as $cap ) { if ( ! \WS_Form_Common::can_user( $cap ) ) { return I\error( 'forbidden', 'WS Form does not grant the required ' . $cap . ' permission to this account.', 403 ); } } return true;
}
function schema(): array {
    $toggle = [ 'type' => 'boolean' ];
    return [ 'update' => [ 'label' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 300 ], 'design' => [ 'type' => 'object', 'description' => 'The id, label and groups/sections/fields design returned by get-integration-item. Existing item IDs, types and structure must be preserved; use the dedicated structural operations to add/remove items. Actions and conditionals are not accepted.' ], 'settings' => [ 'type' => 'object', 'properties' => array_fill_keys( SETTINGS, $toggle ), 'additionalProperties' => false ] ],
		'create'      => array(
			'design' => array(
				'type'        => 'object',
				'description' => 'A labelled draft with up to 20 tabs, 100 sections and 100 requested fields, subject to the native limit. Give each tab, section and field a label. Omit existing IDs, actions and conditionals. WS Form appends its standard consent checkbox and Submit button.',
			),
		),
        'structure' => [ 'id' => 'ID of the existing field, section or tab', 'parent_id' => 'Existing section ID for add_field; existing tab ID for add_section', 'label' => 'Label text', 'type' => 'Native field type for add_field', 'meta' => 'Only native WS Form AI editable field properties; use field_meta below', 'before_id' => 'Optional existing sibling to insert before' ],
        'field_meta' => I\status( 'ws-form' )['compatible'] ? field_meta_schema() : [],
        'settings' => array_fill_keys( SETTINGS, $toggle ), 'submissions' => 'Unavailable. No submission, export, resend, credential, licence, notification-action or global WS Form option endpoint is exposed.' ];
}
function field_meta_schema(): array {
    $out = [];
    foreach ( ai()->get_field_meta_keys_editable() as $key => $spec ) {
        $type = match ( $spec['type'] ?? 'string' ) { 'boolean' => 'boolean', 'integer' => 'integer', 'float' => 'number', 'array' => 'array', 'object' => 'object', default => 'string' };
        $out[ $key ] = [ 'type' => $type, 'description' => $spec['description'] ?? '' ]; if ( $key === 'required' ) { $out[$key]['enum'] = [ '', 'on' ]; } if ( $type === 'string' ) { $out[ $key ]['maxLength'] = 10000; }
    }
    $out['widths'] = [ 'type' => [ 'object', 'array' ] ];
    return $out;
}
function json_array( $object ): array { return json_decode( (string) wp_json_encode( $object ), true, 20, JSON_THROW_ON_ERROR ); }
function design( int $id ): array { $value = json_array( ai( $id )->form_get() ); unset( $value['actions'], $value['conditionals'] ); return $value; }
/** Include native actions and conditionals, excluding the editor's own view preferences. */
function publication_hash( array $form ): ?string {
	if ( ! isset( $form['label'], $form['groups'], $form['meta'] ) || ! is_array( $form['groups'] ) || ! is_array( $form['meta'] ) ) {
		return null;
	}
	$meta = $form['meta'];
	unset( $meta['tab_index'], $meta['breakpoint'] );
	return I\fingerprint( array( $form['label'], $form['groups'], $meta ) );
}

function read( array $target ) {
    $ok = permission(); if ( is_wp_error( $ok ) ) { return $ok; }
	if ( 0 === $target['id'] ) {
		return array(
			'data'  => array(
				'new_item' => true,
				'status'   => 'draft',
			),
			'state' => array( 'create', I\status( 'ws-form' )['installed_version'], creation_defaults(), creation_field_limit() ),
		);
	}
    $raw = model( $target['id'] )->db_read( true, true ); $raw_array = json_array( $raw );
    if ( (int) ( $raw_array['id'] ?? 0 ) !== $target['id'] || ( $raw_array['status'] ?? '' ) === 'trash' ) { return I\error( 'missing', 'Choose an existing form outside Trash.', 404 ); }
    $settings = []; foreach ( SETTINGS as $key ) { $settings[ $key ] = in_array( $raw_array['meta'][ $key ] ?? '', [ true, 1, '1', 'on' ], true ); }
    $state = $raw_array; foreach ( array_keys( $state ) as $key ) { if ( str_starts_with( $key, 'count_' ) ) { unset( $state[ $key ] ); } }
	$auto_publish = (bool) \WS_Form_Common::option_get( 'publish_auto', false );
	// WS Form applies this option only to forms that are already published.
	if ( 'publish' === $raw_array['status'] ) {
		$state['auto_publish'] = $auto_publish;
		if ( $auto_publish ) {
			$published                   = model( $target['id'] )->db_read_published();
			$public                      = $published ? json_array( $published ) : array();
			$state['public_design_hash'] = publication_hash( $public );
			$pending                     = null === $state['public_design_hash'] || publication_hash( $raw_array ) !== $state['public_design_hash'];
			if ( $pending && null !== $state['public_design_hash'] ) {
				$areas = array(
					'label'  => 'form title',
					'groups' => 'tabs, sections or fields',
					'meta'   => 'form settings, actions or conditional rules',
				);
				foreach ( $areas as $key => $description ) {
					$draft_value  = $raw_array[ $key ] ?? null;
					$public_value = $public[ $key ];
					if ( 'meta' === $key && is_array( $draft_value ) ) {
						unset( $draft_value['tab_index'], $draft_value['breakpoint'], $public_value['tab_index'], $public_value['breakpoint'] );
					}
					if ( I\fingerprint( $draft_value ) !== I\fingerprint( $public_value ) ) {
						$differences[] = $description;
					}
				}
			}
		}
	}
	return array(
		'data'  => array(
			'label'                 => $raw_array['label'],
			'status'                => $raw_array['status'],
			'design'                => design( $target['id'] ),
			'settings'              => $settings,
			'auto_publish'          => $auto_publish,
			'auto_publish_conflict' => $pending ?? false,
		),
		'state' => $state,
		'raw'   => $raw,
		'pending_areas' => $differences ?? array(),
	);
}
/** Emit structural nodes in their native order, with explicit parents for paged reviews. */
function design_nodes( array $design ): \Generator {
	foreach ( $design['groups'] as $tab ) {
		$sections = $tab['sections'];
		unset( $tab['sections'] );
		yield array( 'tab', $design['id'], $tab );
		foreach ( $sections as $section ) {
			$fields = $section['fields'];
			unset( $section['fields'] );
			yield array( 'section', $tab['id'], $section );
			foreach ( $fields as $field ) {
				yield array( 'field', $section['id'], $field );
			}
		}
	}
}

function design_page( array $data, int $requested ): array|\WP_Error {
	$design = $data['design'];
	unset( $data['design'] );
	$data['design_page'] = $requested;
	$data['message']     = 'Nodes are ordered as [kind, parent ID, node]. If a node spans pages, join its numbered json_part strings before decoding. Read next_design_page with this state_hash.';
	$page                = 1;
	$bytes               = 0;
	$nodes               = array();
	// Keep room for the envelope and for escaping a JSON fragment as a response string.
	$budget = intdiv( I\MAX_JSON, 2 );
	foreach ( design_nodes( $design ) as $node ) {
		$json  = wp_json_encode( $node );
		$parts = strlen( $json ) > $budget ? str_split( $json, intdiv( $budget, 4 ) ) : array();
		if ( $parts ) {
			$entries = array();
			foreach ( $parts as $index => $part ) {
				$entries[] = array(
					'kind'      => $node[0],
					'id'        => $node[2]['id'],
					'part'      => $index + 1,
					'parts'     => count( $parts ),
					'json_part' => $part,
				);
			}
		} else {
			$entries = array( $node );
		}
		foreach ( $entries as $entry ) {
			$length = strlen( wp_json_encode( $entry ) ) + 1;
			if ( $bytes && $bytes + $length > $budget ) {
				if ( $requested === $page ) {
					$data['design_nodes']     = $nodes;
					$data['next_design_page'] = $page + 1;
					return $data;
				}
				++$page;
				$bytes = 0;
			}
			$bytes += $length;
			if ( $requested === $page ) {
				$nodes[] = $entry;
			}
		}
	}
	if ( $page !== $requested ) {
		return I\error( 'design_page', 'That form design page does not exist.' );
	}
	$data['design_nodes']     = $nodes;
	$data['next_design_page'] = null;
	return $data;
}

function listing( int $page, int $limit ) {
    $ok = permission(); if ( is_wp_error( $ok ) ) { return $ok; }
	$rows = model()->db_read_all( '', "status <> 'trash'", 'id ASC', (string) $limit, (string) ( ( $page - 1 ) * $limit ), false, false, 'id,label,status' );
	$out  = array();
	foreach ( $rows as $row ) {
		$out[] = array(
			'id'     => (int) $row['id'],
			'label'  => $row['label'],
			'status' => $row['status'],
		);
	}
	return $out;
}
function meta( $values, bool $creating = false ) {
    if ( ! is_array( $values ) || ( $values !== [] && array_is_list( $values ) ) || ! I\bounded_json( $values ) ) { return I\error( 'meta', 'Use a bounded object of editable field properties.' ); }
    $schema = field_meta_schema();
    $widths = $values['widths'] ?? null; unset( $values['widths'], $schema['widths'] );
    if ( $widths !== null ) { ai()->breakpoint_widths_parse( [ 'widths' => $widths ] ); }
    foreach ( $values as $key => &$value ) { if ( ( $schema[$key]['type'] ?? '' ) === 'boolean' && in_array( $value, [ 'on', '' ], true ) ) { $value = $value === 'on'; } } unset( $value );
    if ( $creating ) {
        foreach ( ai()->get_field_meta_keys() as $key => $spec ) { if ( ! isset( $schema[ $key ] ) && in_array( $key, [ 'required', 'placeholder', 'help', 'default_value', 'options' ], true ) ) { $schema[ $key ] = [ 'type' => match ( $spec['type'] ?? 'string' ) { 'boolean' => 'boolean', 'integer' => 'integer', 'array' => 'array', 'object' => 'object', default => 'string' } ]; } }
    }
    if ( $values === [] ) { return $widths === null ? [] : [ 'widths' => $widths ]; }
    $valid = I\validate_fields( $values, $schema ); if ( is_wp_error( $valid ) ) { return $valid; }
    $normalised = ai()->field_meta_sanitize( $values, ! $creating );
    if ( $widths !== null ) { $normalised['widths'] = $widths; }
    return $normalised;
}
function validate_design( $value, bool $creating, ?array $original = null ) {
    if ( ! is_array( $value ) || array_diff( array_keys( $value ), [ 'id', 'label', 'groups' ] ) || ! is_string( $value['label'] ?? null ) || $value['label'] === '' || strlen( $value['label'] ) > 300 || ! is_array( $value['groups'] ?? null ) || ! array_is_list( $value['groups'] ) || count( $value['groups'] ) < 1 || count( $value['groups'] ) > 20 ) { return I\error( 'design', 'Use the documented form label and groups design, without actions, conditionals or arbitrary metadata.' ); }
    if ( $creating && ! empty( $value['id'] ) ) { return I\error( 'design', 'A new form must not refer to an existing form ID.' ); }
    $value['label'] = sanitize_text_field( $value['label'] );
	if ( $creating && '' === $value['label'] ) {
		return I\error( 'design', 'Give the new form a label.' );
	}
	// Whole-design updates must preserve the original structure, including native controls.
	$field_limit   = $creating ? creation_field_limit() : count( nodes( $original ?? array( 'groups' => array() ), 'field' ) );
	$section_limit = $creating ? 100 : count( nodes( $original ?? array( 'groups' => array() ), 'section' ) );
    $sections = 0; $fields = 0; $ids = [];
    foreach ( $value['groups'] as &$group ) {
        if ( ! is_array( $group ) || array_diff( array_keys( $group ), [ 'id', 'label', 'sections' ] ) || ! is_string( $group['label'] ?? null ) || strlen( $group['label'] ) > 300 || ! is_array( $group['sections'] ?? null ) || ! array_is_list( $group['sections'] ) || ! $group['sections'] ) { return I\error( 'design', 'Use a labelled tab containing sections.' ); }
        $group['label'] = sanitize_text_field( $group['label'] );
        $valid = node_id( $group, $creating, 'tab', $ids ); if ( is_wp_error( $valid ) ) { return $valid; }
        foreach ( $group['sections'] as &$section ) {
			if ( ++$sections > $section_limit ) {
				return I\error( 'design', sprintf( 'This design supports up to %d sections.', $section_limit ) );
			}
			if ( ! is_array( $section ) || array_diff( array_keys( $section ), array( 'id', 'label', 'fields', 'meta' ) )
				|| ! is_string( $section['label'] ?? null ) || strlen( $section['label'] ) > 300
				|| ! is_array( $section['fields'] ?? null ) || ! array_is_list( $section['fields'] ) || ! $section['fields']
				|| ( isset( $section['meta'] ) && ( ! is_array( $section['meta'] ) || array_diff( array_keys( $section['meta'] ), array( 'widths' ) ) ) ) ) {
				return I\error( 'design', 'Use labelled sections with fields and optional native breakpoint widths.' );
			}
            $section['label'] = sanitize_text_field( $section['label'] );
            if ( isset( $section['meta']['widths'] ) ) { ai()->breakpoint_widths_parse( $section['meta'] ); }
            $valid = node_id( $section, $creating, 'section', $ids ); if ( is_wp_error( $valid ) ) { return $valid; }
            foreach ( $section['fields'] as &$field ) {
				if ( ++$fields > $field_limit ) {
					return I\error( 'design', sprintf( 'This design supports up to %d fields on this installation.', $field_limit ) );
				}
				if ( ! is_array( $field ) || array_diff( array_keys( $field ), array( 'id', 'label', 'type', 'meta' ) )
					|| ! is_string( $field['label'] ?? null ) || strlen( $field['label'] ) > 300
					|| ! is_string( $field['type'] ?? null ) || ( $creating && ! in_array( $field['type'], ai()->get_field_type_ids(), true ) ) ) {
					return I\error( 'design', 'Use labelled fields with native field types.' );
				}
                $field['label'] = sanitize_text_field( $field['label'] );
                $valid = node_id( $field, $creating, 'field', $ids ); if ( is_wp_error( $valid ) ) { return $valid; }
                if ( isset( $field['meta'] ) ) { $normalised = meta( $field['meta'], $creating ); if ( is_wp_error( $normalised ) ) { return $normalised; } $field['meta'] = $normalised; }
            } unset( $field );
        } unset( $section );
    } unset( $group );
    if ( $creating ) { unset( $value['id'] ); }
    elseif ( $original === null || ! ai( (int) $original['id'] )->form_json_compare_structure( wp_json_encode( $original ), wp_json_encode( $value ) ) || ( $value['id'] ?? null ) !== $original['id'] ) { return I\error( 'structure', 'Keep the current form, tab, section and field IDs/types. Use a structural operation to add or remove an item.' ); }
    return $value;
}
function node_id( array &$node, bool $creating, string $kind, array &$seen ) {
	if ( $creating && '' === $node['label'] ) {
		return I\error( 'design', 'Give each new tab, section and field a label.' );
	}
    $id = $node['id'] ?? 0;
    if ( ! is_int( $id ) || $id < 0 || ( $creating ? $id !== 0 : $id < 1 ) ) { return I\error( 'id', 'Use existing integer IDs when updating and no existing IDs when creating a form.' ); }
    if ( $creating ) { unset( $node['id'] ); }
    else { $key = $kind . ':' . $id; if ( isset( $seen[ $key ] ) ) { return I\error( 'id', 'Do not repeat a design item ID.' ); } $seen[ $key ] = true; } return true;
}
function nodes( array $design, string $kind ): array {
    $out = []; foreach ( $design['groups'] as $group ) { if ( $kind === 'tab' ) { $out[ $group['id'] ] = $group; } foreach ( $group['sections'] as $section ) { if ( $kind === 'section' ) { $out[ $section['id'] ] = $section; } foreach ( $section['fields'] as $field ) { if ( $kind === 'field' ) { $out[ $field['id'] ] = $field; } } } } return $out;
}
/** Only explicit publication may release an earlier draft. */
function edit_publication_conflict( array $item, string $operation ): bool {
	return ! empty( $item['data']['auto_publish_conflict'] ) && ! in_array( $operation, array( 'publish', 'unpublish' ), true );
}

function unpublished_changes_error( array $item, int $id ): \WP_Error {
	$url     = admin_url( 'admin.php?page=ws-form-edit&id=' . $id );
	$areas   = $item['pending_areas'] ?? array();
	$summary = $areas ? 'Unpublished changes affect the ' . implode( ' and the ', $areas ) . '.' : 'The current public design could not be matched to the draft.';
	$message = $summary . ' Review the existing changes before publishing them. Open this form in WS Form at ' . $url;
	return new \WP_Error(
		'church_connector_integration_unpublished_changes',
		$message,
		array(
			'status'        => 409,
			'pending_areas' => $areas,
			'edit_url'      => $url,
		)
	);
}

/** Native choice rows also contain IDs and settings absent from the AI value/label list. */
function choice_grids( array $item ): array {
	$grids = array();
	foreach ( $item['raw']->groups ?? array() as $tab ) {
		foreach ( $tab->sections as $section ) {
			foreach ( $section->fields as $field ) {
				if ( ! empty( $field->meta->data_source_id ) ) {
					$grids[ $field->id ]['data_source_id'] = $field->meta->data_source_id;
				}
				foreach ( (array) ( $field->meta ?? array() ) as $key => $value ) {
					if ( str_starts_with( $key, 'data_grid' ) && isset( $value->groups, $value->columns ) ) {
						$grids[ $field->id ][ $key ] = $value;
					}
				}
			}
		}
	}
	return $grids;
}

function review_choice_edits( array $design, array $original, array $grids ): array|\WP_Error {
	$fields = nodes( $original, 'field' );
	foreach ( $design['groups'] as &$tab ) {
		foreach ( $tab['sections'] as &$section ) {
			foreach ( $section['fields'] as &$field ) {
				if ( ! isset( $field['meta']['options'] ) ) {
					continue;
				}
				$id = $field['id'];
				if ( I\canonical( $field['meta']['options'] ) === I\canonical( $fields[ $id ]['meta']['options'] ?? null ) ) {
					unset( $field['meta']['options'] );
					continue;
				}
				$allowed = choice_update_supported( $grids[ $id ] ?? array() );
				if ( is_wp_error( $allowed ) ) {
					return $allowed;
				}
			}
			unset( $field );
		}
		unset( $section );
	}
	unset( $tab );
	return $design;
}

/** The native option writer rebuilds a simple grid with consecutive IDs and two columns at most. */
function choice_update_supported( array $grids ): true|\WP_Error {
	if ( ! empty( $grids['data_source_id'] ) ) {
		return I\error( 'choice_grid', 'This field gets its options from a native data source. Review its options in WS Form.', 409 );
	}
	foreach ( $grids as $grid ) {
		$simple = 1 === count( $grid->groups ) && count( $grid->columns ) >= 1 && count( $grid->columns ) <= 2;
		foreach ( $grid->groups[0]->rows ?? array() as $index => $row ) {
			if ( $index + 1 !== (int) $row->id || array_diff( array_keys( (array) $row ), array( 'id', 'data' ) ) ) {
				$simple = false;
				break;
			}
		}
		if ( ! $simple ) {
			return I\error( 'choice_grid', 'These options have native row IDs or settings that the simplified editor cannot preserve. Review option changes in WS Form.', 409 );
		}
	}
	return true;
}

function prepare( array $target, array $item, string $operation, array $changes ) {
    $ok = permission( $operation ); if ( is_wp_error( $ok ) ) { return $ok; }
    if ( ! empty( $item['data']['auto_publish'] ) && ( $item['data']['status'] ?? '' ) === 'publish' && ! \WS_Form_Common::can_user( 'publish_form' ) ) { return I\error( 'forbidden', 'Changing this automatically published form also requires publish_form permission.', 403 ); }
	if ( edit_publication_conflict( $item, $operation ) ) {
		return unpublished_changes_error( $item, $target['id'] );
	}
    if ( $operation === 'create' ) {
        if ( array_keys( $changes ) !== [ 'design' ] ) { return I\error( 'fields', 'Create a form using only a design object.' ); }
        $clean = validate_design( $changes['design'], true ); if ( is_wp_error( $clean ) ) { return $clean; }
		return array(
			'changes' => array( 'design' => $clean ),
			'diff'    => array(
				'design' => array(
					'before' => null,
					'after'  => creation_layout( $clean ),
				),
			),
			'warning' => 'Creates a draft including the standard WS Form consent checkbox and Submit button shown in the design. Publishing requires a separate approval.',
		);
    }
    if ( in_array( $operation, [ 'publish', 'unpublish' ], true ) ) {
        if ( $changes ) { return I\error( 'fields', 'Publishing operations do not accept design changes.' ); }
        return [ 'changes' => [], 'diff' => [ 'publication' => [ 'before' => [ 'status' => $item['data']['status'], 'state_hash' => I\fingerprint( $item['state'] ) ], 'after' => $operation === 'publish' ? 'Publish the current draft design' : 'Remove the published form; retain its draft design' ] ], 'warning' => 'This changes the form available to visitors. Review the complete current design before approving.' ];
    }
	$publication = 'publish' === $item['data']['status'] && $item['data']['auto_publish']
		? 'Applying this edit immediately publishes the reviewed change to the current public form.'
		: 'Applying this edit saves the draft design only.';
    if ( $operation === 'update' ) {
        if ( ! $changes || array_diff( array_keys( $changes ), [ 'label', 'design', 'settings' ] ) ) { return I\error( 'fields', 'Update label, design or the documented form settings.' ); }
        $clean = [];
        if ( isset( $changes['label'] ) ) { $valid = I\validate_fields( [ 'label' => $changes['label'] ], schema()['update'] ); if ( is_wp_error( $valid ) ) { return $valid; } $clean['label'] = sanitize_text_field( $changes['label'] ); }
        if ( isset( $changes['design'] ) ) { $valid = validate_design( $changes['design'], false, $item['data']['design'] ); if ( is_wp_error( $valid ) ) { return $valid; } if ( isset( $clean['label'] ) && $valid['label'] !== $clean['label'] ) { return I\error( 'label', 'The top-level label and design label must agree.' ); } $clean['design'] = $valid; }
		if ( isset( $clean['design'] ) ) {
			$checked = review_choice_edits( $clean['design'], $item['data']['design'], choice_grids( $item ) );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
		}
        if ( isset( $changes['settings'] ) ) { if ( ! is_array( $changes['settings'] ) ) { return I\error( 'settings', 'Use a settings object.' ); } $valid = I\validate_fields( $changes['settings'], schema()['settings'] ); if ( is_wp_error( $valid ) ) { return $valid; } $clean['settings'] = array_replace( $item['data']['settings'], $valid ); }
		return array(
			'changes' => $clean,
			'diff'    => I\diff( $item['data'], $clean ),
			'warning' => $publication,
		);
    }
    [ $action, $kind ] = explode( '_', $operation, 2 );
    $schema = [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'parent_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'before_id' => [ 'type' => 'integer', 'minimum' => 0 ], 'label' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 300 ], 'type' => [ 'type' => 'string', 'enum' => ai()->get_field_type_ids() ], 'meta' => [ 'type' => 'object' ] ];
    $allowed = $action === 'add' ? ( $kind === 'tab' ? [ 'label', 'before_id' ] : [ 'parent_id', 'label', 'before_id', ...( $kind === 'field' ? [ 'type', 'meta' ] : [] ) ] ) : ( $action === 'delete' ? [ 'id' ] : [ 'id', 'label', ...( $kind === 'field' ? [ 'meta' ] : [] ) ] );
    $valid = I\validate_fields( $changes, array_intersect_key( $schema, array_flip( $allowed ) ) ); if ( is_wp_error( $valid ) ) { return $valid; }
    if ( $action === 'add' && ( ! isset( $valid['label'] ) || ( $kind !== 'tab' && ! isset( $valid['parent_id'] ) ) || ( $kind === 'field' && ! isset( $valid['type'] ) ) ) ) { return I\error( 'fields', 'The structural addition requires its label, parent and field type where applicable.' ); }
    if ( isset( $valid['label'] ) ) { $valid['label'] = sanitize_text_field( $valid['label'] ); }
    $all = nodes( $item['data']['design'], $kind );
    if ( $action !== 'add' && ! isset( $all[ $valid['id'] ?? 0 ] ) ) { return I\error( 'parent', 'The target item must belong to this form.' ); }
    if ( $action === 'add' && $kind !== 'tab' && ! isset( nodes( $item['data']['design'], $kind === 'field' ? 'section' : 'tab' )[ $valid['parent_id'] ] ) ) { return I\error( 'parent', 'Choose a parent that belongs to this form.' ); }
    if ( ! empty( $valid['before_id'] ) ) {
        $siblings = $kind === 'tab' ? $all : nodes( [ 'groups' => $kind === 'section' ? [ nodes( $item['data']['design'], 'tab' )[ $valid['parent_id'] ] ] : [ [ 'sections' => [ nodes( $item['data']['design'], 'section' )[ $valid['parent_id'] ] ] ] ] ], $kind );
        if ( ! isset( $siblings[ $valid['before_id'] ] ) ) { return I\error( 'parent', 'The insertion sibling must belong to the same parent.' ); }
    }
    if ( $action === 'update' && ! isset( $valid['label'] ) && empty( $valid['meta'] ) ) { return I\error( 'fields', 'Supply a label or editable field metadata for this update.' ); }
    if ( isset( $valid['meta'] ) ) { $clean_meta = meta( $valid['meta'], $action === 'add' ); if ( is_wp_error( $clean_meta ) ) { return $clean_meta; } $valid['meta'] = $clean_meta; }
	if ( 'update_field' === $operation && isset( $valid['meta']['options'] ) ) {
		if ( I\canonical( $valid['meta']['options'] ) === I\canonical( $all[ $valid['id'] ]['meta']['options'] ?? null ) ) {
			unset( $valid['meta']['options'] );
		} else {
			$allowed = choice_update_supported( choice_grids( $item )[ $valid['id'] ] ?? array() );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
	}

	return array(
		'changes' => $valid,
		'diff'    => array(
			$operation => array(
				'before' => 'add' === $action ? null : $all[ $valid['id'] ],
				'after'  => 'delete' === $action ? null : $valid,
			),
		),
		'warning' => 'delete' === $action ? $publication . ' Removing this item may also change WS Form actions or conditional rules that refer to it.' : $publication,
	);
}
/** Veto an unreviewed visitor-facing snapshot at WS Form's native database write. */
function publish_reviewed( $form, array $item ) {
	global $wpdb;
	$expected = publication_hash( $item['state'] );
	$stack    = array();
	$pending  = null;
	$attempt  = null;
	$capture  = static function ( $form_data ) use ( &$stack, $form ) {
		// Keep the actual object that WS Form serializes. It ignores filter return values.
		$stack[] = is_object( $form_data ) && (int) ( $form_data->id ?? 0 ) === (int) $form->id ? array( $form_data, $form_data->date_publish ?? null ) : array( null, null );
		return $form_data;
	};
	$ready    = static function ( $value ) use ( &$stack, &$pending, &$attempt ) {
		list( $pending, $attempt ) = array_pop( $stack );
		return $value;
	};
	$prefix   = 'UPDATE `' . $wpdb->prefix . 'wsf_form` SET `published` = ';
	$suffix   = ' WHERE `id` = ' . (int) $form->id;
	$stopped  = false;
	$veto     = static function ( $query ) use ( &$pending, &$stopped, $expected, $prefix, $suffix ) {
		if ( ! is_string( $query ) || ! str_starts_with( $query, $prefix ) || ! str_ends_with( $query, $suffix ) ) {
			return $query;
		}
		// An empty query makes wpdb return false and lets the vendor handle its failed write
		// after WordPress has left the filter. No unreviewed version or post-publication event.
		try {
			if ( $pending && null !== $expected && publication_hash( json_array( $pending ) ) === $expected ) {
				return $query;
			}
		} catch ( \Throwable $exception ) {
			// An extension supplied a snapshot we cannot verify.
		}
		$stopped = true;
		return '';
	};
	add_filter( 'wsf_form_publish', $capture, PHP_INT_MIN );
	add_filter( 'wsf_form_publish', $ready, PHP_INT_MAX );
	add_filter( 'query', $veto, PHP_INT_MAX );
	try {
		$form->db_publish();
	} catch ( \Throwable $exception ) {
		if ( ! $stopped ) {
			throw $exception;
		}
	} finally {
		remove_filter( 'query', $veto, PHP_INT_MAX );
		remove_filter( 'wsf_form_publish', $capture, PHP_INT_MIN );
		remove_filter( 'wsf_form_publish', $ready, PHP_INT_MAX );
	}
	if ( ! $stopped ) {
		return true;
	}
	// Native publication sets its date/status before snapshot validation. Undo that update
	// only if the same attempt and prior visitor copy are still current.
	$where = array(
		'id'                 => $form->id,
		'status'             => 'publish',
		'date_publish'       => $attempt,
		'published_checksum' => $item['state']['published_checksum'] ?? '',
	);
	if ( 'draft' === $item['data']['status'] ) {
		$where['published'] = '';
	}
	$restored = $wpdb->update(
		$wpdb->prefix . 'wsf_form',
		array(
			'status'       => $item['data']['status'],
			'date_publish' => $item['state']['date_publish'] ?? null,
		),
		$where
	);
	if ( false === $restored ) {
		return I\error( 'readback', 'WS Form refused the unreviewed publication. Its prior publication status could not be restored. Inspect the form.', 502 );
	}
	return I\error( 'stale', 'The native form changed during publication. Review its current design before publishing.', 409 );
}

function write( array $target, array $item, string $operation, array $changes ) {
    $check = permission( $operation ); if ( is_wp_error( $check ) ) { return $check; }
    if ( ! empty( $item['data']['auto_publish'] ) && ( $item['data']['status'] ?? '' ) === 'publish' && ! \WS_Form_Common::can_user( 'publish_form' ) ) { return I\error( 'forbidden', 'Automatic publication requires publish_form permission.', 403 ); }
	if ( 'create' === $operation ) {
		return create_form( $target, $changes['design'] );
	}
	// Claiming an approval runs hooks, including the native editor's first publication.
	$current = I\read( $target );
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	if ( edit_publication_conflict( $current, $operation ) ) {
		return unpublished_changes_error( $current, $target['id'] );
	}
	if ( $current['state_hash'] !== $item['state_hash'] ) {
		return I\error( 'stale', 'The form or its publication setting changed during approval. Preview the edit again.', 409 );
	}

	$id      = $target['id'];
	$form    = model( $id );
	$success = array(
		'id'                      => $id,
		'native_result_read_back' => true,
	);
	if ( 'unpublish' === $operation ) {
		$form->db_draft();
		if ( 'draft' !== $form->db_read()->status || false !== $form->db_read_published() ) {
			return I\error( 'readback', 'WS Form did not unpublish the form.', 502 );
		}
		return $success;
	}
	$expected = $item;
	if ( 'publish' !== $operation ) {
		$expected = save_reviewed_edit( $target, $item, $operation, $changes );
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}
		if ( $expected['data']['status'] !== $item['data']['status'] ||
			$expected['state']['published_checksum'] !== $item['state']['published_checksum'] ||
			( 'publish' === $item['data']['status'] && $expected['data']['auto_publish'] !== $item['data']['auto_publish'] ) ) {
			return I\error( 'readback', 'The publication status or visitor copy changed while WS Form saved the edit. Inspect the form.', 502 );
		}
		if ( 'publish' !== $item['data']['status'] || ! $item['data']['auto_publish'] ) {
			return $success;
		}
		if ( $expected['state']['public_design_hash'] !== $item['state']['public_design_hash'] ) {
			return I\error( 'readback', 'The visitor copy changed while WS Form saved the edit. Inspect the form.', 502 );
		}
		if ( ! review_saved_scope( $item, $expected, $operation, $changes ) ) {
			return I\error( 'readback', 'Other native design values changed while WS Form saved the draft. Review those changes before publishing.', 502 );
		}
	}
	$result = publish_reviewed( $form, $expected );
	if ( is_wp_error( $result ) ) {
		if ( 'publish' !== $operation ) {
			return I\error( 'readback', 'WS Form saved the draft edit but refused its automatic publication. Inspect the draft and the visitor copy.', 502 );
		}
		return $result;
	}
	$published = $form->db_read_published();
	$hash      = publication_hash( $expected['state'] );
	if ( 'publish' !== $form->db_read()->status || ! $published || null === $hash || publication_hash( json_array( $published ) ) !== $hash ) {
		return I\error( 'readback', 'WS Form’s visitor-facing copy differs from the reviewed design. Inspect the published form.', 502 );
	}
	return $success;
}

/** Compare untouched native values, including data hidden from the simplified editor. */
function review_saved_scope( array $before, array $after, string $operation, array $changes ): bool {
	$edits = array();
	if ( 'update' === $operation ) {
		$edits['form'][ $before['state']['id'] ] = array(
			'label' => ( $changes['label'] ?? $changes['design']['label'] ?? $before['data']['label'] ) !== $before['data']['label'],
			'meta'  => $changes['settings'] ?? array(),
		);
		if ( isset( $changes['design'] ) ) {
			foreach ( array( 'tab', 'section', 'field' ) as $kind ) {
				$original = nodes( $before['data']['design'], $kind );
				foreach ( nodes( $changes['design'], $kind ) as $id => $node ) {
					$edits[ $kind ][ $id ] = array(
						'label' => $original[ $id ]['label'] !== $node['label'],
						'meta'  => array_filter(
							$node['meta'] ?? array(),
							static fn( $key ) => I\canonical( $original[ $id ]['meta'][ $key ] ?? null ) !== I\canonical( $node['meta'][ $key ] ),
							ARRAY_FILTER_USE_KEY
						),
					);
				}
			}
		}
	} else {
		list( $verb, $kind ) = explode( '_', $operation, 2 );
		$id                  = $changes['id'] ?? null;
		if ( 'add' === $verb ) {
			$new = array_diff_key( nodes( $after['data']['design'], $kind ), nodes( $before['data']['design'], $kind ) );
			if ( 1 !== count( $new ) ) {
				return false;
			}
			$id       = array_key_first( $new );
			$design   = $after['data']['design'];
			$siblings = match ( $kind ) {
				'tab'     => $design['groups'],
				'section' => nodes( $design, 'tab' )[ $changes['parent_id'] ]['sections'] ?? array(),
				'field'   => nodes( $design, 'section' )[ $changes['parent_id'] ]['fields'] ?? array(),
			};
			$ids   = array_column( $siblings, 'id' );
			$index = array_search( $id, $ids, true );
			if ( false === $index || ( $ids[ $index + 1 ] ?? 0 ) !== ( $changes['before_id'] ?? 0 ) ) {
				return false;
			}
		}
		$edits[ $kind ][ $id ] = array(
			'omit'  => 'update' !== $verb,
			'label' => isset( $changes['label'] ),
			'meta'  => $changes['meta'] ?? array(),
		);
	}

	$stable = static function ( array $state ) use ( $edits ): string {
		$form = array_intersect_key( $state, array_flip( array( 'id', 'label', 'groups', 'meta' ) ) );
		unset( $form['meta']['tab_index'], $form['meta']['breakpoint'] );
		return I\fingerprint( untouched_node( $form, 'form', $edits ) );
	};
	return $stable( $before['state'] ) === $stable( $after['state'] );
}

/** Keep native order and parentage while excluding only the approved node and metadata changes. */
function untouched_node( array $node, string $kind, array $edits ): ?array {
	$allowed = $edits[ $kind ][ $node['id'] ] ?? array();
	if ( ! empty( $allowed['omit'] ) ) {
		return null;
	}
	// Native saves renumber siblings and update field timestamps. Array order retains placement.
	unset( $node['sort_index'], $node['date_updated'] );
	if ( ! empty( $allowed['label'] ) ) {
		unset( $node['label'] );
	}
	foreach ( $allowed['meta'] ?? array() as $key => $value ) {
		if ( 'widths' === $key ) {
			foreach ( ai()->breakpoint_widths_parse( array( 'widths' => $value ) ) ?? array() as $breakpoint => $width ) {
				unset( $node['meta'][ 'breakpoint_size_' . $breakpoint ] );
			}
		} elseif ( 'options' === $key && isset( $node['type'] ) ) {
			// The native grid writer rebuilds its columns and resets two mappings.
			$grid               = new \WS_Form_Data_Grid();
			$grid->field_object = (object) array( 'type' => $node['type'] );
			unset( $node['meta'][ $grid->get_meta_key() ], $node['meta'][ $grid->get_column_mapping_meta_key( 'label' ) ], $node['meta'][ $grid->get_column_mapping_meta_key( 'parse_variable' ) ] );
		}
		unset( $node['meta'][ $key ] );
	}
	$children = array(
		'form'    => array( 'groups', 'tab' ),
		'tab'     => array( 'sections', 'section' ),
		'section' => array( 'fields', 'field' ),
	);
	if ( isset( $children[ $kind ] ) ) {
		list( $key, $child_kind ) = $children[ $kind ];
		$result                   = array();
		foreach ( $node[ $key ] as $child ) {
			$value = untouched_node( $child, $child_kind, $edits );
			if ( null !== $value ) {
				$result[] = $value;
			}
		}
		$node[ $key ] = $result;
	}
	return $node;
}

/** Save and verify a native draft before releasing any visitor-facing copy. */
function save_reviewed_edit( array $target, array $item, string $operation, array $changes ) {
	$id = $target['id'];
	// The native editor checks publish_auto at each checksum. Save and verify
	// all parts of one approved edit before a single guarded publication.
	$defer = static function ( $value, $key ) use ( $id ) {
		if ( 'publish_auto' === $key && $value ) {
			// WS Form does not pass the form ID to its option filter. Scope this
			// override to the target checksum, preserving other forms and option reads.
			$trace = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 6 );
			foreach ( $trace as $index => $call ) {
				if ( 'option_get' === $call['function'] && 'WS_Form_Common' === ( $call['class'] ?? '' ) ) {
					$caller = $trace[ $index + 1 ] ?? array();
					if ( 'db_checksum' === ( $caller['function'] ?? '' ) && ( $caller['object'] ?? null ) instanceof \WS_Form_Form && (int) $caller['object']->id === $id ) {
						return false;
					}
					break;
				}
			}
		}
		return $value;
	};
	add_filter( 'wsf_option_get', $defer, PHP_INT_MAX, 2 );
	try {
		return write_draft( $target, $item, $operation, $changes );
	} finally {
		remove_filter( 'wsf_option_get', $defer, PHP_INT_MAX );
	}
}

function write_draft( array $target, array $item, string $operation, array $changes ) {
	$id   = $target['id'];
	$ai   = ai( $id );
	$form = model( $id );
	if ( 'update' === $operation ) {
		if ( isset( $changes['design'] ) ) {
			$preserved = choice_grids( $item );
			$design    = review_choice_edits( $changes['design'], $item['data']['design'], $preserved );
			if ( is_wp_error( $design ) ) {
				return $design;
			}
			foreach ( nodes( $design, 'field' ) as $field ) {
				if ( isset( $field['meta']['options'] ) ) {
					unset( $preserved[ $field['id'] ] );
				}
			}
			$ai->form_update_json( wp_json_encode( $design ) );
		}
        if ( isset( $changes['label'] ) || isset( $changes['settings'] ) ) {
            $raw = $form->db_read( true, false );
            if ( isset( $changes['label'] ) ) { $raw->label = $changes['label']; }
            // Preserve all untouched form metadata and action settings server-side.
            foreach ( $changes['settings'] ?? [] as $key => $value ) { $raw->meta->{$key} = $value ? 'on' : ''; }
            $form->db_update_from_object( $raw, false, false, false ); $form->db_checksum();
        }
	} else {
        $label = $changes['label'] ?? ( str_starts_with( $operation, 'update_' ) ? ( nodes( $item['data']['design'], explode( '_', $operation, 2 )[1] )[ $changes['id'] ]['label'] ?? '' ) : '' ); $meta = $changes['meta'] ?? []; $node_id = $changes['id'] ?? 0; $before = $changes['before_id'] ?? 0;
        match ( $operation ) {
            'add_field' => add_field( $ai, $changes['parent_id'], $label, $changes['type'], $meta, $before ),
            'update_field' => $ai->field_update( $node_id, $label, $meta ),
            'add_section' => $ai->section_add( $changes['parent_id'], $label, $before ),
            'update_section' => $ai->section_update( $node_id, $label ),
            'delete_section' => $ai->section_delete( $node_id ),
            'add_tab' => $ai->tab_add( $label, $before ),
            'update_tab' => $ai->tab_update( $node_id, $label ),
            'delete_tab' => $ai->tab_delete( $node_id ),
            'delete_field' => delete_field( $id, $node_id ),
        };
    }
    $after = read( array_replace( $target, [ 'id' => $id ] ) ); if ( is_wp_error( $after ) ) { return $after; }
    if ( isset( $changes['label'] ) && $operation === 'update' && $after['data']['label'] !== $changes['label'] ) { return I\error( 'readback', 'The form label did not match the approved value.', 502 ); }
    if ( $operation === 'update' ) {
		if ( isset( $preserved ) && I\fingerprint( $preserved ) !== I\fingerprint( array_intersect_key( choice_grids( $after ), $preserved ) ) ) {
			return I\error( 'readback', 'Other native option rows changed while WS Form saved the design. Inspect the saved form.', 502 );
		}
        foreach ( $changes['settings'] ?? [] as $key => $value ) { if ( $after['data']['settings'][$key] !== $value ) { return I\error( 'readback', 'An approved form setting was not saved.', 502 ); } }
        if ( isset( $changes['design'] ) && ! design_matches( $changes['design'], $after['data']['design'] ) ) { return I\error( 'readback', 'The saved design differs from the approved design. Inspect before retrying.', 502 ); }
    }
    if ( str_contains( $operation, '_' ) ) {
        [ $action, $kind ] = explode( '_', $operation, 2 );
        $before_nodes = nodes( $item['data']['design'], $kind ); $after_nodes = nodes( $after['data']['design'], $kind );
        if ( $action === 'delete' && isset( $after_nodes[$changes['id']] ) ) { return I\error( 'readback', 'The form design item was not removed.', 502 ); }
        if ( $action === 'update' ) { $actual = $after_nodes[$changes['id']] ?? []; }
        elseif ( $action === 'add' ) {
            $new = array_diff_key( $after_nodes, $before_nodes );
            if ( count( $new ) !== 1 ) { return I\error( 'readback', 'WS Form did not return one new design item.', 502 ); }
            $actual = reset( $new );
        }
        if ( $action !== 'delete' ) {
            foreach ( [ 'label', 'type' ] as $key ) { if ( isset( $changes[$key] ) && ( $actual[$key] ?? null ) !== $changes[$key] ) { return I\error( 'readback', 'A design item did not match the approved value.', 502 ); } }
            if ( isset( $changes['meta'] ) && ! meta_matches( $changes['meta'], $actual['meta'] ?? [] ) ) { return I\error( 'readback', 'An approved field property was not saved.', 502 ); }
        }
    }
	return $after;
}
function creation_field_limit(): int {
	$ai = ai();
	return max( 0, min( 100, (int) apply_filters( 'wsf_create_from_json_max_fields', $ai::CREATE_FROM_JSON_MAX_FIELDS ) ) );
}

function creation_defaults(): array {
	$submit = ( new \WS_Form_Field() )->db_field_type_config( 'submit' );
	return array(
		'label'  => __( 'Section', 'ws-form' ),
		'fields' => array(
			array(
				'type'  => 'checkbox',
				'label' => __( 'GDPR', 'ws-form' ),
			),
			array(
				'type'  => 'submit',
				'label' => $submit['label_default'] ?? __( 'Field', 'ws-form' ),
			),
		),
	);
}

/** Include the native consent and submit fields in the preview and the saved-layout check. */
function creation_layout( array $design ): array {
	$group    = array_key_last( $design['groups'] );
	$defaults = creation_defaults();
	if ( 0 === $group && 1 === count( $design['groups'][0]['sections'] ) ) {
		$design['groups'][0]['sections'][0]['fields'] = array_merge( $design['groups'][0]['sections'][0]['fields'], $defaults['fields'] );
	} else {
		$design['groups'][ $group ]['sections'][] = $defaults;
	}
	return $design;
}

/** Create one native draft and retain its real ID even if a later native hook fails. */
function create_form( array $target, array $design ) {
	$expected    = creation_layout( $design );
	$import      = $design;
	$field_count = 0;
	$editor_meta = array();
	foreach ( $design['groups'] as $group_index => $group ) {
		foreach ( $group['sections'] as $section_index => $section ) {
			foreach ( $section['fields'] as $field_index => $field ) {
				// Native import IDs are form-local references, never existing database IDs.
				$import['groups'][ $group_index ]['sections'][ $section_index ]['fields'][ $field_index ]['id'] = ++$field_count;
				// WS Form 1.12.20 saves these through its field editor, not its JSON importer.
				$meta = array_intersect_key( $field['meta'] ?? array(), array_flip( array( 'step', 'invalid_feedback' ) ) );
				if ( $meta ) {
					$editor_meta[] = array( $group_index, $section_index, $field_index, $meta );
				}
			}
		}
	}

	$form        = model();
	$form->label = $design['label'];
	try {
		$id = (int) $form->db_create( false );
		if ( $id < 1 || 'draft' !== ( $form->db_read()->status ?? '' ) ) {
			throw new \RuntimeException( 'WS Form could not initialise the draft.' );
		}
		$target['id'] = $id;
		$ai           = ai( $id );
		$ai->form_create_json( wp_json_encode( $import ) );
		$after = I\read( $target );
		if ( is_wp_error( $after ) || 'draft' !== $after['data']['status'] || ! design_matches( $expected, $after['data']['design'], false ) ) {
			throw new \RuntimeException( 'The saved draft layout differs from the approved layout or could not be read.' );
		}

		$edited = false;
		foreach ( $editor_meta as list( $group_index, $section_index, $field_index, $meta ) ) {
			$field = $after['data']['design']['groups'][ $group_index ]['sections'][ $section_index ]['fields'][ $field_index ];
			if ( ! meta_matches( $meta, $field['meta'] ?? array() ) ) {
				if ( 'draft' !== ( $form->db_read()->status ?? '' ) ) {
					throw new \RuntimeException( 'WS Form changed the draft’s publication status during creation.' );
				}
				$ai->field_update( $field['id'], '', $meta );
				$edited = true;
			}
		}
		if ( $edited ) {
			$after = I\read( $target );
		}
		if ( is_wp_error( $after ) || 'draft' !== $after['data']['status'] || false !== $form->db_read_published() || ! design_matches( $expected, $after['data']['design'] ) ) {
			throw new \RuntimeException( 'The saved draft differs from the approved design or could not be read.' );
		}
	} catch ( \Throwable $error ) {
		$message = $form->id ? sprintf( 'Inspect WS Form ID %d before creating another form. ', $form->id ) : '';
		return I\error( 'create', $message . $error->getMessage(), 502 );
	}
	return array(
		'id'                      => $id,
		'native_result_read_back' => true,
		'verified_item'           => I\public_item( $target, $after ),
	);
}

function delete_field( int $form_id, int $id ) {
    $field = new \WS_Form_Field(); $field->id = $id; $field->form_id = $form_id; $result = $field->db_delete(); $field->db_checksum(); return $result;
}

/** Compare the approved editable subset; native unedited defaults may be present. */
function meta_matches( array $expected, array $actual ): bool {
    foreach ( $expected as $key => $value ) {
        if ( $key === 'widths' ) {
            $wanted = ai()->breakpoint_widths_parse( [ 'widths' => $value ] ) ?? [];
            $saved = ai()->breakpoint_widths_parse( [ 'widths' => $actual[$key] ?? [] ] ) ?? [];
            foreach ( $wanted as $breakpoint => $width ) { if ( $width !== null && ( $saved[$breakpoint] ?? null ) !== $width ) { return false; } }
		} elseif ( 'step' === $key && is_numeric( $value ) && is_numeric( $actual[ $key ] ?? null ) ) {
			if ( (float) $actual[ $key ] !== (float) $value ) {
				return false;
			}
        } elseif ( I\canonical( $actual[$key] ?? null ) !== I\canonical( $value ) ) { return false; }
    } return true;
}
function design_matches( array $expected, array $actual, bool $check_meta = true ): bool {
	foreach ( array( 'id', 'label', 'type' ) as $key ) {
		if ( isset( $expected[ $key ] ) && ( $actual[ $key ] ?? null ) !== $expected[ $key ] ) {
			return false;
		}
	}
	if ( $check_meta && isset( $expected['meta'] ) && ! meta_matches( $expected['meta'], $actual['meta'] ?? array() ) ) {
		return false;
	}
	foreach ( array( 'groups', 'sections', 'fields' ) as $key ) {
		if ( ! isset( $expected[ $key ] ) ) {
			continue;
		}
		if ( count( $expected[ $key ] ) !== count( $actual[ $key ] ?? array() ) ) {
			return false;
		}
		foreach ( $expected[ $key ] as $index => $node ) {
			if ( ! design_matches( $node, $actual[ $key ][ $index ], $check_meta ) ) {
				return false;
			}
		}
	}
	return true;
}

/** 1.12.20 needs an empty initial label to load native field defaults correctly. */
function add_field( \WS_Form_Form_AI $ai, int $section, string $label, string $type, array $meta, int $before ) {
    $created = $ai->field_add( $section, '', $type, $meta, $before );
    return $ai->field_update( (int) $created->id, $label, [] );
}
