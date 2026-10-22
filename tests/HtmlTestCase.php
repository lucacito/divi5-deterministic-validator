<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HtmlMapper;
use AiEditorDivi5\WP\HtmlSource;
use PHPUnit\Framework\TestCase;

foreach ( [ 'HtmlSource', 'HtmlProse', 'HtmlMapper', 'DiviMarkup', 'HtmlImages', 'HtmlFonts', 'HtmlToDivi', 'ImagePack', 'ImageTokens', 'CodePolicy', 'LayoutGate' ] as $htmlClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $htmlClass . '.php';
}

/** Helpers shared by the html_to_divi tests. */
abstract class HtmlTestCase extends TestCase
{
    protected const FIXTURES = __DIR__ . '/../fixtures/html';

    /** @return array<string,mixed> the plan for an HTML fragment (the body's content) */
    protected function plan( string $bodyHtml ): array
    {
        $s = HtmlSource::parse( '<html><body>' . $bodyHtml . '</body></html>' );
        $this->assertNull( $s['error'], (string) $s['error'] );

        return HtmlMapper::map( $s['body'] );
    }

    /** The module types of every chunk of the first section, flattened. @return list<string> */
    protected function types( array $plan, int $section = 0 ): array
    {
        $out = [];
        foreach ( $plan['sections'][ $section ]['chunks'] as $chunk ) {
            foreach ( $chunk['modules'] ?? [] as $m ) {
                $out[] = $m['type'];
            }
        }

        return $out;
    }

    /** Resolves {{aied:image:token}} like the plugin does, against a fixed pack address. */
    public static function resolve( string $layout ): string
    {
        return \AiEditorDivi5\WP\ImageTokens::resolve( $layout, 'https://example.com/wp-content/plugins/x/assets/images/' );
    }

    /** @return array<string,mixed> */
    protected function convert( string $html, array $options = [] ): array
    {
        return \AiEditorDivi5\WP\HtmlToDivi::convert( $html, $options, \AiEditorDivi5\WP\ImagePack::manifest(), null, [ self::class, 'resolve' ] );
    }
}
