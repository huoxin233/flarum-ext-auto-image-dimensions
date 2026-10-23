import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import SettingsPage from './components/SettingsPage';

const extenders = [
  new Extend.Admin()
    .page(SettingsPage)
    .setting(() => ({
      setting: 'huoxin-auto-image-dimensions.operating_mode',
      label: app.translator.trans('huoxin-auto-image-dimensions.admin.operating_mode_label'),
      help: app.translator.trans('huoxin-auto-image-dimensions.admin.operating_mode_help'),
      type: 'select',
      options: {
        client: app.translator.trans('huoxin-auto-image-dimensions.admin.operating_mode_client'),
        backend: app.translator.trans('huoxin-auto-image-dimensions.admin.operating_mode_backend'),
        hybrid: app.translator.trans('huoxin-auto-image-dimensions.admin.operating_mode_hybrid'),
      },
      default: 'client',
    }))
    .setting(() => ({
      setting: 'huoxin-auto-image-dimensions.max_height',
      label: app.translator.trans('huoxin-auto-image-dimensions.admin.max_height_label'),
      help: app.translator.trans('huoxin-auto-image-dimensions.admin.max_height_help'),
      type: 'number',
      default: 400,
    }))
    .setting(() => ({
      setting: 'huoxin-auto-image-dimensions.schedule_interval',
      label: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_label'),
      help: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_help'),
      type: 'select',
      options: {
        disabled: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_disabled'),
        daily: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_daily'),
        weekly: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_weekly'),
        monthly: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_monthly'),
      },
      default: 'disabled',
    }))
    .setting(() => ({
      setting: 'huoxin-auto-image-dimensions.retry_mode',
      label: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_label'),
      help: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_help'),
      type: 'select',
      options: {
        failed_only: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_failed_only'),
        all: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_all'),
      },
      default: 'failed_only',
    }))
    .setting(() => ({
      setting: 'huoxin-auto-image-dimensions.proxy',
      label: app.translator.trans('huoxin-auto-image-dimensions.admin.proxy_label'),
      help: app.translator.trans('huoxin-auto-image-dimensions.admin.proxy_help'),
      type: 'text',
      placeholder: 'tcp://10.0.0.5:3128',
    }))
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

export default extenders;
