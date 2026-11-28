<?php
/** Create native Details blocks and edit a selected summary without reserialising its siblings. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\PostDetails;

use ChurchWebsiteConnector as Connector;
use ChurchWebsiteConnector\Editorial;
use ChurchWebsiteConnector\PostFiles;
use ChurchWebsiteConnector\PostTools;
use ChurchWebsiteConnector\RestTools;

defined( 'ABSPATH' ) || exit;

const OPERATIONS = array( 'create_details', 'edit_details_summary', 'create_flyer', 'edit_flyer_poster' );

function preview_create( $input ): array|\WP_Error {
	return PostTools\preview( 'create_details', $input, array( 'after_block', 'summary', 'inner_blocks' ), true );
}

function preview_flyer( $input ): array|\WP_Error {
	return PostTools\preview( 'create_flyer', $input, array( 'after_block', 'summary', 'inner_blocks', 'poster_id', 'poster_alt', 'poster_url' ), true );
}

function preview_poster( $input ): array|\WP_Error {
	return PostTools\preview( 'edit_flyer_poster', $input, array( 'block', 'poster_id', 'poster_alt', 'poster_url' ), true );
}

function preview_summary( $input ): array|\WP_Error {
	return PostTools\preview( 'edit_details_summary', $input, array( 'block', 'summary' ), true );
}

function locations( string $content ): array|\WP_Error {
	$blocks = array();
	$unused = null;
	$result = PostFiles\ranges( $content, $unused, $blocks );
	return is_wp_error( $result ) ? $result : $blocks;
}

/** Locate one text run, preserving inline formatting and links around it. */
function summary_text( string $content, array $block ): ?\WP_HTML_Tag_Processor {
	if ( 'core/details' !== $block['name'] || ! empty( $block['attrs']['metadata']['bindings']['summary'] ) ) {
		return null;
	}
	$html = new \WP_HTML_Tag_Processor( substr( $content, $block['inner'], $block['end'] - $block['inner'] ) );
	if ( 'DETAILS' !== PostFiles\file_token( $html ) || ! $html->has_class( 'wp-block-details' ) || 'SUMMARY' !== PostFiles\file_token( $html ) ) {
		return null;
	}
	$inline = array();
	while ( $html->next_token() ) {
		$type = $html->get_token_name();
		if ( '#text' === $type ) {
			if ( ! $html->set_bookmark( 'wording' ) ) {
				return null;
			}
			foreach ( array_reverse( $inline ) as $tag ) {
				if ( ! $html->next_token() || ! $html->is_tag_closer() || $tag !== $html->get_tag() ) {
					return null;
				}
			}
			return $html->next_token() && $html->is_tag_closer() && 'SUMMARY' === $html->get_tag() && $html->seek( 'wording' ) ? $html : null;
		}
		if ( $html->is_tag_closer() || ! in_array( $type, array( 'A', 'STRONG', 'EM', 'B', 'I', 'MARK', 'SPAN', 'CODE', 'S', 'SUB', 'SUP' ), true ) ) {
			break;
		}
		$inline[] = $type;
	}
	return null;
}

/** Describe the selected location in a review, including a poster’s saved alternative text. */
function describe_block( int $number, array $block, string $content ): array {
	$row = array(
		'block'  => $number,
		'name'   => $block['name'],
		'parent' => $block['parent'],
	);
	if ( isset( $block['attrs']['metadata']['name'] ) ) {
		$row['label'] = $block['attrs']['metadata']['name'];
	}
	if ( in_array( $block['name'], array( 'core/image', 'generateblocks/image', 'generateblocks/media' ), true ) ) {
		$html = new \WP_HTML_Tag_Processor( substr( $content, $block['inner'], $block['end'] - $block['inner'] ) );
		$link = null;
		while ( $html->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			if ( 'A' === $html->get_tag() ) {
				$link = $html->is_tag_closer() ? null : $html->get_attribute( 'href' );
			}
			if ( 'IMG' === $html->get_tag() && ! $html->is_tag_closer() ) {
				$row['alt'] = $html->get_attribute( 'alt' );
				$row['src'] = $html->get_attribute( 'src' );
				if ( is_string( $link ) && '' !== $link ) {
					$row['link'] = $link;
				}
				break;
			}
		}
	}
	if ( 'core/details' === $block['name'] ) {
		$row['editable_summary'] = summary_text( $content, $block )?->get_modifiable_text();
	}
	return $row;
}

