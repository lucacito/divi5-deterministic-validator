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
    'version' => '4.19',
    'title'   => __( 'What\'s new in 4.19', 'jhmg-ai-editor-for-divi-5' ),
    'bullets' => [
        __( 'Lab: a new tab where you answer a few questions and see a live preview of your page.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'Save the result as a draft page, checked before it is saved like every other change.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'Your AI assistant can fill in the words of a Lab brief you started, with the new lab_build tool.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'It never overwrites what you changed meanwhile.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'Lab is new and not yet confirmed with every assistant.', 'jhmg-ai-editor-for-divi-5' ),
    ],
    'link'    => [
        'label' => __( 'See the full changelog', 'jhmg-ai-editor-for-divi-5' ),
        'url'   => 'https://wordpress.org/plugins/jhmg-ai-editor-for-divi-5/#developers',
    ],
];
