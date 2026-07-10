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
use Illuminate\Database\ConnectionInterface;

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
     * @var bool
     */
    protected $ignoreMode;

    /**
     * @param int $postId
     * @param string|null $editedAt
     * @param bool $forceRetry
     * @param bool $ignoreMode
     */
    public function __construct(int $postId, ?string $editedAt, bool $forceRetry = false, bool $ignoreMode = false)
    {
        $this->postId = $postId;
        $this->editedAt = $editedAt;
        $this->forceRetry = $forceRetry;
        $this->ignoreMode = $ignoreMode;
    }

    public function handle(ImageXmlProcessor $processor, SettingsRepositoryInterface $settings, ConnectionInterface $db)
    {
        /** @var Post|null $post */
        $post = Post::find($this->postId);

        if (! $post || ! $post->parsed_content) {
            return;
        }

        $mode = $settings->get('huoxin-auto-image-dimensions.operating_mode', 'client');
        if ($mode === 'client' && ! $this->ignoreMode) {
            return;
        }

        // Abort if post was edited after job was queued
        if ($this->editedAt === null && $post->edited_at !== null) {
            return;
        }

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

                $ip = null;
                if (! $proxy && isset($parsed['host'])) {
                    // Check if host is already an IP
                    if (filter_var($parsed['host'], FILTER_VALIDATE_IP)) {
                        $ip = $parsed['host'];
                        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                            return false; // Direct SSRF
                        }
                    } else {
                        // Resolve both IPv4 and IPv6 to patch IPv6 loopback bypasses
                        $records = dns_get_record($parsed['host'], DNS_A | DNS_AAAA);
                        $validIp = null;
                        if ($records !== false) {
                            foreach ($records as $record) {
                                $recordIp = $record['ip'] ?? $record['ipv6'] ?? null;
                                if ($recordIp && filter_var($recordIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                                    $validIp = $recordIp;
                                    break;
                                }
                            }
                        }
                        if (! $validIp) {
                            return false; // SSRF blocked (no public IPs found)
                        }
                        $ip = $validIp;
                    }

                    // Rewrite SRC to use the verified IP to physically prevent DNS Rebinding (TOCTOU)
                    $urlIp = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$ip]" : $ip;
                    $src = $parsed['scheme'] . '://' . $urlIp . (isset($parsed['port']) ? ':' . $parsed['port'] : '') . ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
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
                } elseif (isset($parsed['host']) && $ip !== null) {
                    $fastImageSize->setStreamContextOptions([
                        'http' => [
                            'header' => "Host: {$parsed['host']}\r\n",
                            'timeout' => 5.0,
                            'follow_location' => 0,
                        ],
                        'ssl' => [
                            'peer_name' => $parsed['host'],
                        ]
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

        $query = Post::where('id', $post->id);
        if ($post->edited_at) {
            $query->where('edited_at', $post->edited_at);
        } else {
            $query->whereNull('edited_at');
        }

        $updatedRows = 1;
        if ($newXml !== false) {
            $updatedRows = $query->update(['content' => $newXml]);
        } else {
            // If no XML changes were needed, we must still ensure the post wasn't
            // concurrently edited before we modify the tracking table.
            if (! $query->exists()) {
                $updatedRows = 0;
            }
        }

        // If updatedRows === 0, it means the optimistic lock failed because the user
        // concurrently edited the post. In this case, another job has already been queued
        // by the Revised listener, so we should abort without modifying the tracking table.
        if ($updatedRows === 0) {
            return;
        }

        $finalXml = $newXml !== false ? $newXml : $xml;
        $hasFailedTags = strpos($finalXml, 'data-image-dimension-failed="1"') !== false;

        if ($hasFailedTags) {
            $db->table('auto_image_dimensions_tracking')->updateOrInsert(
                ['post_id' => $post->id],
                ['has_failed' => true, 'last_attempt_at' => Carbon::now()]
            );
        } else {
            $db->table('auto_image_dimensions_tracking')
                ->where('post_id', $post->id)
                ->delete();
        }
    }
}
