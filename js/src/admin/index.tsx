import app from 'flarum/admin/app';
import SettingsPage from './components/SettingsPage';

app.initializers.add('huoxin-auto-image-dimensions', () => {
  app.extensionData
    .for('huoxin-auto-image-dimensions')
    .registerPage(SettingsPage)
    .registerPermission(
      {
        icon: 'fas fa-sync',
        label: app.translator.trans('huoxin-auto-image-dimensions.admin.permissions.refresh_label'),
        permission: 'huoxin-auto-image-dimensions.refresh',
      },
      'moderate',
      90
    )
    .registerPermission(
      {
        icon: 'fas fa-image',
        label: app.translator.trans('huoxin-auto-image-dimensions.admin.permissions.report_label'),
        permission: 'huoxin-auto-image-dimensions.report',
        allowGuest: true,
      },
      'view',
      90
    );
});
