<?php

namespace Huoxin\AutoImageDimensions\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;

class ReportDimensionsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('huoxin-auto-image-dimensions');

        $this->prepareDatabase([
            'users' => [
                ['id' => 1, 'username' => 'Mural', 'email' => 'mural@machine.local', 'is_email_confirmed' => 1],
                ['id' => 2, 'username' => 'Normal', 'email' => 'normal@machine.local', 'is_email_confirmed' => 1],
            ],
            'discussions' => [
                ['id' => 1, 'title' => __CLASS__, 'created_at' => Carbon::now()->toDateTimeString(), 'user_id' => 1],
            ],
            'posts' => [
                [
                    'id' => 1,
                    'discussion_id' => 1,
                    'user_id' => 1,
                    'type' => 'comment',
                    'content' => '<r><p><IMG src="https://example.com/img.png"><s>[img]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>',
                ],
            ],
        ]);
    }

    public function test_user_can_report_dimensions()
    {
        $response = $this->send(
            $this->request('POST', '/api/auto-image-dimensions/report', [
                'authenticatedAs' => 2,
                'json' => [
                    'post_id' => 1,
                    'images' => [
                        [
                            'url' => 'https://example.com/img.png',
                            'width' => 800,
                            'height' => 600,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(204, $response->getStatusCode());

        $post = Post::find(1);
        $this->assertStringContainsString('width="533"', $post->content);
        $this->assertStringContainsString('height="400"', $post->content);
    }

    public function test_report_dimensions_skips_concurrent_edits()
    {
        $post = Post::find(1);
        $post->edited_at = Carbon::now();
        $post->save();

        $response = $this->send(
            $this->request('POST', '/api/auto-image-dimensions/report', [
                'authenticatedAs' => 2,
                'json' => [
                    'post_id' => 1,
                    'images' => [
                        [
                            'url' => 'https://example.com/img.png',
                            'width' => 800,
                            'height' => 600,
                        ],
                    ],
                ],
            ])
        );

        // Optimistic locking fails gracefully (returns 204 to client without crashing)
        $this->assertEquals(204, $response->getStatusCode());

        // The post content should NOT be modified because of optimistic locking mismatch
        $post->refresh();
        $this->assertStringNotContainsString('width="533"', $post->content);
    }
}
