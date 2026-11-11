<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Lab\LabCompile;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;

require_once __DIR__ . '/ThemeLayoutFixture.php';

/**
 * The Theme Builder fixture (pages, real free history on a fake wpdb, in-memory batch log) plus the Lab brief table.
 * Use in a TestCase's setUp(): `$this->setUpLab();`.
 */
trait LabFixture
{
    use ThemeLayoutFixture;

    private function setUpLab(): void
    {
        $this->setUpThemeBuilder();
        $GLOBALS['wpdb']->createStudioTable();
        $GLOBALS['__wp_options'][ StudioTable::DB_VERSION_OPTION ] = StudioTable::DB_VERSION;
        $GLOBALS['__wp_transients']                                = [];
        $GLOBALS['__wp_current_user_id']                           = 1;
    }

    private function tearDownLab(): void
    {
        unset( $GLOBALS['__wp_current_user_id'] );
        $GLOBALS['__wp_transients'] = [];
        $this->tearDownThemeBuilder();
    }

    /**
     * A valid two-section brief (hero-cta, cta-band).
     *
     * @param array<string,mixed> $words slot words for the first section
     * @return array<string,mixed>
     */
    private function labBrief( string $language, ?string $kit = null, array $words = [] ): array
    {
        $first = [ 'recipe' => 'hero-cta' ];
        if ( [] !== $words ) {
            $first['slots'] = $words;
        }
        $brief = [
            'version'  => 1,
            'title'    => 'Lab test page',
            'goal'     => 'showcase',
            'language' => $language,
            'sections' => [ $first, [ 'recipe' => 'cta-band' ] ],
        ];
        if ( null !== $kit ) {
            $brief['kit'] = $kit;
        }

        return $brief;
    }

    /**
     * A page built from a brief the way Lab builds one: compiled markup saved as the page, the brief stored and linked
     * with the sha256 of that markup. Returns the brief id.
     *
     * @param array<string,mixed> $brief
     */
    private function labPage( int $id, string $status, array $brief, int $user = 1 ): int
    {
        $r = LabCompile::compile( $brief );
        $this->assertTrue( (bool) $r['ok'], 'the fixture brief must compile' );
        $markup = (string) $r['markup'];
        $this->themePost( $id, 'page', $status, [ '_et_pb_use_divi_5' => 'on' ], $markup );
        $saved = StudioStore::save( $user, $brief );
        $this->assertTrue( $saved['ok'] );
        $this->assertTrue( StudioStore::link( $user, $saved['id'], $id, hash( 'sha256', $markup ) ) );

        return $saved['id'];
    }
}
