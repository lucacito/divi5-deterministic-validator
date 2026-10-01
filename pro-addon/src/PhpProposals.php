<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stores AI-proposed PHP snippets for HUMAN review — never executes them.
 *
 * The AI can generate code (custom post types, hooks, form handlers, …) via the
 * propose_php_snippet tool. Proposals land here, inert. The site owner reviews
 * each one in WP Admin and applies it deliberately (copy into a snippets plugin
 * / functions.php). The plugin does not eval, include, or write executable PHP —
 * so the API key never becomes a code-execution credential.
 */
final class PhpProposals
{
    private const OPTION = 'ai_editor_divi5_php_proposals';
    private const MAX    = 50;

    /** @return list<array{id:string,title:string,description:string,code:string,created:int}> */
    public static function all(): array
    {
        $data = get_option(self::OPTION, []);
        return is_array($data) ? array_values($data) : [];
    }

    /** Store a proposal (inert). Returns its id. */
    public static function add(string $title, string $description, string $code): string
    {
        $items = self::all();
        $id = uniqid('php_', false);
        array_unshift($items, [
            'id'          => $id,
            'title'       => $title !== '' ? $title : 'Untitled snippet',
            'description' => $description,
            'code'        => $code,
            'created'     => time(),
        ]);
        update_option(self::OPTION, array_slice($items, 0, self::MAX), false);
        return $id;
    }

    public static function delete(string $id): void
    {
        $items = array_filter(self::all(), static fn(array $p): bool => $p['id'] !== $id);
        update_option(self::OPTION, array_values($items), false);
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    public static function count(): int
    {
        return count(self::all());
    }
}
