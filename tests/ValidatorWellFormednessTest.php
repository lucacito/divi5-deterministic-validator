<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Pass 1 — well-formedness.
 * These tests do not require Divi 5 schema knowledge and run immediately.
 */
class ValidatorWellFormednessTest extends TestCase
{
    private Validator $v;

    protected function setUp(): void
    {
        $this->v = new Validator();
    }

    public function testEmptyStringFails(): void
    {
        $result = $this->v->validate('');
        $this->assertFalse($result->isValid());
        $this->assertViolationCode(Validator::E_EMPTY_DOCUMENT, $result->violations());
    }

    public function testWhitespaceOnlyFails(): void
    {
        $result = $this->v->validate("   \n\t  ");
        $this->assertFalse($result->isValid());
        $this->assertViolationCode(Validator::E_EMPTY_DOCUMENT, $result->violations());
    }

    public function testInvalidJsonFails(): void
    {
        $result = $this->v->validate('{not valid json');
        $this->assertFalse($result->isValid());
        $this->assertViolationCode(Validator::E_INVALID_JSON, $result->violations());
    }

    public function testTruncatedJsonFails(): void
    {
        $result = $this->v->validate('{"version":"1.0","layouts":[{"id":1');
        $this->assertFalse($result->isValid());
        $this->assertViolationCode(Validator::E_INVALID_JSON, $result->violations());
    }

    public function testJsonArrayRootFails(): void
    {
        $result = $this->v->validate('[1, 2, 3]');
        $this->assertFalse($result->isValid());
        $this->assertViolationCode(Validator::E_WRONG_ROOT_TYPE, $result->violations());
    }

    public function testJsonStringRootFails(): void
    {
        $result = $this->v->validate('"just a string"');
        $this->assertFalse($result->isValid());
        $this->assertViolationCode(Validator::E_WRONG_ROOT_TYPE, $result->violations());
    }

    public function testJsonNullRootFails(): void
    {
        $result = $this->v->validate('null');
        $this->assertFalse($result->isValid());
        $this->assertViolationCode(Validator::E_WRONG_ROOT_TYPE, $result->violations());
    }

    public function testMinimalObjectPasses(): void
    {
        // An empty JSON object is well-formed; schema checks are stubs for now.
        $result = $this->v->validate('{}');
        // Well-formedness passes; result depends on schema stubs (currently no required fields).
        $this->assertTrue($result->isValid(), 'Expected pass with stub schema, got: ' . $this->describeViolations($result->violations()));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @param \Divi5Validator\Violation[] $violations */
    private function assertViolationCode(string $expectedCode, array $violations): void
    {
        $codes = array_map(fn($v) => $v->code(), $violations);
        $this->assertContains(
            $expectedCode,
            $codes,
            "Expected violation code '$expectedCode', got: [" . implode(', ', $codes) . ']'
        );
    }

    /** @param \Divi5Validator\Violation[] $violations */
    private function describeViolations(array $violations): string
    {
        if ($violations === []) {
            return '(none)';
        }
        return implode('; ', array_map(fn($v) => $v->code() . ': ' . $v->message(), $violations));
    }
}
