<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Companion\ProAbilities;
use AiEditorDivi5\Pro\Rest\ProOpenApi;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../wp-plugin/src/autoload.php';

/**
 * Pins the Pro add-on's public surface: the 23 tool definitions (what MCP serves), the operations ChatGPT's importer sees, and the
 * Pro abilities. A change to any of them is a change to what customers' assistants see, so it must be deliberate:
 * regenerate with AIED_UPDATE_GOLDEN=1 and review the diff.
 */
class ProGoldenTest extends TestCase
{
    private const DIR = __DIR__ . '/golden/';

    private function json( mixed $data ): string
    {
        return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
    }

    private function check( string $file, string $actual ): void
    {
        if ( getenv( 'AIED_UPDATE_GOLDEN' ) ) {
            file_put_contents( self::DIR . $file, $actual );
        }
        $this->assertFileExists( self::DIR . $file );
        $this->assertSame( file_get_contents( self::DIR . $file ), $actual, "{$file} changed" );
    }

    public function testTheProToolDefinitionsArePinned(): void
    {
        $defs = array_map( static fn ( $t ) => $t->definition() + [ 'capability' => $t->capability() ], Tools::all() );
        $this->assertCount( 23, $defs );
        $this->check( 'pro-tools-list.json', $this->json( $defs ) );
    }

    public function testTheNewMegaMenuToolDefinitionIsPinnedOnItsOwn(): void
    {
        $tool = array_values( array_filter( Tools::all(), static fn ( $t ) => 'manage_mega_menu' === $t->name() ) )[0];
        $this->check( 'manage-mega-menu-tool.json', $this->json( $tool->definition() + [ 'capability' => $tool->capability() ] ) );
    }

    public function testTheChatGptOperationsAreStillFourteenForPro(): void
    {
        $paths = ( new ProOpenApi( new ToolRegistry( Tools::all(), static fn (): bool => true ) ) )->paths( [], '' );
        $ops   = [];
        foreach ( (array) $paths as $path => $item ) {
            foreach ( (array) $item as $method => $op ) {
                $ops[] = strtoupper( (string) $method ) . ' ' . $path . ' ' . $op['operationId'];
            }
        }
        sort( $ops );
        $this->assertCount( 14, $ops, '16 free + 14 Pro = the importer\'s 30' );
        $this->check( 'pro-openapi-operations.json', $this->json( $ops ) );
    }

    public function testTheProAbilitiesArePinned(): void
    {
        $defs = ProAbilities::definitions();
        $this->assertCount( 9, $defs );
        $this->assertContains( 'get-mega-menu', array_column( $defs, 'name' ) );
        $this->assertContains( 'set-mega-menu', array_column( $defs, 'name' ) );
        $this->check( 'pro-abilities.json', $this->json( $defs ) );
    }
}
