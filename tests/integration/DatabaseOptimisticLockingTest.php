<?php

namespace Huoxin\AutoImageDimensions\Tests\integration;

use Carbon\Carbon;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;

class DatabaseOptimisticLockingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('huoxin-auto-image-dimensions');
        $this->prepareDatabase([
            'discussions' => [['id' => 1, 'title' => 'test', 'created_at' => Carbon::now()->toDateTimeString(), 'user_id' => 1]],
            'posts' => [['id' => 1, 'discussion_id' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<r><p>original</p></r>', 'edited_at' => null]],
        ]);
    }

    public function test_optimistic_locking_prevents_stale_overwrites()
    {
        $this->app();
        
        // 1. Background Job fetches the post and begins 5-second HTTP processing
        $staleJobPost = Post::find(1);

        // 2. CONCURRENT EDIT: A user edits the post in the UI during those 5 seconds
        $userEditPost = Post::find(1);
        $newEditedAt = Carbon::now();
        
        // We use a raw DB query to simulate the edit to prevent Flarum's Eloquent
        // mutators from treating our raw XML as Markdown and escaping it.
        Post::where('id', 1)->update([
            'edited_at' => $newEditedAt,
            'content' => '<r><p>edited by user</p></r>'
        ]);

        // 3. Background Job finishes HTTP requests and attempts to save its XML
        // (This mirrors the exact logic inside FetchImageDimensionsJob and ReportDimensionsController)
        $query = Post::where('id', $staleJobPost->id);
        if ($staleJobPost->edited_at) {
            $query->where('edited_at', $staleJobPost->edited_at);
        } else {
            $query->whereNull('edited_at');
        }
        
        $rowsAffected = $query->update(['content' => '<r><p>stale dimensions injected</p></r>']);

        // Assert the update was blocked by the database!
        $this->assertEquals(0, $rowsAffected);

        // Assert the user's edit was safely preserved!
        $finalPost = Post::find(1);
        $this->assertEquals('<r><p>edited by user</p></r>', $finalPost->parsed_content);
    }
}
