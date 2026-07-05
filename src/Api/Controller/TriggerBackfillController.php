<?php

/*
 * This file is part of huoxin/auto-image-dimensions.
 *
 * Copyright (c) 2026 huoxin.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Huoxin\AutoImageDimensions\Api\Controller;

use Flarum\Http\RequestUtil;
use Huoxin\AutoImageDimensions\Service\BackfillService;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TriggerBackfillController implements RequestHandlerInterface
{
    /**
     * @var BackfillService
     */
    protected $backfillService;

    /**
     * @param BackfillService $backfillService
     */
    public function __construct(BackfillService $backfillService)
    {
        $this->backfillService = $backfillService;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = $request->getParsedBody();
        $retryMode = Arr::get($body, 'retry_mode', 'all');

        $failedOnly = $retryMode === 'failed_only';
        // Admin manual trigger implies force retry is always true so that we can retry failed images
        $forceRetry = true; 

        $count = $this->backfillService->getCount($failedOnly);
        
        if ($count > 0) {
            $this->backfillService->process($failedOnly, $forceRetry);
        }

        return new JsonResponse(['queued' => $count]);
    }
}