function get_blocks( $input ): array|\WP_Error {
	$state = Editorial\state( $input );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	$content = $state['post']->post_content;
	$blocks  = locations( $content );
	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}
	$list     = array();
	$previous = array();
	foreach ( $blocks as $number => $block ) {
		$row   = describe_block( $number, $block, $content );
		$index = $previous[ $block['parent'] ] ?? null;
		if ( 'core/details' === $row['name'] && isset( $list[ $index ]['link'] )
			&& '' === placement_gap( $content, $blocks[ $list[ $index ]['block'] ]['end'], $block['start'] ) ) {
			$row['paired_poster_block']             = $list[ $index ]['block'];
			$list[ $index ]['paired_details_block'] = $number;
		}
		$previous[ $block['parent'] ] = count( $list );
		$list[]                       = $row;
	}
	return array(
		'site_url' => Connector\bound_site(),
		'post'     => $state['review'],
		'blocks'   => $list,
	);
}

/** WordPress can store Classic content between two numbered block delimiters. */
function placement_gap( string $content, int $end, int $start ): string {
	return trim( substr( $content, $end, $start - $end ) );
}

/** Check without running shortcode callbacks. A closing bracket can also come from a later block. */
function contains_shortcode( string $value ): bool {
	return str_contains( $value, '[' ) && ! empty( get_shortcode_tags_in_content( $value . ']' ) );
}

function plain_text( mixed $value, int $limit ): bool {
	return is_string( $value ) && '' !== trim( $value ) && mb_strlen( $value, 'UTF-8' ) <= $limit
		&& ! preg_match( '/[\x00-\x1f\x7f]/', $value ) && wp_check_invalid_utf8( $value ) === $value && ! contains_shortcode( $value );
}

function http_url( mixed $url ): bool {
	return is_string( $url ) && strlen( $url ) <= 2048 && wp_parse_url( $url, PHP_URL_HOST )
		&& in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'https', 'http' ), true ) && sanitize_url( $url ) === $url && ! contains_shortcode( $url );
}

/** Resolve the full-size poster and the responsive files WordPress offers to visitors. */
function poster_attachment( int $id ): array|\WP_Error {
	wp_cache_delete( $id, 'posts' );
	wp_cache_delete( $id, 'post_meta' );
	$post = get_post( $id );
	if ( ! $post || 'attachment' !== $post->post_type || 'inherit' !== $post->post_status || ! wp_attachment_is_image( $id ) || ! current_user_can( 'read_post', $id ) ) {
		return RestTools\error( 'flyer_poster', 'Choose a readable image from this website’s Media Library.' );
	}
	$file = PostFiles\reviewable_upload( $id );
	$src  = wp_get_attachment_url( $id );
	if ( ! $file || ! http_url( $src ) || wp_get_image_mime( $file ) !== $post->post_mime_type ) {
		return RestTools\error( 'flyer_poster', 'WordPress could not verify the uploaded poster image and its URL.' );
	}
	$digest = hash_file( 'sha256', $file );
	if ( false === $digest ) {
		return RestTools\error( 'flyer_poster', 'WordPress could not read the poster for review.' );
	}
	$sources = poster_sources( $id, $src, $file, $digest );
	if ( is_wp_error( $sources ) ) {
		return $sources;
	}
	return array(
		'attachment_id' => $id,
		'src'           => $src,
		'sha256'        => $digest,
		'responsive'    => $sources,
	);
}

