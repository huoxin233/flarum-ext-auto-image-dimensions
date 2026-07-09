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

use Flarum\Post\CommentPost;
use Huoxin\AutoImageDimensions\Job\FetchImageDimensionsJob;
use Illuminate\Contracts\Queue\Queue;

class BackfillService
{
    /**
     * @var Queue
     */
    protected $queue;

    /**
     * @param Queue $queue
     */
    public function __construct(Queue $queue)
    {
        $this->queue = $queue;
    }

    protected function buildQuery(bool $failedOnly)
    {
        $query = CommentPost::query();

        if ($failedOnly) {
            $query->where('content', 'LIKE', '%data-image-dimension-failed%');
        } else {
            // We only look for IMG tags. ImageXmlProcessor handles deduplication.
            $query->where('content', 'LIKE', '%<IMG %');
        }

        return $query;
    }

    /**
     * @param bool $failedOnly
     * @return int
     */
    public function getCount(bool $failedOnly): int
    {
        return $this->buildQuery($failedOnly)->count();
    }

    /**
     * @param bool $failedOnly
     * @param bool $forceRetry
     * @param callable|null $progressCallback
     * @param bool $isDryRun
     * @return void
     */
    public function process(bool $failedOnly, bool $forceRetry, ?callable $progressCallback = null, bool $isDryRun = false): void
    {
        $query = $this->buildQuery($failedOnly);

        $query->chunkById(100, function ($posts) use ($forceRetry, $progressCallback, $isDryRun) {
            foreach ($posts as $post) {
                if (! $isDryRun) {
                    $this->queue->push(new FetchImageDimensionsJob($post->id, $post->edited_at ? $post->edited_at->toIso8601String() : null, $forceRetry));
                }
                if ($progressCallback) {
                    $progressCallback($post);
                }
            }
        });
    }
}
