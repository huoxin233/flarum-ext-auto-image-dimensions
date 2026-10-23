import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import SettingsPage from './components/SettingsPage';

export default [
  new Extend.Admin()
    .page(SettingsPage)
    .permission(
      () => ({
        icon: 'fas fa-sync',
        label: app.translator.trans('huoxin-auto-image-dimensions.admin.permissions.refresh_label'),
        permission: 'huoxin-auto-image-dimensions.refresh',
      }),
      'moderate',
      90
    )
    .permission(
      () => ({
        icon: 'fas fa-image',
        label: app.translator.trans('huoxin-auto-image-dimensions.admin.permissions.report_label'),
        permission: 'huoxin-auto-image-dimensions.report',
      }),
      'view',
      90
    ),
];
