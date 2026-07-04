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

use Flarum\Post\CommentPost;
use Huoxin\AutoImageDimensions\Job\FetchImageDimensionsJob;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Queue;

class BackfillImageDimensionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'image-dimensions:backfill {--retry-failed : Force retry of previously failed images}';

    /**
     * @var string
     */
    protected $description = 'Queue old posts for image dimension extraction.';

    /**
     * @var Queue
     */
    protected $queue;

    /**
     * @param Queue $queue
     */
    public function __construct(Queue $queue)
    {
        parent::__construct();
        $this->queue = $queue;
    }

    public function handle()
    {
        $this->info('Finding posts with images lacking dimensions...');

        $forceRetry = $this->option('retry-failed');

        // Only target comment posts containing an image tag without a width attribute.
        // This is a fast heuristic. The actual job does precise DOM parsing.
        $query = CommentPost::where('parsed_content', 'LIKE', '%<IMG %')
            ->where('parsed_content', 'NOT LIKE', '%width=%');

        $count = $query->count();

        if ($count === 0) {
            $this->info('No posts found that require backfilling.');
            return;
        }

        $this->info("Found $count posts to process. Queueing jobs...");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        // Process in chunks to prevent memory exhaustion
        $query->chunk(100, function ($posts) use ($bar, $forceRetry) {
            foreach ($posts as $post) {
                // Pass edited_at as null to bypass race condition check for old posts
                // Pass forceRetry from command option
                $this->queue->push(new FetchImageDimensionsJob($post->id, null, $forceRetry));
                $bar->advance();
            }
        });

        $bar->finish();
        $this->line('');
        $this->info('Successfully queued all jobs! Make sure your queue worker is running.');
    }
}
