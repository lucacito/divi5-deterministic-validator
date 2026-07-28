<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Pure exact find/replace engine behind the surgical `edit_page_content` tool.
 *
 * It lets an AI change one thing on a page (an email, a phone number, a line of
 * copy) WITHOUT re-emitting the entire Divi 5 layout — which is lossy for large
 * pages and risks corrupting unrelated blocks. The engine only decides WHAT the
 * new content is and whether the edit is safe; callers handle validation + save.
 *
 * No WordPress dependencies, so it is unit-testable in isolation. Semantics
 * mirror a code editor's exact-string replace: `find` must identify a unique
 * spot unless the caller states, via $expectCount, how many matches it expects.
 * Anything empty, not-found, or ambiguous leaves the content untouched.
 */
final class PageEditor
{
    /**
     * @return array{ok:bool, content:string, count:int, error:string}
     */
    public static function apply(string $content, string $find, string $replace, ?int $expectCount = null): array
    {
        if ($find === '') {
            return self::fail($content, 0, 'The "find" text must not be empty.');
        }
        if ($find === $replace) {
            return self::fail($content, 0, 'The "find" and "replace" text are identical — nothing to change.');
        }

        $count = substr_count($content, $find);

        if ($count === 0) {
            return self::fail($content, 0, 'The "find" text was not found on the page. Nothing was changed.');
        }
        if ($expectCount !== null && $count !== $expectCount) {
            return self::fail($content, $count, sprintf(
                'Expected %d occurrence(s) of the "find" text but found %d. Nothing was changed.',
                $expectCount,
                $count
            ));
        }
        if ($expectCount === null && $count > 1) {
            return self::fail($content, $count, sprintf(
                'The "find" text matches %1$d places on the page, so the edit is ambiguous. Include more '
                . 'surrounding text to target one spot, or set expect_count to %1$d to replace them all. '
                . 'Nothing was changed.',
                $count
            ));
        }

        return [
            'ok'      => true,
            'content' => str_replace($find, $replace, $content),
            'count'   => $count,
            'error'   => '',
        ];
    }

    /** @return array{ok:bool, content:string, count:int, error:string} */
    private static function fail(string $content, int $count, string $error): array
    {
        return ['ok' => false, 'content' => $content, 'count' => $count, 'error' => $error];
    }
}
