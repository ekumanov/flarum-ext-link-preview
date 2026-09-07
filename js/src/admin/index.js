import app from 'flarum/admin/app';

// Nine settings in one flat form. Flarum 2.0.0-rc.8 offers nothing to group
// them with: `AdminRegistry` is byte-identical to rc.3 (no section or fieldset
// API), and `SettingsComponentOptions` is just `FieldComponentOptions` plus
// `setting` / `json` / `refreshAfterSaving`. The one escape hatch core does
// expose — passing a render callback to `registerSetting` — would mean adding
// mithril as a build dependency to draw a heading, which is a poor trade.
//
// So the grouping is the ordering, using the `priority` argument core already
// supports (higher renders first): fetching cadence and limits, then the site
// mark, then the identity/host controls.
app.initializers.add('ekumanov/link-preview', () => {
    const reg = app.registry.for('ekumanov-link-preview');

    reg.registerSetting(
        {
            setting: 'ekumanov-link-preview.ttl_seconds',
            type: 'number',
            label: app.translator.trans('ekumanov-link-preview.admin.settings.ttl_seconds'),
            help: app.translator.trans('ekumanov-link-preview.admin.settings.ttl_seconds_help'),
            min: 60,
            default: 2592000, // 30 days
        },
        100
    );

    reg.registerSetting(
        {
            setting: 'ekumanov-link-preview.user_rate_per_hour',
            type: 'number',
            label: app.translator.trans('ekumanov-link-preview.admin.settings.user_rate_per_hour'),
            help: app.translator.trans('ekumanov-link-preview.admin.settings.user_rate_per_hour_help'),
            min: 0,
            default: 20,
        },
        90
    );

    reg.registerSetting(
        {
            setting: 'ekumanov-link-preview.max_urls_per_post',
            type: 'number',
            label: app.translator.trans('ekumanov-link-preview.admin.settings.max_urls_per_post'),
            help: app.translator.trans('ekumanov-link-preview.admin.settings.max_urls_per_post_help'),
            min: 0,
            default: 10,
        },
        80
    );

    // Switches read '' as off, so a default-on switch needs its default spelled
    // out here — the PHP side defaults to on too (SettingsRepository), and no
    // row is written until an admin actually moves it.
    reg.registerSetting(
        {
            setting: 'ekumanov-link-preview.show_favicons',
            type: 'bool',
            label: app.translator.trans('ekumanov-link-preview.admin.settings.show_favicons'),
            help: app.translator.trans('ekumanov-link-preview.admin.settings.show_favicons_help'),
            default: '1',
        },
        70
    );

    reg.registerSetting(
        {
            setting: 'ekumanov-link-preview.icon_probe',
            type: 'bool',
            label: app.translator.trans('ekumanov-link-preview.admin.settings.icon_probe'),
            help: app.translator.trans('ekumanov-link-preview.admin.settings.icon_probe_help'),
            default: '1',
        },
        60
    );

    reg.registerSetting(
        {
            setting: 'ekumanov-link-preview.favicon_max_bytes',
            type: 'number',
            label: app.translator.trans('ekumanov-link-preview.admin.settings.favicon_max_bytes'),
            help: app.translator.trans('ekumanov-link-preview.admin.settings.favicon_max_bytes_help'),
            min: 0,
            default: 32768, // 32 KB
        },
        50
    );

    reg.registerSetting(
        {
            setting: 'ekumanov-link-preview.user_agents',
            type: 'textarea',
            label: app.translator.trans('ekumanov-link-preview.admin.settings.user_agents'),
            help: app.translator.trans('ekumanov-link-preview.admin.settings.user_agents_help'),
            placeholder:
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36\nTwitterbot/1.0\nfacebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            // A textarea defaults to rows=2. This placeholder is three lines,
            // the first of which wraps to two on its own, so the built-in
            // chain was rendered clipped inside a two-row box — it read as one
            // run-on line with the other two identities scrolled out of sight,
            // and since placeholder text can't be selected the field looked
            // broken rather than empty. Size the box to its own placeholder.
            rows: 8,
        },
        40
    );

    reg.registerSetting(
        {
            setting: 'ekumanov-link-preview.whitelist',
            type: 'textarea',
            label: app.translator.trans('ekumanov-link-preview.admin.settings.whitelist'),
            help: app.translator.trans('ekumanov-link-preview.admin.settings.whitelist_help'),
            placeholder: 'example.com\n*.trusted.org',
            rows: 4,
        },
        30
    );

    reg.registerSetting(
        {
            setting: 'ekumanov-link-preview.blacklist',
            type: 'textarea',
            label: app.translator.trans('ekumanov-link-preview.admin.settings.blacklist'),
            help: app.translator.trans('ekumanov-link-preview.admin.settings.blacklist_help'),
            placeholder: 'amazon.com\n*.amazon.com\nebay.com',
            rows: 4,
        },
        20
    );
});
