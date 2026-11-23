<?php
/** Exercise saved File blocks through the registered preview and apply abilities. */
require_once '/wordpress/wp-load.php';
if ( file_exists( '/provider-fixtures/GeneratePress-AI-MCP.php' ) ) {
	require_once '/provider-fixtures/GeneratePress-AI-MCP.php';
	require_once '/provider-fixtures/GeneratePress-AI-OAuth.php';
} else {
	class GeneratePress_AI_MCP {}
	class GeneratePress_AI_OAuth {}
}
define( 'GENERATEPRESS_AI_VERSION', '1.0.0' );
wp_set_current_user( 1 );

function file_editor_assert( $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( 'FILE EDITOR: ' . $message );
	}
}

function file_editor_tool( string $name, array $input ) {
	$result = wp_get_ability( 'church-connector/' . $name )->execute( array( 'site_url' => home_url() ) + $input );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $name . ': ' . $result->get_error_message() );
	}
	return $result;
}

function file_editor_pdf( string $filename ): array {
	$upload = wp_upload_bits( $filename, null, "%PDF-1.4\n" . $filename . "\n%%EOF" );
	$id     = wp_insert_attachment( array( 'post_title' => $filename, 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $upload['file'] );
	return array( 'id' => $id, 'href' => wp_get_attachment_url( $id ) );
}

function file_editor_fixture( array $media, string $shape ): string {
	$attrs    = $media + array( 'displayPreview' => 'preview-off' !== $shape, 'previewHeight' => 880, 'showDownloadButton' => in_array( $shape, array( 'download', 'legacy-download' ), true ) );
	$old      = 'Earlier magazine';
	$name     = 'faulty-label' === $shape ? 'Embed of ' . $old : $old;
	$button   = 'wp-block-file__button wp-element-button';
	$link_end = '';
	$wrap     = 'class="wp-block-file"';
	if ( 'legacy-download' === $shape ) {
		$name   = 'Embed of ' . $old . '.';
		$button = 'wp-block-file__button';
	}
	if ( 'new-tab' === $shape ) {
		$link_end           = ' target="_blank" rel="noreferrer noopener"';
		$attrs['align']     = 'wide';
		$attrs['className'] = 'magazine';
		$attrs['anchor']    = 'this-issue';
		$wrap               = 'class="wp-block-file alignwide magazine" id="this-issue"';
	}
	$url  = esc_url( $media['href'] );
	$html = '<div ' . $wrap . '>';
	if ( $attrs['displayPreview'] ) {
		$html .= '<object class="wp-block-file__embed" data="' . $url . '" type="application/pdf" style="width:100%;height:880px" aria-label="' . $name . '"></object>';
	}
	$html .= '<a id="wp-block-file--media-test" href="' . $url . '"' . $link_end . '>' . $old . '</a>';
	if ( $attrs['showDownloadButton'] ) {
		$html .= '<a href="' . $url . '" class="' . $button . '" download aria-describedby="wp-block-file--media-test">Download issue</a>';
	}
	return get_comment_delimited_block_content( 'core/file', $attrs, "\n" . $html . "</div>\n" );
}

function file_editor_replace( int $id, array $media, string $label ) {
	$state   = file_editor_tool( 'get-post-workflow', array( 'id' => $id ) );
	$preview = file_editor_tool( 'preview-pdf-replacement', array( 'id' => $id, 'state_hash' => $state['post']['state_hash'], 'file_block' => 1, 'attachment_id' => $media['id'], 'label' => $label ) );
	file_editor_tool( 'apply-post-edit', array( 'preview_token' => $preview['preview_token'], 'approved' => true ) );
	return get_post( $id )->post_content;
}

$first  = file_editor_pdf( 'previous-magazine.pdf' );
$second = file_editor_pdf( 'december-magazine.pdf' );
$spacer = "\n\n<!-- wp:spacer {\"height\":\"4em\"} -->\n" . '<div style="height:4em" aria-hidden="true" class="wp-block-spacer"></div>' . "\n<!-- /wp:spacer -->";
$cases  = array();

foreach ( array( 'modern', 'faulty-label', 'legacy-download', 'download', 'preview-off', 'new-tab' ) as $shape ) {
	$source = file_editor_fixture( $first, $shape ) . $spacer;
	$fixture_id     = wp_insert_post( wp_slash( array( 'post_title' => $shape . ' File block', 'post_content' => $source, 'post_status' => 'draft' ) ) );
	$source = get_post( $fixture_id )->post_content;
	$suffix = substr( $source, \ChurchWebsiteConnector\PostFiles\ranges( $source )[0]['end'] );
	file_editor_assert( str_contains( $suffix, 'height:4em' ), 'Source contains the saved spacer.' );
	foreach ( array( 'December Parish & People (PDF)', 'Embed of “Hymns” & the choir' ) as $pass => $label ) {
		$media = 0 === $pass ? $second : $first;
		$saved = file_editor_replace( $fixture_id, $media, $label );
		file_editor_assert( str_ends_with( $saved, $suffix ) && 'draft' === get_post_status( $fixture_id ), 'Preserve adjacent spacer and draft status: ' . $shape . '-' . $pass );
		$block   = parse_blocks( $saved )[0];
		$html    = new WP_HTML_Tag_Processor( $block['innerHTML'] );
		$render  = new WP_HTML_Tag_Processor( do_blocks( $saved ) );
		$preview = 'preview-off' !== $shape;
		$button  = in_array( $shape, array( 'legacy-download', 'download' ), true );
		file_editor_assert( $preview === $html->next_tag( 'OBJECT' ) && $preview === $render->next_tag( 'OBJECT' ), 'Preserve preview visibility.' );
		if ( $preview ) {
			file_editor_assert( $html->get_attribute( 'aria-label' ) === $label && $html->get_attribute( 'data' ) === $media['href'] && $html->get_attribute( 'style' ) === 'width:100%;height:880px', 'Save the exact native embed label, attachment and height.' );
			file_editor_assert( $render->get_attribute( 'aria-label' ) === sprintf( __( 'Embed of %s.' ), $label ), 'Core supplies the translated embed description once.' );
		}
		$cases[ $shape . '-' . $pass ] = array( 'source' => $source, 'saved' => $saved, 'label' => $label, 'id' => $media['id'], 'url' => $media['href'], 'preview' => $preview, 'button' => $button, 'target' => 'new-tab' === $shape ? '_blank' : null );
		$source = $saved;
	}
}

$evidence = file_editor_fixture( $first, 'modern' );
foreach ( array(
	'rich label'       => str_replace( '>Earlier magazine</a>', '><em>Earlier magazine</em></a>', $evidence ),
	'custom HTML'      => str_replace( '</div>', '<span>Authored text</span></div>', $evidence ),
	'wrong height'     => str_replace( 'height:880px', 'height:900px', $evidence ),
	'custom behaviour' => str_replace( '<a id=', '<a data-local-action="toggle" id=', $evidence ),
	'custom button'    => str_replace( 'wp-element-button', 'my-button', file_editor_fixture( $first, 'download' ) ),
	'named download'   => str_replace( ' download aria-', ' download="custom-name.pdf" aria-', file_editor_fixture( $first, 'download' ) ),
	'authored label'    => str_replace( 'aria-label="Earlier magazine"', 'aria-label="The large-print accessible edition"', $evidence ),
	'bound fields'     => str_replace( '<!-- wp:file {', '<!-- wp:file {"metadata":{"bindings":{"fileName":{"source":"test/source"}}},', $evidence ),
) as $name => $source ) {
	$fixture_id      = wp_insert_post( wp_slash( array( 'post_title' => $name, 'post_content' => $source, 'post_status' => 'draft' ) ) );
	$before  = get_post( $fixture_id )->post_content;
	$state   = file_editor_tool( 'get-post-workflow', array( 'id' => $fixture_id ) );
	$preview = wp_get_ability( 'church-connector/preview-pdf-replacement' )->execute( array( 'site_url' => home_url(), 'id' => $fixture_id, 'state_hash' => $state['post']['state_hash'], 'file_block' => 1, 'attachment_id' => $second['id'], 'label' => 'New issue' ) );
	file_editor_assert( is_wp_error( $preview ) && 'church_connector_rest_pdf_block' === $preview->get_error_code() && $before === get_post( $fixture_id )->post_content, 'Unrecognised shape must be refused before writing: ' . $name );
}
file_put_contents( '/reports/pdf-editor-cases.json', wp_json_encode( array( 'wordpress' => $GLOBALS['wp_version'], 'cases' => $cases ) ) );
