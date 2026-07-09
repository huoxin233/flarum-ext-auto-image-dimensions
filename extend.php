<?php

/*
 * This file is part of huoxin/auto-image-dimensions.
 *
 * Copyright (c) 2026 huoxin.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Huoxin\AutoImageDimensions;

use Flarum\Api\Serializer\PostSerializer;
use Flarum\Extend;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Console\Scheduling\Event;
use s9e\TextFormatter\Configurator;
use s9e\TextFormatter\Configurator\TemplateNormalizations\SetAttributeOnElements;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js'),
    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Settings())
        ->serializeToForum('huoxinAutoImageDimensionsMode', 'huoxin-auto-image-dimensions.operating_mode', null, 'client')
        ->serializeToForum('huoxinAutoImageDimensionsMaxHeight', 'huoxin-auto-image-dimensions.max_height', null, 400),

    (new Extend\Settings())
        ->default('huoxin-auto-image-dimensions.operating_mode', 'client')
        ->default('huoxin-auto-image-dimensions.max_height', 400)
        ->default('huoxin-auto-image-dimensions.schedule_interval', 'disabled')
        ->default('huoxin-auto-image-dimensions.retry_mode', 'failed_only')
        ->default('huoxin-auto-image-dimensions.proxy', ''),

    (new Extend\Event())
        ->subscribe(Listener\QueueImageDimensionsFetch::class),

    (new Extend\Console())
        ->command(Console\BackfillImageDimensionsCommand::class)
        ->command(Console\ClearImageDimensionsCommand::class)
        ->schedule('auto-image-dimensions:backfill', function (Event $event) {
            $settings = resolve(SettingsRepositoryInterface::class);
            $interval = $settings->get('huoxin-auto-image-dimensions.schedule_interval', 'disabled');
            
            if ($interval === 'daily') $event->daily();
            elseif ($interval === 'weekly') $event->weekly();
            elseif ($interval === 'monthly') $event->monthly();
            
            $event->when(function () use ($settings, $interval) {
                if ($interval === 'disabled') return false;
                return $settings->get('huoxin-auto-image-dimensions.retry_mode', 'failed_only') === 'all';
            });
        })
        ->schedule('auto-image-dimensions:backfill --retry-failed', function (Event $event) {
            $settings = resolve(SettingsRepositoryInterface::class);
            $interval = $settings->get('huoxin-auto-image-dimensions.schedule_interval', 'disabled');
            
            if ($interval === 'daily') $event->daily();
            elseif ($interval === 'weekly') $event->weekly();
            elseif ($interval === 'monthly') $event->monthly();
            
            $event->when(function () use ($settings, $interval) {
                if ($interval === 'disabled') return false;
                return $settings->get('huoxin-auto-image-dimensions.retry_mode', 'failed_only') === 'failed_only';
            });
        }),

    (new Extend\Routes('api'))
        ->post('/image-dimensions/backfill', 'image-dimensions.backfill', Api\Controller\TriggerBackfillController::class)
        ->post('/auto-image-dimensions/report', 'auto-image-dimensions.report', Api\Controller\ReportDimensionsController::class)
        ->post('/posts/{id}/refresh-image-dimensions', 'posts.refresh-image-dimensions', Api\Controller\RefreshPostDimensionsController::class),

    (new Extend\ApiSerializer(PostSerializer::class))
        ->attribute('canRefreshImageDimensions', function ($serializer, $post) {
            return $serializer->getActor()->can('huoxin-auto-image-dimensions.refresh');
        }),

    (new Extend\Formatter())
        ->configure(function (Configurator $configurator) {
            $configurator->templateNormalizer->add(
                new SetAttributeOnElements('//img[not(@height)]', 'height', '{@height}')
            );
            $configurator->templateNormalizer->add(
                new SetAttributeOnElements('//img[not(@width)]', 'width', '{@width}')
            );
        }),
];
