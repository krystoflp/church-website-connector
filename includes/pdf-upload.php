<?php
declare(strict_types=1);

namespace ChurchWebsiteConnector\PdfUpload;

defined( 'ABSPATH' ) || exit;

const MAX_BYTES = 10485760;
const MAX_BASE64 = 13981016;

function error( string $code, string $message, int $status = 400 ): \WP_Error {
    return new \WP_Error( 'church_connector_pdf_' . $code, $message, [ 'status' => $status ] );
}

function permission( $input = null ) {
    if ( ! is_user_logged_in() || ! \ChurchWebsiteConnector\feature_enabled( 'rest_tools' ) || ! current_user_can( 'upload_files' ) ) {
        return error( 'forbidden', 'Sign in with an account permitted to upload files, and enable the connector REST-backed tools on this website.', 403 );
    }
    return true;
}

/** One fixed core REST upload route; no remote download, arbitrary meta or grants. */
function upload( $input ) {
    $allowed = permission();
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    if ( ! is_array( $input ) || array_diff( array_keys( $input ), [ 'site_url', 'filename', 'data_base64', 'title', 'caption', 'description', 'attach_to' ] ) ) {
        return error( 'input', 'Only the documented PDF upload parameters are accepted.' );
    }
    if ( ( $input['site_url'] ?? null ) !== \ChurchWebsiteConnector\bound_site() ) {
        return error( 'site', 'Identify this installation’s exact website address.', 403 );
    }
    $filename = $input['filename'] ?? null;
    if ( ! is_string( $filename ) || strlen( $filename ) > 200 || preg_match( '~[\x00-\x1f\x7f/\\\\]~', $filename ) || ! preg_match( '/\.pdf\z/i', $filename ) ) {
        return error( 'filename', 'Supply a PDF filename without a directory or control characters (maximum 200 bytes).' );
    }
    $filename = sanitize_file_name( $filename );
    if ( $filename === '' || preg_match( '~[\x00-\x1f\x7f"/\\\\]~', $filename ) || ! preg_match( '/\.pdf\z/i', $filename ) ) {
        return error( 'filename', 'WordPress could not produce a safe PDF filename.' );
    }
    if ( ( wp_check_filetype( $filename, get_allowed_mime_types() )['type'] ?? '' ) !== 'application/pdf' ) {
        return error( 'policy', 'This website’s WordPress upload policy does not allow PDF documents.', 403 );
    }
    $encoded = $input['data_base64'] ?? null;
    if ( ! is_string( $encoded ) || $encoded === '' || strlen( $encoded ) > MAX_BASE64 ) {
        return error( 'size', 'Supply canonical base64 from the actual PDF file, up to 10 MiB decoded.' );
    }
    $bytes = base64_decode( $encoded, true );
    if ( $bytes === false || base64_encode( $bytes ) !== $encoded ) {
        return error( 'encoding', 'The PDF data must be canonical base64, without a data URL or whitespace.' );
    }
    $size = strlen( $bytes );
    if ( $size > MAX_BYTES || $size > wp_max_upload_size() ) {
        return error( 'size', 'The PDF exceeds the connector’s 10 MiB limit or this website’s upload limit.', 413 );
    }
    if ( ! preg_match( '/\A%PDF-(?:1\.[0-7]|2\.0)[\x09\x0a\x0c\x0d\x20]/', $bytes ) || ! preg_match( '/%%EOF[\x09\x0a\x0c\x0d\x20]*\z/', substr( $bytes, -1024 ) ) ) {
        return error( 'type', 'The file does not have a supported PDF header and end marker.' );
    }
    if ( class_exists( '\finfo' ) && ( new \finfo( FILEINFO_MIME_TYPE ) )->buffer( $bytes ) !== 'application/pdf' ) {
        return error( 'type', 'File inspection did not identify a PDF document.' );
    }
    $params = [];
    foreach ( [ 'title', 'caption', 'description' ] as $field ) {
        if ( ! array_key_exists( $field, $input ) ) { continue; }
        if ( ! is_string( $input[ $field ] ) || strlen( $input[ $field ] ) > ( $field === 'title' ? 2000 : 10000 ) ) {
            return error( 'text', 'Use plain text within the documented length limits.' );
        }
        $params[ $field ] = $field === 'title' ? sanitize_text_field( $input[ $field ] ) : sanitize_textarea_field( $input[ $field ] );
    }
    if ( array_key_exists( 'attach_to', $input ) ) {
        $parent = $input['attach_to'];
        if ( ! is_int( $parent ) || $parent < 1 || ! get_post( $parent ) || in_array( get_post_type( $parent ), [ 'attachment', 'revision' ], true ) || ! current_user_can( 'edit_post', $parent ) ) {
            return error( 'parent', 'Choose an existing post or page your account can edit.', 403 );
        }
        $params['post'] = $parent;
    }
    if ( ! function_exists( 'rest_do_request' ) || ! class_exists( '\WP_REST_Request' ) ) {
        return error( 'unavailable', 'The WordPress REST dispatcher is unavailable.', 503 );
    }
    $request = new \WP_REST_Request( 'POST', '/wp/v2/media' );
    $request->set_header( 'Content-Type', 'application/pdf' );
    $request->set_header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
    $request->set_body( $bytes );
    $request->set_query_params( $params );
    $response = rest_do_request( $request );
    if ( is_wp_error( $response ) ) { return $response; }
    $data = $response->get_data();
    if ( $response->get_status() >= 400 ) {
        return error( 'denied', is_array( $data ) && is_string( $data['message'] ?? null ) ? wp_strip_all_tags( $data['message'] ) : 'WordPress refused the PDF upload.', $response->get_status() );
    }
    $id = is_array( $data ) ? ( $data['id'] ?? 0 ) : 0;
    if ( ! is_int( $id ) || $id < 1 || get_post_type( $id ) !== 'attachment' || get_post_mime_type( $id ) !== 'application/pdf' ) {
        return error( 'response', 'WordPress returned an unexpected upload result. Inspect the Media Library before retrying.', 502 );
    }
    $path = get_attached_file( $id );
    $digest = hash( 'sha256', $bytes );
    if ( ! is_string( $path ) || ! is_file( $path ) || filesize( $path ) !== $size || hash_file( 'sha256', $path ) !== $digest ) {
        return error( 'verification', 'The uploaded attachment could not be verified. Inspect the Media Library before retrying.', 502 );
    }
    return [ 'site_url' => \ChurchWebsiteConnector\bound_site(), 'attachment_id' => $id, 'url' => wp_get_attachment_url( $id ), 'mime' => 'application/pdf', 'filename' => wp_basename( $path ), 'bytes' => $size, 'sha256' => $digest ];
}

