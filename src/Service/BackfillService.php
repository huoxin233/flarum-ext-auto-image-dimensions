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
        if ($failedOnly) {
            return CommentPost::query()
                ->join('auto_image_dimensions_tracking', 'posts.id', '=', 'auto_image_dimensions_tracking.post_id')
                ->where('auto_image_dimensions_tracking.has_failed', 1)
                ->select('posts.id', 'posts.edited_at');
        }

        return CommentPost::query()
            ->where('content', 'LIKE', '%<IMG %')
            ->select('id', 'edited_at');
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
     * @param bool $ignoreMode
     * @return void
     */
    public function process(bool $failedOnly, bool $forceRetry, ?callable $progressCallback = null, bool $isDryRun = false, bool $ignoreMode = false): void
    {
        $query = $this->buildQuery($failedOnly);

        $query->chunkById(100, function ($posts) use ($forceRetry, $progressCallback, $isDryRun, $ignoreMode) {
            foreach ($posts as $post) {
                if (! $isDryRun) {
                    $this->queue->push(new FetchImageDimensionsJob($post->id, $post->edited_at ? $post->edited_at->toIso8601String() : null, $forceRetry, $ignoreMode));
                }
                if ($progressCallback) {
                    $progressCallback($post);
                }
            }
        }, 'posts.id', 'id');
    }
}
