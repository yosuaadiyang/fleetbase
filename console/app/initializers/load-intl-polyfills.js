import translations from 'ember-intl/translations';
import { all } from 'rsvp';
import { shouldPolyfill as numberFormatNeedsPolyfill } from '@formatjs/intl-numberformat/should-polyfill';
import { shouldPolyfill as pluralRulesNeedsPolyfill } from '@formatjs/intl-pluralrules/should-polyfill';
import { shouldPolyfill as dateTimeFormatNeedsPolyfill } from '@formatjs/intl-datetimeformat/should-polyfill';
import { shouldPolyfill as relativeTimeFormatNeedsPolyfill } from '@formatjs/intl-relativetimeformat/should-polyfill';

const isBrowser = typeof window !== 'undefined';
const isValidLang = (lang) => typeof lang === 'string' && /^[a-z]{2,3}$/i.test(lang);

// The only caller maps over ember-intl's translation manifest, so a tag is always
// present; the default exists purely as a guard.
/* istanbul ignore next -- unreachable default argument */
function langOf(tag = 'en') {
    return String(tag).toLowerCase().split('-')[0];
}

const DETECTORS = {
    NumberFormat: numberFormatNeedsPolyfill,
    PluralRules: pluralRulesNeedsPolyfill,
    DateTimeFormat: dateTimeFormatNeedsPolyfill,
    RelativeTimeFormat: relativeTimeFormatNeedsPolyfill,
};

export function requiredPolyfills(langs, detectors = DETECTORS) {
    const languages = langs.length ? langs : ['en'];
    return Object.keys(detectors).filter((name) => languages.some((lang) => Boolean(detectors[name](lang))));
}

async function loadBasePolyfills(required) {
    // Load implementations only when the active language needs them.
    if (required.includes('NumberFormat')) await import('@formatjs/intl-numberformat/polyfill-force');
    if (required.includes('PluralRules')) await import('@formatjs/intl-pluralrules/polyfill-force');
    if (required.includes('DateTimeFormat')) await import('@formatjs/intl-datetimeformat/polyfill-force');
    if (required.includes('RelativeTimeFormat')) await import('@formatjs/intl-relativetimeformat/polyfill-force');
}

/**
 * The first of the browser's preferred languages that has locale data loaded, or English.
 */
export function preferredLanguage(langs, browserLanguages = []) {
    const match = browserLanguages.map((tag) => langOf(tag)).find((lang) => langs.includes(lang));

    return match ?? (langs.includes('en') ? 'en' : langs[0]);
}

/**
 * A formatjs polyfill takes its default locale from whichever locale data registers first.
 * Locale data loads for every translation at once, so without this the default becomes an
 * arbitrary language (Arabic, being first alphabetically) and every Intl call made without
 * an explicit locale formats numbers and dates in it. Pins the polyfills to the user's
 * language instead. A native constructor has no __defaultLocale and is left alone.
 */
export function pinDefaultLocale(lang, intl = Intl) {
    for (const name of ['NumberFormat', 'PluralRules', 'DateTimeFormat', 'RelativeTimeFormat']) {
        const constructor = intl[name];

        if (constructor && typeof constructor.__defaultLocale === 'string') {
            constructor.__defaultLocale = lang;
        }
    }
}

async function loadLocaleData(lang, required) {
    // initialize() already filters the language list through isValidLang, so this is a
    // second line of defence that the initializer itself can never trigger.
    /* istanbul ignore next -- unreachable via initialize() */
    if (!isValidLang(lang)) return;

    const loads = [];
    if (required.includes('NumberFormat')) loads.push(import(`@formatjs/intl-numberformat/locale-data/${lang}.js`));
    if (required.includes('PluralRules')) loads.push(import(`@formatjs/intl-pluralrules/locale-data/${lang}.js`));
    if (required.includes('DateTimeFormat')) loads.push(import(`@formatjs/intl-datetimeformat/locale-data/${lang}.js`));
    if (required.includes('RelativeTimeFormat')) loads.push(import(`@formatjs/intl-relativetimeformat/locale-data/${lang}.js`));
    return all(loads);
}

let pendingPolyfills = Promise.resolve();

export function ensureIntlLocales(locales = ['en']) {
    const selected = (Array.isArray(locales) ? locales : [locales]).map(langOf).filter(isValidLang);
    // Serializing avoids competing locale changes overwriting Intl constructors.
    pendingPolyfills = pendingPolyfills
        .catch(() => {})
        .then(async () => {
            const required = requiredPolyfills(selected);
            if (!required.length) return;
            await loadBasePolyfills(required);
            const available = [...new Set(['en', ...translations.map(([locale]) => langOf(locale)), ...selected])].filter(isValidLang);
            await all(available.map((lang) => loadLocaleData(lang, required)));
            pinDefaultLocale(preferredLanguage(available, window.navigator.languages));
        });
    return pendingPolyfills;
}

export function initialize(application) {
    // There is no window to polyfill outside a browser. The test suite only ever runs in
    // one, so this arm cannot be exercised.
    /* istanbul ignore next -- no browser, nothing to polyfill */
    if (!isBrowser) return;

    const locales = translations.map(([locale]) => String(locale));
    const langs = [...new Set(locales.map(langOf).filter(isValidLang))];

    application.deferReadiness();

    (async () => {
        // The application starts in English; missing extension languages are
        // loaded by the intl service before that locale is selected.
        await ensureIntlLocales(['en', preferredLanguage(langs, window.navigator.languages)]);
        pinDefaultLocale(preferredLanguage(langs, window.navigator.languages));
        application.advanceReadiness();
    })();
}

export default {
    name: 'load-intl-polyfills',
    initialize,
};
