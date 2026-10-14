<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The one place a new Divi 5 page is inserted: always a draft, with the builder flags that make it open in Divi 5. */
final class DraftPage
{
    /**
     * @return int|\WP_Error the new page id
     */
    public static function insert( string $title, string $content, string $slug )
    {
        // Always a draft: the site owner reviews and publishes. The Divi 5 builder meta flags make the page open in
        // the Divi 5 editor and appear in list_divi_pages (which filters on _et_pb_use_divi_5).
        // wp_slash: wp_insert_post runs wp_unslash internally, which would otherwise strip backslashes from
        // escaped HTML (for example <) and corrupt the content.
        $postArr = [
            'post_type'    => 'page',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'meta_input'   => [
                '_et_pb_use_divi_5'  => 'on',
                '_et_pb_use_builder' => 'on',
            ],
        ];
        if ( '' !== $slug ) {
            $postArr['post_name'] = $slug;
        }

        return wp_insert_post( wp_slash( $postArr ), true );
    }
}
