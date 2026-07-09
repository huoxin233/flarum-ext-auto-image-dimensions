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
use Flarum\Settings\SettingsRepositoryInterface;
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
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @param BackfillService $backfillService
     * @param SettingsRepositoryInterface $settings
     */
    public function __construct(BackfillService $backfillService, SettingsRepositoryInterface $settings)
    {
        $this->backfillService = $backfillService;
        $this->settings = $settings;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $mode = $this->settings->get('huoxin-auto-image-dimensions.operating_mode', 'client');
        if ($mode === 'client') {
            return new JsonResponse(['error' => 'Backfill cannot be triggered in client mode.'], 400);
        }

        $body = $request->getParsedBody();
        $retryMode = Arr::get($body, 'retry_mode', 'failed_only');

        $failedOnly = $retryMode === 'failed_only';

        $forceRetry = true;

        $count = $this->backfillService->getCount($failedOnly);

        if ($count > 0) {
            $this->backfillService->process($failedOnly, $forceRetry);
        }

        return new JsonResponse(['queued' => $count]);
    }
}
