<?php
/** Locate and update saved WordPress File blocks without reserialising other blocks. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\PostFiles;

use ChurchWebsiteConnector\RestTools;

defined( 'ABSPATH' ) || exit;

/** Return byte ranges in document order, including File blocks inside groups. */
function ranges( string $content, ?array &$references = null ): array|\WP_Error {
	if ( strlen( $content ) > 1024 * 1024 ) {
		return RestTools\error( 'post_size', 'This post exceeds the 1 MiB review limit.' );
	}

	// Core's tokenizer gives byte offsets that parse_blocks() does not retain.
	$parser           = new \WP_Block_Parser();
	$parser->document = $content;
	$parser->offset   = 0;
	$stack            = array();
	$files            = array();
	while ( true ) {
		list( $type, $name, $attrs, $start, $length ) = $parser->next_token();
		if ( 'no-more-tokens' === $type ) {
			break;
		}
		$parser->offset = $start + $length;
		if ( null !== $references && 'core/block' === $name && in_array( $type, array( 'void-block', 'block-opener' ), true ) ) {
			$references[] = empty( $attrs['content'] ) && empty( $attrs['overrides'] ) ? ( $attrs['ref'] ?? null ) : false;
		}
		if ( 'void-block' === $type && 'core/file' !== $name ) {
			continue;
		}
		if ( 'block-opener' === $type ) {
			$stack[] = array( $name, $attrs, $start, $parser->offset );
			continue;
		}
		$opening = array_pop( $stack );
		if ( 'block-closer' !== $type || ( $opening[0] ?? null ) !== $name ) {
			return RestTools\error( 'post_blocks', 'The saved block delimiters are incomplete or unsupported.' );
		}
		if ( 'core/file' === $name ) {
			$files[] = array(
				'start' => $opening[2],
				'end'   => $parser->offset,
				'attrs' => $opening[1],
				'html'  => substr( $content, $opening[3], $start - $opening[3] ),
			);
		}
	}
	if ( $stack || count( $files ) > 50 ) {
		return RestTools\error( 'post_blocks', 'The saved block structure is incomplete or exceeds the 50 File block review limit.' );
	}
	usort( $files, static fn( $left, $right ) => $left['start'] <=> $right['start'] );
	return $files;
}

/** Follow saved, publicly renderable synced patterns without executing dynamic blocks. */
function content_files( string $content ): array|\WP_Error {
	$references = array();
	$ranges     = ranges( $content, $references );
	if ( is_wp_error( $ranges ) ) {
		return $ranges;
	}
	$patterns = array();
	$bytes    = strlen( $content );
	$count    = count( $ranges );
	$result   = collect_patterns( $references, array(), $patterns, $bytes, $count );
	return array(
		'ranges'   => $ranges,
		'patterns' => is_wp_error( $result ) ? $result : $patterns,
	);
}

