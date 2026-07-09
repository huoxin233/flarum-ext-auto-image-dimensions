<?php

namespace Huoxin\AutoImageDimensions\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;

class RefreshDimensionsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('huoxin-auto-image-dimensions');

        $this->setting('huoxin-auto-image-dimensions.operating_mode', 'backend');

        $this->prepareDatabase([
            'users' => [
                ['id' => 1, 'username' => 'Mural', 'email' => 'mural@machine.local', 'is_email_confirmed' => 1],
                // ID 2 is a normal user
                ['id' => 2, 'username' => 'Normal', 'email' => 'normal@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 1, 'group_id' => 1], // Admin
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
                    // Pre-existing dimensions
                    'content' => '<r><p><IMG height="400" src="https://example.com/img.png" width="533"><s>[img]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>',
                ],
            ],
        ]);
    }

    public function test_admin_can_refresh_dimensions()
    {
        $response = $this->send(
            $this->request('POST', '/api/posts/1/refresh-image-dimensions', [
                'authenticatedAs' => 1,
            ])
        );

        $this->assertEquals(204, $response->getStatusCode());

        $this->app();
        $post = Post::find(1);
        // Attributes should be stripped from the XML
        $this->assertStringNotContainsString('width="533"', $post->parsed_content);
        $this->assertStringNotContainsString('height="400"', $post->parsed_content);

        // Because the test environment uses a synchronous queue, the Job runs immediately,
        // fails to fetch the fake URL, and injects the failure tag.
        $this->assertStringContainsString('data-image-dimension-failed="1"', $post->parsed_content);
    }

    public function test_normal_user_cannot_refresh_dimensions()
    {
        $response = $this->send(
            $this->request('POST', '/api/posts/1/refresh-image-dimensions', [
                'authenticatedAs' => 2,
            ])
        );

        // 403 Forbidden
        $this->assertEquals(403, $response->getStatusCode());

        $this->app();
        $post = Post::find(1);
        // Attributes should NOT be stripped
        $this->assertStringContainsString('width="533"', $post->parsed_content);
    }
}
