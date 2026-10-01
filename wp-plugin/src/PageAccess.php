<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Authorisation and input rules shared by the MCP and REST transports.
 * Pure functions: the WordPress capability check is passed in, so they are unit-testable.
 */
final class PageAccess
{
    /** Every plugin endpoint works on pages, so the authenticated user must be able to edit pages. */
    public const CAP = 'edit_pages';

    /**
     * Whether the user behind an accepted API key may use the plugin at all.
     * A key whose owner was demoted, deleted (user 0) or never could edit pages is refused.
     *
     * @param callable(string):bool $can current_user_can
     */
    public static function keyUserAllowed(callable $can): bool
    {
        return (bool) $can(self::CAP);
    }

    /**
     * Keeps only the posts the current user can edit (drafts, pending and private pages of
     * other users are never listed to someone who cannot edit them).
     *
     * @param array<int,object>      $posts
     * @param callable(int):bool     $canEditPost fn(int $id) => current_user_can('edit_post', $id)
     * @return list<object>
     */
    public static function filterEditable(array $posts, callable $canEditPost): array
    {
        return array_values(array_filter(
            $posts,
            static fn($p): bool => is_object($p) && isset($p->ID) && (bool) $canEditPost((int) $p->ID)
        ));
    }

    /**
     * A string argument from a JSON body: '' when missing or null, the string itself when it is
     * a string, and null when it is anything else (array, number, bool) so the caller can
     * answer 400 / -32602 instead of casting it.
     *
     * @param array<string,mixed> $args
     */
    public static function stringArg(array $args, string $key): ?string
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            return '';
        }
        return is_string($args[$key]) ? $args[$key] : null;
    }
}
