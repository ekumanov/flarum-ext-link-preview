import app from 'flarum/admin/app';

app.initializers.add('ekumanov/link-preview', () => {
    const reg = app.registry.for('ekumanov-link-preview');

    reg.registerSetting({
        setting: 'ekumanov-link-preview.ttl_seconds',
        type: 'number',
        label: app.translator.trans('ekumanov-link-preview.admin.settings.ttl_seconds'),
        help: app.translator.trans('ekumanov-link-preview.admin.settings.ttl_seconds_help'),
        min: 60,
        default: 2592000, // 30 days
    });

    reg.registerSetting({
        setting: 'ekumanov-link-preview.user_rate_per_hour',
        type: 'number',
        label: app.translator.trans('ekumanov-link-preview.admin.settings.user_rate_per_hour'),
        help: app.translator.trans('ekumanov-link-preview.admin.settings.user_rate_per_hour_help'),
        min: 0,
        default: 20,
    });

    reg.registerSetting({
        setting: 'ekumanov-link-preview.max_urls_per_post',
        type: 'number',
        label: app.translator.trans('ekumanov-link-preview.admin.settings.max_urls_per_post'),
        help: app.translator.trans('ekumanov-link-preview.admin.settings.max_urls_per_post_help'),
        min: 0,
        default: 10,
    });

    reg.registerSetting({
        setting: 'ekumanov-link-preview.whitelist',
        type: 'textarea',
        label: app.translator.trans('ekumanov-link-preview.admin.settings.whitelist'),
        help: app.translator.trans('ekumanov-link-preview.admin.settings.whitelist_help'),
        placeholder: 'example.com\n*.trusted.org',
    });

    reg.registerSetting({
        setting: 'ekumanov-link-preview.blacklist',
        type: 'textarea',
        label: app.translator.trans('ekumanov-link-preview.admin.settings.blacklist'),
        help: app.translator.trans('ekumanov-link-preview.admin.settings.blacklist_help'),
        placeholder: 'amazon.com\n*.amazon.com\nebay.com',
    });
});
