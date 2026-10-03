<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\PhpProposals;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** File PHP for a human to review on the Pro tab. Nothing is run, saved as a file or loaded. */
final class ProposePhpSnippet implements ProTool
{
    public const NAME = 'propose_php_snippet';

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
            'description' => 'Propose a PHP snippet (post type, hook, shortcode...) for the site owner to REVIEW. It is stored inert on the Pro tab and is never run or saved to the site. Say what it does and where to put it.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'title'       => [ 'type' => 'string', 'description' => 'Short name for the snippet' ],
                    'description' => [ 'type' => 'string', 'description' => 'What it does and where or how to apply it' ],
                    'code'        => [ 'type' => 'string', 'description' => 'The PHP code' ],
                ],
                'required'   => [ 'code' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $code  = (string) Args::string( $arguments, 'code', true, 100000 );
        $title = (string) Args::string( $arguments, 'title', false, 120 );
        $desc  = (string) Args::string( $arguments, 'description', false, 2000 );

        $id = PhpProposals::add( sanitize_text_field( $title ), wp_strip_all_tags( $desc ), $code, (int) get_current_user_id() );

        return [
            'proposed'   => true,
            'executed'   => false,
            'id'         => $id,
            'message'    => 'Snippet stored for review. It was NOT executed or saved to the site. The owner can review it on the Pro tab (AI Editor > Pro > Code proposals).',
            'review_url' => admin_url( 'admin.php?page=ai-editor-divi5&tab=pro#proposals' ),
        ];
    }
}
