import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import PostControls from 'flarum/forum/utils/PostControls';
import ItemList from 'flarum/common/utils/ItemList';
import Button from 'flarum/common/components/Button';
import Model from 'flarum/common/Model';
import Post from 'flarum/common/models/Post';
import type Mithril from 'mithril';

app.initializers.add('huoxin-auto-image-dimensions', () => {
  // We extend oncreate to run whenever a post is rendered in the DOM
  extend(CommentPost.prototype, 'oncreate', function (this: CommentPost, val: void, vnode: Mithril.VnodeDOM<any, CommentPost>) {
    const mode = app.forum.attribute<string>('huoxinAutoImageDimensionsMode') || 'client';
    if (mode === 'backend') return;

    // Only allow logged-in users with permission to report dimensions
    if (!app.session || !app.session.user) return;
    if (!app.forum.attribute<boolean>('canReportImageDimensions')) return;

    const post = this.attrs.post;
    if (!post) return;

    const postId = post.id();
    if (!postId) return;

    // Find all images in this post
    const element = this.element as HTMLElement;
    const images = element.querySelectorAll<HTMLImageElement>('.Post-body img');

    let batchedImages: { url: string; width: number; height: number }[] = [];
    let debounceTimer: number | null = null;

    const flushBatch = () => {
      if (batchedImages.length === 0) return;

      // Only take the first 100 images. Subsequent page views by this user or other users
      // will crowdsource the remaining images, because these first 100 will already be fixed!
      const payload = batchedImages.slice(0, 100);
      batchedImages = [];

      app
        .request({
          method: 'POST',
          url: app.forum.attribute<string>('apiUrl') + '/auto-image-dimensions/report',
          body: {
            post_id: postId,
            images: payload,
          },
        })
        .catch((e: unknown) => {
          console.error('Failed to report image dimensions batch', e);
        });
    };

    images.forEach((img: HTMLImageElement) => {
      const hasFailedTag = img.hasAttribute('data-image-dimension-failed');
      const widthVal = img.getAttribute('width');
      const heightVal = img.getAttribute('height');
      const hasWidth = widthVal !== null && widthVal !== '';
      const hasHeight = heightVal !== null && heightVal !== '';

      if ((hasWidth || hasHeight) && !hasFailedTag) {
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
          debounceTimer = window.setTimeout(flushBatch, 500);
        }
      };

      if (img.complete) {
        reportDimensions();
      } else {
        img.addEventListener('load', reportDimensions, { once: true });
      }
    });
  });

  Post.prototype.canRefreshImageDimensions = Model.attribute<boolean>('canRefreshImageDimensions');

  extend(PostControls, 'moderationControls', function (items: ItemList<Mithril.Children>, post: Post) {
    if (post.canRefreshImageDimensions()) {
      items.add(
        'refreshImageDimensions',
        <Button
          icon="fas fa-sync"
          onclick={() => {
            app
              .request({
                method: 'POST',
                url: app.forum.attribute<string>('apiUrl') + `/posts/${post.id()}/refresh-image-dimensions`,
              })
              .then(() => {
                app.alerts.show({ type: 'success' }, app.translator.trans('huoxin-auto-image-dimensions.forum.alerts.refresh_success'));
              });
          }}
        >
          {app.translator.trans('huoxin-auto-image-dimensions.forum.post_controls.refresh_button')}
        </Button>
      );
    }
  });
});