function collect_patterns( array $references, array $ancestors, array &$patterns, int &$bytes, int &$count ): true|\WP_Error {
	if ( count( $references ) > 50 ) {
		return RestTools\error( 'pattern_review', 'This content contains more than 50 synced pattern references. Review it in WordPress before publication.', 409 );
	}
	foreach ( $references as $id ) {
		if ( false === $id ) {
			return RestTools\error( 'pattern_review', 'This synced pattern has instance-specific content overrides. Review its rendered content in WordPress before publication.', 409 );
		}
		if ( ! is_int( $id ) || $id < 1 || isset( $ancestors[ $id ] ) || count( $ancestors ) >= 8 ) {
			return RestTools\error( 'pattern_review', 'A synced pattern reference is invalid, circular or too deeply nested. Review it in WordPress before publication.', 409 );
		}
		if ( isset( $patterns[ $id ] ) ) {
			continue;
		}
		wp_cache_delete( $id, 'posts' );
		$post = get_post( $id );
		if ( ! $post || 'wp_block' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password || ! current_user_can( 'read_post', $id ) ) {
			return RestTools\error( 'pattern_review', 'A referenced synced pattern is unavailable for review. Inspect the pattern in WordPress before publication.', 409 );
		}
		$bytes += strlen( $post->post_content );
		if ( $bytes > MB_IN_BYTES || count( $patterns ) >= 50 ) {
			return RestTools\error( 'pattern_review', 'The post and its synced patterns exceed the bounded review. Inspect the patterns in WordPress before publication.', 409 );
		}
		$nested = array();
		$files  = ranges( $post->post_content, $nested );
		if ( is_wp_error( $files ) || $count + count( $files ) > 50 ) {
			return RestTools\error( 'pattern_review', 'The referenced patterns contain unreviewable File blocks or exceed the combined 50-block limit. Review them in WordPress.', 409 );
		}
		$count          += count( $files );
		$patterns[ $id ] = array(
			'id'           => $id,
			'title'        => $post->post_title,
			'content_hash' => hash( 'sha256', $post->post_content ),
			'ranges'       => $files,
		);

		$result = collect_patterns( $nested, $ancestors + array( $id => true ), $patterns, $bytes, $count );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	return true;
}

/** Direct File block indexes stay local to the page or the named synced pattern. */
function describe_content( array $files ): array {
	$review = array( 'file_blocks' => describe_all( $files['ranges'] ) );
	if ( is_wp_error( $files['patterns'] ) ) {
		$review['pattern_review_issue'] = $files['patterns']->get_error_message();
	} else {
		foreach ( $files['patterns'] as $pattern ) {
			$pattern['file_blocks'] = describe_all( $pattern['ranges'] );
			unset( $pattern['ranges'] );
			$review['synced_patterns'][] = $pattern;
		}
	}
	return $review;
}

/** URL metadata is displayed as data. No attachment URL is fetched by these tools. */
function display_url( mixed $url ): string {
	if ( ! is_string( $url ) || ! preg_match( '~\Ahttps?://~i', $url ) ) {
		return '';
	}
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) || array_intersect( array_keys( (array) $parts ), array( 'user', 'pass', 'query', 'fragment' ) ) ) {
		return '';
	}
	return esc_url_raw( $url, array( 'http', 'https' ) );
}

/** Inspect the saved targets of one block. Include embedded URLs even if links differ. */
function describe( array $file, int $number ): array {
	$html     = new \WP_HTML_Tag_Processor( $file['html'] );
	$info     = array(
		'file_block'     => $number,
		'attachment_id'  => (int) ( $file['attrs']['id'] ?? 0 ),
		'label'          => '',
		'link_url'       => '',
		'embed_url'      => '',
		'download_url'   => '',
		'preview_height' => $file['attrs']['previewHeight'] ?? 600,
	);
	$in_label = false;
	while ( $html->next_token() ) {
		if ( $in_label && '#text' === $html->get_token_name() ) {
			$info['label'] .= $html->get_modifiable_text();
		}
		if ( 'OBJECT' === $html->get_tag() && ! $html->is_tag_closer() ) {
			$info['embed_url'] = display_url( $html->get_attribute( 'data' ) );
		} elseif ( 'A' === $html->get_tag() ) {
			$in_label = ! $html->is_tag_closer() && null === $html->get_attribute( 'download' );
			if ( $in_label ) {
				$info['link_url'] = display_url( $html->get_attribute( 'href' ) );
			} elseif ( ! $html->is_tag_closer() ) {
				$info['download_url'] = display_url( $html->get_attribute( 'href' ) );
			}
		}
	}
	return $info;
}

function describe_all( array $ranges ): array {
	$files = array();
	foreach ( $ranges as $index => $file ) {
		$files[] = describe( $file, $index + 1 );
	}
	return $files;
}

/** Recognise direct links to the verified file, including site-root paths and PDF viewer fragments. */
function matches_pdf_url( mixed $target, string $url ): bool {
	if ( ! is_string( $target ) ) {
		return false;
	}
	$address = explode( '#', $target, 2 )[0];
	if ( $address === $url ) {
		return true;
	}
	$parts = wp_parse_url( $url );
	$path  = $parts['path'];
	if ( ! str_ends_with( $address, $path ) ) {
		return false;
	}
	// Compare origins independently of the case-sensitive file path. Accept only
	// the reviewed host, scheme and their default port, or a site-root path.
	$origin = strtolower( substr( $address, 0, -strlen( $path ) ) );
	if ( '' === $origin ) {
		return ! str_starts_with( $address, '//' );
	}
	$host   = '//' . strtolower( $parts['host'] );
	$scheme = strtolower( $parts['scheme'] );
	$port   = 'https' === $scheme ? ':443' : ':80';
	return in_array( $origin, array( $host, $host . $port, $scheme . ':' . $host, $scheme . ':' . $host . $port ), true );
}

