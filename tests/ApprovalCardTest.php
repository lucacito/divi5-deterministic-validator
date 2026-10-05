<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use AiEditorDivi5\WP\ApprovalMode;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminFormShims.php';
require_once __DIR__ . '/../wp-plugin/src/ApprovalMode.php';
require_once __DIR__ . '/../wp-plugin/src/AdminPage.php';

class ApprovalCardTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
    }

    private function card(): string
    {
        ob_start();
        ( new AdminPage() )->approvalCard();

        return (string) ob_get_clean();
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/' . $file );
    }

    public function testTheCardPostsToAdminPostWithANonceAndTheCheckboxIsUncheckedByDefault(): void
    {
        $html = $this->card();
        $this->assertStringContainsString( 'name="action" value="ai_editor_divi5_toggle_approval"', $html );
        $this->assertStringContainsString( 'nonce-for-ai_editor_divi5_toggle_approval', $html );
        $this->assertStringContainsString( 'name="require_preview"', $html );
        $this->assertStringNotContainsString( "checked='checked'", $html );
        $this->assertStringContainsString( 'Require a preview before every save', $html );
    }

    public function testTheCheckboxIsCheckedWhenTheModeIsOn(): void
    {
        ApprovalMode::set( true );
        $this->assertStringContainsString( "checked='checked'", $this->card() );
    }

    public function testTheHandlerIsRegisteredGuardedAndOnlyOneMeansOn(): void
    {
        $src = $this->src( 'src/AdminPage.php' );
        $this->assertStringContainsString( "add_action('admin_post_ai_editor_divi5_toggle_approval', [\$this, 'handleToggleApproval']);", $src );
        $this->assertStringContainsString( "\$this->guard('ai_editor_divi5_toggle_approval');", $src );
        $this->assertStringContainsString( "'1' === sanitize_text_field( wp_unslash( \$_POST['require_preview'] ?? '' ) )", $src );
        $this->assertStringContainsString( "'approval_on'", $src );
        $this->assertStringContainsString( "'approval_off'", $src );
    }

    public function testTheCardIsShownOnTheSettingsTabAndDescribedOnTheFeaturesTab(): void
    {
        $src = $this->src( 'src/AdminPage.php' );
        $this->assertStringContainsString( '$this->approvalCard();', $src );
        $this->assertStringContainsString( 'Approval mode (optional)', $src );
    }

    public function testUninstallRemovesTheSettingAndTheReadmeExplainsIt(): void
    {
        $this->assertStringContainsString( 'ApprovalMode::OPTION', $this->src( 'uninstall.php' ) );
        $readme = $this->src( 'readme.txt' );
        $this->assertStringContainsString( 'Approval mode', $readme );
        $this->assertStringContainsString( 'Require a preview before every save', $readme );
    }
}
