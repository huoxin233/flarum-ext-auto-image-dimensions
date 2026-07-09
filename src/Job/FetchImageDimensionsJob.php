<?php

/*
 * This file is part of huoxin/auto-image-dimensions.
 *
 * Copyright (c) 2026 huoxin.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Huoxin\AutoImageDimensions\Job;

use Carbon\Carbon;
use Exception;
use FastImageSize\FastImageSize;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Huoxin\AutoImageDimensions\Service\ImageXmlProcessor;

class FetchImageDimensionsJob extends AbstractJob
{
    /**
     * @var int
     */
    protected $postId;

    /**
     * @var string|null
     */
    protected $editedAt;

    /**
     * @var bool
     */
    protected $forceRetry;

    /**
     * @param int $postId
     * @param string|null $editedAt
     * @param bool $forceRetry
     */
    public function __construct(int $postId, ?string $editedAt, bool $forceRetry = false)
    {
        $this->postId = $postId;
        $this->editedAt = $editedAt;
        $this->forceRetry = $forceRetry;
    }

    public function handle(ImageXmlProcessor $processor, SettingsRepositoryInterface $settings)
    {
        /** @var Post|null $post */
        $post = Post::find($this->postId);

        if (! $post || ! $post->parsed_content) {
            return;
        }

        $mode = $settings->get('huoxin-auto-image-dimensions.operating_mode', 'client');
        if ($mode === 'client') {
            return;
        }

        // Abort if post was edited after job was queued
        if ($this->editedAt !== null && $post->edited_at !== null) {
            $jobEditedAt = Carbon::parse($this->editedAt);
            if ($post->edited_at->gt($jobEditedAt)) {
                return;
            }
        }

        // DOMDocument requires a single root node (Flarum uses <r> or <t>)
        $xml = $post->parsed_content;

        $proxy = $settings->get('huoxin-auto-image-dimensions.proxy');
        $maxHeight = (int) $settings->get('huoxin-auto-image-dimensions.max_height', 400);

        $newXml = $processor->process($xml, function (string $src) use ($proxy) {
            try {
                $parsed = parse_url($src);
                if (! isset($parsed['scheme']) || ! in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
                    return false;
                }

                if (! $proxy) {
                    if (isset($parsed['host'])) {
                        $ip = gethostbyname($parsed['host']);
                        if ($ip === $parsed['host'] && ! filter_var($ip, FILTER_VALIDATE_IP)) {
                            return false; // DNS failed
                        }
                        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                            return false; // SSRF blocked (Private/Reserved IP)
                        }
                    }
                }

                $fastImageSize = new FastImageSize();

                if ($proxy) {
                    $fastImageSize->setStreamContextOptions([
                        'http' => [
                            'proxy' => $proxy,
                            'request_fulluri' => true,
                            'timeout' => 5.0,
                        ],
                    ]);
                }

                $size = $fastImageSize->getImageSize($src);

                if ($size !== false && isset($size['width']) && isset($size['height'])) {
                    return [(int) $size['width'], (int) $size['height']];
                }
            } catch (Exception $e) {
                // Ignore exceptions (e.g., timeout, 404) but mark as failed
            }

            return false;
        }, $this->forceRetry, $maxHeight);

        if ($newXml !== false) {
            $query = Post::where('id', $post->id);
            if ($post->edited_at) {
                $query->where('edited_at', $post->edited_at);
            } else {
                $query->whereNull('edited_at');
            }
            $query->update(['content' => $newXml]);
        }
    }
}
