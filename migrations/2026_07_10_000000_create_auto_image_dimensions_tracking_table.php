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

            // Backfill existing posts into the tracking table. This full-table scan only happens
            // once during installation/upgrade, which is acceptable, unlike a daily cron.
            $schema->getConnection()->table('posts')
                ->where('content', 'LIKE', '%<IMG %')
                ->select('id', 'content')
                ->chunkById(1000, function ($posts) use ($schema) {
                    $insertData = [];
                    foreach ($posts as $post) {
                        $insertData[] = [
                            'post_id' => $post->id,
                            'has_failed' => strpos($post->content, 'data-image-dimension-failed') !== false,
                            'last_attempt_at' => null
                        ];
                    }
                    if (! empty($insertData)) {
                        $schema->getConnection()->table('auto_image_dimensions_tracking')->insert($insertData);
                    }
                });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('auto_image_dimensions_tracking');
    }
];
