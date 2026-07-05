import app from 'flarum/admin/app';

app.initializers.add('huoxin-auto-image-dimensions', () => {
  app.extensionData
    .for('huoxin-auto-image-dimensions')
    .registerSetting({
      setting: 'huoxin-auto-image-dimensions.schedule_interval',
      label: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_label'),
      help: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_help'),
      type: 'select',
      options: {
        disabled: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_disabled'),
        daily: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_daily'),
        weekly: app.translator.trans('huoxin-auto-image-dimensions.admin.schedule_interval_weekly'),
      },
      default: 'disabled',
    })
    .registerSetting({
      setting: 'huoxin-auto-image-dimensions.retry_mode',
      label: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_label'),
      help: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_help'),
      type: 'select',
      options: {
        all: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_all'),
        failed_only: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_failed_only'),
      },
      default: 'all',
    })
    .registerSetting({
      setting: 'huoxin-auto-image-dimensions.proxy',
      label: app.translator.trans('huoxin-auto-image-dimensions.admin.proxy_label'),
      help: app.translator.trans('huoxin-auto-image-dimensions.admin.proxy_help'),
      type: 'text',
      placeholder: 'tcp://10.0.0.5:3128',
    })
    .registerSetting(function () {
      return (
        <div className="Form-group">
          <label>{app.translator.trans('huoxin-auto-image-dimensions.admin.manual_trigger_label')}</label>
          <div className="helpText">
            {app.translator.trans('huoxin-auto-image-dimensions.admin.manual_trigger_help')}
          </div>
          <div style="display: flex; gap: 10px; margin-top: 10px;">
            <button
              className="Button Button--primary"
              onclick={() => triggerBackfill('all')}
            >
              {app.translator.trans('huoxin-auto-image-dimensions.admin.trigger_all_button')}
            </button>
            <button
              className="Button Button--warning"
              onclick={() => triggerBackfill('failed_only')}
            >
              {app.translator.trans('huoxin-auto-image-dimensions.admin.trigger_failed_button')}
            </button>
          </div>
        </div>
      );
    });

  function triggerBackfill(mode: string) {
    if (!confirm(app.translator.trans('huoxin-auto-image-dimensions.admin.trigger_confirm_text')[0])) {
        return;
    }

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/image-dimensions/backfill',
        body: { retry_mode: mode },
      })
      .then((response: any) => {
        app.alerts.show(
          { type: 'success' },
          app.translator.trans('huoxin-auto-image-dimensions.admin.trigger_success', { count: response.queued })
        );
      })
      .catch((error) => {
        console.error(error);
        app.alerts.show(
          { type: 'error' },
          'An error occurred while triggering the backfill.'
        );
      });
  }
});