/** Display filenames only, without URL credentials, query strings or viewer fragments. */
function linked_filename( mixed $url ): string {
	if ( ! is_string( $url ) || '' === $url ) {
		return '';
	}
	$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
	if ( $scheme && ! in_array( strtolower( $scheme ), array( 'https', 'http' ), true ) ) {
		return '';
	}
	$path = wp_parse_url( $url, PHP_URL_PATH );
	return is_string( $path ) ? sanitize_text_field( wp_basename( rawurldecode( $path ) ) ) : '';
}

/** Verify every saved destination, even when a later link in the same block is correct. */
function publication_targets( array $file, int $number, string $url ): array|\WP_Error {
	$mismatch = static function ( $role, $target = null ) use ( $number, $url ) {
		$expected = linked_filename( $url );
		$linked   = linked_filename( $target );
		$message  = sprintf( 'In File block %d, %s. The saved destination is %s. The Media Library PDF is %s.', $number, $role, $linked ? $linked : 'unidentified', $expected );
		return RestTools\review_error(
			'church_connector_rest_pdf_target',
			sprintf( 'File block %d needs review. Read the post and prepare a separate replacement for the intended attachment.', $number ),
			$message,
			array(
				'status'            => 409,
				'file_block'        => $number,
				'linked_filename'   => $linked,
				'expected_filename' => $expected,
			)
		);
	};
	foreach ( array( 'href', 'textLinkHref' ) as $attribute ) {
		if ( array_key_exists( $attribute, $file['attrs'] ) && ! matches_pdf_url( $file['attrs'][ $attribute ], $url ) ) {
			return $mismatch( 'the saved File block URL differs from its PDF attachment', $file['attrs'][ $attribute ] );
		}
	}
	$html    = new \WP_HTML_Tag_Processor( $file['html'] );
	$targets = array();
	while ( $html->next_tag() ) {
		$tag = $html->get_tag();
		if ( 'A' === $tag ) {
			$key       = null === $html->get_attribute( 'download' ) ? 'link_url' : 'download_url';
			$attribute = 'href';
		} elseif ( in_array( $tag, array( 'OBJECT', 'EMBED', 'IFRAME' ), true ) ) {
			$key       = 'embed_url';
			$attribute = 'OBJECT' === $tag ? 'data' : 'src';
			if ( 'IFRAME' === $tag && null !== $html->get_attribute( 'srcdoc' ) ) {
				return $mismatch( 'the embedded preview supplies its own content' );
			}
		} else {
			continue;
		}
		if ( ! matches_pdf_url( $html->get_attribute( $attribute ), $url ) ) {
			$labels = array(
				'link_url'     => 'the file link does not point directly to its PDF attachment',
				'download_url' => 'the download button does not point directly to its PDF attachment',
				'embed_url'    => 'the embedded preview does not point directly to its PDF attachment',
			);
			return $mismatch( $labels[ $key ], $html->get_attribute( $attribute ) );
		}
		$targets[ $key ] = $url;
	}
	if ( ! $targets || $html->paused_at_incomplete_token() ) {
		return $mismatch( 'the saved markup has no verifiable destination or is incomplete' );
	}
	return $targets;
}

