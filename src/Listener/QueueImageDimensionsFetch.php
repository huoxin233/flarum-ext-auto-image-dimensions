<?php

namespace Huoxin\AutoImageDimensions\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Huoxin\AutoImageDimensions\Job\FetchImageDimensionsJob;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Carbon\Carbon;

class QueueImageDimensionsFetch
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

    /**
     * @param Dispatcher $events
     */
    public function subscribe(Dispatcher $events)
    {
        $events->listen(Posted::class, [$this, 'whenPosted']);
        $events->listen(Revised::class, [$this, 'whenRevised']);
    }

    /**
     * @param Posted $event
     */
    public function whenPosted(Posted $event)
    {
        $this->dispatchJob($event->post->id, $event->post->edited_at);
    }

    /**
     * @param Revised $event
     */
    public function whenRevised(Revised $event)
    {
        $this->dispatchJob($event->post->id, $event->post->edited_at);
    }

    /**
     * @param int $postId
     * @param Carbon|null $editedAt
     */
    protected function dispatchJob(int $postId, ?Carbon $editedAt)
    {
        $this->queue->push(
            new FetchImageDimensionsJob($postId, $editedAt ? $editedAt->toIso8601String() : null)
        );
    }
}
