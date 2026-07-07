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
        // Load the XML. We add an XML declaration to ensure UTF-8 handling and strict parsing.
        $success = $dom->loadXML('<?xml version="1.0" encoding="UTF-8"?>' . $xml);
        
        libxml_use_internal_errors($internalErrors);

        if (!$success) {
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
            if (!$src) {
                continue;
            }

            // Skip if it previously failed, unless we are forcing a retry
            if ($img->hasAttribute('data-image-dimension-failed') && !$forceRetry) {
                continue;
            }

            // Fetch dimensions using the provided callable
            $size = call_user_func($fetchDimensions, $src);
            
            if ($size === null) {
                continue;
            }

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
        }

        if ($hasChanges) {
            return $dom->saveXML($dom->documentElement);
        }

        return false;
    }
}
