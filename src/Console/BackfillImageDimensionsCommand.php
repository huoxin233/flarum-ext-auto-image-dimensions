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
    protected $signature = 'auto-image-dimensions:backfill {--retry-failed : Force retry of previously failed images} {--failed-only : Only process previously failed images} {--dry-run : Only calculate how many posts would be affected} {--ignore-mode : Run backfill even if operating mode is client}';

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
        $mode = $this->settings->get('huoxin-auto-image-dimensions.operating_mode', 'client');
        if ($mode === 'client' && ! $this->option('ignore-mode')) {
            $this->error('Aborted: Extension is configured to client mode. Backend backfilling is disabled.');
            $this->line('Hint: Use --ignore-mode to force the backfill to run anyway.');

            return 1;
        }

        $this->info('Finding posts with images lacking dimensions...');

        $forceRetry = $this->option('retry-failed');
        $failedOnly = $this->option('failed-only');

        // Fallback to settings if no CLI flags provided
        $retryMode = $this->settings->get('huoxin-auto-image-dimensions.retry_mode', 'failed_only');
        if (! $forceRetry && ! $failedOnly && $retryMode === 'failed_only') {
            $failedOnly = true;
            $forceRetry = true;
        }

        $count = $this->backfillService->getCount($failedOnly);

        if ($count === 0) {
            $this->info('No posts found that require backfilling.');

            return;
        }

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info("DRY RUN: $count posts would be queued for backfilling.");

            if ($this->output->isVerbose()) {
                $this->backfillService->process(
                    $failedOnly,
                    $forceRetry,
                    function ($post) {
                        $this->line("  -> Post #{$post->id} would be queued.");
                    },
                    $isDryRun,
                    $this->option('ignore-mode')
                );
            }

            return;
        }

        $this->info("Found $count posts to process. Queueing jobs...");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $this->backfillService->process(
            $failedOnly,
            $forceRetry,
            function ($post) use ($bar) {
                $bar->advance();
            },
            $isDryRun,
            $this->option('ignore-mode')
        );

        $bar->finish();
        $this->line('');
        $this->info('Successfully queued all jobs! Make sure your queue worker is running.');
    }
}
