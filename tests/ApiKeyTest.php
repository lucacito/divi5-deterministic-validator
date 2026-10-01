<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ApiKey;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ApiKey.php';

/** An unauthenticated request must never create a key as a side effect. */
class ApiKeyTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['__wp_options']['ai_editor_divi5_api_key'], $GLOBALS['__wp_options']['ai_editor_divi5_api_user_id']);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    public function testVerifyIsReadOnlyWhenNoKeyExists(): void
    {
        $this->assertFalse(ApiKey::verify(''));
        $this->assertFalse(ApiKey::verify(str_repeat('a', 64)));
        $this->assertArrayNotHasKey('ai_editor_divi5_api_key', $GLOBALS['__wp_options'], 'verify() must not generate a key');
    }

    public function testVerifyAcceptsOnlyTheStoredKey(): void
    {
        $key = ApiKey::generate();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertTrue(ApiKey::verify($key));
        $this->assertFalse(ApiKey::verify($key . 'x'));
        $this->assertFalse(ApiKey::verify(''));
    }
}
