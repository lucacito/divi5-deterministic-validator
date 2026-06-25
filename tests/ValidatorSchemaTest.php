<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Passes 2–5 — schema, hierarchy, referential integrity, render-critical.
 *
 * These tests load real fixture files captured by `make export-layouts`.
 * They are marked incomplete until Phase 2 (capture ground truth) runs and
 * produces fixtures in fixtures/valid/ and fixtures/invalid/.
 *
 * IMPORTANT: Do not hand-write fixture JSON here. Every fixture must come from
 * a real Divi 5 export so the schema is empirically correct.
 */
class ValidatorSchemaTest extends TestCase
{
    private Validator $v;
    private string $fixturesValid;
    private string $fixturesInvalid;

    protected function setUp(): void
    {
        $this->v = new Validator();
        $this->fixturesValid   = dirname(__DIR__) . '/fixtures/valid';
        $this->fixturesInvalid = dirname(__DIR__) . '/fixtures/invalid';
    }

    // ---------------------------------------------------------------
    // Valid fixtures — should all pass
    // ---------------------------------------------------------------

    public function testValidFixturesAllPass(): void
    {
        $files = glob($this->fixturesValid . '/*.json') ?: [];

        if ($files === []) {
            $this->markTestSkipped("No valid fixtures yet — run 'make export-layouts' first");
        }

        foreach ($files as $file) {
            $json   = file_get_contents($file);
            $result = $this->v->validate($json);

            $this->assertTrue(
                $result->isValid(),
                basename($file) . ' should be valid, but got violations: ' .
                implode('; ', array_map(fn($v) => $v->code() . ': ' . $v->message(), $result->violations()))
            );
        }
    }

    // ---------------------------------------------------------------
    // Invalid fixtures — each must fail with a specific violation code
    // ---------------------------------------------------------------

    #[DataProvider('invalidFixtureProvider')]
    public function testInvalidFixtureFails(string $path, string $expectedCode): void
    {
        if (!file_exists($path)) {
            $this->markTestIncomplete("Fixture not yet created: $path — run 'make export-layouts' first");
        }

        $json   = file_get_contents($path);
        $result = $this->v->validate($json);

        $this->assertFalse($result->isValid(), basename($path) . ' should fail validation');

        $codes = array_map(fn($v) => $v->code(), $result->violations());
        $this->assertContains(
            $expectedCode,
            $codes,
            basename($path) . " expected violation '$expectedCode', got: [" . implode(', ', $codes) . ']'
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFixtureProvider(): iterable
    {
        $dir = dirname(__DIR__) . '/fixtures/invalid';

        // Each entry: [fixture file, expected violation code]
        // Files are created in Phase 5 once the real schema is known.
        // Add entries here as invalid fixtures are created.
        return [
            'truncated-json'             => [$dir . '/truncated.json',             Validator::E_INVALID_JSON],
            'unknown-module-type'        => [$dir . '/unknown-module-type.json',   Validator::E_UNKNOWN_MODULE_TYPE],
            'missing-required-field'     => [$dir . '/missing-required-field.json', Validator::E_MISSING_REQUIRED_FIELD],
            'wrong-nesting'              => [$dir . '/wrong-nesting.json',          Validator::E_WRONG_NESTING],
            'scalar-where-object'        => [$dir . '/scalar-where-object.json',   Validator::E_SCALAR_WHERE_OBJECT],
            'orphaned-reference'         => [$dir . '/orphaned-reference.json',    Validator::E_ORPHANED_REFERENCE],
        ];
    }

    public function testInvalidFixtureDirectoryExists(): void
    {
        $this->assertDirectoryExists(
            $this->fixturesInvalid,
            "fixtures/invalid/ must exist — run 'make export-layouts' to create fixtures"
        );
    }
}
