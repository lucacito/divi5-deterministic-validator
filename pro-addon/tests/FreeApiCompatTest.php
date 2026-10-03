<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';

/**
 * The add-on calls a small set of the free plugin's classes and hooks directly (spec decision: one
 * shared write path and one undo history). If the free plugin changes any of these, this test fails
 * first, instead of the add-on failing at runtime on a customer's site.
 */
class FreeApiCompatTest extends TestCase
{
    /** @return list<array{string,string,array<string,string>,string}> */
    public static function signatures(): array
    {
        $w = 'AiEditorDivi5\\WP\\';

        return [
            [ $w . 'HistoryService', 'write', [ 'pageId' => 'int', 'newContent' => 'string', 'tool' => 'string' ], 'array' ],
            [ $w . 'HistoryService', 'restore', [ 'pageId' => 'int', 'versionId' => 'int' ], 'array' ],
            [ $w . 'HistoryService', 'entry', [ 'pageId' => 'int', 'versionId' => 'int' ], '?array' ],
            [ $w . 'HistoryService', 'listFor', [ 'pageId' => 'int' ], 'array' ],
            [ $w . 'PageEditor', 'apply', [ 'content' => 'string', 'find' => 'string', 'replace' => 'string', 'expectCount' => '?int' ], 'array' ],
            [ $w . 'EditTarget', 'resolve', [ 'id' => 'int' ], 'array' ],
            [ $w . 'LayoutGate', 'check', [ 'content' => 'string', 'baseline' => '?string' ], 'Divi5Validator\\ValidationResult' ],
            [ $w . 'LayoutDiff', 'compare', [ 'before' => 'string', 'after' => 'string' ], 'array' ],
            [ $w . 'DryRun', 'preview', [ 'before' => 'string', 'after' => 'string' ], 'array' ],
            [ $w . 'StyleReferences', 'attach', [ 'payload' => 'array', 'content' => 'string' ], 'array' ],
            [ $w . 'StyleReferences', 'advise', [ 'content' => 'string', 'known' => 'array' ], '?array' ],
        ];
    }

    /**
     * @param array<string,string> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'signatures' )]
    public function testTheFreeMethodKeepsItsSignature( string $class, string $method, array $params, string $returns ): void
    {
        $m = new \ReflectionMethod( $class, $method );
        $this->assertTrue( $m->isStatic() && $m->isPublic(), "{$class}::{$method} must stay public static" );
        $actual = [];
        foreach ( $m->getParameters() as $p ) {
            $actual[ $p->getName() ] = (string) $p->getType();
        }
        $this->assertSame( $params, $actual, "{$class}::{$method} parameters changed" );
        $this->assertSame( $returns, (string) $m->getReturnType(), "{$class}::{$method} return type changed" );
    }

    /** The validator types LayoutWriter::refusal() reads off the verdict LayoutGate::check() returns. */
    public function testTheValidatorTypesTheAddOnReadsKeepTheirShape(): void
    {
        $isValid = new \ReflectionMethod( \Divi5Validator\ValidationResult::class, 'isValid' );
        $this->assertTrue( $isValid->isPublic() && ! $isValid->isStatic() );
        $this->assertSame( 0, $isValid->getNumberOfParameters() );
        $this->assertSame( 'bool', (string) $isValid->getReturnType() );

        $violations = new \ReflectionMethod( \Divi5Validator\ValidationResult::class, 'violations' );
        $this->assertTrue( $violations->isPublic() && ! $violations->isStatic() );
        $this->assertSame( 0, $violations->getNumberOfParameters() );
        $this->assertSame( 'array', (string) $violations->getReturnType() );

        $toArray = new \ReflectionMethod( \Divi5Validator\Violation::class, 'toArray' );
        $this->assertTrue( $toArray->isPublic() && ! $toArray->isStatic() );
        $this->assertSame( 0, $toArray->getNumberOfParameters() );
        $this->assertSame( 'array', (string) $toArray->getReturnType() );

        $this->assertSame( [ 'code', 'message', 'path' ], array_keys( ( new \Divi5Validator\Violation( 'c', 'm', 'p' ) )->toArray() ) );
        $this->assertTrue( ( new \Divi5Validator\ValidationResult( [] ) )->isValid() );
    }

    public function testTheRestAuthenticationMethodTheProRoutesUseKeepsItsShape(): void
    {
        $m = new \ReflectionMethod( \AiEditorDivi5\WP\RestController::class, 'require_edit_posts' );
        $this->assertTrue( $m->isPublic() && ! $m->isStatic(), 'RestController::require_edit_posts must stay a public instance method' );
        $this->assertSame( [], $m->getParameters() );
        $this->assertSame( 'WP_Error|bool', (string) $m->getReturnType() );
        $s = new \ReflectionMethod( \AiEditorDivi5\WP\OpenApiSpec::class, 'spec' );
        $this->assertTrue( $s->isStatic() && $s->isPublic() );
    }

    public function testTheEditTargetErrorConstantsStayTheSame(): void
    {
        $this->assertSame( 'not_found', \AiEditorDivi5\WP\EditTarget::NOT_FOUND );
        $this->assertSame( 'forbidden', \AiEditorDivi5\WP\EditTarget::FORBIDDEN );
    }

    public function testTheExtensionGuardKeepsBuiltInsFirst(): void
    {
        $m     = new \ReflectionMethod( \AiEditorDivi5\WP\ExtensionGuard::class, 'tools' );
        $types = array_map( static fn ( \ReflectionParameter $p ): string => (string) $p->getType(), $m->getParameters() );
        $this->assertSame( [ 'array', 'mixed' ], $types );
        $this->assertSame( 'array', (string) $m->getReturnType() );
    }

    public function testEveryHookTheAddOnUsesIsStillFiredByTheFreePlugin(): void
    {
        $source = '';
        foreach ( glob( __DIR__ . '/../../wp-plugin/src/*.php' ) ?: [] as $file ) {
            $source .= (string) file_get_contents( $file );
        }
        foreach ( [ 'jhmg_aied_mcp_tools', 'jhmg_aied_mcp_call', 'jhmg_aied_admin_tabs', 'jhmg_aied_render_admin_tab', 'jhmg_aied_register_rest_routes', 'jhmg_aied_openapi_paths', 'jhmg_aied_openapi_schemas' ] as $hook ) {
            $this->assertStringContainsString( "'{$hook}'", $source, "the free plugin no longer fires {$hook}" );
        }
    }

    public function testEveryRequiredClassExistsInTheFreePlugin(): void
    {
        require_once __DIR__ . '/../src/Compat.php';
        foreach ( \AiEditorDivi5\Pro\Compat::REQUIRED_CLASSES as $class ) {
            $this->assertTrue( class_exists( $class ), $class );
        }
    }
}