/** Map native width candidates to actual files in the same local uploads directory. */
function poster_sources( int $id, string $src, string $file, string $digest ): array|\WP_Error {
	$invalid = RestTools\error( 'flyer_sources', 'WordPress could not verify the poster and its responsive image files in local uploads.' );
	$uploads = wp_get_upload_dir();
	$base    = trailingslashit( $uploads['baseurl'] );
	$root    = realpath( $uploads['basedir'] );
	$path    = rawurldecode( substr( $src, strlen( $base ) ) );
	if ( ! $root || ! str_starts_with( $src, $base ) || strpbrk( $path, "?#\\\0" ) || realpath( $root . '/' . $path ) !== $file ) {
		return $invalid;
	}
	$srcset = wp_get_attachment_image_srcset( $id, 'full' );
	if ( ! $srcset ) {
		return array();
	}
	$candidates = explode( ', ', $srcset );
	if ( count( $candidates ) > 50 ) {
		return $invalid;
	}
	$directory = trailingslashit( dirname( $src ) );
	$bytes     = wp_filesize( $file );
	$sources   = array();
	foreach ( $candidates as $candidate ) {
		if ( ! preg_match( '/\A(\S+) ([1-9][0-9]*)w\z/D', $candidate, $parts ) || ! str_starts_with( $parts[1], $directory ) ) {
			return $invalid;
		}
		$url  = $parts[1];
		$name = rawurldecode( substr( $url, strlen( $directory ) ) );
		if ( '' === $name || basename( $name ) !== $name || strpbrk( $name, "?#\\\0" ) ) {
			return $invalid;
		}
		$path = realpath( dirname( $file ) . '/' . $name );
		if ( ! $path || dirname( $path ) !== dirname( $file ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return $invalid;
		}
		$info   = wp_getimagesize( $path );
		$length = wp_filesize( $path );
		$bytes += $file === $path ? 0 : $length;
		if ( ! $info || $info[0] !== (int) $parts[2] || ! $length || $length > 50 * MB_IN_BYTES || $bytes > 100 * MB_IN_BYTES ) {
			return $invalid;
		}
		$hash = $file === $path ? $digest : hash_file( 'sha256', $path );
		if ( false === $hash ) {
			return $invalid;
		}
		$sources[] = array(
			'src'    => $url,
			'width'  => $info[0],
			'sha256' => $hash,
		);
	}
	return $sources;
}

function poster_markup( array $poster, string $alt, string $url ): string {
	$id    = $poster['attachment_id'];
	$attrs = array(
		'id'              => $id,
		'sizeSlug'        => 'full',
		'linkDestination' => $url === $poster['src'] ? 'media' : 'custom',
	);
	$html  = sprintf(
		'<figure class="wp-block-image size-full"><a href="%s"><img src="%s" alt="%s" class="wp-image-%d"/></a></figure>',
		htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ),
		htmlspecialchars( $poster['src'], ENT_QUOTES, 'UTF-8' ),
		htmlspecialchars( $alt, ENT_QUOTES, 'UTF-8' ),
		$id
	);
	return get_comment_delimited_block_content( 'core/image', $attrs, "\n" . $html . "\n" );
}

