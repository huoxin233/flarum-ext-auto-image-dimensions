<?php

namespace Huoxin\AutoImageDimensions\Tests\integration\console;

use Carbon\Carbon;
use Flarum\Post\Post;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;

class ClearDimensionsCommandTest extends ConsoleTestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('huoxin-auto-image-dimensions');

        $this->prepareDatabase([
            'users' => [
                ['id' => 1, 'username' => 'Mural', 'email' => 'mural@machine.local', 'is_email_confirmed' => 1],
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
                    // Image with dimensions
                    'content' => '<r><p><IMG height="400" src="https://example.com/img.png" width="533"><s>[img]</s>https://example.com/img.png<e>[/img]</e></IMG></p></r>',
                ],
                [
                    'id' => 2,
                    'discussion_id' => 1,
                    'user_id' => 1,
                    'type' => 'comment',
                    // Image with failure tag
                    'content' => '<r><p><IMG data-image-dimension-failed="1" src="https://example.com/img2.png"><s>[img]</s>https://example.com/img2.png<e>[/img]</e></IMG></p></r>',
                ],
                [
                    'id' => 3,
                    'discussion_id' => 1,
                    'user_id' => 1,
                    'type' => 'comment',
                    // Text only, no images
                    'content' => '<r><p>Just some text.</p></r>',
                ],
            ],
        ]);
    }

    public function test_it_clears_dimensions_from_all_posts()
    {
        $output = $this->runCommand(['command' => 'auto-image-dimensions:clear-all', '--force' => true]);

        $this->assertStringContainsString('Successfully cleared dimensions from 2 posts', $output);

        $this->app();
        $post1 = Post::find(1);
        $this->assertStringNotContainsString('width="533"', $post1->parsed_content);
        $this->assertStringNotContainsString('height="400"', $post1->parsed_content);

        $post2 = Post::find(2);
        $this->assertStringNotContainsString('data-image-dimension-failed="1"', $post2->parsed_content);

        // Verify post 3 was untouched
        $post3 = Post::find(3);
        $this->assertEquals('<r><p>Just some text.</p></r>', $post3->parsed_content);
    }
}
