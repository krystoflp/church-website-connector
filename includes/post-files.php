<?php
/** Locate and update saved WordPress File blocks without reserialising other blocks. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\PostFiles;

use ChurchWebsiteConnector\RestTools;

defined( 'ABSPATH' ) || exit;

/** Return byte ranges in document order, including File blocks inside groups. */
function ranges( string $content ): array|\WP_Error {
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

/** Change only core File markup and its attachment attributes. */
function replace( string $content, array $file, array $pdf, string $label ): string|\WP_Error {
	$html      = new \WP_HTML_Tag_Processor( $file['html'] );
	$links     = 0;
	$downloads = 0;
	$embeds    = 0;
	$plain     = true;
	while ( $html->next_tag() ) {
		if ( 'OBJECT' === $html->get_tag() && 'application/pdf' === $html->get_attribute( 'type' ) ) {
			++$embeds;
			$html->set_attribute( 'data', $pdf['url'] );
			$html->set_attribute(
				'aria-label',
				/* translators: %s: PDF link label. */
				sprintf( __( 'Embed of %s', 'church-website-connector' ), $label )
			);
		} elseif ( 'A' === $html->get_tag() ) {
			$html->set_attribute( 'href', $pdf['url'] );
			if ( null !== $html->get_attribute( 'download' ) ) {
				++$downloads;
			} else {
				++$links;
				$plain = $html->next_token() && '#text' === $html->get_token_name()
					&& $html->set_modifiable_text( $label ) && $html->next_token()
					&& 'A' === $html->get_tag() && $html->is_tag_closer();
			}
		} elseif ( in_array( $html->get_tag(), array( 'OBJECT', 'EMBED', 'IFRAME' ), true ) ) {
			return RestTools\error( 'pdf_block', 'Choose a standard WordPress File block with a plain file label.' );
		}
	}
	$attrs = $file['attrs'];
	if ( ! is_array( $attrs ) || 1 !== $links || $downloads > 1 || $embeds > 1 || ! $plain
		|| str_contains( $file['html'], '<!-- wp:' ) || ( ! empty( $attrs['displayPreview'] ) && 1 !== $embeds ) ) {
		return RestTools\error( 'pdf_block', 'Choose a standard WordPress File block with a plain file label.' );
	}
	$attrs['id']   = $pdf['attachment_id'];
	$attrs['href'] = $pdf['url'];
	if ( isset( $attrs['textLinkHref'] ) ) {
		$attrs['textLinkHref'] = $pdf['url'];
	}
	$replacement = get_comment_delimited_block_content( 'core/file', $attrs, $html->get_updated_html() );
	return substr_replace( $content, $replacement, $file['start'], $file['end'] - $file['start'] );
}