/** Read the simple linked image the flyer tool creates, accepting Gutenberg's equivalent HTML serialization. */
function saved_poster( string $source, array $attrs ): ?array {
	$id = $attrs['id'] ?? null;
	if ( ! is_int( $id ) || $id < 1 || 'full' !== ( $attrs['sizeSlug'] ?? null )
		|| ! in_array( $attrs['linkDestination'] ?? null, array( 'media', 'custom' ), true )
		|| array_diff( array_keys( $attrs ), array( 'id', 'sizeSlug', 'linkDestination' ) ) ) {
		return null;
	}
	$block = parse_blocks( $source )[0];
	$html  = new \WP_HTML_Tag_Processor( $block['innerHTML'] );
	if ( $block['innerBlocks'] || 'FIGURE' !== PostFiles\file_token( $html ) || ! PostFiles\file_attributes( $html, array( 'class' ) )
		|| ! PostFiles\file_classes( $html, 'wp-block-image size-full' ) || 'A' !== PostFiles\file_token( $html )
		|| ! PostFiles\file_attributes( $html, array( 'href' ) ) ) {
		return null;
	}
	$url = $html->get_attribute( 'href' );
	if ( ! http_url( $url ) || 'IMG' !== PostFiles\file_token( $html ) || ! PostFiles\file_attributes( $html, array( 'src', 'class', 'alt' ) )
		|| ! PostFiles\file_classes( $html, 'wp-image-' . $id ) ) {
		return null;
	}
	$src = $html->get_attribute( 'src' );
	$alt = $html->get_attribute( 'alt' );
	if ( ! http_url( $src ) || ! is_string( $alt ) || ( 'media' === $attrs['linkDestination'] && $src !== $url )
		|| '/A' !== PostFiles\file_token( $html ) || '/FIGURE' !== PostFiles\file_token( $html ) || null !== PostFiles\file_token( $html ) ) {
		return null;
	}
	return array(
		'attachment_id' => $id,
		'src'           => $src,
		'alt'           => $alt,
		'destination'   => $url,
	);
}

/** Correct only a listed plain core/image immediately followed by its sibling core/details. */
function plan_poster( array $state, array $input ): array|\WP_Error {
	$content = $state['post']->post_content;
	$review  = $state['review'];
	$blocks  = locations( $content );
	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}
	$number = $input['block'] ?? null;
	$image  = is_int( $number ) ? ( $blocks[ $number ] ?? null ) : null;
	$next   = $image ? ( $blocks[ $number + 1 ] ?? null ) : null;
	if ( 'future' === $review['status'] || 'core/image' !== ( $image['name'] ?? null ) || 'core/details' !== ( $next['name'] ?? null )
		|| $image['parent'] !== $next['parent'] || '' !== trim( substr( $content, $image['end'], $next['start'] - $image['end'] ) ) ) {
		return RestTools\error( 'flyer_target', 'Choose a plain linked Image followed immediately by a sibling Details block on a draft or published item.' );
	}
	$before = saved_poster( substr( $content, $image['start'], $image['end'] - $image['start'] ), $image['attrs'] ?? array() );
	if ( ! $before ) {
		return RestTools\error( 'flyer_target', 'Edit this poster in WordPress to preserve its existing block settings or markup.' );
	}
	$id  = array_key_exists( 'poster_id', $input ) ? $input['poster_id'] : $before['attachment_id'];
	$alt = array_key_exists( 'poster_alt', $input ) ? $input['poster_alt'] : $before['alt'];
	if ( ! is_int( $id ) || $id < 1 || ! plain_text( $alt, 500 ) || ( array_key_exists( 'poster_url', $input ) && ! http_url( $input['poster_url'] ) ) ) {
		return RestTools\error( 'flyer_input', 'Choose a Media Library image, descriptive poster link text and an absolute HTTP(S) destination.' );
	}
	$poster = poster_attachment( $id );
	if ( is_wp_error( $poster ) ) {
		return $poster;
	}
	if ( ! array_key_exists( 'poster_id', $input ) && $poster['src'] !== $before['src'] ) {
		return RestTools\error( 'flyer_poster', 'The saved poster URL differs from the media file. Choose the intended attachment ID explicitly.' );
	}
	$url = $input['poster_url'] ?? ( 'media' === $image['attrs']['linkDestination'] ? $poster['src'] : $before['destination'] );
	if ( $before['attachment_id'] === $id && $before['src'] === $poster['src'] && $before['alt'] === $alt && $before['destination'] === $url ) {
		return RestTools\error( 'unchanged', 'The selected poster already has these values.' );
	}
	$updated = substr_replace( $content, poster_markup( $poster, $alt, $url ), $image['start'], $image['end'] - $image['start'] );
	if ( strlen( $updated ) > MB_IN_BYTES ) {
		return RestTools\error( 'post_size', 'The proposed content exceeds the 1 MiB review limit.' );
	}
	$review['change'] = array(
		'block'   => $number,
		'details' => describe_block( $number + 1, $next, $content ),
		'before'  => $before,
		'after'   => $poster + array(
			'alt'         => $alt,
			'destination' => $url,
		),
	);
	return array(
		'review'   => $review,
		'body'     => array( 'content' => $updated ),
		'expected' => array( 'post_content' => $updated ),
		'poster'   => $poster,
	);
}

