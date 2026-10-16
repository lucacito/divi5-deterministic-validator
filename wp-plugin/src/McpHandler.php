<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/ViolationReport.php';
require_once __DIR__ . '/PageOutline.php';

// The extension guard is also loaded by autoload.php; requiring it here keeps this file safe on its own.
require_once __DIR__ . '/ExtensionGuard.php';
require_once __DIR__ . '/PageAccess.php';
require_once __DIR__ . '/TitleChange.php';

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * MCP Streamable HTTP transport endpoint.
 *
 * Implements the Model Context Protocol (2024-11-05) over a single
 * POST endpoint. No Node.js or stdio required — runs entirely in PHP.
 *
 * Endpoint: POST /wp-json/ai-editor-divi5/v1/mcp
 * Auth:     Authorization: Bearer {api_key}
 */
final class McpHandler
{
    private const PROTOCOL_VERSION = '2024-11-05';

    public function register_routes(): void
    {
        register_rest_route('ai-editor-divi5/v1', '/mcp', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
    }

    public function authenticate(): bool|WP_Error
    {
        if (ApiKey::authenticateRequest()) {
            // The key is valid, but its user must still be able to edit pages (a demoted or
            // deleted key owner cannot keep using the plugin).
            if (!PageAccess::keyUserAllowed('current_user_can')) {
                return new WP_Error('forbidden', ApiKey::presentsOAuthToken() ? 'The signed-in user cannot edit pages.' : 'The API key belongs to a user who cannot edit pages.', ['status' => 403]);
            }
            return true;
        }

        // An access token that is wrong, expired or revoked is a 401: that is what makes a client such as Claude
        // refresh its token or sign in again. (A wrong API key keeps its published 403 below.)
        if (ApiKey::presentsOAuthToken()) {
            return new WP_Error('invalid_token', 'The access token is invalid or has expired.', ['status' => 401]);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
        if (str_starts_with(strtolower($header), 'bearer ')) {
            return new WP_Error('forbidden', 'Invalid API key.', ['status' => 403]);
        }
        return new WP_Error('unauthorized', 'Authorization: Bearer {api_key} required.', ['status' => 401]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        if (!is_array($body) || ($body['jsonrpc'] ?? '') !== '2.0') {
            return $this->rpcError(null, -32600, 'Invalid Request — expected JSON-RPC 2.0');
        }

        $id = $body['id'] ?? null;
        if (!is_string($body['method'] ?? '')) {
            return $this->rpcError($id, -32600, 'Invalid Request — "method" must be a string');
        }
        $method = (string) ($body['method'] ?? '');
        $params = (array)  ($body['params'] ?? []);

        return match ($method) {
            'initialize'               => $this->onInitialize($id),
            'ping'                     => $this->rpcResult($id, new \stdClass()),
            'tools/list'               => $this->onToolsList($id),
            'tools/call'               => $this->onToolsCall($id, $params),
            default                    => str_starts_with($method, 'notifications/')
                ? $this->accepted()
                : $this->rpcError($id, -32601, "Method not found: {$method}"),
        };
    }

    // ---------------------------------------------------------------
    // Protocol handlers
    // ---------------------------------------------------------------

    private function onInitialize(mixed $id): WP_REST_Response
    {
        return $this->rpcResult($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => ['name' => 'ai-editor-divi5', 'version' => AI_EDITOR_DIVI5_VERSION],
        ]);
    }

    private function onToolsList(mixed $id): WP_REST_Response
    {
        $tools = [
            [
                'name'        => 'list_divi_pages',
                'description' => 'List the WordPress pages (or Divi Projects, with post_type "project") built with the Divi 5 editor that you can edit. Returns IDs, titles, status, post type and links. The IDs work with get_page_layout, update_page_layout, edit_page_content and the history tools.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['post_type' => ['type' => 'string', 'enum' => ['page', 'project'], 'description' => 'Optional. "page" (default) or "project" (Divi Projects).']],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'list_global_styles',
                'description' => 'List this site\'s own Divi 5 module presets, global colors and global variables (read-only), so layouts can reuse them instead of hard-coding colors. Call it before styling. Reference a color by its id (see get_style_guide); a module with no modulePreset already gets the site\'s default preset. Filter presets with "module" (e.g. divi/button). Creating or changing presets and colors is done in Divi\'s Visual Builder, not here.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['module' => ['type' => 'string', 'description' => 'Optional module name to list presets for, e.g. divi/button']],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_site_structure',
                'description' => 'List how this site is set up (read-only): public post types, each with how many items it has (published), taxonomies, image sizes and, when Advanced Custom Fields is active, its field groups, where each group applies (location rules) and its fields (names, labels, types and choices; never field values). Call it before building a page that lists custom post types, uses a given image size or should refer to ACF fields or other custom fields. Pass "include" to get only some sections: post_types, taxonomies, image_sizes, acf, custom_fields, components. The custom_fields section lists the custom field KEYS your recent posts use (a sample, never values); use get_custom_fields to read one post\'s values. The components section lists the site\'s Divi 5.15 components (name, kind, the ids of their Slots, how many pages use each): a component is shared by every page that uses it, so read it before editing a page that holds one.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['include' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['post_types', 'taxonomies', 'image_sizes', 'acf', 'custom_fields', 'components']], 'description' => 'Optional. Sections to return; default all.']],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_custom_fields',
                'description' => 'Read the custom fields (post meta) of ONE page, Divi Project or other post you can edit (read-only): each key with its value and, when Advanced Custom Fields knows the key, its label and type. Keys that start with an underscore, are protected by WordPress or look like secrets (password, token, API key, licence) are never returned; the answer says how many were withheld. Values are cut at 500 characters and at most 100 fields are returned; a key stored several times returns its first value. Use it to write copy that fits the post or to find a key to bind a module to (see get_style_guide). Pass "keys" to read only some. get_site_structure lists the keys a site\'s posts use.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'   => ['type' => 'integer', 'description' => 'WordPress post ID (a page, a Divi Project or another public post type)'],
                        'keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional. Only these keys (at most 100).'],
                    ],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'list_products',
                'description' => 'List and count the WooCommerce products of this site (read-only): totals by status and by type, and one light row per product (id, name, type, status, SKU, current, regular and sale price, on-sale flag, stock status and quantity, categories, link). Use it to answer store questions ("how many products do I have?") and before writing a shop page or product copy, so names, prices and categories are real. Filters: status, type, category (slug), stock_status, on_sale, search, orderby (title, date, price), order, page, per_page (1 to 50, default 25). Needs the edit_products and edit_others_products permissions. Never returns downloadable files, purchase notes, customer or order data, or custom fields. If WooCommerce is not active it says so. Use get_product for one product in full.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'       => ['type' => 'string', 'enum' => ['any', 'publish', 'draft', 'pending', 'private', 'future'], 'description' => 'Optional. Default any (never trash).'],
                        'type'         => ['type' => 'string', 'enum' => ['simple', 'variable', 'grouped', 'external'], 'description' => 'Optional. Product type.'],
                        'category'     => ['type' => 'string', 'description' => 'Optional. Product category slug.'],
                        'stock_status' => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder'], 'description' => 'Optional.'],
                        'on_sale'      => ['type' => 'boolean', 'description' => 'Optional. true = only products on sale, false = only products not on sale.'],
                        'search'       => ['type' => 'string', 'description' => 'Optional. Words in the product name or text (at most 100 characters).'],
                        'orderby'      => ['type' => 'string', 'enum' => ['title', 'date', 'price'], 'description' => 'Optional. Default title.'],
                        'order'        => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Optional. Default asc.'],
                        'page'         => ['type' => 'integer', 'description' => 'Optional. Default 1.'],
                        'per_page'     => ['type' => 'integer', 'description' => 'Optional. 1 to 50, default 25.'],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_product',
                'description' => 'Read ONE WooCommerce product in full (read-only): its text, price, stock, categories, tags, attributes, images, dimensions, tax and shipping settings and, for a variable product, every variation with its own price, stock and attributes (grouped products list their child ids, external products their link). Never returns downloadable files, the purchase note, custom fields, or the text of a password-protected product. Get ids from list_products. Needs permission to edit that product.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'WooCommerce product ID (not a variation id)']],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'get_page_layout',
                'description' => 'Get the Divi 5 layout (Gutenberg block HTML) for a specific page or Divi Project by ID. A full read of a real page costs 8,000 to 16,000 tokens: pass view "outline" to list every block (path, name, size and the start of its text) for a fraction of that, then view "block" with a path to read just one block\'s exact markup.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id' => ['type' => 'integer', 'description' => 'WordPress page or Divi Project ID'],
                        'view'    => ['type' => 'string', 'enum' => ['full', 'outline', 'block'], 'description' => 'Optional. full (default) = the whole layout; outline = a list of blocks; block = one block (needs path).'],
                        'path'    => ['type' => 'string', 'description' => 'With view "block": the path from the outline, such as "0.1.2".'],
                    ],
                    'required'   => ['page_id'],
                ],
            ],
            [
                'name'        => 'get_style_guide',
                'description' => 'Get the Divi 5 authoring guide: real block structure/nesting rules, required content keys, and the exact styling attribute shapes (backgrounds, gradients, spacing, typography, borders, radius, box-shadow, transform, hover, filters, animation). Call this BEFORE building or restyling a layout so the result is styled and valid, not plain.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'get_site_guide',
                'description' => 'Get the blueprint for building an ENTIRE multi-page website from one brief: how to plan the page set, lock one shared design system across all pages, build each page with create_page (passing a slug), cross-link them, and hand the owner a short list of what to set up in WordPress (front page, menu). Call this when the user asks for a whole site, not a single page.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'get_landing_guide',
                'description' => 'Get the conversion-focused LANDING PAGE blueprint: how to structure a page that sells, not just looks good. Covers the persuasion flow (hero → problem → solution → benefits → social proof → how-it-works → features → FAQ → final CTA), how to adapt the structure to the business type/audience/goal, copywriting rules (benefit-driven headlines, banned generic phrases), and CTA placement strategy. Call this BEFORE building or restyling any single landing/marketing page so the result is strategically structured to convert — pair it with get_style_guide (styling) and get_section_recipes (section markup).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'get_image_guide',
                'description' => 'Get the IMAGE-INTELLIGENCE guide: how to assign the right visual to each section by role (not random images everywhere), using the Media Library of the site first (list_media_images) and the built-in image pack (a catalogue of real site-local URLs) as the fallback. Covers per-section image rules, aspect-ratio sizing and alt text. Never hotlink or invent an image URL. Call this BEFORE choosing any image src so generated pages look like finished demos, not empty templates.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'get_section_recipes',
                'description' => 'Get a library of complete, validated Divi 5 section recipes (hero, feature grids, split, slider, CTA, footer). With no arguments, returns the catalog of recipe names. Pass {"name":"<recipe>"} to get that section\'s full block markup to copy and fill with the user\'s content. Use these to assemble well-composed pages instead of building sections from scratch.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['name' => ['type' => 'string', 'description' => 'Recipe name from the catalog (omit to list all)']],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'validate_layout',
                'description' => 'Validate a Divi 5 post_content string against the schema without saving. Use this to check edits before calling update_page_layout.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['post_content' => ['type' => 'string', 'description' => 'Divi 5 Gutenberg block HTML']],
                    'required'   => ['post_content'],
                ],
            ],
            [
                'name'        => 'update_page_layout',
                'description' => 'Validate and save a new Divi 5 layout to a page or Divi Project. It is only updated if the layout passes all schema checks — invalid layouts are rejected with a list of violations. For a landing/marketing page, call get_landing_guide first for the conversion structure (persuasion flow, copywriting, CTA placement), get_style_guide for the real styling attribute shapes, get_section_recipes to assemble the page from complete proven section patterns, and get_image_guide to choose a relevant, role-appropriate image for each section — so the result is strategically structured, styled, well-composed, and visually finished, not plain. Never leave an image module without a src: images must come from the Media Library (list_media_images) or the built-in image pack (or an image URL the owner supplied) per get_image_guide — never an invented or hotlinked URL. Pass dry_run=true to preview what would change (a diff plus the checker\'s verdict) without saving anything; an invalid result comes back as a normal preview with valid:false. To change the WordPress page title (what shows in the page list, and usually the browser tab; NOT the h1 inside the layout), pass title; with only title and page_id the layout is left alone. A title change cannot be undone with restore_page_version, so tell the owner the old title (the result has it). In the result, title.after is the exact stored title and the one to trust; page.title is the display title and can differ.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'      => ['type' => 'integer', 'description' => 'WordPress page or Divi Project ID'],
                        'post_content' => ['type' => 'string',  'description' => 'Divi 5 Gutenberg block HTML. Optional when title is given.'],
                        'title'        => ['type' => 'string',  'description' => 'Optional. New WordPress page title: plain text, one line, 1 to 200 characters, no HTML. Changes the page title only, not any heading in the layout.'],
                        'dry_run' => ['type' => 'boolean', 'description' => 'Optional. true = preview only: returns the checker verdict and a diff of what would change, and saves nothing.'],
                    ],
                    'required' => ['page_id'],
                ],
            ],
            [
                'name'        => 'edit_page_content',
                'description' => 'Make a small, surgical edit to an existing page or Divi Project WITHOUT re-sending the whole layout: an exact find-and-replace on its stored content. Use this for changing an email, phone number, link, price, or a line of copy — never rebuild the full page for a tiny change. "find" must match exactly once unless you set expect_count to the number of matches. The result is re-validated before saving; if "find" is empty, not found, or ambiguous, nothing is changed. Pass dry_run=true to preview the change (a diff plus the checker\'s verdict) without saving; an invalid result comes back as a normal preview with valid:false.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'      => ['type' => 'integer', 'description' => 'WordPress page or Divi Project ID'],
                        'find'         => ['type' => 'string',  'description' => 'Exact text to find (verbatim, including any surrounding markup needed to make it unique)'],
                        'replace'      => ['type' => 'string',  'description' => 'Text to replace it with'],
                        'expect_count' => ['type' => 'integer', 'description' => 'Optional: how many matches to replace (must equal the number found). Omit to require a single unique match.'],
                        'dry_run' => ['type' => 'boolean', 'description' => 'Optional. true = preview only: returns the checker verdict and a diff of what would change, and saves nothing.'],
                    ],
                    'required' => ['page_id', 'find', 'replace'],
                ],
            ],
            [
                'name'        => 'list_page_history',
                'description' => 'List the saved previous versions of a page or Divi Project (newest first). Every AI save tries to snapshot the previous content of the page or Project (up to the last 10 per page, fewer for very large pages), so an AI edit can be undone when a snapshot was kept (the save result\'s history.stored says so). Returns id, saved_at, tool, actor, bytes, label for each version (no content). Use restore_page_version to undo.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id' => ['type' => 'integer', 'description' => 'WordPress page or Divi Project ID'],
                    ],
                    'required' => ['page_id'],
                ],
            ],
            [
                'name'        => 'get_page_history_entry',
                'description' => 'Get the full saved content of one previous version of a page or Divi Project (from list_page_history), e.g. to compare it with the current layout before restoring.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'    => ['type' => 'integer', 'description' => 'WordPress page or Divi Project ID'],
                        'version_id' => ['type' => 'integer', 'description' => 'Version id from list_page_history'],
                    ],
                    'required' => ['page_id', 'version_id'],
                ],
            ],
            [
                'name'        => 'restore_page_version',
                'description' => 'Undo: restore a page or Divi Project to a previous saved version. The current content is snapshotted first; the restore itself is undoable when its own history.stored is true. Restore does not block on validation (it returns the person\'s own earlier content); the result reports whether that content passes the validator.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'    => ['type' => 'integer', 'description' => 'WordPress page or Divi Project ID'],
                        'version_id' => ['type' => 'integer', 'description' => 'Version id from list_page_history'],
                    ],
                    'required' => ['page_id', 'version_id'],
                ],
            ],
            [
                'name'        => 'list_media_images',
                'description' => 'List images already in this site\'s Media Library (read-only). Call this BEFORE building a page and use a suitable image\'s "url" in Divi image modules (set the module alt text from the image alt). Filter with "search" (title/alt/filename) and "orientation" (landscape for heroes/cards, square for people). Scans the newest 200 matches (the result has "truncated": true when more exist; narrow with search). Use the built-in image pack (get_image_guide) only when the library has nothing suitable.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'      => ['type' => 'string',  'description' => 'Optional keywords matched against title, alt text and filename'],
                        'orientation' => ['type' => 'string',  'enum' => ['landscape', 'portrait', 'square'], 'description' => 'Optional orientation filter'],
                        'per_page'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50,  'description' => 'Results per page (default 20, max 50)'],
                        'page'        => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Page number (default 1, max 200)'],
                    ],
                ],
            ],
            [
                'name'        => 'create_page',
                'description' => 'Create a new WordPress page with a validated Divi 5 layout. The page is always created as a DRAFT for the site owner to review and publish. For a landing/marketing page, call get_landing_guide first for the conversion structure (persuasion flow, copywriting, CTA placement), get_style_guide for the real styling attribute shapes, get_section_recipes to assemble the page from complete proven section patterns, and get_image_guide to choose a relevant, role-appropriate image for each section — so the page is strategically structured, styled, well-composed, and visually finished, not plain. Never leave an image module without a src: images must come from the Media Library (list_media_images) or the built-in image pack (or an image URL the owner supplied) per get_image_guide — never an invented or hotlinked URL.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'        => ['type' => 'string', 'description' => 'Page title'],
                        'post_content' => ['type' => 'string', 'description' => 'Divi 5 Gutenberg block HTML'],
                        'slug'         => ['type' => 'string', 'description' => 'Optional URL slug (e.g. "about") for predictable cross-linking'],
                    ],
                    'required' => ['title', 'post_content'],
                ],
            ],
            [
                'name'        => 'html_to_divi',
                'description' => 'Convert HTML you already have (a page you or another AI wrote, exported markup) into a valid Divi 5 layout, deterministically: no AI and no network. The structure, all the copy, links and images are converted (sections, rows and columns for card grids, headings, text, buttons, images); scripts, forms, embeds, navigation menus and CSS are never converted and are listed in the report. Pass the HTML as `html` (a document or a fragment, up to 400,000 bytes). By default it only PREVIEWS: you get the layout plus a report (what was converted, what was not, whether every word and link was kept, which images became placeholders). Pass create=true and a title to save it as a new DRAFT page in one step, without sending the layout back. Every image becomes a placeholder from the built-in pack (a third-party address never reaches the layout) unless image_mode is "keep" and the image is already in this site\'s Media Library; replace placeholders with list_media_images afterwards. It does not read colors or fonts from the HTML (styles are not converted yet): style the result with get_style_guide. For a pixel-accurate copy of a live web page use the separate URL-to-Divi converter instead. The layout is checked by the same validator as every other write.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'html'       => ['type' => 'string', 'description' => 'The HTML to convert (a full document or a fragment).'],
                        'create'     => ['type' => 'boolean', 'description' => 'Optional. true = save the converted layout as a new DRAFT page (needs title). Default false = preview only.'],
                        'title'      => ['type' => 'string', 'description' => 'Page title. Required when create is true; the result suggests one from the HTML.'],
                        'slug'       => ['type' => 'string', 'description' => 'Optional URL slug for the new page.'],
                        'image_mode' => ['type' => 'string', 'enum' => ['placeholder', 'keep'], 'description' => 'Optional. placeholder (default): every image becomes a built-in placeholder. keep: images already in this site\'s Media Library are kept.'],
                        'styles'     => ['type' => 'string', 'enum' => ['basic', 'none'], 'description' => 'Optional. Colors, fonts and spacing are not converted yet in either mode; basic only adds a reminder to the report.'],
                    ],
                    'required' => ['html'],
                ],
            ],
        ];

        // Extension point: add-ons may append tool definitions. With no listener this is a no-op.
        $tools = ExtensionGuard::tools( $tools, apply_filters( 'jhmg_aied_mcp_tools', $tools ) );

        return $this->rpcResult($id, ['tools' => $tools]);
    }

    private function onToolsCall(mixed $id, array $params): WP_REST_Response
    {
        $name = PageAccess::stringArg($params, 'name');
        if ($name === null) {
            return $this->rpcError($id, -32602, 'Invalid params — tool "name" must be a string');
        }
        $arguments = (array) ($params['arguments'] ?? []);

        return match ($name) {
            'list_divi_pages'    => $this->toolListPages($id, $arguments),
            'get_style_guide'    => $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => StyleGuide::markdown()]]]),
            'get_site_guide'     => $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => SiteGuide::markdown()]]]),
            'get_landing_guide'  => $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => LandingGuide::markdown()]]]),
            'get_image_guide'    => $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => ImageGuide::markdown()]]]),
            'get_section_recipes' => $this->toolSectionRecipes($id, $arguments),
            'get_page_layout'    => $this->toolGetLayout($id, $arguments),
            'validate_layout'    => $this->toolValidate($id, $arguments),
            'update_page_layout' => $this->toolUpdate($id, $arguments),
            'edit_page_content'  => $this->toolEditContent($id, $arguments),
            'list_page_history'      => $this->toolListHistory($id, $arguments),
            'get_page_history_entry' => $this->toolGetHistoryEntry($id, $arguments),
            'restore_page_version'   => $this->toolRestoreVersion($id, $arguments),
            'list_media_images'  => $this->toolListMedia($id, $arguments),
            'list_global_styles' => $this->toolListGlobalStyles($id, $arguments),
            'get_site_structure' => $this->toolSiteStructure($id, $arguments),
            'get_custom_fields'  => $this->toolGetCustomFields($id, $arguments),
            'list_products'      => $this->toolListProducts($id, $arguments),
            'get_product'        => $this->toolGetProduct($id, $arguments),
            'create_page'        => $this->toolCreatePage($id, $arguments),
            'html_to_divi'       => $this->toolHtmlToDivi($id, $arguments),
            default              => $this->extensionCall($id, $name, $arguments),
        };
    }

    /**
     * Add-ons claim tool calls via the jhmg_aied_mcp_call filter. An add-on handler is
     * responsible for its own capability checks and returns a WP_REST_Response
     * (use the same JSON-RPC shapes as the built-in tools); anything else = unknown tool.
     */
    private function extensionCall(mixed $id, string $name, array $arguments): WP_REST_Response
    {
        $response = apply_filters( 'jhmg_aied_mcp_call', null, $name, $arguments, $id );
        if ( $response instanceof WP_REST_Response ) {
            return $response;
        }

        return $this->rpcError($id, -32602, "Unknown tool: {$name}");
    }

    // ---------------------------------------------------------------
    // Tool implementations
    // ---------------------------------------------------------------

    private function toolSectionRecipes(mixed $id, array $args): WP_REST_Response
    {
        $name = PageAccess::stringArg($args, 'name');
        if ($name === null) {
            return $this->rpcError($id, -32602, 'Invalid params — recipe "name" must be a string');
        }
        if ($name !== '') {
            $markup = SectionRecipes::recipe($name);
            $text = $markup ?? ("Unknown recipe '{$name}'.\n\n" . SectionRecipes::catalog());
        } else {
            $text = SectionRecipes::catalog();
        }
        return $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => $text]]]);
    }

    private function toolListPages(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('list_pages', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to list pages.');
        }

        $postType = PageAccess::postTypeArg($args, 'post_type', EditTarget::ALLOWED_TYPES);
        if ($postType === null) {
            return $this->rpcError($id, -32602, 'post_type must be one of: ' . implode(', ', EditTarget::ALLOWED_TYPES) . '.');
        }

        $posts = get_posts([
            'post_type'      => $postType,
            'post_status'    => 'any',
            'posts_per_page' => 100,
            'meta_query'     => [['key' => '_et_pb_use_divi_5', 'value' => 'on']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ]);
        // Only pages or Projects this user can edit (other users' drafts/private ones stay hidden).
        $posts = PageAccess::filterEditable($posts, static fn(int $pid): bool => current_user_can('edit_post', $pid));

        $pages = array_map(fn(\WP_Post $p) => [
            'id'        => $p->ID,
            'title'     => get_the_title($p),
            'status'    => $p->post_status,
            'post_type' => $p->post_type,
            'link'      => get_permalink($p),
        ], $posts);

        UsageTracker::log('list_pages', null, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(['pages' => $pages, 'count' => count($pages)])]],
        ]);
    }

    private function toolGetLayout(mixed $id, array $args): WP_REST_Response
    {
        $pageId = (int) ($args['page_id'] ?? 0);
        $target = EditTarget::resolve($pageId);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            UsageTracker::log('get_layout', $pageId ?: null, 'error');
            return $this->rpcError($id, -32602, "Page or Project {$pageId} not found.");
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            UsageTracker::log('get_layout', $pageId, 'error');
            return $this->rpcError($id, -32602, "You do not have permission to read page or Project {$pageId}.");
        }
        $post = $target['post'];

        $view = PageOutline::parseView($args['view'] ?? null, $args['path'] ?? null);
        if ($view['error'] !== null) {
            UsageTracker::log('get_layout', $pageId, 'error');
            return $this->rpcError($id, -32602, (string) $view['error']);
        }

        UsageTracker::log('get_layout', $pageId, 'read');

        $meta = [
            'post_id'     => $post->ID,
            'post_type'   => $post->post_type,
            'post_title'  => get_the_title($post),
            'post_status' => $post->post_status,
        ];
        if ($view['view'] !== 'full') {
            $r = PageOutline::respond($meta, (string) $post->post_content, $view);

            return $r['status'] === 200
                ? $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => json_encode($r['body'])]]])
                : $this->rpcError($id, -32602, (string) $r['body']['error']);
        }

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode($meta + ['post_content' => $post->post_content])]],
        ]);
    }

    private function toolValidate(mixed $id, array $args): WP_REST_Response
    {
        $content = PageAccess::stringArg($args, 'post_content');
        if ($content === null) {
            return $this->rpcError($id, -32602, 'post_content must be a string.');
        }
        if ($content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        $result = LayoutGate::check($content);
        UsageTracker::log('validate', null, $result->isValid() ? 'read' : 'invalid', count($result->violations()));

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach(['valid' => $result->isValid(), 'violations' => ViolationReport::compact($result->violations())], $content))]],
        ]);
    }

    private function toolUpdate(mixed $id, array $args): WP_REST_Response
    {
        $pageId     = (int) ($args['page_id'] ?? 0);
        $hasContent = array_key_exists('post_content', $args) && $args['post_content'] !== null;
        $content    = PageAccess::stringArg($args, 'post_content');
        $dryRun     = PageAccess::boolArg($args, 'dry_run');

        if ($content === null) {
            return $this->rpcError($id, -32602, 'post_content must be a string.');
        }

        if ($dryRun === null) {
            return $this->rpcError($id, -32602, 'dry_run must be a boolean.');
        }

        $target = EditTarget::resolve($pageId);
        if ($target['error'] === EditTarget::NOT_FOUND) {
            return $this->rpcError($id, -32602, "Page or Project {$pageId} not found.");
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            return $this->rpcError($id, -32602, "You do not have permission to edit page or Project {$pageId}.");
        }
        $post = $target['post'];

        // As in REST, the page is looked up first: a bad title on a missing or forbidden page answers "not found" / "no permission".
        $titleArg = PageTitle::parse($args);
        if (!$titleArg['ok']) {
            return $this->rpcError($id, -32602, (string) $titleArg['error']);
        }

        if (!$hasContent && !$titleArg['present']) {
            return $this->rpcError($id, -32602, 'Give post_content, title, or both.');
        }

        if ($hasContent && $content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        // The title is only written when it differs from the stored one (a repeat is reported, never re-saved).
        $plan = TitleChange::plan((string) $post->post_title, (string) $post->post_content, $hasContent ? $content : null, $titleArg);

        if (!$hasContent) {
            return $this->toolUpdateTitleOnly($id, $pageId, $plan, $dryRun);
        }

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $pageId, UsageTracker::RESULT_READ);

            $preview = DryRun::preview((string) $post->post_content, $content, $plan['report']);
            ApprovalMode::recordPreview($pageId, $plan['subject'], (bool) $preview['valid'], $plan['base']);

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach($preview, $content, (string) $post->post_content))]],
            ]);
        }

        $result = LayoutGate::check($content, (string) $post->post_content);

        if (!$result->isValid()) {
            UsageTracker::log('update_layout', $pageId, 'invalid', count($result->violations()));
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'saved'      => false,
                    'valid'      => false,
                    'violations' => ViolationReport::compact($result->violations()),
                ])]],
                'isError' => true,
            ]);
        }

        if (!ApprovalMode::consume($pageId, $plan['subject'], $plan['base'])) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(ApprovalMode::refusal())]],
                'isError' => true,
            ]);
        }

        // Snapshots the previous content, then saves (wp_slash is applied inside HistoryService).
        $previousContent = (string) $post->post_content;
        $write = HistoryService::write($pageId, $content, 'update_page_layout', $plan['writeTitle']);

        if (!$write['ok']) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcError($id, -32603, (string) $write['message']);
        }

        UsageTracker::log('update_layout', $pageId, 'valid');

        $out = [
            'saved'   => true,
            'valid'   => true,
            'history' => $write['snapshot'],
            'page'    => ['id' => $pageId, 'title' => get_the_title($pageId)],
        ];
        if ($plan['report'] !== null) {
            $out['title'] = $plan['changed']
                ? PageTitle::report((string) $plan['report']['before'], (string) ($write['title_after'] ?? $plan['report']['after']), true)
                : $plan['report'];
            $out['message'] = $plan['message'];
        }

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach($out, $content, $previousContent))]],
        ]);
    }

    /**
     * update_page_layout with a title and no post_content: only the page title is touched. The layout is neither
     * checked nor snapshotted (nothing about it changes), and the call needs the same approval-mode preview as any save.
     *
     * @param array{present:bool, changed:bool, writeTitle:?string, report:?array<string,mixed>, subject:string, base:string, message:?string} $plan TitleChange::plan()
     */
    private function toolUpdateTitleOnly(mixed $id, int $pageId, array $plan, bool $dryRun): WP_REST_Response
    {
        $report      = (array) $plan['report'];
        $titleBefore = (string) $report['before'];

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $pageId, UsageTracker::RESULT_READ);

            $preview = DryRun::previewTitle($report);
            if ($plan['changed']) {
                ApprovalMode::recordPreview($pageId, $plan['subject'], true, $plan['base']);
            } else {
                $preview['message'] = $plan['message'];
            }

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode($preview)]],
            ]);
        }

        if (!$plan['changed']) {
            UsageTracker::log('update_layout', $pageId, UsageTracker::RESULT_READ); // nothing was saved

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'saved'          => false,
                    'valid'          => true,
                    'layout_changed' => false,
                    'title'          => $report,
                    'page'           => ['id' => $pageId, 'title' => get_the_title($pageId)],
                    'message'        => $plan['message'],
                ])]],
            ]);
        }

        if (!ApprovalMode::consume($pageId, $plan['subject'], $plan['base'])) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(ApprovalMode::refusal())]],
                'isError' => true,
            ]);
        }

        $write = HistoryService::writeTitle($pageId, (string) $plan['writeTitle'], 'update_page_layout');
        if (!$write['ok']) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcError($id, -32603, (string) $write['message']);
        }

        UsageTracker::log('update_layout', $pageId, 'valid');

        $layoutChanged = (bool) ($write['layout_changed'] ?? false);

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode([
                'saved'          => true,
                'valid'          => true,
                'layout_changed' => $layoutChanged,
                'history'        => $write['snapshot'],
                'title'          => PageTitle::report($titleBefore, (string) ($write['title_after'] ?? $plan['writeTitle']), true),
                'page'           => ['id' => $pageId, 'title' => get_the_title($pageId)],
                'message'        => $plan['message'] . ($layoutChanged ? ' ' . PageTitle::contentTouchedNote() : ''),
            ])]],
        ]);
    }

    private function toolEditContent(mixed $id, array $args): WP_REST_Response
    {
        $pageId  = (int) ($args['page_id'] ?? 0);
        $find    = PageAccess::stringArg($args, 'find');
        $replace = PageAccess::stringArg($args, 'replace');
        if ($find === null || $replace === null) {
            return $this->rpcError($id, -32602, 'find and replace must be strings.');
        }
        $expect  = (array_key_exists('expect_count', $args) && $args['expect_count'] !== null)
            ? (int) $args['expect_count']
            : null;
        $dryRun  = PageAccess::boolArg($args, 'dry_run');
        if ($dryRun === null) {
            return $this->rpcError($id, -32602, 'dry_run must be a boolean.');
        }

        $target = EditTarget::resolve($pageId);
        if ($target['error'] === EditTarget::NOT_FOUND) {
            return $this->rpcError($id, -32602, "Page or Project {$pageId} not found.");
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return $this->rpcError($id, -32602, "You do not have permission to edit page or Project {$pageId}.");
        }
        $post = $target['post'];

        $edit = PageEditor::apply($post->post_content, $find, $replace, $expect);

        if (!$edit['ok']) {
            UsageTracker::log('edit_content', $pageId, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'saved'   => false,
                    'matches' => $edit['count'],
                    'message' => $edit['error'],
                ])]],
                'isError' => true,
            ]);
        }

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $pageId, UsageTracker::RESULT_READ);

            $preview = DryRun::preview((string) $post->post_content, $edit['content']);
            ApprovalMode::recordPreview($pageId, $edit['content'], (bool) $preview['valid'], (string) $post->post_content);

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach($preview + ['replaced' => $edit['count']], $edit['content'], (string) $post->post_content))]],
            ]);
        }

        // A find/replace still has to clear the deterministic validator — the
        // same gate as update_page_layout — before anything is written.
        $result = LayoutGate::check($edit['content'], (string) $post->post_content);
        if (!$result->isValid()) {
            UsageTracker::log('edit_content', $pageId, 'invalid', count($result->violations()));
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'saved'      => false,
                    'valid'      => false,
                    'violations' => ViolationReport::compact($result->violations()),
                ])]],
                'isError' => true,
            ]);
        }

        if (!ApprovalMode::consume($pageId, $edit['content'], (string) $post->post_content)) {
            UsageTracker::log('edit_content', $pageId, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(ApprovalMode::refusal())]],
                'isError' => true,
            ]);
        }

        $previousContent = (string) $post->post_content;
        $write = HistoryService::write($pageId, $edit['content'], 'edit_page_content');
        if (!$write['ok']) {
            UsageTracker::log('edit_content', $pageId, 'error');
            return $this->rpcError($id, -32603, (string) $write['message']);
        }

        UsageTracker::log('edit_content', $pageId, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach([
                'saved'    => true,
                'valid'    => true,
                'replaced' => $edit['count'],
                'history'  => $write['snapshot'],
                'page'     => ['id' => $pageId, 'title' => get_the_title($pageId)],
            ], $edit['content'], $previousContent))]],
        ]);
    }

    /** Resolves a page the caller may edit, or returns an rpcError response. */
    private function historyPage(mixed $id, array $args): \WP_Post|WP_REST_Response
    {
        $pageId = (int) ($args['page_id'] ?? 0);
        $target = EditTarget::resolve($pageId);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return $this->rpcError($id, -32602, "Page or Project {$pageId} not found.");
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return $this->rpcError($id, -32602, "You do not have permission to access page or Project {$pageId}.");
        }

        return $target['post'];
    }

    private function toolListHistory(mixed $id, array $args): WP_REST_Response
    {
        $post = $this->historyPage($id, $args);
        if ($post instanceof WP_REST_Response) {
            return $post;
        }
        UsageTracker::log('list_history', $post->ID, 'read');
        $versions = HistoryService::listFor($post->ID);

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(['page_id' => $post->ID, 'versions' => $versions, 'count' => count($versions)])]],
        ]);
    }

    private function toolGetHistoryEntry(mixed $id, array $args): WP_REST_Response
    {
        $post = $this->historyPage($id, $args);
        if ($post instanceof WP_REST_Response) {
            return $post;
        }
        $versionId = (int) ($args['version_id'] ?? 0);
        $entry     = HistoryService::entry($post->ID, $versionId);
        if ($entry === null) {
            UsageTracker::log('get_history', $post->ID, 'error');
            return $this->rpcError($id, -32602, "Version {$versionId} not found for page or Project {$post->ID}.");
        }
        UsageTracker::log('get_history', $post->ID, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(['page_id' => $post->ID, 'version' => array_diff_key($entry, ['content' => 1]), 'post_content' => $entry['content']])]],
        ]);
    }

    private function toolRestoreVersion(mixed $id, array $args): WP_REST_Response
    {
        $post = $this->historyPage($id, $args);
        if ($post instanceof WP_REST_Response) {
            return $post;
        }
        $versionId = (int) ($args['version_id'] ?? 0);
        $result    = HistoryService::restore($post->ID, $versionId);
        if (!$result['ok']) {
            UsageTracker::log('restore_version', $post->ID, 'error');
            return $this->rpcError($id, $result['error'] === 'version_not_found' ? -32602 : -32603, (string) $result['message']);
        }
        UsageTracker::log('restore_version', $post->ID, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode([
                'restored'         => true,
                'restored_version' => $result['restored_version'],
                'history'          => $result['snapshot'],
                'validator'        => $result['validator'],
                'title_restored'   => $result['title_restored'],
                'title_note'       => $result['title_note'],
                'page'             => ['id' => $post->ID, 'title' => get_the_title($post->ID)],
            ])]],
        ]);
    }

    private function toolListGlobalStyles(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('list_global_styles', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read the site styles.');
        }
        $module = PageAccess::stringArg($args, 'module');
        if ($module === null) {
            return $this->rpcError($id, -32602, 'module must be a string.');
        }
        $result = GlobalStyles::catalog($module);
        UsageTracker::log('list_global_styles', null, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }

    private function toolSiteStructure(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('site_structure', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read the site structure.');
        }
        $include = SiteStructure::parseInclude($args['include'] ?? null);
        if ($include === null) {
            return $this->rpcError($id, -32602, 'include must be a list of: ' . implode(', ', SiteStructure::SECTIONS) . '.');
        }
        $result = SiteStructure::catalog($include);
        UsageTracker::log('site_structure', null, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }

    private function toolGetCustomFields(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('custom_fields', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read custom fields.');
        }
        $postId = $args['id'] ?? null;
        if (!is_int($postId) || $postId < 1) {
            return $this->rpcError($id, -32602, 'id must be a positive integer.');
        }
        $only = is_string($args['keys'] ?? null) ? null : CustomFieldReader::parseKeys($args['keys'] ?? null); // MCP takes a list, never the REST comma form
        if ($only === null) {
            return $this->rpcError($id, -32602, 'keys must be a list of at most ' . CustomFieldReader::MAX_KEYS_ARG . ' strings.');
        }
        $target = FieldTarget::resolve($postId);
        if ($target['error'] !== null) {
            // A missing post, a non-public type and a post the user cannot edit all answer the same.
            UsageTracker::log('custom_fields', $postId, 'error');
            return $this->rpcError($id, -32602, "Post {$postId} not found.");
        }
        try {
            $result = CustomFieldReader::read($target['post'], $only);
        } catch (\Throwable) {
            UsageTracker::log('custom_fields', $postId, 'error');
            return $this->rpcError($id, -32603, 'Could not read the custom fields.');
        }
        UsageTracker::log('custom_fields', $postId, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }

    private function toolListProducts(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('list_products', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read products.');
        }

        return $this->productResult($id, 'list_products', null, ProductService::list($args, false, new WooProductGateway(), 'current_user_can'));
    }

    private function toolGetProduct(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('get_product', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read products.');
        }
        $productId = $args['id'] ?? null;
        if (!is_int($productId) || $productId < 1) {
            UsageTracker::log('get_product', null, 'error');
            return $this->rpcError($id, -32602, 'id must be a positive integer.');
        }

        return $this->productResult($id, 'get_product', $productId, ProductService::get($productId, new WooProductGateway(), 'current_user_can'));
    }

    /** @param array<string,mixed> $r a ProductService result */
    private function productResult(mixed $id, string $endpoint, ?int $postId, array $r): WP_REST_Response
    {
        UsageTracker::log($endpoint, $postId, $r['ok'] ? UsageTracker::RESULT_READ : UsageTracker::RESULT_ERROR);
        if (!$r['ok']) {
            return $this->rpcError($id, 'server' === $r['kind'] ? -32603 : -32602, (string) $r['message']);
        }

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($r['data'])]],
        ]);
    }

    private function toolListMedia(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can('upload_files')) {
            UsageTracker::log('list_media', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read the Media Library.');
        }
        $result = MediaService::list($args);
        UsageTracker::log('list_media', null, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }

    private function toolHtmlToDivi(mixed $id, array $args): WP_REST_Response
    {
        $out = HtmlToDiviService::run($args);
        if ($out['status'] === 400 && !isset($out['body']['report'])) {
            // A bad argument: the same JSON-RPC error every other tool uses.
            return $this->rpcError($id, -32602, (string) ($out['body']['error'] ?? 'Invalid arguments.'));
        }
        if ($out['status'] === 403) {
            return $this->rpcError($id, -32602, (string) ($out['body']['error'] ?? 'Not allowed.'));
        }

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($out['body'])]],
            'isError' => $out['status'] >= 400,
        ]);
    }

    private function toolCreatePage(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can('publish_pages')) {
            UsageTracker::log('create_page', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to create pages.');
        }

        $title   = PageAccess::stringArg($args, 'title');
        $content = PageAccess::stringArg($args, 'post_content');
        $slug    = PageAccess::stringArg($args, 'slug');
        if ($title === null || $content === null || $slug === null) {
            return $this->rpcError($id, -32602, 'title, post_content and slug must be strings.');
        }
        // A title is plain text: strip markup before it is stored (many themes print it unescaped).
        $title = trim(sanitize_text_field($title));
        $slug  = sanitize_title($slug);

        if ($title === '') {
            return $this->rpcError($id, -32602, 'title is required.');
        }
        if ($content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        $result = LayoutGate::check($content);

        if (!$result->isValid()) {
            UsageTracker::log('create_page', null, 'invalid', count($result->violations()));
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'created'    => false,
                    'valid'      => false,
                    'violations' => ViolationReport::compact($result->violations()),
                ])]],
                'isError' => true,
            ]);
        }

        $pageId = DraftPage::insert($title, $content, $slug);

        if (is_wp_error($pageId)) {
            UsageTracker::log('create_page', null, 'error');
            return $this->rpcError($id, -32603, $pageId->get_error_message());
        }

        UsageTracker::log('create_page', (int) $pageId, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach([
                'created' => true,
                'valid'   => true,
                'page'    => [
                    'id'     => (int) $pageId,
                    'title'  => $title,
                    'status' => 'draft',
                    'link'   => get_permalink((int) $pageId),
                ],
            ], $content))]],
        ]);
    }

    // ---------------------------------------------------------------
    // JSON-RPC helpers
    // ---------------------------------------------------------------

    private function rpcResult(mixed $id, array|\stdClass $result): WP_REST_Response
    {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200);
    }

    private function rpcError(mixed $id, int $code, string $message): WP_REST_Response
    {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], 200);
    }

    /** The MCP HTTP transport answers a notification with 202 Accepted and no body. */
    private function accepted(): WP_REST_Response
    {
        return new WP_REST_Response(null, 202);
    }
}
