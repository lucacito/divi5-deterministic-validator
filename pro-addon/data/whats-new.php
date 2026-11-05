<?php
/**
 * The "What's new" panel at the top of the Pro tab: ONE announcement, all of its text in this file.
 *
 * To announce something new, change this file and raise `version`: the panel then shows once more for everyone who dismissed the
 * earlier one. Keep it short: a title, three to five one-line bullets and one link.
 *
 * @package JHMG_AI_Editor_For_Divi_5_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

return [
    'version' => '0.14',
    'title'   => __( 'What\'s new in Pro 0.14', 'jhmg-ai-editor-for-divi-5-pro' ),
    'bullets' => [
        __( 'Companion abilities for any AI: audit-site, design-review-site and more, in their own category.', 'jhmg-ai-editor-for-divi-5-pro' ),
        __( 'Undo a whole batch of changes made by any tool, with start-batch and end-batch.', 'jhmg-ai-editor-for-divi-5-pro' ),
        __( 'Strict validation, off by default: flag Divi pages saved by AI tools that fail the layout check.', 'jhmg-ai-editor-for-divi-5-pro' ),
        __( 'Mega menu: 14 ready-made layouts for your header, built in Divi and undoable.', 'jhmg-ai-editor-for-divi-5-pro' ),
    ],
    'link'    => [
        'label' => __( 'Read the full list of changes', 'jhmg-ai-editor-for-divi-5-pro' ),
        'url'   => 'https://divi5lab.com/plugins/divi-5-ai-editor',
    ],
];