function create_markup( string $summary, mixed $children ): string|\WP_Error {
	if ( ! is_array( $children ) || ! array_is_list( $children ) || ! $children || count( $children ) > 50 ) {
		return RestTools\error( 'details_body', 'Supply between 1 and 50 inner blocks.' );
	}
	$body = array();
	foreach ( $children as $child ) {
		if ( ! is_array( $child ) || array_diff( array_keys( $child ), array( 'name', 'text', 'url' ) )
			|| ! in_array( $child['name'] ?? null, array( 'core/paragraph', 'core/heading' ), true ) || ! plain_text( $child['text'] ?? null, 4000 ) ) {
			return RestTools\error( 'details_body', 'Choose a paragraph or heading and supply plain text without registered shortcodes.' );
		}
		$text = htmlspecialchars( $child['text'], ENT_NOQUOTES, 'UTF-8' );
		if ( array_key_exists( 'url', $child ) ) {
			$url = $child['url'];
			if ( ! http_url( $url ) ) {
				return RestTools\error( 'details_link', 'Use an absolute HTTP or HTTPS link without registered shortcodes.' );
			}
			$text = '<a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '">' . $text . '</a>';
		}
		$html   = 'core/heading' === $child['name'] ? '<h2 class="wp-block-heading">' . $text . '</h2>' : '<p>' . $text . '</p>';
		$body[] = get_comment_delimited_block_content( $child['name'], array(), "\n" . $html . "\n" );
	}
	$html = '<details class="wp-block-details"><summary>' . htmlspecialchars( $summary, ENT_NOQUOTES, 'UTF-8' ) . '</summary>' . implode( "\n\n", $body ) . '</details>';
	return get_comment_delimited_block_content( 'core/details', array(), "\n" . $html . "\n" );
}

/** Check explicit GenerateBlocks layout declarations, including responsive overrides. */
function horizontal_styles( array $styles, string $display = '', string $direction = 'row' ): bool {
	$display   = $styles['display'] ?? $display;
	$direction = $styles['flexDirection'] ?? $styles['flex-direction'] ?? $direction;
	if ( in_array( $display, array( 'grid', 'inline-grid' ), true )
		|| ( in_array( $display, array( 'flex', 'inline-flex' ), true ) && 'column' !== $direction ) ) {
		return true;
	}
	foreach ( $styles as $selector => $rules ) {
		if ( is_array( $rules ) && str_starts_with( (string) $selector, '@media' )
			&& horizontal_styles( $rules, (string) $display, (string) $direction ) ) {
			return true;
		}
	}
	return false;
}

