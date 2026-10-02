<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

/** The licence client is generated from the canonical divi5lab client; nothing of another product may remain. */
class LicenseClientFileTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents( __DIR__ . '/../src/Licensing/LicenseClient.php' );
    }

    public function testItIsRewrittenForTheProAddOn(): void
    {
        $f = $this->source();
        $this->assertStringContainsString( 'namespace AiEditorDivi5\\Pro\\Licensing;', $f );
        $this->assertStringContainsString( "'jhmg-ai-editor-for-divi-5-pro'", $f );
        $this->assertStringContainsString( 'AI Editor for Divi 5 Pro', $f );
        $this->assertStringContainsString( 'tab=pro', $f );
        $this->assertStringContainsString( 'admin.php?page=', $f );
    }

    public function testNothingOfAnotherProductRemains(): void
    {
        $f = $this->source();
        foreach ( [ 'ElementorDivi5Converter', 'jhmg-converter-for-elementor-to-divi-pro', 'JHMG Converter Pro', 'tools.php', 'tab=license' ] as $stale ) {
            $this->assertStringNotContainsString( $stale, $f, $stale );
        }
    }

    public function testItCarriesTheUpdaterAndOnlyTheLicenceApiPaths(): void
    {
        $f = $this->source();
        $this->assertStringContainsString( 'function inject_update', $f );
        foreach ( [ '/api/license/activate', '/api/license/validate', '/api/license/deactivate', '/api/plugin/update-check' ] as $path ) {
            $this->assertStringContainsString( $path, $f, $path );
        }
    }
}
