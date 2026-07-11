<?php

namespace Huoxin\AutoImageDimensions\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Huoxin\AutoImageDimensions\Job\FetchImageDimensionsJob;
use Huoxin\AutoImageDimensions\Service\ImageXmlProcessor;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RefreshPostDimensionsController implements RequestHandlerInterface
{
    protected $queue;
    protected $processor;
    protected $settings;

    public function __construct(Queue $queue, ImageXmlProcessor $processor, SettingsRepositoryInterface $settings)
    {
        $this->queue = $queue;
        $this->processor = $processor;
        $this->settings = $settings;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $postId = Arr::get($request->getQueryParams(), 'id');
        $post = Post::whereVisibleTo($actor)->findOrFail($postId);

        $actor->assertCan('huoxin-auto-image-dimensions.refresh');

        if (! $post->parsed_content) {
            return new EmptyResponse(204);
        }

        $newXml = $this->processor->clear($post->parsed_content);

        if ($newXml !== false) {
            // Bypass Eloquent events to prevent infinite loops with QueueImageDimensionsFetch.
            // Optimistic locking via edited_at prevents overwriting concurrent user edits.
            $query = Post::where('id', $post->id);
            if ($post->edited_at) {
                $query->where('edited_at', $post->edited_at);
            } else {
                $query->whereNull('edited_at');
            }
            $query->update(['content' => $newXml]);
        }

        // Force retry to ensure failed/missing dimensions are fetched again,
        // but ONLY if the extension is permitted to use the backend.
        $mode = $this->settings->get('huoxin-auto-image-dimensions.operating_mode', 'client');
        if ($mode !== 'client') {
            $this->queue->push(
                new FetchImageDimensionsJob($post->id, $post->edited_at ? $post->edited_at->toIso8601String() : null, true)
            );
        }

        return new EmptyResponse(204);
    }
}
