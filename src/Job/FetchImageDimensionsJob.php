<?php

namespace Huoxin\AutoImageDimensions\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class FetchImageDimensionsJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @var int
     */
    protected $postId;

    /**
     * @var string|null
     */
    protected $editedAt;

    /**
     * @param int $postId
     * @param string|null $editedAt
     */
    public function __construct(int $postId, ?string $editedAt)
    {
        $this->postId = $postId;
        $this->editedAt = $editedAt;
    }

    public function handle()
    {
        /** @var \Flarum\Post\Post|null $post */
        $post = \Flarum\Post\Post::find($this->postId);

        if (!$post || !$post->parsed_content) {
            return;
        }

        // Race condition prevention
        // If the post was edited after this job was queued, we abort.
        if ($this->editedAt !== null && $post->edited_at !== null) {
            $jobEditedAt = \Carbon\Carbon::parse($this->editedAt);
            if ($post->edited_at->gt($jobEditedAt)) {
                return;
            }
        }

        // We wrap the parsed_content in a root element so DOMDocument can parse it easily if it has multiple root elements.
        // Flarum uses <r> or <t> as root tags.
        $xml = $post->parsed_content;
        
        $dom = new \DOMDocument();
        // Suppress warnings for invalid XML/HTML
        $internalErrors = libxml_use_internal_errors(true);
        // Load the XML. We add an XML declaration to ensure UTF-8 handling if needed,
        // though Flarum's parsed content is usually simple XML.
        $success = $dom->loadXML($xml);
        libxml_use_internal_errors($internalErrors);

        if (!$success) {
            return;
        }

        $images = $dom->getElementsByTagName('IMG');
        $hasChanges = false;

        foreach ($images as $img) {
            /** @var \DOMElement $img */
            
            // Check if it already has dimensions
            if ($img->hasAttribute('width') && $img->hasAttribute('height')) {
                continue;
            }

            $src = $img->getAttribute('src');
            if (!$src) {
                continue;
            }

            // Fetch dimensions using Guzzle
            try {
                $client = new \GuzzleHttp\Client(['timeout' => 5]);
                // We use stream to not download the whole image if possible, but for getimagesize we need a local file or wrapper
                // For simplicity and safety, we fetch it into a temp stream
                $response = $client->request('GET', $src, ['stream' => true]);
                
                if ($response->getStatusCode() === 200) {
                    $stream = $response->getBody();
                    
                    // Since getimagesize needs a file path or URI, and Guzzle returns a stream,
                    // we can read a chunk and use imagecreatefromstring, or save to a temp file.
                    // Saving to a temp file is most reliable for getimagesize.
                    $tmpFile = tempnam(sys_get_temp_dir(), 'flarum_img_');
                    if ($tmpFile) {
                        file_put_contents($tmpFile, $stream->getContents());
                        $size = @getimagesize($tmpFile);
                        unlink($tmpFile);

                        if ($size !== false) {
                            $img->setAttribute('width', (string)$size[0]);
                            $img->setAttribute('height', (string)$size[1]);
                            $hasChanges = true;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore exceptions (e.g., timeout, 404)
            }
        }

        if ($hasChanges) {
            // Flarum s9e uses the root tag, usually we just save the whole XML back.
            // Save parsed_content back to XML string.
            $newXml = $dom->saveXML($dom->documentElement);
            
            // We update quietly via the query builder to avoid dispatching another Revised event
            \Flarum\Post\Post::where('id', $post->id)->update(['parsed_content' => $newXml]);
        }
    }
}