/** Resolve the saved container and the post editor's effective insertion restrictions. */
function placement( \WP_Post $post, array $blocks, int $after, array $types, array $children ): array|\WP_Error {
	$parent  = $blocks[ $after ]['parent'] ?? 0;
	$content = $post->post_content;
	$attrs   = $blocks[ $parent ]['attrs'] ?? array();
	if ( $parent && ( ! in_array( $blocks[ $parent ]['name'], array( 'core/group', 'core/column', 'core/details', 'generateblocks/element', 'generateblocks/container' ), true )
		|| ( isset( $attrs['allowedBlocks'] ) && array_diff( $types, (array) $attrs['allowedBlocks'] ) ) ) ) {
		return RestTools\error( 'details_parent', 'Choose a sibling in a container that allows the requested blocks.' );
	}

	$post_type = get_post_type_object( $post->post_type );
	$template  = array();
	if ( ! empty( $post_type->template ) ) {
		$template['template']     = $post_type->template;
		$template['templateLock'] = $post_type->template_lock;
	}
	// The editor asset registries may not have been initialised on a native MCP request.
	wp_styles();
	wp_scripts();
	$settings = get_block_editor_settings( $template, new \WP_Block_Editor_Context( array( 'post' => $post ) ) );
	$allowed  = $settings['allowedBlockTypes'];
	if ( true !== $allowed && ( ! is_array( $allowed ) || array_diff( array_merge( $types, array_column( $children, 'name' ) ), $allowed ) ) ) {
		return RestTools\error( 'details_parent', 'This post editor restricts one or more of the requested block types.' );
	}
	$lock      = $settings['templateLock'] ?? false;
	$ancestors = array();
	for ( $number = $parent; $number; $number = $blocks[ $number ]['parent'] ) {
		$ancestors[] = $number;
	}
	foreach ( array_reverse( $ancestors ) as $number ) {
		$block = $blocks[ $number ];
		// Explicit false may unlock a nested template, except under a content-only lock.
		if ( 'contentOnly' !== $lock ) {
			$lock = $block['attrs']['templateLock'] ?? $lock;
		}
		$html = new \WP_HTML_Tag_Processor( substr( $content, $block['inner'], $block['end'] - $block['inner'] ) );
		$tag  = PostFiles\file_token( $html );
		if ( in_array( $tag, array( 'A', 'BUTTON', 'LABEL', 'SUMMARY' ), true ) || in_array( $html->get_attribute( 'role' ), array( 'link', 'button' ), true ) ) {
			return RestTools\error( 'details_parent', sprintf( 'Block %d is an interactive wrapper. Select block %d to place the new content after it.', $number, $number ) );
		}
		if ( $number === $parent && ! in_array( $tag, array( 'DIV', 'SECTION', 'ARTICLE', 'ASIDE', 'NAV', 'MAIN', 'HEADER', 'FOOTER', 'DETAILS' ), true ) ) {
			return RestTools\error( 'details_parent', 'Choose a container with a wrapper that accepts flow content.' );
		}
	}
	if ( $lock ) {
		return RestTools\error( 'details_parent', 'The WordPress editor locks insertion at this position. Choose an unlocked position.' );
	}
	$layout = $attrs['layout'] ?? array();
	if ( 'grid' === ( $layout['type'] ?? '' ) || ( 'flex' === ( $layout['type'] ?? '' ) && 'vertical' !== ( $layout['orientation'] ?? '' ) )
		|| horizontal_styles( (array) ( $attrs['styles'] ?? array() ) ) ) {
		return RestTools\error( 'details_parent', sprintf( 'Block %d lays its children out in a row or grid. Select that block to insert after it, or choose a position inside a vertical group.', $parent ) );
	}

	$left  = $after ? describe_block( $after, $blocks[ $after ], $content ) : 0;
	$right = 0;
	foreach ( $blocks as $number => $block ) {
		if ( $number > $after && $parent === $block['parent'] ) {
			$right = describe_block( $number, $block, $content );
			break;
		}
	}
	if ( $right || 0 === $parent ) {
		$gap = placement_gap( $content, $blocks[ $after ]['end'] ?? 0, $right ? $blocks[ $right['block'] ]['start'] : strlen( $content ) );
		if ( '' !== $gap ) {
			$right = array(
				'name'   => 'core/freeform',
				'parent' => $parent,
				'label'  => wp_html_excerpt( $gap, 120, '…' ),
			);
		}
	}
	if ( isset( $left['link'] ) && 'core/details' === ( $right['name'] ?? null ) ) {
		return RestTools\error( 'details_pair', sprintf( 'Linked poster %d and Details block %d are an existing pair. Select block %d to insert after the pair.', $after, $right['block'], $right['block'] ) );
	}
	return array(
		'after_block'  => $left,
		'before_block' => $right,
		'parent'       => $parent,
	);
}

