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

use Flarum\Extend;
use s9e\TextFormatter\Configurator;
use s9e\TextFormatter\Configurator\TemplateNormalizations\SetAttributeOnElements;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),
    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Event())
        ->subscribe(Listener\QueueImageDimensionsFetch::class),

    (new Extend\Console())
        ->command(Console\BackfillImageDimensionsCommand::class),

    (new Extend\Routes('api'))
        ->post('/image-dimensions/backfill', 'image-dimensions.backfill', Api\Controller\TriggerBackfillController::class),

    (new Extend\Formatter())
        ->configure(function (Configurator $configurator) {
            // Adds attributes to the compiled XSLT template for <img> tags
            $configurator->templateNormalizer->add(
                new SetAttributeOnElements('//img[not(@height)]', 'height', '{@height}')
            );
            $configurator->templateNormalizer->add(
                new SetAttributeOnElements('//img[not(@width)]', 'width', '{@width}')
            );

            // Re-normalize the IMG BBCode template if it exists
            if ($configurator->BBCodes->collection->exists('IMG')) {
                $configurator->BBCodes->addFromRepository('IMG');
            }
        }),
];