/** Resolve a same-origin, readable PDF attachment using WordPress Media Library. */
function attachment( int $id ): array|\WP_Error {
	$attachment = get_post( $id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type || 'inherit' !== $attachment->post_status
		|| 'application/pdf' !== $attachment->post_mime_type ) {
		return RestTools\error( 'pdf_attachment', 'Choose a PDF attachment from this website’s Media Library.' );
	}
	$media = RestTools\dispatch( 'GET', RestTools\route( 'media', $id ) );
	if ( is_wp_error( $media ) ) {
		return $media;
	}

	$url   = display_url( wp_get_attachment_url( $id ) );
	$parts = wp_parse_url( $url );
	$site  = wp_parse_url( \ChurchWebsiteConnector\bound_site() );
	$path  = $parts['path'] ?? '';
	if ( '' === $url || ( $parts['scheme'] ?? '' ) !== $site['scheme'] || ( $parts['host'] ?? '' ) !== $site['host']
		|| array_intersect( array_keys( (array) $parts ), array( 'port', 'user', 'pass', 'query', 'fragment' ) )
		|| ! str_ends_with( strtolower( $path ), '.pdf' ) ) {
		return RestTools\error( 'pdf_site', 'The PDF must have a file URL on this website.' );
	}

	$path    = get_attached_file( $id, true );
	$uploads = wp_get_upload_dir();
	$root    = realpath( $uploads['basedir'] );
	$actual  = is_string( $path ) ? realpath( $path ) : false;
	$size    = $actual && is_file( $actual ) ? wp_filesize( $actual ) : 0;
	if ( ! $root || ! $actual || ! str_starts_with( $actual, $root . DIRECTORY_SEPARATOR ) || ! $size || $size > 50 * MB_IN_BYTES || ! is_readable( $actual ) ) {
		return RestTools\error( 'pdf_file', 'The PDF must be a readable uploads file of at most 50 MiB.' );
	}
	$signature = file_get_contents( $actual, false, null, 0, 5 );
	$digest    = '%PDF-' === $signature ? hash_file( 'sha256', $actual ) : false;
	if ( false === $digest ) {
		return RestTools\error( 'pdf_file', 'WordPress could not verify the bytes of this PDF.' );
	}
	return array(
		'attachment_id' => $id,
		'url'           => $url,
		'sha256'        => $digest,
	);
}

/** Read the next significant token, including closing tags and unexpected content. */
function file_token( \WP_HTML_Tag_Processor $html ): ?string {
	while ( $html->next_token() ) {
		if ( '#text' === $html->get_token_name() && '' === trim( $html->get_modifiable_text(), " \t\r\n\f" ) ) {
			continue;
		}
		return $html->is_tag_closer() ? '/' . $html->get_tag() : $html->get_token_name();
	}
	return null;
}

/** Only change recognised File markup. Extra attributes can affect editor validation or behaviour. */
function file_attributes( \WP_HTML_Tag_Processor $html, array $allowed ): bool {
	return ! array_diff( $html->get_attribute_names_with_prefix( '' ) ?? array(), $allowed );
}

/** Compare class sets independent of their order, as the editor does. */
function file_classes( \WP_HTML_Tag_Processor $html, string $expected ): bool {
	$actual = iterator_to_array( $html->class_list(), false );
	$wanted = preg_split( '/\s+/', trim( $expected ), -1, PREG_SPLIT_NO_EMPTY );
	sort( $actual );
	sort( $wanted );
	return $actual === $wanted;
}

