<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Passes 2–5 using real fixtures from make export-layouts.
 * Valid fixtures must pass clean; invalid fixtures must fail with the specific expected code.
 */
class ValidatorSchemaTest extends TestCase
{
    private Validator $v;
    private string $fixturesValid;
    private string $fixturesInvalid;

    protected function setUp(): void
    {
        $this->v             = new Validator();
        $this->fixturesValid   = dirname(__DIR__) . '/fixtures/valid';
        $this->fixturesInvalid = dirname(__DIR__) . '/fixtures/invalid';
    }

    // ---------------------------------------------------------------
    // Valid fixtures — all must pass with zero violations
    // ---------------------------------------------------------------

    public function testValidFixturesAllPass(): void
    {
        $files = glob($this->fixturesValid . '/*.json') ?: [];

        if ($files === []) {
            $this->markTestSkipped("No valid fixtures — run 'make export-layouts' first");
        }

        foreach ($files as $file) {
            $result = $this->v->validate(file_get_contents($file));
            $this->assertTrue(
                $result->isValid(),
                basename($file) . ' should be valid, violations: ' .
                implode('; ', array_map(fn($v) => $v->code() . ': ' . $v->message(), $result->violations()))
            );
        }
    }

    // ---------------------------------------------------------------
    // Invalid fixtures — each must produce the expected violation code
    // ---------------------------------------------------------------

    #[DataProvider('invalidFixtureProvider')]
    public function testInvalidFixtureFails(string $path, string $expectedCode): void
    {
        $this->assertFileExists($path, "Fixture missing: $path");

        $result = $this->v->validate(file_get_contents($path));
        $this->assertFalse($result->isValid(), basename($path) . ' should fail validation');

        $codes = array_map(fn($v) => $v->code(), $result->violations());
        $this->assertContains(
            $expectedCode,
            $codes,
            basename($path) . " expected '$expectedCode', got: [" . implode(', ', $codes) . ']'
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFixtureProvider(): iterable
    {
        $dir = dirname(__DIR__) . '/fixtures/invalid';
        return [
            // Pass 1 — envelope
            'truncated-json'                  => [$dir . '/truncated.json',                       Validator::E_INVALID_JSON],
            'missing-post-content'            => [$dir . '/missing-post-content.json',            Validator::E_MISSING_POST_CONTENT],
            // Pass 3 — schema
            'unknown-module-type'             => [$dir . '/unknown-module-type.json',             Validator::E_UNKNOWN_MODULE_TYPE],
            'missing-required-field'          => [$dir . '/missing-required-field.json',          Validator::E_MISSING_REQUIRED_FIELD],
            'missing-builderversion-compound' => [$dir . '/missing-builderversion-compound.json', Validator::E_MISSING_REQUIRED_FIELD],
            // Pass 4 — hierarchy
            'wrong-nesting'                   => [$dir . '/wrong-nesting.json',                   Validator::E_UNEXPECTED_CHILD_TYPE],
            'wrong-nesting-compound'          => [$dir . '/wrong-nesting-compound.json',          Validator::E_UNEXPECTED_CHILD_TYPE],
            'orphaned-compound-child'         => [$dir . '/orphaned-compound-child.json',         Validator::E_UNEXPECTED_CHILD_TYPE],
            'woo-shop-wrong-nesting'          => [$dir . '/woo-shop-wrong-nesting.json',          Validator::E_UNEXPECTED_CHILD_TYPE],
            // Pass 5 — render-critical
            'scalar-where-object'             => [$dir . '/scalar-where-object.json',             Validator::E_SCALAR_WHERE_OBJECT],
            'scalar-where-object-video'       => [$dir . '/scalar-where-object-video.json',       Validator::E_SCALAR_WHERE_OBJECT],
        ];
    }
}
