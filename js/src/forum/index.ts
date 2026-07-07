import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';

app.initializers.add('huoxin-auto-image-dimensions', () => {
  // We extend oncreate to run whenever a post is rendered in the DOM
  extend(CommentPost.prototype, 'oncreate', function (this: any, vnode: any) {
    const post = this.attrs.post;
    if (!post) return;
    
    const postId = post.id();
    if (!postId) return;

    // Find all images in this post
    const element = this.element as HTMLElement;
    const images = element.querySelectorAll('img');

    let batchedImages: { url: string; width: number; height: number }[] = [];
    let debounceTimer: any = null;

    const flushBatch = () => {
      if (batchedImages.length === 0) return;

      // Only take the first 100 images. Subsequent page views by this user or other users
      // will crowdsource the remaining images, because these first 100 will already be fixed!
      const payload = batchedImages.slice(0, 100);
      batchedImages = [];

      app.request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/auto-image-dimensions/report',
        body: {
          post_id: postId,
          images: payload,
        },
      }).catch((e) => {
        console.error('Failed to report image dimensions batch', e);
      });
    };

    images.forEach((img: HTMLImageElement) => {
      const hasFailedTag = img.hasAttribute('data-image-dimension-failed');
      const hasWidth = img.hasAttribute('width');
      const hasHeight = img.hasAttribute('height');

      if (hasWidth && hasHeight && !hasFailedTag) {
        return;
      }

      if (img.dataset.reported === 'true') {
        return;
      }

      const reportDimensions = () => {
        img.dataset.reported = 'true';

        const naturalWidth = img.naturalWidth;
        const naturalHeight = img.naturalHeight;

        if (naturalWidth > 0 && naturalHeight > 0) {
          batchedImages.push({
            url: img.src,
            width: naturalWidth,
            height: naturalHeight,
          });

          // Debounce the flush
          if (debounceTimer) clearTimeout(debounceTimer);
          debounceTimer = setTimeout(flushBatch, 500);
        }
      };

      if (img.complete) {
        reportDimensions();
      } else {
        img.addEventListener('load', reportDimensions, { once: true });
      }
    });
  });
});
