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
            $query->where('content', 'LIKE', '%<IMG %')
                ->where('content', 'NOT LIKE', '%width=%');
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
     * @return void
     */
    public function process(bool $failedOnly, bool $forceRetry, callable $progressCallback = null): void
    {
        $query = $this->buildQuery($failedOnly);

        $query->chunkById(100, function ($posts) use ($forceRetry, $progressCallback) {
            foreach ($posts as $post) {
                $this->queue->push(new FetchImageDimensionsJob($post->id, null, $forceRetry));
                if ($progressCallback) {
                    $progressCallback();
                }
            }
        });
    }
}
