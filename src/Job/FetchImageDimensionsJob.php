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
use DOMDocument;
use DOMElement;
use Exception;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use GuzzleHttp\Client;

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

    public function handle()
    {
        /** @var Post|null $post */
        $post = Post::find($this->postId);

        if (! $post || ! $post->parsed_content) {
            return;
        }

        // Race condition prevention
        // If the post was edited after this job was queued, we abort.
        if ($this->editedAt !== null && $post->edited_at !== null) {
            $jobEditedAt = Carbon::parse($this->editedAt);
            if ($post->edited_at->gt($jobEditedAt)) {
                return;
            }
        }

        // We wrap the content in a root element so DOMDocument can parse it easily if it has multiple root elements.
        // Flarum uses <r> or <t> as root tags.
        $xml = $post->parsed_content;

        $dom = new DOMDocument();
        // Suppress warnings for invalid XML/HTML
        $internalErrors = libxml_use_internal_errors(true);
        // Load the XML. We add an XML declaration to ensure UTF-8 handling and strict parsing.
        $success = $dom->loadXML('<?xml version="1.0" encoding="UTF-8"?>'.$xml);

        libxml_use_internal_errors($internalErrors);

        if (! $success) {
            return;
        }

        $images = $dom->getElementsByTagName('IMG');
        $hasChanges = false;

        foreach ($images as $img) {
            /** @var DOMElement $img */

            $hasUserWidth = $img->hasAttribute('width');
            $hasUserHeight = $img->hasAttribute('height');
            
            $userWidthVal = $hasUserWidth ? (float) $img->getAttribute('width') : 0;
            $userHeightVal = $hasUserHeight ? (float) $img->getAttribute('height') : 0;

            // Flarum's BBCode parser can drop 'width' or 'height' if they aren't provided in strict pairs.
            // We extract them manually from the raw <s> tag markdown to ensure we respect user intent.
            if (!$hasUserWidth || !$hasUserHeight) {
                $sTags = $img->getElementsByTagName('s');
                if ($sTags->length > 0) {
                    $rawText = $sTags->item(0)->nodeValue;
                    
                    if (!$hasUserWidth && preg_match('/width=[\'"]?(\d+)/i', $rawText, $wMatch)) {
                        $hasUserWidth = true;
                        $userWidthVal = (float) $wMatch[1];
                        $img->setAttribute('width', (string) $userWidthVal);
                    }
                    if (!$hasUserHeight && preg_match('/height=[\'"]?(\d+)/i', $rawText, $hMatch)) {
                        $hasUserHeight = true;
                        $userHeightVal = (float) $hMatch[1];
                        $img->setAttribute('height', (string) $userHeightVal);
                    }
                }
            }

            // Check if it already has both dimensions
            if ($hasUserWidth && $hasUserHeight) {
                continue;
            }

            $src = $img->getAttribute('src');
            if (! $src) {
                continue;
            }

            // Skip if it previously failed, unless we are forcing a retry
            if ($img->hasAttribute('data-image-dimension-failed') && ! $this->forceRetry) {
                continue;
            }

            // Fetch dimensions using Guzzle
            try {
                $client = new Client(['timeout' => 5]);
                // We use stream to not download the whole image if possible, but for getimagesize we need a local file or wrapper
                // For simplicity and safety, we fetch it into a temp stream
                $response = $client->request('GET', $src, ['stream' => true]);

                if ($response->getStatusCode() === 200) {
                    $stream = $response->getBody();

                    // Since getimagesize needs a file path or URI, and Guzzle returns a stream,
                    // we can read a chunk and use imagecreatefromstring, or save to a temp file.
                    // Saving to a temp file is most reliable for getimagesize.
                    $tmpFile = tempnam(sys_get_temp_dir(), 'flarum_img_');
                    if ($tmpFile) {
                        file_put_contents($tmpFile, $stream->getContents());
                        $size = @getimagesize($tmpFile);
                        unlink($tmpFile);

                        if ($size !== false) {
                            $realWidth = $size[0];
                            $realHeight = $size[1];

                            if ($hasUserWidth && !$hasUserHeight) {
                                // User defined width, calculate height to preserve aspect ratio
                                if ($realWidth > 0) {
                                    $calcHeight = round($userWidthVal * ($realHeight / $realWidth));
                                    $img->setAttribute('height', (string) $calcHeight);
                                }
                            } elseif ($hasUserHeight && !$hasUserWidth) {
                                // User defined height, calculate width to preserve aspect ratio
                                if ($realHeight > 0) {
                                    $calcWidth = round($userHeightVal * ($realWidth / $realHeight));
                                    $img->setAttribute('width', (string) $calcWidth);
                                }
                            } else {
                                // Neither defined, inject true dimensions
                                $img->setAttribute('width', (string) $realWidth);
                                $img->setAttribute('height', (string) $realHeight);
                            }

                            $img->removeAttribute('data-image-dimension-failed');
                            $hasChanges = true;
                        } else {
                            $img->setAttribute('data-image-dimension-failed', '1');
                            $hasChanges = true;
                        }
                    } else {
                        $img->setAttribute('data-image-dimension-failed', '1');
                        $hasChanges = true;
                    }
                } else {
                    $img->setAttribute('data-image-dimension-failed', '1');
                    $hasChanges = true;
                }
            } catch (Exception $e) {
                // Ignore exceptions (e.g., timeout, 404) but mark as failed
                $img->setAttribute('data-image-dimension-failed', '1');
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $newXml = $dom->saveXML($dom->documentElement);
            Post::where('id', $post->id)->update(['content' => $newXml]);
        }
    }
}