/** Change only the selected plain core File block, retaining link IDs and presentation. */
function replace( string $content, array $file, array $pdf, string $label ): string|\WP_Error {
	$unsupported = RestTools\error( 'pdf_block', 'Review this File block in the WordPress editor. Its markup or settings cannot be preserved by the PDF replacement tool.', 409 );
	$attrs       = $file['attrs'];
	$html        = new \WP_HTML_Tag_Processor( $file['html'] );
	// Complex block supports and bindings require the editor's own serializer.
	if ( ! is_array( $attrs ) || array_diff( array_keys( $attrs ), array( 'id', 'href', 'textLinkHref', 'displayPreview', 'previewHeight', 'showDownloadButton', 'align', 'anchor', 'className', 'metadata' ) )
		|| ! is_array( $attrs['metadata'] ?? array() ) || ! empty( $attrs['metadata']['bindings'] )
		|| ! is_bool( $attrs['displayPreview'] ?? false ) || ! is_int( $attrs['previewHeight'] ?? 600 )
		|| ! is_string( $attrs['className'] ?? '' ) || ! is_string( $attrs['align'] ?? '' ) || ! is_string( $attrs['anchor'] ?? '' )
		|| 'DIV' !== file_token( $html ) || ! file_attributes( $html, array( 'class', 'id' ) )
		|| $html->get_attribute( 'id' ) !== ( $attrs['anchor'] ?? null ) ) {
		return $unsupported;
	}
	$classes = 'wp-block-file ' . ( $attrs['className'] ?? '' );
	if ( ! empty( $attrs['align'] ) ) {
		$classes .= ' align' . $attrs['align'];
	}
	if ( ! file_classes( $html, $classes ) ) {
		return $unsupported;
	}

	$embed_label = null;
	if ( ! empty( $attrs['displayPreview'] ) ) {
		$height = $attrs['previewHeight'] ?? 600;
		if ( 'OBJECT' !== file_token( $html ) || ! file_classes( $html, 'wp-block-file__embed' )
			|| 'application/pdf' !== $html->get_attribute( 'type' )
			|| ! file_attributes( $html, array( 'class', 'data', 'type', 'style', 'aria-label' ) )
			|| ! preg_match( '/\A\s*width\s*:\s*100%\s*;\s*height\s*:\s*' . (int) $height . 'px\s*;?\s*\z/i', (string) $html->get_attribute( 'style' ) ) ) {
			return $unsupported;
		}
		$embed_label = $html->get_attribute( 'aria-label' );
		if ( ! is_string( $embed_label ) ) {
			return $unsupported;
		}
		// Gutenberg saves the filename. WordPress localises the accessible name at render time.
		$html->set_attribute( 'aria-label', $label );
		$html->set_attribute( 'data', $pdf['url'] );
		if ( '/OBJECT' !== file_token( $html ) ) {
			return $unsupported;
		}
	}

	if ( 'A' !== file_token( $html ) || ! file_attributes( $html, array( 'href', 'id', 'target', 'rel' ) ) ) {
		return $unsupported;
	}
	$id     = $html->get_attribute( 'id' );
	$target = $html->get_attribute( 'target' );
	$rel    = $html->get_attribute( 'rel' );
	if ( ( null !== $id && ! is_string( $id ) ) || ( null !== $target && ( ! is_string( $target ) || '' === $target ) )
		|| ( null !== $rel && ( null === $target || 'noreferrer noopener' !== $rel ) ) ) {
		return $unsupported;
	}
	if ( null !== $target ) {
		$html->set_attribute( 'rel', 'noreferrer noopener' );
	}
	$html->set_attribute( 'href', $pdf['url'] );
	if ( '#text' !== file_token( $html ) ) {
		return $unsupported;
	}
	$old_label = $html->get_modifiable_text();
	if ( null !== $embed_label && $embed_label !== $old_label ) {
		// Accept the two historical saved labels, preserving a separately authored description.
		/* translators: %s: filename. This string belongs to the WordPress File block. */
		$legacy = sprintf( __( 'Embed of %s.' ), $old_label ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Match the native core translation.
		/* translators: %s: PDF link label. Existing connector versions saved this description. */
		$previous = sprintf( __( 'Embed of %s', 'church-website-connector' ), $old_label );
		if ( $embed_label !== $legacy && $embed_label !== $previous ) {
			return $unsupported;
		}
	}
	if ( ! $html->set_modifiable_text( $label ) || '/A' !== file_token( $html ) ) {
		return $unsupported;
	}

	$next   = file_token( $html );
	$button = 'A' === $next;
	if ( array_key_exists( 'showDownloadButton', $attrs ) && $attrs['showDownloadButton'] !== $button ) {
		return $unsupported;
	}
	if ( $button ) {
		if ( ! in_array( $html->get_attribute( 'download' ), array( true, '' ), true ) || ! file_attributes( $html, array( 'class', 'href', 'download', 'aria-describedby' ) )
			|| $html->get_attribute( 'aria-describedby' ) !== $id ) {
			return $unsupported;
		}
		// Older native File blocks used only the first class. Migrate that button with the embed.
		if ( file_classes( $html, 'wp-block-file__button' ) ) {
			$html->add_class( 'wp-element-button' );
		} elseif ( ! file_classes( $html, 'wp-block-file__button wp-element-button' ) ) {
			return $unsupported;
		}
		$html->set_attribute( 'href', $pdf['url'] );
		if ( '#text' !== file_token( $html ) || '/A' !== file_token( $html ) ) {
			return $unsupported;
		}
		$next = file_token( $html );
	} else {
		// Older link-only blocks may omit the flag. Its current editor default is true.
		$attrs['showDownloadButton'] = false;
	}
	if ( '/DIV' !== $next || null !== file_token( $html ) || $html->paused_at_incomplete_token() ) {
		return $unsupported;
	}
	$attrs['id']   = $pdf['attachment_id'];
	$attrs['href'] = $pdf['url'];
	if ( isset( $attrs['textLinkHref'] ) ) {
		$attrs['textLinkHref'] = $pdf['url'];
	}
	$replacement = get_comment_delimited_block_content( 'core/file', $attrs, $html->get_updated_html() );
	return substr_replace( $content, $replacement, $file['start'], $file['end'] - $file['start'] );
}
