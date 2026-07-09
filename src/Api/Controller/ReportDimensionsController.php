<?php

namespace Huoxin\AutoImageDimensions\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
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
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_MAX = 60;

    protected $cache;
    protected $processor;
    protected $settings;

    public function __construct(Cache $cache, ImageXmlProcessor $processor, SettingsRepositoryInterface $settings)
    {
        $this->cache = $cache;
        $this->processor = $processor;
        $this->settings = $settings;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        $actor->assertCan('huoxin-auto-image-dimensions.report');

        if ($actor->isGuest()) {
            return new EmptyResponse(403);
        }

        if ($this->settings->get('huoxin-auto-image-dimensions.operating_mode', 'client') === 'backend') {
            return new EmptyResponse(204);
        }

        // Rate Limiting
        $rlKey = 'auto_img_dim.rl.'.$actor->id;
        $count = (int) $this->cache->get($rlKey, 0);
        if ($count >= self::RATE_LIMIT_MAX) {
            return new JsonResponse(['errors' => [['code' => 'rate_limited']]], 429);
        }
        $this->cache->put($rlKey, $count + 1, self::RATE_LIMIT_WINDOW);

        $body = $request->getParsedBody();
        $postId = (int) Arr::get($body, 'post_id', 0);
        $images = Arr::get($body, 'images', []);

        if (! $postId || ! is_array($images) || empty($images)) {
            return new JsonResponse(['errors' => [['code' => 'invalid_payload']]], 422);
        }

        // Cap batch size
        if (count($images) > 100) {
            return new JsonResponse(['errors' => [['code' => 'payload_too_large']]], 413);
        }

        $imageMap = [];
        foreach ($images as $img) {
            $url = (string) Arr::get($img, 'url', '');
            $width = (int) Arr::get($img, 'width', 0);
            $height = (int) Arr::get($img, 'height', 0);

            if ($url === '' || $width < 1 || $width > self::MAX_DIM || $height < 1 || $height > self::MAX_DIM) {
                continue;
            }

            $imageMap[$url] = [$width, $height];
        }

        if (empty($imageMap)) {
            return new JsonResponse(['errors' => [['code' => 'no_valid_images']]], 422);
        }

        /** @var Post|null $post */
        $post = Post::find($postId);
        if (! $post || ! $post->parsed_content) {
            return new EmptyResponse(204);
        }

        if ($actor->cannot('view', $post)) {
            return new EmptyResponse(403);
        }

        // Inject dimensions (ImageXmlProcessor auto-skips if dimensions exist)
        $maxHeight = (int) $this->settings->get('huoxin-auto-image-dimensions.max_height', 400);

        $newXml = $this->processor->process($post->parsed_content, function (string $src) use ($imageMap) {
            // Exact match (absolute URLs)
            if (isset($imageMap[$src])) {
                return $imageMap[$src];
            }
            // Fallback for relative URLs stored in XML (browser always reports absolute URLs)
            $requestHost = $request->getUri()->getHost();
            foreach ($imageMap as $reportedUrl => $dims) {
                if (str_ends_with($reportedUrl, $src)) {
                    // Prevent dimension spoofing by ensuring the reported URL originates from this request's exact domain
                    if (parse_url($reportedUrl, PHP_URL_HOST) === $requestHost) {
                        return $dims;
                    }
                }
            }

            return null;
        }, true, $maxHeight);

        if ($newXml !== false) {
            // Optimistic locking via edited_at prevents overwriting concurrent user edits.
            // We use edited_at instead of content to avoid MySQL TEXT collation mismatch bugs.
            $query = Post::where('id', $post->id);
            if ($post->edited_at) {
                $query->where('edited_at', $post->edited_at);
            } else {
                $query->whereNull('edited_at');
            }
            $query->update(['content' => $newXml]);
        }

        return new EmptyResponse(204);
    }
}
