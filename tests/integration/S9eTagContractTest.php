<?php

namespace Huoxin\AutoImageDimensions\Tests\Integration;

use Flarum\Formatter\Formatter;
use Flarum\Testing\integration\TestCase;

class S9eTagContractTest extends TestCase
{
    /**
     * @var Formatter
     */
    protected $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flarum-markdown');
        $this->extension('flarum-bbcode');
        // Resolve formatter from the app container to get Flarum's configured s9e instance
        $this->formatter = $this->app()->getContainer()->make(Formatter::class);
    }

    public function test_s9e_compiles_images_to_uppercase_img_tag()
    {
        $markdown = '![z-alt](https://example.com/image.png)';
        $xml = $this->formatter->parse($markdown);

        $this->assertStringContainsString('<IMG ', $xml, 's9e TextFormatter contract violation: The compiled XML no longer contains uppercase <IMG tags. The auto-image-dimensions extension relies on this exact casing for tracking table lookups.');

        // Assert that s9e natively sorts attributes alphabetically (alt before src)
        $this->assertStringContainsString('<IMG alt="z-alt" src="https://example.com/image.png"', $xml, 's9e TextFormatter contract violation: Attributes are no longer sorted alphabetically natively.');
    }

    public function test_s9e_compiles_bbcode_images_to_uppercase_img_tag()
    {
        $bbcode = '[img]https://example.com/image.png[/img]';
        $xml = $this->formatter->parse($bbcode);

        $this->assertStringContainsString('<IMG ', $xml, 's9e TextFormatter contract violation for BBCode.');
    }
}
