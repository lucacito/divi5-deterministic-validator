<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutCaches;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\WP\HistoryService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The content of a header or footer layout. Like `PostContentKind`, the previous content lives in the free plugin's
 * history and the item records the version to restore; unlike it, undo re-checks that the layout is STILL a live
 * header or footer the user may edit under Divi's Theme Builder bar, and clears Divi's caches after restoring.
 */
final class ThemeLayoutKind implements ItemKind
{
    public const NAME = 'theme_layout_content';

    /**
     * @param (\Closure(int,int):array<string,mixed>)|null $restorer fn(layoutId, versionId): HistoryService::restore()'s result
     * @param (\Closure():void)|null $afterRestore called after a successful restore (default: clear Divi's caches)
     */
    public function __construct( private ?\Closure $restorer = null, private ?\Closure $afterRestore = null )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * The batch item for a write made through `HistoryService::write()`.
     *
     * @param array{stored?:bool, version_id?:?int, reason?:?string} $snapshot that call's `snapshot`
     * @return array<string,mixed>
     */
    public static function itemFor( int $layoutId, string $title, string $kind, string $newContent, array $snapshot ): array
    {
        return Item::make(
            self::NAME,
            (string) $layoutId,
            "Content of the {$kind} \"{$title}\" (#{$layoutId})",
            null,
            $newContent,
            PostContentKind::undoPoint( $snapshot )
        );
    }

    public function current( array $item ): mixed
    {
        $post = get_post( (int) $item['ref'] );

        return $post ? (string) $post->post_content : null;
    }

    /** Undo is never weaker than the write: the tools' own capability AND the live-layout / Theme Builder bar. */
    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_theme_options' ) && null === LayoutTarget::resolve( (int) $item['ref'] )['error'];
    }

    public function restore( array $item ): array
    {
        $layoutId  = (int) $item['ref'];
        $versionId = (int) ( $item['undo']['version_id'] ?? 0 );
        if ( $versionId < 1 ) {
            return [ 'ok' => false, 'reason' => 'no_version' ];
        }
        $restorer = $this->restorer ?? static fn ( int $p, int $v ): array => HistoryService::restore( $p, $v );
        $r        = $restorer( $layoutId, $versionId );
        if ( empty( $r['ok'] ) ) {
            return [ 'ok' => false, 'reason' => (string) ( $r['error'] ?? 'restore_failed' ) ];
        }
        ( $this->afterRestore ?? [ LayoutCaches::class, 'clear' ] )();

        return [
            'ok'      => true,
            'inverse' => Item::make(
                self::NAME,
                (string) $layoutId,
                (string) $item['label'],
                null,
                $this->current( $item ),
                PostContentKind::undoPoint( is_array( $r['snapshot'] ?? null ) ? $r['snapshot'] : [] )
            ),
        ];
    }
}
