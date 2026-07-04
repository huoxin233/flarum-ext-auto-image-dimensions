<?php

namespace Huoxin\AutoImageDimensions\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class FetchImageDimensionsJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @var int
     */
    protected $postId;

    /**
     * @var string|null
     */
    protected $editedAt;

    /**
     * @param int $postId
     * @param string|null $editedAt
     */
    public function __construct(int $postId, ?string $editedAt)
    {
        $this->postId = $postId;
        $this->editedAt = $editedAt;
    }

    public function handle()
    {
        // To be implemented in Stage 2
    }
}
