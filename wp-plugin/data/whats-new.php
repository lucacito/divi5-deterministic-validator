<?php
/**
 * The "What's new" panel on the plugin's own Dashboard: ONE announcement, all of its text in this file.
 *
 * To announce something new, change this file and raise `version`: the panel then shows once more for everyone who dismissed the
 * earlier one. Keep it short: a title, three to five one-line bullets and one link. Strings are written as translatable literals
 * so they can be extracted; this file is only read on the plugin's own screens (see WhatsNew).
 *
 * @package JHMG_AI_Editor_For_Divi_5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

return [
    'version' => '4.17',
    'title'   => __( 'What\'s new in 4.17', 'jhmg-ai-editor-for-divi-5' ),
    'bullets' => [
        __( 'Design languages and section recipes are now abilities any AI can call (WordPress 6.9 and later).', 'jhmg-ai-editor-for-divi-5' ),
        __( 'check-design: an advisory look at readability, Divi\'s stock look and repeated sections. It never blocks a save.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'Undo for Divi page changes from any source, with the source shown in Recent AI edits.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'Designed to work alongside Divi\'s upcoming MCP.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'A new "Instructions for your AI" card in Settings, ready to paste into your assistant.', 'jhmg-ai-editor-for-divi-5' ),
    ],
    'link'    => [
        'label' => __( 'See the full changelog', 'jhmg-ai-editor-for-divi-5' ),
        'url'   => 'https://wordpress.org/plugins/jhmg-ai-editor-for-divi-5/#developers',
    ],
];
