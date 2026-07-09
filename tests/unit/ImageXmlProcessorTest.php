<?php

namespace Huoxin\AutoImageDimensions\Tests\Unit;

use Huoxin\AutoImageDimensions\Service\ImageXmlProcessor;
use PHPUnit\Framework\TestCase;

class ImageXmlProcessorTest extends TestCase
{
    /**
     * @var ImageXmlProcessor
     */
    protected $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new ImageXmlProcessor();
    }

    public function test_it_adds_dimensions_to_standard_markdown_image()
    {
        $xml = '<r><p><IMG alt="test" src="https://example.com/img.png"><s>![test]</s><e>(https://example.com/img.png)</e></IMG></p></r>';

        $newXml = $this->processor->process($xml, function ($src) {
            $this->assertEquals('https://example.com/img.png', $src);
            return [800, 600];
        });

        $this->assertNotFalse($newXml);
        $this->assertStringContainsString('width="533"', $newXml);
        $this->assertStringContainsString('height="400"', $newXml);
        $this->assertStringContainsString('alt="test"', $newXml); // ensure alt wasn't dropped
    }

    public function test_it_adds_dimensions_to_standard_bbcode_image()
    {
        $xml = '<r><p><IMG src="https://example.com/img.png"><s>[img]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>';

        $newXml = $this->processor->process($xml, function ($src) {
            return [1024, 768];
        });

        $this->assertNotFalse($newXml);
        $this->assertStringContainsString('width="533"', $newXml);
        $this->assertStringContainsString('height="400"', $newXml);
    }

    public function test_it_skips_when_both_dimensions_are_present()
    {
        // Simulated output of [img width=50 height=50]
        $xml = '<r><p><IMG height="50" src="https://example.com/img.png" width="50"><s>[img width=50 height=50]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>';

        $called = false;
        $newXml = $this->processor->process($xml, function ($src) use (&$called) {
            $called = true;
            return [800, 600];
        });

        // The processor should return false because no changes were made (it skipped it)
        $this->assertFalse($newXml);
        // The fetch callback should never have been executed
        $this->assertFalse($called);
    }

    public function test_it_caps_height_when_both_dimensions_are_present_and_exceed_max_height()
    {
        // Simulated output of [img width=1000 height=1000]
        $xml = '<r><p><IMG height="1000" src="https://example.com/img.png" width="1000"><s>[img width=1000 height=1000]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>';

        $called = false;
        $newXml = $this->processor->process($xml, function ($src) use (&$called) {
            $called = true;
            return [1000, 1000];
        });

        // The processor should rescale it down to 400x400
        $this->assertNotFalse($newXml);
        // The fetch callback should never have been executed (it doesn't need natural sizes)
        $this->assertFalse($called);
        
        $this->assertStringContainsString('width="400"', $newXml);
        $this->assertStringContainsString('height="400"', $newXml);
    }

    public function test_it_sorts_attributes_alphabetically()
    {
        // Out of order attributes with unneeded failure tag
        $xml = '<r><p><IMG width="1000" src="https://example.com/img.png" data-image-dimension-failed="1" height="1000"><s>[img width=1000 height=1000]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>';

        $newXml = $this->processor->process($xml, function ($src) {
            return [1000, 1000];
        });

        $this->assertNotFalse($newXml);
        
        // Assert exact alphabetical order in the output string
        $this->assertStringContainsString('<IMG height="400" src="https://example.com/img.png" width="400">', $newXml);
    }

    public function test_it_recovers_dropped_width_and_calculates_aspect_ratio_height()
    {
        // Simulated output of [img width=50] where s9e stripped the width attribute
        $xml = '<r><p><IMG src="https://example.com/img.png"><s>[img width=50]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>';

        $newXml = $this->processor->process($xml, function ($src) {
            // Real image is 1000x500 (2:1 aspect ratio)
            return [1000, 500];
        });

        $this->assertNotFalse($newXml);
        // Recovered width should be 50
        $this->assertStringContainsString('width="50"', $newXml);
        // Calculated height should be 50 * (500 / 1000) = 25
        $this->assertStringContainsString('height="25"', $newXml);
    }

    public function test_it_recovers_dropped_height_and_calculates_aspect_ratio_width()
    {
        // Simulated output of [img height=100] where s9e stripped the height attribute
        $xml = '<r><p><IMG src="https://example.com/img.png"><s>[img height=100]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>';

        $newXml = $this->processor->process($xml, function ($src) {
            // Real image is 200x400 (1:2 aspect ratio)
            return [200, 400];
        });

        $this->assertNotFalse($newXml);
        // Recovered height should be 100
        $this->assertStringContainsString('height="100"', $newXml);
        // Calculated width should be 100 * (200 / 400) = 50
        $this->assertStringContainsString('width="50"', $newXml);
    }

    public function test_it_applies_failure_attribute_on_fetch_failure()
    {
        $xml = '<r><p><IMG src="https://example.com/img.png"><s>[img]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>';

        $newXml = $this->processor->process($xml, function ($src) {
            return false; // Simulate Guzzle Exception / 404
        });

        $this->assertNotFalse($newXml);
        $this->assertStringContainsString('data-image-dimension-failed="1"', $newXml);
        $this->assertStringNotContainsString('width="', $newXml);
    }

    public function test_it_skips_previously_failed_images()
    {
        $xml = '<r><p><IMG data-image-dimension-failed="1" src="https://example.com/img.png"><s>[img]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>';

        $called = false;
        $newXml = $this->processor->process($xml, function ($src) use (&$called) {
            $called = true;
            return [100, 100];
        });

        $this->assertFalse($newXml);
        $this->assertFalse($called);
    }

    public function test_it_retries_failed_images_when_force_retry_is_true()
    {
        $xml = '<r><p><IMG data-image-dimension-failed="1" src="https://example.com/img.png"><s>[img]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>';

        $called = false;
        $newXml = $this->processor->process($xml, function ($src) use (&$called) {
            $called = true;
            return [800, 600];
        }, true); // forceRetry = true

        $this->assertNotFalse($newXml);
        $this->assertTrue($called);
        $this->assertStringContainsString('width="533"', $newXml);
        $this->assertStringNotContainsString('data-image-dimension-failed', $newXml);
    }
}
