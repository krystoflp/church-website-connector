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
        'create' => [ 'design' => [ 'type' => 'object', 'description' => 'A label and at most 20 groups, 100 sections and 100 fields, with no existing IDs, actions or conditionals. New forms remain drafts.' ] ],
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
function read( array $target ) {
    $ok = permission(); if ( is_wp_error( $ok ) ) { return $ok; }
    if ( $target['id'] === 0 ) { return [ 'data' => [ 'new_item' => true, 'status' => 'draft' ], 'state' => [ 'create', I\status( 'ws-form' )['installed_version'] ] ]; }
    $raw = model( $target['id'] )->db_read( true, true ); $raw_array = json_array( $raw );
    if ( (int) ( $raw_array['id'] ?? 0 ) !== $target['id'] || ( $raw_array['status'] ?? '' ) === 'trash' ) { return I\error( 'missing', 'Choose an existing form outside Trash.', 404 ); }
    $settings = []; foreach ( SETTINGS as $key ) { $settings[ $key ] = in_array( $raw_array['meta'][ $key ] ?? '', [ true, 1, '1', 'on' ], true ); }
    $state = $raw_array; foreach ( array_keys( $state ) as $key ) { if ( str_starts_with( $key, 'count_' ) ) { unset( $state[ $key ] ); } }
    return [ 'data' => [ 'label' => $raw_array['label'], 'status' => $raw_array['status'], 'design' => design( $target['id'] ), 'settings' => $settings, 'auto_publish' => (bool) \WS_Form_Common::option_get( 'publish_auto', false ) ], 'state' => $state, 'raw' => $raw ];
}
function listing( int $page, int $limit ) {
    $ok = permission(); if ( is_wp_error( $ok ) ) { return $ok; }
    $rows = model()->db_read_all( '', '', 'id ASC', (string) $limit, (string) ( ( $page - 1 ) * $limit ), false, false, 'id,label,status' ); $out = [];
    foreach ( (array) $rows as $row ) { if ( ( $row->status ?? '' ) !== 'trash' ) { $out[] = [ 'id' => (int) $row->id, 'label' => (string) $row->label, 'status' => (string) $row->status ]; } } return $out;
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
    $sections = 0; $fields = 0; $ids = [];
    foreach ( $value['groups'] as &$group ) {
        if ( ! is_array( $group ) || array_diff( array_keys( $group ), [ 'id', 'label', 'sections' ] ) || ! is_string( $group['label'] ?? null ) || strlen( $group['label'] ) > 300 || ! is_array( $group['sections'] ?? null ) || ! array_is_list( $group['sections'] ) || ! $group['sections'] ) { return I\error( 'design', 'Use a labelled tab containing sections.' ); }
        $group['label'] = sanitize_text_field( $group['label'] );
        $valid = node_id( $group, $creating, 'tab', $ids ); if ( is_wp_error( $valid ) ) { return $valid; }
        foreach ( $group['sections'] as &$section ) {
            if ( ++$sections > 100 || ! is_array( $section ) || array_diff( array_keys( $section ), [ 'id', 'label', 'fields', 'meta' ] ) || ! is_string( $section['label'] ?? null ) || strlen( $section['label'] ) > 300 || ! is_array( $section['fields'] ?? null ) || ! array_is_list( $section['fields'] ) || ! $section['fields'] || ( isset( $section['meta'] ) && ( ! is_array( $section['meta'] ) || array_diff( array_keys( $section['meta'] ), [ 'widths' ] ) ) ) ) { return I\error( 'design', 'Use labelled sections with fields and optional native breakpoint widths.' ); }
            $section['label'] = sanitize_text_field( $section['label'] );
            if ( isset( $section['meta']['widths'] ) ) { ai()->breakpoint_widths_parse( $section['meta'] ); }
            $valid = node_id( $section, $creating, 'section', $ids ); if ( is_wp_error( $valid ) ) { return $valid; }
            foreach ( $section['fields'] as &$field ) {
                if ( ++$fields > 100 || ! is_array( $field ) || array_diff( array_keys( $field ), [ 'id', 'label', 'type', 'meta' ] ) || ! is_string( $field['label'] ?? null ) || strlen( $field['label'] ) > 300 || ! is_string( $field['type'] ?? null ) || ( $creating && ! in_array( $field['type'], ai()->get_field_type_ids(), true ) ) ) { return I\error( 'design', 'Use up to 100 fields with native field types and labelled design data.' ); }
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
    $id = $node['id'] ?? 0;
    if ( ! is_int( $id ) || $id < 0 || ( $creating ? $id !== 0 : $id < 1 ) ) { return I\error( 'id', 'Use existing integer IDs when updating and no existing IDs when creating a form.' ); }
    if ( $creating ) { unset( $node['id'] ); }
    else { $key = $kind . ':' . $id; if ( isset( $seen[ $key ] ) ) { return I\error( 'id', 'Do not repeat a design item ID.' ); } $seen[ $key ] = true; } return true;
}
function nodes( array $design, string $kind ): array {
    $out = []; foreach ( $design['groups'] as $group ) { if ( $kind === 'tab' ) { $out[ $group['id'] ] = $group; } foreach ( $group['sections'] as $section ) { if ( $kind === 'section' ) { $out[ $section['id'] ] = $section; } foreach ( $section['fields'] as $field ) { if ( $kind === 'field' ) { $out[ $field['id'] ] = $field; } } } } return $out;
}
function prepare( array $target, array $item, string $operation, array $changes ) {
    $ok = permission( $operation ); if ( is_wp_error( $ok ) ) { return $ok; }
    if ( ! empty( $item['data']['auto_publish'] ) && ( $item['data']['status'] ?? '' ) === 'publish' && ! \WS_Form_Common::can_user( 'publish_form' ) ) { return I\error( 'forbidden', 'Changing this automatically published form also requires publish_form permission.', 403 ); }
    if ( $operation === 'create' ) {
        if ( array_keys( $changes ) !== [ 'design' ] ) { return I\error( 'fields', 'Create a form using only a design object.' ); }
        $clean = validate_design( $changes['design'], true ); if ( is_wp_error( $clean ) ) { return $clean; }
        return [ 'changes' => [ 'design' => $clean ], 'diff' => [ 'design' => [ 'before' => null, 'after' => $clean ] ], 'warning' => 'Creates a draft form using native WS Form defaults. Review its fields before approval; publishing requires a separate preview.' ];
    }
    if ( in_array( $operation, [ 'publish', 'unpublish' ], true ) ) {
        if ( $changes ) { return I\error( 'fields', 'Publishing operations do not accept design changes.' ); }
        return [ 'changes' => [], 'diff' => [ 'publication' => [ 'before' => [ 'status' => $item['data']['status'], 'state_hash' => I\fingerprint( $item['state'] ) ], 'after' => $operation === 'publish' ? 'Publish the current draft design' : 'Remove the published form; retain its draft design' ] ], 'warning' => 'This changes the form available to visitors. Review the complete current design before approving.' ];
    }
    if ( $operation === 'update' ) {
        if ( ! $changes || array_diff( array_keys( $changes ), [ 'label', 'design', 'settings' ] ) ) { return I\error( 'fields', 'Update label, design or the documented form settings.' ); }
        $clean = [];
        if ( isset( $changes['label'] ) ) { $valid = I\validate_fields( [ 'label' => $changes['label'] ], schema()['update'] ); if ( is_wp_error( $valid ) ) { return $valid; } $clean['label'] = sanitize_text_field( $changes['label'] ); }
        if ( isset( $changes['design'] ) ) { $valid = validate_design( $changes['design'], false, $item['data']['design'] ); if ( is_wp_error( $valid ) ) { return $valid; } if ( isset( $clean['label'] ) && $valid['label'] !== $clean['label'] ) { return I\error( 'label', 'The top-level label and design label must agree.' ); } $clean['design'] = $valid; }
        if ( isset( $changes['settings'] ) ) { if ( ! is_array( $changes['settings'] ) ) { return I\error( 'settings', 'Use a settings object.' ); } $valid = I\validate_fields( $changes['settings'], schema()['settings'] ); if ( is_wp_error( $valid ) ) { return $valid; } $clean['settings'] = array_replace( $item['data']['settings'], $valid ); }
        return [ 'changes' => $clean, 'diff' => I\diff( $item['data'], $clean ), 'warning' => 'Review the form design/settings diff. Native auto-publishing, if enabled, can make this update visible immediately. Submission records, actions and credentials are not changed.' ];
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
    return [ 'changes' => $valid, 'diff' => [ $operation => [ 'before' => $action === 'add' ? null : $all[ $valid['id'] ], 'after' => $action === 'delete' ? null : $valid ] ], 'warning' => $action === 'delete' ? 'This removes a form-design item and native WS Form may repair action/conditional references to it. Review it and its affected fields before approval. Submission records are not exposed or deleted.' : 'Review this form-design operation before approval. Native auto-publishing may make the change visible immediately.' ];
}
function write( array $target, array $item, string $operation, array $changes ) {
    $check = permission( $operation ); if ( is_wp_error( $check ) ) { return $check; }
    if ( ! empty( $item['data']['auto_publish'] ) && ( $item['data']['status'] ?? '' ) === 'publish' && ! \WS_Form_Common::can_user( 'publish_form' ) ) { return I\error( 'forbidden', 'Automatic publication requires publish_form permission.', 403 ); }
    $id = $target['id']; $ai = ai( $id ); $form = model( $id );
    if ( $operation === 'create' ) {
        $created = $ai->form_create_json( wp_json_encode( $changes['design'] ) );
        $id = is_numeric( $created ) ? (int) $created : (int) ( json_array( $created )['id'] ?? $ai->id );
        if ( $id < 1 ) { return I\error( 'create', 'WS Form did not return the new form ID.', 502 ); }
    } elseif ( $operation === 'update' ) {
        if ( isset( $changes['design'] ) ) { $ai->form_update_json( wp_json_encode( $changes['design'] ) ); }
        if ( isset( $changes['label'] ) || isset( $changes['settings'] ) ) {
            $raw = $form->db_read( true, false );
            if ( isset( $changes['label'] ) ) { $raw->label = $changes['label']; }
            // Preserve all untouched form metadata and action settings server-side.
            foreach ( $changes['settings'] ?? [] as $key => $value ) { $raw->meta->{$key} = $value ? 'on' : ''; }
            $form->db_update_from_object( $raw, false, false, false ); $form->db_checksum();
        }
    } elseif ( $operation === 'publish' ) { $form->db_publish(); }
    elseif ( $operation === 'unpublish' ) { $form->db_draft(); }
    else {
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
    if ( $operation === 'create' && $after['data']['status'] !== 'draft' ) { return I\error( 'readback', 'The new form is not a draft. Inspect before retrying.', 502 ); }
    if ( $operation === 'publish' && $after['data']['status'] !== 'publish' ) { return I\error( 'readback', 'WS Form did not publish the form.', 502 ); }
    if ( $operation === 'unpublish' && $after['data']['status'] !== 'draft' ) { return I\error( 'readback', 'WS Form did not unpublish the form.', 502 ); }
    if ( $operation === 'update' ) {
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
    return [ 'id' => $id, 'native_result_read_back' => true ];
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
        } elseif ( I\canonical( $actual[$key] ?? null ) !== I\canonical( $value ) ) { return false; }
    } return true;
}
function design_matches( array $expected, array $actual ): bool {
    foreach ( [ 'id', 'label', 'type' ] as $key ) { if ( isset( $expected[$key] ) && ( $actual[$key] ?? null ) !== $expected[$key] ) { return false; } }
    if ( isset( $expected['meta'] ) && ! meta_matches( $expected['meta'], $actual['meta'] ?? [] ) ) { return false; }
    foreach ( [ 'groups', 'sections', 'fields' ] as $key ) {
        if ( ! isset( $expected[$key] ) ) { continue; }
        if ( count( $expected[$key] ) !== count( $actual[$key] ?? [] ) ) { return false; }
        foreach ( $expected[$key] as $index => $node ) { if ( ! design_matches( $node, $actual[$key][$index] ) ) { return false; } }
    } return true;
}

/** 1.12.20 needs an empty initial label to load native field defaults correctly. */
function add_field( \WS_Form_Form_AI $ai, int $section, string $label, string $type, array $meta, int $before ) {
    $created = $ai->field_add( $section, '', $type, $meta, $before );
    return $ai->field_update( (int) $created->id, $label, [] );
}
