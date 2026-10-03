<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\PostContentKind;
use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Site\PreviewToken;
use AiEditorDivi5\Pro\Site\SiteItems;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutCaches;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\LayoutDiff;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\PageEditor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Literal, case-sensitive find and replace across the site's Divi 5 pages and (with the Theme Builder permission) its
 * live header and footer, as ONE previewed, undoable batch. The replacement itself is the free plugin's own
 * `PageEditor::apply()` and every result must pass the shared layout gate with the stored content as baseline; an item
 * that would fail it is skipped and reported, never saved. A real run needs the token from a dry run of the same
 * arguments, and an item that changed since that preview is skipped.
 */
final class FindReplaceSite implements ProTool
{
    public const NAME      = 'find_replace_site';
    public const MAX_ITEMS = 50;
    public const MAX_BYTES = 20000;

    private \Closure $clock;

    /**
     * @param (\Closure(int,string,string):array<string,mixed>)|null $write fn(postId, content, tool): HistoryService::write()'s result
     * @param (\Closure():int)|null $clock fn(): unix time
     */
    public function __construct( private BatchLog $log, private ?\Closure $write = null, ?\Closure $clock = null )
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_pages';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Replace literal text across Divi 5 pages (and, with Theme Builder permission, the live header and footer) as one undoable batch. Always call with dry_run=true first, then repeat with the token it returns. Items that would fail the layout checks are skipped. At most 50 items per run.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'find'     => [ 'type' => 'string', 'description' => 'Exact text to find (case-sensitive, literal, not a pattern)' ],
                    'replace'  => [ 'type' => 'string', 'description' => 'Text to put in its place (may be empty)' ],
                    'scope'    => [ 'type' => 'string', 'description' => 'pages (default), layouts or all', 'enum' => SiteItems::SCOPES ],
                    'page_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Optional. Only these page or layout ids' ],
                    'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview only; returns the token a real run needs' ],
                    'token'    => [ 'type' => 'string', 'description' => 'The token from the dry run of the same arguments' ],
                ],
                'required'   => [ 'find', 'replace' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $find = (string) Args::string( $arguments, 'find', true, self::MAX_BYTES );
        if ( ! array_key_exists( 'replace', $arguments ) || null === $arguments['replace'] ) {
            throw new \InvalidArgumentException( '"replace" is required (it may be an empty string).' );
        }
        $replace = (string) Args::string( $arguments, 'replace', false, self::MAX_BYTES );
        if ( $find === $replace ) {
            throw new \InvalidArgumentException( 'The "find" and "replace" text are identical; nothing to change.' );
        }
        $scope = array_key_exists( 'scope', $arguments ) && null !== $arguments['scope'] ? (string) Args::string( $arguments, 'scope', true, 20 ) : 'pages';
        if ( ! in_array( $scope, SiteItems::SCOPES, true ) ) {
            throw new \InvalidArgumentException( '"scope" must be one of: ' . implode( ', ', SiteItems::SCOPES ) . '.' );
        }
        $ids = null;
        if ( array_key_exists( 'page_ids', $arguments ) && null !== $arguments['page_ids'] ) {
            $ids = [];
            foreach ( Args::listOf( $arguments, 'page_ids', 200 ) as $id ) {
                if ( ! is_int( $id ) || $id < 1 ) {
                    throw new \InvalidArgumentException( '"page_ids" must be a list of positive integers.' );
                }
                $ids[] = $id;
            }
            $ids = array_values( array_unique( $ids ) );
            sort( $ids );
        }
        $dry   = Args::bool( $arguments, 'dry_run' );
        $token = Args::string( $arguments, 'token', false, 8000 );
        if ( 'layouts' === $scope && ! current_user_can( 'edit_theme_options' ) ) {
            throw new \InvalidArgumentException( 'You do not have permission to change header and footer layouts (edit_theme_options and Divi\'s Theme Builder permission).' );
        }

        $set = SiteItems::collect( $scope, SiteItems::EDIT_STATUSES, $ids );
        if ( $set['truncated'] ) {
            throw new \InvalidArgumentException( 'This site has more than ' . SiteItems::PAGE_LIMIT . ' Divi 5 pages, so the search would be incomplete. Narrow it with page_ids.' );
        }

        $candidates = [];
        foreach ( $set['items'] as $item ) {
            $content = SiteItems::content( $item['id'] );
            $count   = substr_count( $content, $find );
            if ( $count > 0 ) {
                $candidates[] = $item + [ 'content' => $content, 'occurrences' => $count, 'hash' => substr( Canon::hash( $content ), 0, 16 ) ];
            }
        }
        $extra    = null === $set['layouts_note'] ? [] : [ 'layouts_note' => $set['layouts_note'] ];
        $argsHash = Canon::hash( [ 'find' => $find, 'replace' => $replace, 'scope' => $scope, 'ids' => $ids ] );
        if ( [] === $candidates ) {
            // A real run whose every previewed item has since stopped matching: report them, do not stay silent.
            $gone = $dry || null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )() );
            if ( null !== $gone && [] !== $gone ) {
                return [ 'changed' => false, 'dry_run' => false, 'replaced_in' => 0, 'skipped' => self::dropped( $gone, [] ), 'message' => 'Nothing was saved.' ] + $extra;
            }

            return [ 'changed' => false, 'dry_run' => $dry, 'matched' => 0, 'message' => 'The text was not found in any of the pages or layouts in scope. Nothing changed.' ] + $extra;
        }
        if ( count( $candidates ) > self::MAX_ITEMS ) {
            throw new \InvalidArgumentException( count( $candidates ) . ' items contain that text; the limit is ' . self::MAX_ITEMS . ' per run. Narrow it with page_ids or scope.' );
        }

        // The replacement and the gate, per item, against the STORED content.
        $plan = [];
        foreach ( $candidates as $c ) {
            $edit = PageEditor::apply( $c['content'], $find, $replace, $c['occurrences'] );
            $new  = $edit['ok'] ? $edit['content'] : $c['content'];
            $gate = LayoutGate::check( $new, $c['content'] );
            $plan[] = $c + [ 'new' => $new, 'valid' => $edit['ok'] && $gate->isValid(), 'codes' => self::codes( $gate ) ];
        }

        if ( $dry ) {
            return $this->preview( $plan, $argsHash ) + $extra;
        }

        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )() );
        if ( null === $map ) {
            throw new \InvalidArgumentException( 'A real run needs the token from a dry run of the same arguments, made in the last 30 minutes. Call again with dry_run=true.' );
        }

        return $this->apply( $plan, $map, $find ) + $extra;
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @return array<string,mixed>
     */
    private function preview( array $plan, string $argsHash ): array
    {
        $items = [];
        $map   = [];
        $skip  = 0;
        foreach ( $plan as $p ) {
            $row = [ 'kind' => $p['kind'], 'id' => $p['id'], 'title' => $p['title'], 'occurrences' => $p['occurrences'], 'valid' => $p['valid'] ];
            if ( $p['valid'] ) {
                $map[ $p['id'] ] = $p['hash'];
                $diff            = LayoutDiff::compare( $p['content'], $p['new'] );
                $row['diff']     = $diff['diff']['summary'] ?? null;
            } else {
                $skip++;
                $row['violations'] = $p['codes'];
            }
            $items[] = $row;
        }

        return [
            'changed'      => false,
            'dry_run'      => true,
            'matched'      => count( $plan ),
            'would_change' => count( $map ),
            'would_skip'   => $skip,
            'items'        => $items,
            'token'        => PreviewToken::make( $argsHash, $map, ( $this->clock )() ),
            'message'      => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it.',
        ];
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @param array<int,string> $map id => content hash the preview saw
     * @return array<string,mixed>
     */
    private function apply( array $plan, array $map, string $find ): array
    {
        $write   = $this->write ?? static fn ( int $id, string $content, string $tool ): array => HistoryService::write( $id, $content, $tool );
        $items   = [];
        $skipped = [];
        $layouts = false;
        foreach ( $plan as $p ) {
            $row = [ 'kind' => $p['kind'], 'id' => $p['id'], 'title' => $p['title'] ];
            if ( ! $p['valid'] ) {
                $skipped[] = $row + [ 'reason' => 'invalid_result', 'violations' => $p['codes'] ];
                continue;
            }
            if ( ! isset( $map[ $p['id'] ] ) ) {
                $skipped[] = $row + [ 'reason' => 'not_in_preview' ];
                continue;
            }
            if ( $map[ $p['id'] ] !== $p['hash'] ) {
                $skipped[] = $row + [ 'reason' => 'changed_since_preview' ];
                continue;
            }
            $result = $write( $p['id'], $p['new'], self::NAME );
            if ( empty( $result['ok'] ) ) {
                $skipped[] = $row + [ 'reason' => 'save_failed' ];
                continue;
            }
            $snapshot = is_array( $result['snapshot'] ?? null ) ? $result['snapshot'] : [];
            $now      = get_post( $p['id'] );
            $stored   = $now ? (string) $now->post_content : $p['new'];
            if ( 'page' === $p['kind'] ) {
                $items[] = PostContentKind::itemFor( $p['id'], $p['title'], $stored, $snapshot );
            } else {
                $items[]  = ThemeLayoutKind::itemFor( $p['id'], $p['title'], $p['kind'], $stored, $snapshot );
                $layouts = true;
            }
        }
        if ( $layouts ) {
            LayoutCaches::clear();
        }
        $skipped = array_merge( $skipped, self::dropped( $map, $plan ) );
        if ( [] === $items ) {
            return [ 'changed' => false, 'dry_run' => false, 'replaced_in' => 0, 'skipped' => $skipped, 'message' => 'Nothing was saved.' ];
        }
        $short = strlen( $find ) > 60 ? substr( $find, 0, 57 ) . '...' : $find;
        $batch = $this->log->record( self::NAME, 'Replaced "' . $short . '" in ' . count( $items ) . ' item(s)', $items );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'replaced_in' => count( $items ), 'skipped' => $skipped ], $batch );
    }

    /**
     * Items the preview saw that are no longer candidates (edited so the text is gone, trashed, or no longer editable
     * by this user). Only the id and the reason: the item may not be readable any more.
     *
     * @param array<int,string> $map id => content hash the preview saw
     * @param list<array<string,mixed>> $plan
     * @return list<array{id:int, reason:string}>
     */
    private static function dropped( array $map, array $plan ): array
    {
        $planned = array_column( $plan, 'id' );
        $rows    = [];
        foreach ( array_keys( $map ) as $id ) {
            if ( ! in_array( $id, $planned, true ) ) {
                $rows[] = [ 'id' => $id, 'reason' => 'changed_since_preview' ];
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private static function codes( \Divi5Validator\ValidationResult $gate ): array
    {
        $codes = [];
        foreach ( $gate->violations() as $v ) {
            $codes[ $v->toArray()['code'] ] = true;
        }

        return array_slice( array_keys( $codes ), 0, 10 );
    }
}