function register_ability(): void {
    if ( ! function_exists( 'wp_register_ability' ) || ! \ChurchWebsiteConnector\feature_enabled( 'rest_tools' ) ) { return; }
    wp_register_ability( 'church-connector/upload-pdf', [
        'label' => 'Upload a PDF document',
        'description' => 'WRITE: uploads an actual local PDF into the WordPress Media Library through existing MCP sign-in and native REST upload permissions. Read and base64-encode the file; never invent file bytes or pass a filesystem path. Maximum 10 MiB decoded (website/client limits may be lower). Checks PDF signature/end marker and verifies stored bytes. Optional plain-text metadata and attachment to an editable post/page. Returns attachment_id, public URL, byte count and SHA-256. Uploads are public and non-idempotent: inspect the Media Library before retrying an uncertain result. Does not publish pages, change grants or clear caches.',
        'category' => 'church-connector',
        'input_schema' => [ 'type' => 'object', 'properties' => [
            'site_url' => [ 'type' => 'string', 'enum' => [ \ChurchWebsiteConnector\bound_site() ] ],
            'filename' => [ 'type' => 'string', 'minLength' => 5, 'maxLength' => 200 ],
            'data_base64' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => MAX_BASE64 ],
            'title' => [ 'type' => 'string', 'maxLength' => 2000 ],
            'caption' => [ 'type' => 'string', 'maxLength' => 10000 ],
            'description' => [ 'type' => 'string', 'maxLength' => 10000 ],
            'attach_to' => [ 'type' => 'integer', 'minimum' => 1 ],
        ], 'required' => [ 'site_url', 'filename', 'data_base64' ], 'additionalProperties' => false ],
        'permission_callback' => __NAMESPACE__ . '\\permission',
        'execute_callback' => __NAMESPACE__ . '\\upload',
        'meta' => [ 'show_in_rest' => false, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ] ],
    ] );
}

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_ability' );
