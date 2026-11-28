<?php
/** Fixed GeneratePress AI tools for editorial work, navigation and Element review. */
declare(strict_types=1);

namespace ChurchWebsiteConnector\EditorialAbilities;

use ChurchWebsiteConnector as Connector;

defined( 'ABSPATH' ) || exit;

function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) || ! Connector\feature_enabled( 'rest_tools' ) || ! Connector\feature_enabled( 'generatepress_bridge' ) ) {
		return;
	}
	$site   = array(
		'site_url' => array(
			'type' => 'string',
			'enum' => array( Connector\bound_site() ),
		),
	);
	$id     = array(
		'type'    => 'integer',
		'minimum' => 1,
	);
	$index  = array(
		'type'    => 'integer',
		'minimum' => 0,
	);
	$token  = array(
		'type'    => 'string',
		'pattern' => '^[a-f0-9]{64}$',
	);
	$text   = array( 'type' => 'string' );
	$date   = $text + array(
		'minLength' => 10,
		'maxLength' => 19,
	);
	$number = array(
		'type'    => 'integer',
		'minimum' => 1,
		'maximum' => 100,
	);
	$kind   = array(
		'type' => 'string',
		'enum' => array( 'post', 'page' ),
	);
	$yes    = array(
		'type'  => 'boolean',
		'const' => true,
	);
	$item   = $site + array( 'id' => $id );
	$target = $item + array( 'kind' => $kind );
	$state  = $target + array( 'state_hash' => $token );
	$apply  = $site + array( 'preview_token' => $token );
	$search = array(
		'query'  => $text + array(
			'minLength' => 1,
			'maxLength' => 120,
		),
		'after'  => $date,
		'before' => $date,
		'page'   => $number,
	);

	$summary = $text + array(
		'minLength' => 1,
		'maxLength' => 300,
	);
	$details = $state + array(
		'after_block'  => $index,
		'summary'      => $summary,
		'inner_blocks' => array(
			'type'     => 'array',
			'minItems' => 1,
			'maxItems' => 50,
			'items'    => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'name', 'text' ),
				'properties'           => array(
					'name' => $text + array( 'enum' => array( 'core/paragraph', 'core/heading' ) ),
					'text' => $text + array(
						'minLength' => 1,
						'maxLength' => 4000,
					),
					'url'  => $text + array( 'maxLength' => 2048 ),
				),
			),
		),
	);
	$poster  = array(
		'poster_id'  => $id,
		'poster_alt' => $text + array(
			'minLength' => 1,
			'maxLength' => 500,
		),
		'poster_url' => $text + array( 'maxLength' => 2048 ),
	);
	$tools   = array(
		'get-details-blocks'           => array(
			'Find block locations for a Details edit',
			'List saved blocks in document order, including parents, linked posters and their paired Details blocks, editable summaries and the current state_hash. Use these numbers to select the intended block.',
			'PostDetails\get_blocks',
			$target,
		),
		'preview-details-create'       => array(
			'Preview a new collapsed native Details block',
			'Use the latest get-details-blocks state_hash. Insert after the selected sibling, or use after_block 0 at the start. Supply plain text paragraphs or level-two headings with optional HTTP(S) links. Show the summary, body and both neighbours before apply-post-edit. Registered shortcodes are unsupported.',
			'PostDetails\preview_create',
			$details,
		),
		'preview-flyer-create'         => array(
			'Preview a linked poster and its collapsed Details together',
			'Use the latest get-details-blocks state_hash and after_block (0 for the start). Select a Media Library image by ID. Show the poster and a phone-width image from responsive when available, its alternative text and destination, the summary, all inner blocks and both neighbours. After approval, use apply-post-edit once.',
			'PostDetails\preview_flyer',
			$details + $poster,
		),
		'preview-flyer-poster'         => array(
			'Preview a correction to an existing linked flyer poster',
			'Use the latest get-details-blocks state_hash and the number of a plain linked core Image immediately before its Details. Supply the corrected Media Library image ID, alternative text or destination. Show the before and after values and the proposed poster, including a phone-width image from responsive when available, before apply-post-edit.',
			'PostDetails\preview_poster',
			$state + array( 'block' => $id ),
			$poster,
		),
		'preview-details-summary'      => array(
			'Preview changing one native Details summary',
			'Use the latest get-details-blocks number and state_hash. Replace the selected summary wording, preserving the inner blocks and saved settings. Review the before and after wording before apply-post-edit.',
			'PostDetails\preview_summary',
			$state + array(
				'block'   => $id,
				'summary' => $summary,
			),
		),
		'search-media-library'         => array(
			'Search editable Media Library attachments',
			'Find attachments by title or filename, exact MIME type such as application/pdf, or site-local upload dates. Returns exact IDs and URLs, 20 results per page.',
			'Editorial\search_media',
			$site,
			$search + array( 'mime_type' => $text ),
		),
		'search-editorial-posts'       => array(
			'Find previous or upcoming posts',
			'Find editable posts by category ID, site-local date range, status or search text. Defaults to published. Returns exact post and category IDs for reuse.',
			'Editorial\search_posts',
			$site,
			$search + array(
				'category_id' => $id,
				'status'      => $text + array( 'enum' => array( 'publish', 'draft', 'future', 'pending', 'private' ) ),
			),
		),
		'get-publication-workflow'     => array(
			'Read the publication state of a post or page',
			'Read one editable post or page, its dates and numbered File blocks, scheduled event when present, and state_hash for a reviewed publication or revision operation.',
			'Editorial\get_publication',
			$target,
		),
		'preview-page-publication'     => array(
			'Preview immediate public publication of a draft page',
			'Set kind page and choose a current or past site-local date. Follow wording_review for the complete wording and saved excerpt. Show the website, page, date and File blocks for explicit confirmation before apply-draft-publish.',
			'Editorial\preview_page',
			array_replace( $state, array( 'kind' => $text + array( 'enum' => array( 'page' ) ) ) ) + array( 'date_local' => $date ),
		),
		'preview-publication-schedule' => array(
			'Preview scheduling or rescheduling a post or page',
			'Choose a draft or scheduled post or page and a future site-local date and time. Follow wording_review for the complete wording and saved excerpt. Show the item, old and new dates, timezone and File blocks before approval.',
			'Editorial\preview_schedule',
			$state + array( 'date_local' => $date ),
		),
		'preview-cancel-schedule'      => array(
			'Preview cancelling a publication schedule',
			'Preview returning the exact scheduled post or page to draft and removing the WordPress scheduled publication event. The saved date is retained.',
			'Editorial\preview_cancel',
			$state,
		),
		'apply-publication-schedule'   => array(
			'Apply an explicitly confirmed scheduling change',
			'Schedule, reschedule or cancel only the previewed change. Use after explicit approval. Rechecks the site, permissions, status and time, and verifies the saved WordPress event.',
			'Editorial\apply_schedule',
			$apply + array( 'confirm_schedule_change' => $yes ),
		),
		'compare-post-revision'        => array(
			'Compare one revision with an editable post or page',
			'Read the before and after values of changed title, content or excerpt. Use the exact saved revision ID from GeneratePress revision listing.',
			'Editorial\compare_revision',
			$target + array( 'revision_id' => $id ),
		),
		'preview-revision-restore'     => array(
			'Preview restoring a saved post or page revision',
			'Preview changes to title, content and excerpt from one saved non-autosave revision. Show the changes for explicit confirmation. Publication status and date are preserved.',
			'Editorial\preview_revision',
			$state + array( 'revision_id' => $id ),
		),
		'apply-revision-restore'       => array(
			'Apply an explicitly confirmed revision restore',
			'Restore only the confirmed revision preview. This can change public content. Recheck the exact item and revision, and verify the saved fields.',
			'Editorial\apply_revision',
			$apply + array( 'confirm_restore' => $yes ),
		),
		'get-navigation-menu'          => array(
			'Read an existing classic navigation menu',
			'Read the menu by ID, its assigned locations, exact link IDs, full item order and hierarchy, and state_hash. Use GeneratePress menu listing to find a menu ID.',
			'Navigation\get_menu',
			$item,
		),
		'preview-navigation-menu-edit' => array(
			'Preview a change to one link in an existing menu',
			'Use item_id 0 to add a link with label, destination and placement. For an existing item, supply only changes. Destination is either an absolute HTTP(S) URL or kind and ID of a published post or page. Placement uses parent_id 0 for root and after_item_id 0 for first sibling. Review the full proposed menu.',
			'Navigation\preview',
			$item + array(
				'state_hash' => $token,
				'item_id'    => $index,
				'changes'    => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'label'       => $text + array(
							'minLength' => 1,
							'maxLength' => 180,
						),
						'destination' => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'url'  => $text + array( 'maxLength' => 2048 ),
								'kind' => $kind,
								'id'   => $id,
							),
						),
						'placement'   => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'parent_id', 'after_item_id' ),
							'properties'           => array(
								'parent_id'     => $index,
								'after_item_id' => $index,
							),
						),
					),
				),
			),
		),
		'apply-navigation-menu-edit'   => array(
			'Apply an explicitly confirmed navigation edit',
			'Apply only the confirmed edit to one link in an existing menu. This can change public navigation. Verify the resulting item order, hierarchy, label and destination.',
			'Navigation\apply',
			$apply + array( 'confirm_menu_edit' => $yes ),
		),
		'list-element-display-rules'   => array(
			'Inspect GeneratePress Premium Element display rules',
			'Read published and draft classic Elements, their locations, exclusions, and audiences. Use this to inspect headers, footers, banners, hooks and layouts.',
			'Elements\list_elements',
			$site,
			array( 'page' => $number ),
		),
	);
	foreach ( $tools as $name => $definition ) {
		list( $label, $description, $callback, $required ) = $definition;
		$namespace = 'ChurchWebsiteConnector\\';
		$readonly  = ! str_starts_with( $name, 'preview-' ) && ! str_starts_with( $name, 'apply-' );
		wp_register_ability(
			'church-connector/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'church-connector',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $required + ( $definition[4] ?? array() ),
					'required'             => array_keys( $required ),
					'additionalProperties' => false,
				),
				'permission_callback' => $namespace . match ( true ) {
					str_starts_with( $callback, 'Navigation\\' ) => 'Navigation\\permission',
					str_starts_with( $callback, 'Elements\\' ) => 'Elements\\permission',
					default => 'PostTools\\permission',
				},
				'execute_callback'    => $namespace . $callback,
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

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );
