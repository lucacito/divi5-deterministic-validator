<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/AdminPage.php';

/** The one-line "has an assistant reached this site yet?" status on the Settings screen. */
class AdminLastRequestTest extends TestCase
{
    public function testWithNoRequestItSaysSoAndTellsTheOwnerWhatToDo(): void
    {
        $text = AdminPage::lastRequestText(null, '');
        $this->assertStringContainsString('No request from an AI assistant has reached this site yet', $text);
        $this->assertStringContainsString('ask your assistant to list your Divi pages', $text);
    }

    public function testWithARequestItNamesWhenWhatAndWho(): void
    {
        $text = AdminPage::lastRequestText(['endpoint' => 'update_layout', 'client' => 'Claude Code', 'age' => 180], '3 mins');
        $this->assertSame('Last request from an AI assistant: 3 mins ago (update_layout, Claude Code).', $text);
    }

    public function testAnUnknownAssistantIsNamedAsSuch(): void
    {
        $text = AdminPage::lastRequestText(['endpoint' => 'ping', 'client' => '', 'age' => 5], 'less than a minute');
        $this->assertSame('Last request from an AI assistant: less than a minute ago (ping, unknown assistant).', $text);
    }
}
