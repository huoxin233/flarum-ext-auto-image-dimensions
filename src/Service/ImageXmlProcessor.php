<?php

/*
 * This file is part of huoxin/auto-image-dimensions.
 *
 * Copyright (c) 2026 huoxin.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Huoxin\AutoImageDimensions\Service;

use DOMDocument;
use DOMElement;

class ImageXmlProcessor
{
    /**
     * Process an XML string to inject image dimensions.
     *
     * @param string $xml The raw XML string from the database.
     * @param callable $fetchDimensions A callable that takes a string $src and returns [width, height], false (failed), or null (skip).
     * @param bool $forceRetry Whether to retry previously failed images.
     * @return string|false The modified XML string, or false if no changes were made/parsing failed.
     */
    public function process(string $xml, callable $fetchDimensions, bool $forceRetry = false)
    {
        $dom = new DOMDocument();
        // Suppress warnings for invalid XML/HTML
        $internalErrors = libxml_use_internal_errors(true);
        // Ensure UTF-8 parsing
        $success = $dom->loadXML('<?xml version="1.0" encoding="UTF-8"?>'.$xml);

        libxml_use_internal_errors($internalErrors);

        if (! $success) {
            return false;
        }

        $images = $dom->getElementsByTagName('IMG');
        $hasChanges = false;

        foreach ($images as $img) {
            /** @var DOMElement $img */

            $hasUserWidth = $img->hasAttribute('width');
            $hasUserHeight = $img->hasAttribute('height');

            $userWidthVal = $hasUserWidth ? (float) $img->getAttribute('width') : 0;
            $userHeightVal = $hasUserHeight ? (float) $img->getAttribute('height') : 0;

            // Extract manually from <s> tag markdown to recover dropped width/height attributes
            if (! $hasUserWidth || ! $hasUserHeight) {
                $sTags = $img->getElementsByTagName('s');
                if ($sTags->length > 0) {
                    $rawText = $sTags->item(0)->nodeValue;

                    if (! $hasUserWidth && preg_match('/width=[\'"]?(\d+)/i', $rawText, $wMatch)) {
                        $hasUserWidth = true;
                        $userWidthVal = (float) $wMatch[1];
                        $img->setAttribute('width', (string) $userWidthVal);
                    }
                    if (! $hasUserHeight && preg_match('/height=[\'"]?(\d+)/i', $rawText, $hMatch)) {
                        $hasUserHeight = true;
                        $userHeightVal = (float) $hMatch[1];
                        $img->setAttribute('height', (string) $userHeightVal);
                    }
                }
            }

            if ($hasUserWidth && $hasUserHeight) {
                continue;
            }

            $src = $img->getAttribute('src');
            if (! $src) {
                continue;
            }

            if ($img->hasAttribute('data-image-dimension-failed') && ! $forceRetry) {
                continue;
            }

            $size = call_user_func($fetchDimensions, $src);

            if ($size === null) {
                continue;
            }

            if ($size !== false) {
                $realWidth = $size[0];
                $realHeight = $size[1];

                if ($hasUserWidth && ! $hasUserHeight) {
                    if ($realWidth > 0) {
                        $calcHeight = round($userWidthVal * ($realHeight / $realWidth));
                        $img->setAttribute('height', (string) $calcHeight);
                    }
                } elseif ($hasUserHeight && ! $hasUserWidth) {
                    if ($realHeight > 0) {
                        $calcWidth = round($userHeightVal * ($realWidth / $realHeight));
                        $img->setAttribute('width', (string) $calcWidth);
                    }
                } else {
                    $img->setAttribute('width', (string) $realWidth);
                    $img->setAttribute('height', (string) $realHeight);
                }

                $img->removeAttribute('data-image-dimension-failed');
                $hasChanges = true;
            } else {
                $img->setAttribute('data-image-dimension-failed', '1');
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            return $dom->saveXML($dom->documentElement);
        }

        return false;
    }
}
