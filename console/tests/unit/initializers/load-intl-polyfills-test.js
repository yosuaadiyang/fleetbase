import { module, test } from 'qunit';
import { initialize, pinDefaultLocale, preferredLanguage, requiredPolyfills } from '@fleetbase/console/initializers/load-intl-polyfills';

module('Unit | Initializer | load-intl-polyfills', function () {
    test('native support keeps all polyfill implementations and locale chunks out of startup', function (assert) {
        assert.deepEqual(requiredPolyfills(['en', 'mn'], { NumberFormat: () => undefined, PluralRules: () => undefined }), []);
    });

    test('a missing extension locale loads only the affected formatter', function (assert) {
        assert.deepEqual(requiredPolyfills(['en', 'mn'], { NumberFormat: () => undefined, PluralRules: (lang) => (lang === 'mn' ? lang : undefined) }), ['PluralRules']);
    });

    test('an empty manifest still checks the English fallback', function (assert) {
        assert.deepEqual(requiredPolyfills([], { NumberFormat: (lang) => lang }), ['NumberFormat']);
    });

    test('it defers readiness while the polyfills load and advances afterwards', async function (assert) {
        const calls = [];
        let advanced;
        const didAdvance = new Promise((resolve) => (advanced = resolve));

        const application = {
            deferReadiness: () => calls.push('defer'),
            advanceReadiness: () => {
                calls.push('advance');
                advanced();
            },
        };

        initialize(application);

        assert.deepEqual(calls, ['defer'], 'readiness is deferred synchronously');

        // The polyfills load through dynamic imports in a bare async IIFE, which settled()
        // does not track — wait on advanceReadiness itself.
        await didAdvance;

        assert.deepEqual(calls, ['defer', 'advance'], 'readiness advances once the polyfills resolve');
    });
});

module('Unit | Initializer | load-intl-polyfills | default locale', function () {
    test('the preferred language is the first browser language with locale data, else English', function (assert) {
        const langs = ['ar', 'de', 'en', 'fr'];

        assert.strictEqual(preferredLanguage(langs, ['fr-CA', 'en-US']), 'fr');
        assert.strictEqual(preferredLanguage(langs, ['ja-JP', 'en-SG']), 'en');
        assert.strictEqual(preferredLanguage(langs, ['ja-JP']), 'en');
        assert.strictEqual(preferredLanguage(['ar', 'de'], []), 'ar');
    });

    test('a polyfill is pinned to that language instead of the first locale data it loaded', function (assert) {
        // A polyfill records the first locale data it receives as its default ("ar" here); native
        // constructors carry no __defaultLocale and must be left untouched.
        const polyfilled = { __defaultLocale: 'ar' };
        const native = function NativeDateTimeFormat() {};
        const intl = { NumberFormat: polyfilled, PluralRules: { __defaultLocale: 'ar' }, DateTimeFormat: native };

        pinDefaultLocale('en', intl);

        assert.strictEqual(intl.NumberFormat.__defaultLocale, 'en');
        assert.strictEqual(intl.PluralRules.__defaultLocale, 'en');
        assert.strictEqual(native.__defaultLocale, undefined);
    });

    test('after loading, formatting without a locale follows the browser language, not the first locale loaded', async function (assert) {
        let advanced;
        const didAdvance = new Promise((resolve) => (advanced = resolve));

        initialize({ deferReadiness() {}, advanceReadiness: () => advanced() });
        await didAdvance;

        const language = (tag) => String(tag).toLowerCase().split('-')[0];
        const browser = preferredLanguage(['ar', 'bg', 'de', 'en', 'es', 'fa', 'fr', 'it', 'mn', 'pt', 'ru', 'uz', 'vi', 'zh'], navigator.languages);

        assert.strictEqual(language(new Intl.NumberFormat().resolvedOptions().locale), browser);
        assert.strictEqual(language(new Intl.DateTimeFormat().resolvedOptions().locale), browser);
        assert.strictEqual(Intl.NumberFormat.supportedLocalesOf(['en']).length, 1);
    });

    test('with no browser languages it falls back to English', function (assert) {
        assert.strictEqual(preferredLanguage(['ar', 'en', 'mn']), 'en');
    });
});
