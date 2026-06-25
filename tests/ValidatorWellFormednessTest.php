<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Pass 1 — envelope well-formedness.
 * These do not require Divi 5 schema knowledge.
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
        $this->assertFails($result, Validator::E_EMPTY_DOCUMENT);
    }

    public function testWhitespaceOnlyFails(): void
    {
        $result = $this->v->validate("   \n\t  ");
        $this->assertFails($result, Validator::E_EMPTY_DOCUMENT);
    }

    public function testInvalidJsonFails(): void
    {
        $result = $this->v->validate('{not valid json');
        $this->assertFails($result, Validator::E_INVALID_JSON);
    }

    public function testTruncatedJsonFails(): void
    {
        $result = $this->v->validate('{"post_content":"<!-- wp:divi/placeholder --><!-- wp:divi/section');
        $this->assertFails($result, Validator::E_INVALID_JSON);
    }

    public function testJsonArrayRootFails(): void
    {
        $result = $this->v->validate('[1, 2, 3]');
        $this->assertFails($result, Validator::E_WRONG_ROOT_TYPE);
    }

    public function testJsonStringRootFails(): void
    {
        $result = $this->v->validate('"just a string"');
        $this->assertFails($result, Validator::E_WRONG_ROOT_TYPE);
    }

    public function testJsonNullRootFails(): void
    {
        $result = $this->v->validate('null');
        $this->assertFails($result, Validator::E_WRONG_ROOT_TYPE);
    }

    public function testMissingPostContentFails(): void
    {
        $result = $this->v->validate('{"source":"test"}');
        $this->assertFails($result, Validator::E_MISSING_POST_CONTENT);
    }

    public function testNonStringPostContentFails(): void
    {
        $result = $this->v->validate('{"post_content": 42}');
        $this->assertFails($result, Validator::E_WRONG_FIELD_TYPE);
    }

    public function testEmptyPostContentProducesNoBlocskFound(): void
    {
        $result = $this->v->validate('{"post_content":""}');
        $this->assertFalse($result->isValid());
    }

    // ---------------------------------------------------------------

    private function assertFails(\Divi5Validator\ValidationResult $result, string $expectedCode): void
    {
        $this->assertFalse($result->isValid());
        $codes = array_map(fn($v) => $v->code(), $result->violations());
        $this->assertContains(
            $expectedCode,
            $codes,
            "Expected violation '$expectedCode', got: [" . implode(', ', $codes) . ']'
        );
    }
}
