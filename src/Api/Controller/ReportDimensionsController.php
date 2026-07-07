<?php

namespace Huoxin\AutoImageDimensions\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Huoxin\AutoImageDimensions\Service\ImageXmlProcessor;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ReportDimensionsController implements RequestHandlerInterface
{
    private const MAX_DIM = 16384;
    private const MAX_RATIO = 20.0;
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_MAX = 60;

    protected $cache;
    protected $processor;

    public function __construct(Cache $cache, ImageXmlProcessor $processor)
    {
        $this->cache = $cache;
        $this->processor = $processor;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        
        // Validation D: Rate Limiting
        $ip = Arr::get($request->getServerParams(), 'REMOTE_ADDR', '127.0.0.1');
        $rlKey = 'auto_img_dim.rl.' . ($actor->isGuest() ? 'ip_' . $ip : $actor->id);
        $count = (int) $this->cache->get($rlKey, 0);
        if ($count >= self::RATE_LIMIT_MAX) {
            return new JsonResponse(['errors' => [['code' => 'rate_limited']]], 429);
        }
        $this->cache->put($rlKey, $count + 1, self::RATE_LIMIT_WINDOW);

        $body = $request->getParsedBody();
        $postId = (int) Arr::get($body, 'post_id', 0);
        $images = Arr::get($body, 'images', []);

        if (!$postId || !is_array($images) || empty($images)) {
            return new JsonResponse(['errors' => [['code' => 'invalid_payload']]], 422);
        }

        // DoS Protection: Cap batch size at 100 images per request
        if (count($images) > 100) {
            return new JsonResponse(['errors' => [['code' => 'payload_too_large']]], 413);
        }

        // Build a lookup map and validate bounds
        $imageMap = [];
        foreach ($images as $img) {
            $url = (string) Arr::get($img, 'url', '');
            $width = (int) Arr::get($img, 'width', 0);
            $height = (int) Arr::get($img, 'height', 0);

            if ($url === '' || $width < 1 || $width > self::MAX_DIM || $height < 1 || $height > self::MAX_DIM) {
                continue; // Skip invalid entries in the batch
            }

            $ratio = $width / $height;
            if ($ratio > self::MAX_RATIO || $ratio < (1 / self::MAX_RATIO)) {
                continue; // Skip stretched entries
            }

            $imageMap[$url] = [$width, $height];
        }

        if (empty($imageMap)) {
            return new JsonResponse(['errors' => [['code' => 'no_valid_images']]], 422);
        }

        /** @var Post|null $post */
        $post = Post::find($postId);
        if (!$post || !$post->parsed_content) {
            return new EmptyResponse(204);
        }

        // Validation: Can the user actually see this post?
        if ($actor->cannot('view', $post)) {
            return new EmptyResponse(403);
        }

        // Validation A & B are inherently handled by our ImageXmlProcessor.
        // We configure the processor to inject the specific dimensions if the URL matches.
        // The processor automatically skips if dimensions already exist.
        $newXml = $this->processor->process($post->parsed_content, function(string $src) use ($imageMap) {
            // Exact match (absolute URLs)
            if (isset($imageMap[$src])) {
                return $imageMap[$src];
            }
            
            // Fallback for relative URLs stored in XML (browser always reports absolute URLs)
            foreach ($imageMap as $reportedUrl => $dims) {
                if (str_ends_with($reportedUrl, $src)) {
                    return $dims;
                }
            }
            
            return null;
        }, true); // forceRetry = true, so it updates even if data-image-dimension-failed="1" is present

        if ($newXml !== false) {
            // Update XML directly to avoid Revised events looping
            Post::where('id', $post->id)->update(['content' => $newXml]);
        }

        return new EmptyResponse(204);
    }
}
