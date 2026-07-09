<?php

/*
 * This file is part of huoxin/auto-image-dimensions.
 *
 * Copyright (c) 2026 huoxin.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Huoxin\AutoImageDimensions\Console;

use DOMDocument;
use Flarum\Post\Post;
use Illuminate\Console\Command;

class ClearImageDimensionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'auto-image-dimensions:clear-all {--force : Force execution without confirmation} {--dry-run : Only calculate how many posts would be affected}';

    /**
     * @var string
     */
    protected $description = 'Clear all cached image dimensions from all posts in the forum.';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        if (! $this->option('force') && ! $isDryRun) {
            if (! $this->confirm('WARNING: This will strip width and height attributes from EVERY image in the forum. This will cause layout shifts until dimensions are recalculated. Do you wish to continue?')) {
                $this->info('Aborted.');

                return;
            }
        }

        if ($isDryRun) {
            $this->info('Starting DRY RUN. No changes will be made to the database.');
        }

        $this->info('Finding posts with images...');

        $query = Post::where('type', 'comment')
            ->where(function ($q) {
                $q->where('content', 'like', '%<IMG %')
                  ->orWhere('content', 'like', '%<IMG>%');
            });

        $total = $query->count();

        if ($total === 0) {
            $this->info('No posts found containing images.');

            return;
        }

        $this->info("Found $total posts. Clearing dimensions...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $clearedCount = 0;

        $query->chunkById(100, function ($posts) use ($bar, &$clearedCount, $isDryRun) {
            foreach ($posts as $post) {
                if (! $post->parsed_content) {
                    $bar->advance();
                    continue;
                }

                $dom = new DOMDocument();
                $internalErrors = libxml_use_internal_errors(true);
                $success = $dom->loadXML('<?xml version="1.0" encoding="UTF-8"?>'.$post->parsed_content);
                libxml_use_internal_errors($internalErrors);

                if (! $success) {
                    $bar->advance();
                    continue;
                }

                $images = $dom->getElementsByTagName('IMG');
                $hasChanges = false;

                foreach ($images as $img) {
                    if ($img->hasAttribute('width')) {
                        $img->removeAttribute('width');
                        $hasChanges = true;
                    }
                    if ($img->hasAttribute('height')) {
                        $img->removeAttribute('height');
                        $hasChanges = true;
                    }
                    if ($img->hasAttribute('data-image-dimension-failed')) {
                        $img->removeAttribute('data-image-dimension-failed');
                        $hasChanges = true;
                    }
                }

                if ($hasChanges) {
                    $newXml = $dom->saveXML($dom->documentElement);

                    if (! $isDryRun) {
                        // Bypass Eloquent events to prevent queueing background jobs
                        // We just want to wipe the dimensions silently.
                        Post::where('id', $post->id)->update(['content' => $newXml]);
                    } elseif ($this->output->isVerbose()) {
                        $this->line("  -> Post #{$post->id} would have dimensions cleared.");
                    }

                    $clearedCount++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->line('');

        if ($isDryRun) {
            $this->info("DRY RUN: $clearedCount posts would have dimensions cleared.");
        } else {
            $this->info("Successfully cleared dimensions from $clearedCount posts!");
        }
    }
}
