<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('auto_image_dimensions_tracking')) {
            $schema->create('auto_image_dimensions_tracking', function (Blueprint $table) {
                $table->integer('post_id')->unsigned()->primary();
                $table->boolean('has_failed')->default(false);
                $table->timestamp('last_attempt_at')->nullable();

                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->index('has_failed');
            });

        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('auto_image_dimensions_tracking');
    }
];
