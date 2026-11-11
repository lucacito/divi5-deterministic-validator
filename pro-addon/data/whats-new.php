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
    'version' => '0.15',
    'title'   => __( 'What\'s new in Pro 0.15', 'jhmg-ai-editor-for-divi-5-pro' ),
    'bullets' => [
        __( 'lab_restyle: rebuild pages made in Lab in another design language or style kit, previewed and undoable.', 'jhmg-ai-editor-for-divi-5-pro' ),
        __( 'It skips pages you edited since and publishes nothing; published pages only when you ask.', 'jhmg-ai-editor-for-divi-5-pro' ),
        __( 'build_site accepts a Lab brief, so a whole multi-page site shares one look.', 'jhmg-ai-editor-for-divi-5-pro' ),
        __( 'The pages of such a site show up in the Lab screen. Needs the free plugin 4.19.0. New: not yet confirmed with every assistant.', 'jhmg-ai-editor-for-divi-5-pro' ),
    ],
    'link'    => [
        'label' => __( 'Read the full list of changes', 'jhmg-ai-editor-for-divi-5-pro' ),
        'url'   => 'https://divi5lab.com/plugins/divi-5-ai-editor',
    ],
];
