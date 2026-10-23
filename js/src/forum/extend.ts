import Extend from 'flarum/common/extenders';
import Post from 'flarum/common/models/Post';

const extenders = [new Extend.Model(Post).attribute<boolean>('canRefreshImageDimensions')];

export default extenders;
