<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/PageTitle.php';
require_once __DIR__ . '/ApprovalMode.php';

/**
 * What an optional `title` argument does to a save, worked out in one pure place so the MCP and REST handlers cannot drift:
 * whether the title changes, what is written, the report and message for the result, and the approval-mode subject/base
 * (so a preview of a title change authorises exactly that change). No WordPress calls.
 */
final class TitleChange
{
    /**
     * @param ?string $newContent the new post_content, or null for a title-only call
     * @param array{ok:bool, present:bool, title:?string, error:?string} $parsedTitle PageTitle::parse() result (ok)
     * @return array{present:bool, changed:bool, writeTitle:?string, report:?array<string,mixed>, subject:string, base:string, message:?string}
     */
    public static function plan( string $storedTitle, string $storedContent, ?string $newContent, array $parsedTitle ): array
    {
        $present    = (bool) $parsedTitle['present'];
        $newTitle   = $present ? (string) $parsedTitle['title'] : null;
        $changed    = $present && $newTitle !== $storedTitle;
        $writeTitle = $changed ? $newTitle : null;

        $message = null;
        if ( $present ) {
            if ( $changed ) {
                $message = PageTitle::notRestorableMessage( $storedTitle );
            } else {
                $message = null === $newContent ? PageTitle::unchangedMessage( $storedTitle ) : PageTitle::keptMessage( $storedTitle );
            }
        }

        return [
            'present'    => $present,
            'changed'    => $changed,
            'writeTitle' => $writeTitle,
            'report'     => $present ? PageTitle::report( $storedTitle, (string) $newTitle, $changed ) : null,
            'subject'    => ApprovalMode::withTitle( $newContent ?? $storedContent, $writeTitle ),
            'base'       => ApprovalMode::withTitle( $storedContent, null === $writeTitle ? null : $storedTitle ),
            'message'    => $message,
        ];
    }
}