function plan( string $operation, array $state, array $input ): array|\WP_Error {
	if ( 'edit_flyer_poster' === $operation ) {
		return plan_poster( $state, $input );
	}
	$content = $state['post']->post_content;
	$review  = $state['review'];
	$summary = $input['summary'] ?? null;
	if ( ! plain_text( $summary, 300 ) || 'future' === $review['status'] ) {
		return RestTools\error( 'details_input', 'Choose a draft or published item and supply a single-line summary of 1 to 300 characters without registered shortcodes.' );
	}
	$blocks = locations( $content );
	if ( is_wp_error( $blocks ) ) {
		return $blocks;
	}
	$flyer = 'create_flyer' === $operation;
	if ( $flyer || 'create_details' === $operation ) {
		$after = $input['after_block'] ?? null;
		if ( ! is_int( $after ) || ( 0 !== $after && ! isset( $blocks[ $after ] ) ) ) {
			return RestTools\error( 'details_target', 'Select a listed block number or 0 for the start of the document.' );
		}
		$types  = $flyer ? array( 'core/image', 'core/details' ) : array( 'core/details' );
		$markup = create_markup( $summary, $input['inner_blocks'] ?? null );
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}
		$placement = placement( $state['post'], $blocks, $after, $types, $input['inner_blocks'] );
		if ( is_wp_error( $placement ) ) {
			return $placement;
		}
		if ( count( $blocks ) + count( $types ) + count( $input['inner_blocks'] ) > 1000 ) {
			return RestTools\error( 'post_blocks', 'The proposed page exceeds the 1000 block selection limit.' );
		}
		if ( $flyer ) {
			if ( ! is_int( $input['poster_id'] ?? null ) || $input['poster_id'] < 1 || ! plain_text( $input['poster_alt'] ?? null, 500 ) || ! http_url( $input['poster_url'] ?? null ) ) {
				return RestTools\error( 'flyer_input', 'Choose an uploaded image, meaningful link alternative text and an absolute HTTP(S) destination. Use wording and URLs without registered shortcodes.' );
			}
			$poster = poster_attachment( $input['poster_id'] );
			if ( is_wp_error( $poster ) ) {
				return $poster;
			}
			$markup = poster_markup( $poster, $input['poster_alt'], $input['poster_url'] ) . "\n\n" . $markup;
		}
		$offset  = $blocks[ $after ]['end'] ?? 0;
		$insert  = $after ? "\n\n" . $markup : $markup . ( '' === $content ? '' : "\n\n" );
		$updated = substr_replace( $content, $insert, $offset, 0 );
		$change  = $placement + array(
			'summary'      => $summary,
			'inner_blocks' => $input['inner_blocks'],
		);
		if ( $flyer ) {
			$change['poster'] = $poster + array(
				'alt'         => $input['poster_alt'],
				'destination' => $input['poster_url'],
			);
		}
	} else {
		$number = $input['block'] ?? null;
		$block  = is_int( $number ) ? ( $blocks[ $number ] ?? null ) : null;
		$html   = $block ? summary_text( $content, $block ) : null;
		if ( ! $html ) {
			return RestTools\error( 'details_target', 'Select an editable Details summary from the saved block list. Mixed formatting or bound summaries need the WordPress editor.' );
		}
		$before = $html->get_modifiable_text();
		if ( $before === $summary || ! $html->set_modifiable_text( $summary ) ) {
			return RestTools\error( 'details_summary', 'The selected summary has no applicable wording change.' );
		}
		$updated = substr_replace( $content, $html->get_updated_html(), $block['inner'], $block['end'] - $block['inner'] );
		$change  = array(
			'block'  => $number,
			'before' => $before,
			'after'  => $summary,
		);
	}
	if ( strlen( $updated ) > 1024 * 1024 ) {
		return RestTools\error( 'post_size', 'The proposed content exceeds the 1 MiB review limit.' );
	}
	$review['change'] = $change;
	return array(
		'review'   => $review,
		'body'     => array( 'content' => $updated ),
		'expected' => array( 'post_content' => $updated ),
	) + ( isset( $poster ) ? array( 'poster' => $poster ) : array() );
}
