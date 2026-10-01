<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
        $post     = get_post( $pageId );
        $snapshot = self::snapshot( $pageId, $post ? (string) $post->post_content : '', $tool );

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
        return PageHistory::summaries( HistoryStore::load( $pageId ) );
    }

    /** @return array<string,mixed>|null */
    public static function entry( int $pageId, int $versionId ): ?array
    {
        return PageHistory::find( HistoryStore::load( $pageId ), $versionId );
    }

    /** @return array<string,mixed> */
    public static function restore( int $pageId, int $versionId ): array
    {
        $target = self::entry( $pageId, $versionId );
        if ( null === $target ) {
            return [ 'ok' => false, 'error' => 'version_not_found', 'message' => "Version {$versionId} not found for page {$pageId}." ];
        }

        $post     = get_post( $pageId );
        $snapshot = self::snapshot( $pageId, $post ? (string) $post->post_content : '', self::TOOL_RESTORE, 'before restore of #' . $versionId );

        $updated = wp_update_post( wp_slash( [ 'ID' => $pageId, 'post_content' => (string) $target['content'] ] ), true );
        if ( is_wp_error( $updated ) ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => $updated->get_error_message() ];
        }

        // Restore is NOT a validated write: it returns the person's own earlier content.
        // The validator's verdict is reported as information only.
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

    /** @return array{stored:bool, version_id:?int, reason:?string} */
    private static function snapshot( int $pageId, string $before, string $tool, ?string $label = null ): array
    {
        $result = PageHistory::record(
            HistoryStore::load( $pageId ),
            $before,
            $tool,
            (int) get_current_user_id(),
            gmdate( 'Y-m-d\TH:i:s\Z' ),
            $label
        );
        if ( $result['stored'] ) {
            HistoryStore::save( $pageId, $result['history'] );
        }

        return [ 'stored' => $result['stored'], 'version_id' => $result['version_id'], 'reason' => $result['reason'] ];
    }
}
