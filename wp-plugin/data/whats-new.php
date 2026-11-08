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
    'version' => '4.18',
    'title'   => __( 'What\'s new in 4.18', 'jhmg-ai-editor-for-divi-5' ),
    'bullets' => [
        __( 'Divi\'s Loop Builder: your AI can build lists of your posts, products, categories or menu items.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'A list can be paged with Next and Previous links.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'Four new ready-made patterns: newest posts, all posts in pages, categories and newest products.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'Before a page is saved, your AI sees what each loop will list on your site.', 'jhmg-ai-editor-for-divi-5' ),
        __( 'The checker refuses loop settings that would break the page.', 'jhmg-ai-editor-for-divi-5' ),
    ],
    'link'    => [
        'label' => __( 'See the full changelog', 'jhmg-ai-editor-for-divi-5' ),
        'url'   => 'https://wordpress.org/plugins/jhmg-ai-editor-for-divi-5/#developers',
    ],
];
