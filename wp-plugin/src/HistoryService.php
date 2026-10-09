<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/CodePolicy.php';

/**
 * Single entry point for page history, shared by the MCP and REST transports.
 * Returns plain arrays (never WP_Error) so each transport maps them its own way.
 */
final class HistoryService
{
    public const TOOL_RESTORE = 'restore';

    /** @return array{ok:bool, snapshot:array, error?:string, message?:string} */
    public static function write( int $pageId, string $newContent, string $tool ): array
    {
        $post = get_post( $pageId );
        if ( ! $post ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => "Page {$pageId} not found.", 'snapshot' => self::noSnapshot() ];
        }
        $snapshot = self::snapshot( $pageId, (string) $post->post_content, $tool );

        // wp_slash: wp_update_post runs wp_unslash internally, which would strip
        // backslashes from escaped HTML (e.g. <) and corrupt the content.
        $updated = wp_update_post( wp_slash( [ 'ID' => $pageId, 'post_content' => $newContent ] ), true );
        if ( is_wp_error( $updated ) ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => $updated->get_error_message(), 'snapshot' => $snapshot ];
        }

        return [ 'ok' => true, 'snapshot' => $snapshot ];
    }

    /** @return list<array<string,mixed>> */
    public static function listFor( int $pageId ): array
    {
        return HistoryStore::summaries( $pageId );
    }

    /** @return array<string,mixed>|null */
    public static function entry( int $pageId, int $versionId ): ?array
    {
        return HistoryStore::entry( $pageId, $versionId );
    }

    /** @return array<string,mixed> */
    public static function restore( int $pageId, int $versionId ): array
    {
        $target = self::entry( $pageId, $versionId );
        if ( null === $target ) {
            return [ 'ok' => false, 'error' => 'version_not_found', 'message' => "Version {$versionId} not found for page {$pageId}.", 'snapshot' => self::noSnapshot() ];
        }

        $post     = get_post( $pageId );
        $snapshot = self::snapshot( $pageId, $post ? (string) $post->post_content : '', self::TOOL_RESTORE, 'before restore of #' . $versionId );

        $updated = wp_update_post( wp_slash( [ 'ID' => $pageId, 'post_content' => (string) $target['content'] ] ), true );
        if ( is_wp_error( $updated ) ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => $updated->get_error_message(), 'snapshot' => $snapshot ];
        }

        // Restore is NOT a validated write: it returns the person's own earlier content.
        // The validator's verdict is reported as information only.
        // Content too large or too deeply nested is never walked (the walk is quadratic in depth): the restore
        // still happens, the verdict says it could not be checked.
        $tooBig = CodePolicy::tooLarge( (string) $target['content'] ) || CodePolicy::tooDeep( (string) $target['content'] )
            ? CodePolicy::tooLargeViolation( (string) $target['content'] )
            : null;
        if ( null !== $tooBig ) {
            return [
                'ok'               => true,
                'restored_version' => $versionId,
                'snapshot'         => $snapshot,
                'validator'        => [
                    'valid'      => false,
                    'violations' => [ [ 'code' => 'LAYOUT_TOO_LARGE_TO_CHECK', 'message' => $tooBig->message(), 'path' => 'post_content' ] ],
                ],
            ];
        }
        $verdict = ( new Validator() )->validateContent( (string) $target['content'] );

        return [
            'ok'               => true,
            'restored_version' => $versionId,
            'snapshot'         => $snapshot,
            'validator'        => [
                'valid'      => $verdict->isValid(),
                'violations' => array_map( static fn ( $v ) => $v->toArray(), $verdict->violations() ),
            ],
        ];
    }

    /** How many newest-per-page rows are looked at to find $limit restorable ones. */
    private const RECENT_SCAN = 200;

    /**
     * The newest snapshot of each page the current user can restore, newest first. A row whose post is not a page or Divi Project
     * this user may edit (for example a header or footer layout saved by an add-on, or a deleted post) is left out:
     * restoring it would always be refused, and the filter runs before the limit so hidden rows never use up a slot.
     *
     * @return list<array{page_id:int, title:string, version_id:int, saved_at:mixed, tool:mixed}>
     */
    public static function recent( int $limit = 5 ): array
    {
        $rows = [];
        foreach ( HistoryStore::recent( max( $limit, self::RECENT_SCAN ) ) as $row ) {
            if ( count( $rows ) >= $limit ) {
                break;
            }
            $target = EditTarget::resolve( $row['page_id'] );
            if ( null === $target['post'] ) {
                continue;
            }
            $rows[] = $row + [ 'title' => (string) ( $target['post']->post_title ?? '' ) ];
        }

        return $rows;
    }

    /** @return array{stored:bool, version_id:?int, reason:?string} */
    private static function noSnapshot(): array
    {
        return [ 'stored' => false, 'version_id' => null, 'reason' => null ];
    }

    /** @return array{stored:bool, version_id:?int, reason:null|'duplicate'|'too_large'|'invalid_encoding'|'store_failed'} */
    private static function snapshot( int $pageId, string $before, string $tool, ?string $label = null ): array
    {
        $history = HistoryStore::load( $pageId );
        if ( isset( $history['failed'] ) ) {
            // The stored history could not be read: recording against an empty one would hand out ids
            // that are already taken. The page write still goes ahead, without an undo point.
            return [ 'stored' => false, 'version_id' => null, 'reason' => 'store_failed' ];
        }
        $result = PageHistory::record(
            $history,
            $before,
            $tool,
            (int) get_current_user_id(),
            gmdate( 'Y-m-d\TH:i:s\Z' ),
            $label
        );
        if ( $result['stored'] && ! HistoryStore::save( $pageId, $result['history'] ) ) {
            return [ 'stored' => false, 'version_id' => null, 'reason' => 'store_failed' ];
        }

        return [ 'stored' => $result['stored'], 'version_id' => $result['version_id'], 'reason' => $result['reason'] ];
    }
}
