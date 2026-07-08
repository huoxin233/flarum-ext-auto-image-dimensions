<?php

/*
 * This file is part of huoxin/auto-image-dimensions.
 *
 * Copyright (c) 2026 huoxin.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Huoxin\AutoImageDimensions\Console;

use Flarum\Settings\SettingsRepositoryInterface;
use Huoxin\AutoImageDimensions\Service\BackfillService;
use Illuminate\Console\Command;

class BackfillImageDimensionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'image-dimensions:backfill {--retry-failed : Force retry of previously failed images} {--failed-only : Only process previously failed images}';

    /**
     * @var string
     */
    protected $description = 'Queue old posts for image dimension extraction.';

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
        parent::__construct();
        $this->backfillService = $backfillService;
        $this->settings = $settings;
    }

    public function handle()
    {
        $this->info('Finding posts with images lacking dimensions...');

        $forceRetry = $this->option('retry-failed');
        $failedOnly = $this->option('failed-only');

        // If no explicit CLI flags are provided, we can optionally fall back to settings
        // for scheduled runs. But typically scheduled runs just use the default (process all missing).
        $retryMode = $this->settings->get('huoxin-auto-image-dimensions.retry_mode', 'all');
        if (! $forceRetry && ! $failedOnly && $retryMode === 'failed_only') {
            $failedOnly = true;
            $forceRetry = true;
        }

        $count = $this->backfillService->getCount($failedOnly);

        if ($count === 0) {
            $this->info('No posts found that require backfilling.');
            return;
        }

        $this->info("Found $count posts to process. Queueing jobs...");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $this->backfillService->process(
            $failedOnly,
            $forceRetry,
            function () use ($bar) {
                $bar->advance();
            }
        );

        $bar->finish();
        $this->line('');
        $this->info('Successfully queued all jobs! Make sure your queue worker is running.');
    }
}
