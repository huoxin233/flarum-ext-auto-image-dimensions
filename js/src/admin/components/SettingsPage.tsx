import ExtensionPage, { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
import app from 'flarum/admin/app';
import extractText from 'flarum/common/utils/extractText';

export default class SettingsPage extends ExtensionPage<ExtensionPageAttrs> {
  content(): JSX.Element {
    const mode = this.setting('huoxin-auto-image-dimensions.operating_mode', 'client')();
    const isClient = mode === 'client';

    return (
      <div className="ExtensionPage-settings AutoImageDimensionsSettingsPage">
        <div className="container">
          <div className="Form">
            {this.buildSettingComponent({
              setting: 'huoxin-auto-image-dimensions.operating_mode',
              label: app.translator.trans('huoxin-auto-image-dimensions.admin.operating_mode_label'),
              help: app.translator.trans('huoxin-auto-image-dimensions.admin.operating_mode_help'),
              type: 'select',
              options: {
                backend: app.translator.trans('huoxin-auto-image-dimensions.admin.operating_mode_backend'),
                client: app.translator.trans('huoxin-auto-image-dimensions.admin.operating_mode_client'),
                hybrid: app.translator.trans('huoxin-auto-image-dimensions.admin.operating_mode_hybrid'),
              },
              default: 'client',
            })}
            {this.buildSettingComponent({
              setting: 'huoxin-auto-image-dimensions.max_height',
              label: app.translator.trans('huoxin-auto-image-dimensions.admin.max_height_label'),
              help: app.translator.trans('huoxin-auto-image-dimensions.admin.max_height_help'),
              type: 'number',
              default: 400,
            })}

            {!isClient && (
              <>
                {this.buildSettingComponent({
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
                })}
                {this.buildSettingComponent({
                  setting: 'huoxin-auto-image-dimensions.retry_mode',
                  label: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_label'),
                  help: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_help'),
                  type: 'select',
                  options: {
                    failed_only: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_failed_only'),
                    all: app.translator.trans('huoxin-auto-image-dimensions.admin.retry_mode_all'),
                  },
                  default: 'failed_only',
                })}
                {this.buildSettingComponent({
                  setting: 'huoxin-auto-image-dimensions.proxy',
                  label: app.translator.trans('huoxin-auto-image-dimensions.admin.proxy_label'),
                  help: app.translator.trans('huoxin-auto-image-dimensions.admin.proxy_help'),
                  type: 'text',
                  placeholder: 'tcp://10.0.0.5:3128',
                })}

                <div className="Form-group">
                  <label>{app.translator.trans('huoxin-auto-image-dimensions.admin.manual_trigger_label')}</label>
                  <div className="helpText">{app.translator.trans('huoxin-auto-image-dimensions.admin.manual_trigger_help')}</div>
                  <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button className="Button Button--primary" onclick={() => this.triggerBackfill('all')}>
                      {app.translator.trans('huoxin-auto-image-dimensions.admin.trigger_all_button')}
                    </button>
                    <button className="Button Button--warning" onclick={() => this.triggerBackfill('failed_only')}>
                      {app.translator.trans('huoxin-auto-image-dimensions.admin.trigger_failed_button')}
                    </button>
                  </div>
                </div>
              </>
            )}

            <div className="Form-group">{this.submitButton()}</div>
          </div>
        </div>
      </div>
    );
  }

  triggerBackfill(mode: string) {
    if (!confirm(extractText(app.translator.trans('huoxin-auto-image-dimensions.admin.trigger_confirm_text')))) {
      return;
    }

    app
      .request<{ queued: number }>({
        method: 'POST',
        url: app.forum.attribute<string>('apiUrl') + '/image-dimensions/backfill',
        body: { retry_mode: mode },
      })
      .then((response: { queued: number }) => {
        app.alerts.show({ type: 'success' }, app.translator.trans('huoxin-auto-image-dimensions.admin.trigger_success', { count: response.queued }));
      })
      .catch((error: unknown) => {
        console.error(error);
        app.alerts.show({ type: 'error' }, 'An error occurred while triggering the backfill.');
      });
  }
}
