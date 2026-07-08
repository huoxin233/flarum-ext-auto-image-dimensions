<?php

namespace Huoxin\AutoImageDimensions\Api\Controller;

use DOMDocument;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Huoxin\AutoImageDimensions\Job\FetchImageDimensionsJob;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RefreshPostDimensionsController implements RequestHandlerInterface
{
    protected $queue;

    public function __construct(Queue $queue)
    {
        $this->queue = $queue;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('huoxin-auto-image-dimensions.refresh');

        $postId = Arr::get($request->getQueryParams(), 'id');
        $post = Post::findOrFail($postId);

        if (! $post->parsed_content) {
            return new EmptyResponse(204);
        }

        $dom = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $success = $dom->loadXML('<?xml version="1.0" encoding="UTF-8"?>'.$post->parsed_content);
        libxml_use_internal_errors($internalErrors);

        if (! $success) {
            return new EmptyResponse(204);
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

            // Bypass Eloquent events to prevent infinite loops with QueueImageDimensionsFetch.
            // Optimistic locking via edited_at prevents overwriting concurrent user edits.
            $query = Post::where('id', $post->id);
            if ($post->edited_at) {
                $query->where('edited_at', $post->edited_at);
            } else {
                $query->whereNull('edited_at');
            }
            $query->update(['content' => $newXml]);

            // Force retry to ensure failed dimensions are fetched again
            $this->queue->push(
                new FetchImageDimensionsJob($post->id, null, true)
            );
        }

        return new EmptyResponse(204);
    }
}
