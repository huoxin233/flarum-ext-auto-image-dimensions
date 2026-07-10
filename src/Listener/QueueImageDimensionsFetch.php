<?php

/*
 * This file is part of huoxin/auto-image-dimensions.
 *
 * Copyright (c) 2026 huoxin.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Huoxin\AutoImageDimensions\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Settings\SettingsRepositoryInterface;
use Huoxin\AutoImageDimensions\Job\FetchImageDimensionsJob;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;

class QueueImageDimensionsFetch
{
    /**
     * @var Queue
     */
    protected $queue;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var ConnectionInterface
     */
    protected $db;

    /**
     * @param Queue $queue
     * @param SettingsRepositoryInterface $settings
     * @param ConnectionInterface $db
     */
    public function __construct(Queue $queue, SettingsRepositoryInterface $settings, ConnectionInterface $db)
    {
        $this->queue = $queue;
        $this->settings = $settings;
        $this->db = $db;
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
     * @param \Flarum\Post\Post $event
     */
    public function whenPosted(Posted $event)
    {
        $this->dispatchJob($event->post);
    }

    /**
     * @param Revised $event
     */
    public function whenRevised(Revised $event)
    {
        $this->dispatchJob($event->post);
    }

    /**
     * @param \Flarum\Post\Post $post
     */
    protected function dispatchJob($post)
    {
        if (empty($post->parsed_content) || strpos($post->parsed_content, '<IMG ') === false) {
            // Delete from queue if it existed but image was removed
            $this->db->table('auto_image_dimensions_tracking')
                ->where('post_id', $post->id)
                ->delete();

            return;
        }

        $hasFailed = strpos($post->parsed_content, 'data-image-dimension-failed') !== false;

        $this->db->table('auto_image_dimensions_tracking')->updateOrInsert(
            ['post_id' => $post->id],
            ['has_failed' => $hasFailed, 'last_attempt_at' => null]
        );

        $mode = $this->settings->get('huoxin-auto-image-dimensions.operating_mode', 'client');
        if ($mode === 'client') {
            return;
        }

        $editedAt = $post->edited_at;
        $this->queue->push(
            new FetchImageDimensionsJob($post->id, $editedAt ? $editedAt->toIso8601String() : null)
        );
    }
}
