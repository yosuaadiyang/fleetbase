import IntlService from 'ember-intl/services/intl';
import { ensureIntlLocales, requiredPolyfills } from '@fleetbase/console/initializers/load-intl-polyfills';

const requests = new WeakMap();

export default class ContrustIntlService extends IntlService {
    createIntl(locale, messages) {
        const tag = Array.isArray(locale) ? locale[0] : locale;
        const language = String(tag).toLowerCase().split('-')[0];
        const previous = this.creatingInactiveLocale;
        // ember-intl hydrates every translation at construction. Missing format
        // data for an inactive locale is expected until that locale is selected.
        this.creatingInactiveLocale = !this.locale.includes(tag) && requiredPolyfills([language]).length > 0;
        try {
            return super.createIntl(locale, messages);
        } finally {
            this.creatingInactiveLocale = previous;
        }
    }

    onIntlError(error) {
        if (this.creatingInactiveLocale && error.code === 'MISSING_DATA') return;
        return super.onIntlError(error);
    }

    setLocale(locale) {
        const request = {};
        requests.set(this, request);
        const languages = (Array.isArray(locale) ? locale : [locale]).map((tag) => String(tag).toLowerCase().split('-')[0]);
        if (!requiredPolyfills(languages).length) {
            return super.setLocale(locale);
        }

        // Keep rendering the previous locale until the requested locale can
        // format correctly. A later selection takes precedence over this one.
        return ensureIntlLocales(languages)
            .then(() => {
                if (!this.isDestroying && !this.isDestroyed && requests.get(this) === request) {
                    super.setLocale(locale);
                }
            })
            .catch((error) => console.error('[Intl] Unable to load the selected locale', error));
    }
}
