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
            'group_user' => [
                ['user_id' => 1, 'group_id' => 1], // Admin
            ],
            'group_permission' => [
                ['group_id' => 3, 'permission' => 'huoxin-auto-image-dimensions.report'],
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
                'authenticatedAs' => 1,
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

        $this->app(); // Boot the application before resolving Eloquent models
        $post = Post::find(1);
        $this->assertStringContainsString('width="533"', $post->parsed_content);
        $this->assertStringContainsString('height="400"', $post->parsed_content);
    }

    public function test_user_can_report_large_stitched_screenshot()
    {
        $response = $this->send(
            $this->request('POST', '/api/auto-image-dimensions/report', [
                'authenticatedAs' => 1,
                'json' => [
                    'post_id' => 1,
                    'images' => [
                        [
                            'url' => 'https://example.com/img.png',
                            'width' => 1950,
                            'height' => 20640,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(204, $response->getStatusCode());

        $this->app();
        $post = Post::find(1);
        $this->assertStringContainsString('width="38"', $post->parsed_content);
        $this->assertStringContainsString('height="400"', $post->parsed_content);
    }

    public function test_rejects_exceeding_max_dim()
    {
        $response = $this->send(
            $this->request('POST', '/api/auto-image-dimensions/report', [
                'authenticatedAs' => 1,
                'json' => [
                    'post_id' => 1,
                    'images' => [
                        [
                            'url' => 'https://example.com/img.png',
                            'width' => 1950,
                            'height' => 100001,
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(422, $response->getStatusCode());
    }
}
