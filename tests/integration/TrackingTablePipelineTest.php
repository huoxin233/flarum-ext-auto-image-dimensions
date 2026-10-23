<?php

namespace Huoxin\AutoImageDimensions\Tests\Integration;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

class TrackingTablePipelineTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-markdown');
        $this->extension('huoxin-auto-image-dimensions');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Test Discussion', 'user_id' => 2, 'comment_count' => 1],
            ],
            Post::class => [
                [
                    'id' => 1,
                    'discussion_id' => 1,
                    'user_id' => 2,
                    'type' => 'comment',
                    'content' => '<t><p>No image here</p></t>',
                    'created_at' => Carbon::now()->subMinute()->toDateTimeString(),
                ],
            ]
        ]);
    }

    #[Test]
    public function test_listener_adds_post_to_tracking_table_on_edit(): void
    {
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);

        // Ensure tracking table is empty
        $db->table('auto_image_dimensions_tracking')->delete();

        // Edit post to include an image
        $response = $this->send(
            $this->request('PATCH', '/api/posts/1', [
                'authenticatedAs' => 2,
                'json' => [
                    'data' => [
                        'type' => 'posts',
                        'id' => '1',
                        'attributes' => [
                            'content' => 'Now it has an image: ![alt](https://example.com/img.png)',
                        ],
                    ],
                ],
            ])
        );

        $this->assertEquals(200, $response->getStatusCode());

        // The listener should have caught the Revised event and added it to the tracking table
        $trackingRow = $db->table('auto_image_dimensions_tracking')->where('post_id', 1)->first();

        $this->assertNotNull($trackingRow, 'Post was not added to the tracking table after edit.');
        $this->assertEquals(0, $trackingRow->has_failed, 'Initial tracking row should not be marked as failed.');
    }
}
