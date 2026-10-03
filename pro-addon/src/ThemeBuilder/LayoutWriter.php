<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\ThemeBuilder;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
use AiEditorDivi5\WP\DryRun;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StyleReferences;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one write path for a header or footer: resolve the target, build the new content from the stored content,
 * run the shared gate (validator + no-custom-code policy, with the stored content as the baseline), preview or save
 * through the free history, clear Divi's caches, record one undoable batch. Both layout tools use it, so they cannot
 * behave differently.
 */
final class LayoutWriter
{
    /** The shared gate rejects anything larger; this only bounds the argument before it is read. */
    public const MAX_BYTES = 1000000;

    public const NOTE = 'A header or footer is shown on every page that uses its template. Divi\'s caches were cleared.';

    public const DRY_NOTE = 'A header or footer is shown on every page that uses its template. Nothing was saved.';

    /** @param (\Closure(int,string,string):array<string,mixed>)|null $write fn(layoutId, content, tool): HistoryService::write()'s result */
    public function __construct( private BatchLog $log, private ?\Closure $write = null )
    {
    }

    /**
     * @param \Closure(string):array{content:string, extra:array<string,mixed>} $make builds the new content from the stored content; throws \InvalidArgumentException for a bad request
     * @return array<string,mixed>
     */
    public function apply( int $layoutId, string $tool, \Closure $make, bool $dry ): array
    {
        $target = LayoutTarget::mustResolve( $layoutId );
        $post   = $target['post'];
        $kind   = $target['kind'];
        $title  = (string) $post->post_title;
        $stored = (string) $post->post_content;
        $built  = $make( $stored );
        $new    = $built['content'];
        $extra  = $built['extra'];
        $layout = [ 'layout_id' => $layoutId, 'kind' => $kind, 'title' => $title ];

        if ( $new === $stored ) {
            $same = [ 'changed' => false, 'dry_run' => $dry, 'layout' => $layout, 'message' => 'The layout already holds exactly this content; nothing changed.' ];
            if ( $dry ) {
                // Same verdict fields as the preview of a change, so a client reads one shape.
                $verdict            = LayoutGate::check( $new, $stored );
                $same['valid']      = $verdict->isValid();
                $same['violations'] = array_map( static fn ( $v ) => $v->toArray(), $verdict->violations() );
            }

            return $same + $extra;
        }
        if ( $dry ) {
            // DryRun::preview runs the same gate and returns valid:false as a normal preview; it never writes.
            return StyleReferences::attach( DryRun::preview( $stored, $new ), $new ) + [ 'layout' => $layout, 'note' => self::DRY_NOTE ] + $extra;
        }

        $verdict = LayoutGate::check( $new, $stored );
        if ( ! $verdict->isValid() ) {
            throw new \InvalidArgumentException( self::refusal( $verdict->violations() ) );
        }

        $write  = $this->write ?? static fn ( int $id, string $content, string $t ): array => HistoryService::write( $id, $content, $t );
        $result = $write( $layoutId, $new, $tool );
        if ( empty( $result['ok'] ) ) {
            throw new \RuntimeException( (string) ( $result['message'] ?? 'The layout could not be saved.' ) );
        }
        LayoutCaches::clear();

        // Hash what is stored now, not what was sent: WordPress may normalise content on save.
        $now      = get_post( $layoutId );
        $snapshot = is_array( $result['snapshot'] ?? null ) ? $result['snapshot'] : [];
        $batch    = $this->log->record(
            $tool,
            ucfirst( $kind ) . " \"{$title}\" (#{$layoutId}) edited",
            [ ThemeLayoutKind::itemFor( $layoutId, $title, $kind, $now ? (string) $now->post_content : $new, $snapshot ) ]
        );

        return BatchLog::attach(
            StyleReferences::attach(
                [ 'changed' => true, 'dry_run' => false, 'layout' => $layout, 'snapshot' => $snapshot, 'note' => self::NOTE ] + $extra,
                $new
            ),
            $batch
        );
    }

    /** @param list<\Divi5Validator\Violation> $violations */
    private static function refusal( array $violations ): string
    {
        $lines = [];
        foreach ( array_slice( $violations, 0, 10 ) as $v ) {
            $a       = $v->toArray();
            $lines[] = $a['code'] . ' at ' . $a['path'] . ': ' . $a['message'];
        }
        $more = count( $violations ) > 10 ? ' (and ' . ( count( $violations ) - 10 ) . ' more)' : '';

        return 'The layout was not saved: it failed the checks. ' . implode( '; ', $lines ) . $more;
    }
}
