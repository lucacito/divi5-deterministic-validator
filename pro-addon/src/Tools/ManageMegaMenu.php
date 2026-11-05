<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\MegaMenu\EditedPanelsException;
use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuService;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turn the global header's menu into a mega menu, or look at its state: the same service the Mega menu admin tab uses, so a change
 * made here and one made there are the same change. Every write goes through the layout checker, keeps an undo point and is
 * recorded as one batch that undo_batch reverses (MegaMenuService writes through LayoutWriter). MCP and REST only: the ChatGPT
 * importer's 30 operations are used up. Editing the content of a panel (headline, text, button, picture) stays in the admin tab.
 */
final class ManageMegaMenu implements ProTool
{
    public const NAME    = 'manage_mega_menu';
    public const ACTIONS = [ 'status', 'apply', 'update', 'remove' ];
    public const EDITED  = [ 'keep', 'rebuild' ];

    public function __construct( private MegaMenuService $service )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Make the global header\'s menu a mega menu built from Divi modules. status (read-only) lists 14 layouts and menu items; apply sets a layout (item_id: one item); update rebuilds from the menu; remove restores it. Checked, undoable with undo_batch; dry_run previews. Needs the Theme Builder permission.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'action'  => [ 'type' => 'string', 'enum' => self::ACTIONS, 'description' => 'status, apply, update or remove' ],
                    'layout'  => [ 'type' => 'string', 'enum' => Layouts::ids(), 'description' => 'Layout id for apply (see status.layouts)' ],
                    'item_id' => [ 'type' => 'integer', 'description' => 'apply only: the menu item id to give this layout (a mega menu must already be applied)' ],
                    'edited'  => [ 'type' => 'string', 'enum' => self::EDITED, 'description' => 'When a panel was changed in the Divi builder: keep your changes or rebuild it. Without it a real run stops and asks.' ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'action' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $action = (string) Args::string( $arguments, 'action', true, 20 );
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            throw new \InvalidArgumentException( '"action" must be one of: ' . esc_html( implode( ', ', self::ACTIONS ) ) . '.' );
        }
        // The tool-level gate, in addition to the registry's WordPress capability: the global header is a Theme Builder layout.
        LayoutTarget::assertMay();

        if ( 'status' === $action ) {
            return $this->status();
        }
        $dry    = Args::bool( $arguments, 'dry_run' );
        $edited = array_key_exists( 'edited', $arguments ) && null !== $arguments['edited'] ? (string) Args::string( $arguments, 'edited', true, 10 ) : null;
        if ( null !== $edited && ! in_array( $edited, self::EDITED, true ) ) {
            throw new \InvalidArgumentException( '"edited" must be one of: ' . esc_html( implode( ', ', self::EDITED ) ) . '.' );
        }

        try {
            return match ( $action ) {
                'apply'  => $this->apply( $arguments, $dry, $edited ),
                'update' => $this->service->refresh( $dry, $edited ),
                default  => $this->service->remove( $dry ),
            };
        } catch ( EditedPanelsException $e ) {
            // A real run does not guess whether the owner's hand edits may be thrown away: it says so and waits for the choice.
            // The names are menu item titles from the site: escaped, because this text may be shown anywhere a client chooses.
            throw new \InvalidArgumentException( 'You changed the panel of ' . esc_html( implode( ', ', array_values( $e->panels ) ) ) . ' in the Divi builder. Call again with edited="keep" to keep your changes or edited="rebuild" to replace them (a dry run shows the difference).' );
        }
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function apply( array $arguments, bool $dry, ?string $edited ): array
    {
        $layout = Args::string( $arguments, 'layout', false, 60 );
        if ( null === $layout || '' === $layout ) {
            throw new \InvalidArgumentException( '"layout" is required for apply. Call with action "status" to see the layout ids.' );
        }
        $item = array_key_exists( 'item_id', $arguments ) && null !== $arguments['item_id'] ? Args::int( $arguments, 'item_id' ) : null;

        return $this->service->apply( $layout, $dry, $item, $edited );
    }

    /** @return array<string,mixed> */
    private function status(): array
    {
        $s     = $this->service->status();
        $items = [];
        foreach ( (array) $s['items'] as $i ) {
            $items[] = [ 'id' => $i['id'], 'title' => $i['title'], 'layout' => $i['layout'], 'layout_name' => $i['layoutName'], 'edited_in_divi' => $i['edited'] ];
        }
        $layouts = [];
        foreach ( Layouts::all() as $l ) {
            $layouts[] = [ 'id' => $l['id'], 'name' => $l['name'], 'family' => $l['family'], 'tone' => $l['tone'], 'description' => $l['blurb'], 'best_for' => $l['hint'], 'uses_own_content' => (bool) $l['content'], 'uses_posts' => Layouts::needsPosts( $l ) ];
        }

        return [
            'header_found'    => null !== $s['header'],
            'has_menu_module' => $s['menuModule'],
            'applied'         => $s['applied'],
            'menu'            => $s['menu'],
            'menus'           => $s['menus'],
            'items'           => $items,
            'out_of_date'     => null !== ( $s['drift'] ?? null ),
            'layouts'         => $layouts,
            'note'            => null === $s['header'] ? MegaMenuService::NO_HEADER : 'Read-only. Use apply, update or remove to change the header (add dry_run to preview). Own content for a panel (headline, text, button, picture) is edited in the Mega menu tab.',
        ];
    }
}
