'use strict';

/**
 * Rebrands the translations that ship inside the extension packages
 * (node_modules/@fleetbase/<engine>/translations), which this repository cannot edit.
 *
 * ember-intl merges every addon's translations first and the application's last, so
 * on a conflict the application wins (ember-intl/lib/broccoli/translation-reducer).
 * For each package string that mentions the upstream brand, this writes the same key
 * with the brand replaced into translations/zz-brand/<locale>.yaml, which then takes
 * precedence. Keys the console defines itself are left alone, so the console's own
 * translations still win as before. Regenerated on every build, so it follows
 * package updates; the output is not committed.
 */
const fs = require('fs');
const path = require('path');
const { rebrand } = require('./brand');

const CONSOLE_ROOT = path.join(__dirname, '..');
const APP_TRANSLATIONS = path.join(CONSOLE_ROOT, 'translations');
const OUTPUT_DIR = path.join(APP_TRANSLATIONS, 'zz-brand');

// ember-intl's own YAML parser, so no dependency is added for it.
function loadYaml() {
    const intlRoot = path.dirname(require.resolve('ember-intl/package.json', { paths: [CONSOLE_ROOT] }));
    // eslint-disable-next-line n/no-missing-require -- resolved from ember-intl's own dependencies
    return require(require.resolve('js-yaml', { paths: [intlRoot] }));
}

function packageTranslationDirs() {
    const scope = path.join(CONSOLE_ROOT, 'node_modules', '@fleetbase');
    if (!fs.existsSync(scope)) {
        return [];
    }

    return fs
        .readdirSync(scope)
        .sort()
        .map((name) => path.join(scope, name, 'translations'))
        .filter((dir) => fs.existsSync(dir));
}

function yamlFiles(dir) {
    return fs
        .readdirSync(dir)
        .filter((file) => /\.ya?ml$/.test(file))
        .sort();
}

function hasPath(object, keys) {
    let node = object;
    for (const key of keys) {
        if (!node || typeof node !== 'object' || !(key in node)) {
            return false;
        }
        node = node[key];
    }
    return true;
}

function setPath(object, keys, value) {
    let node = object;
    keys.slice(0, -1).forEach((key) => {
        node[key] = node[key] && typeof node[key] === 'object' ? node[key] : {};
        node = node[key];
    });
    node[keys[keys.length - 1]] = value;
}

// Collects [keyPath, rebrandedValue] for every string value the rebrand changes.
function collectRebranded(node, keys = [], found = []) {
    if (typeof node === 'string') {
        const value = rebrand(node);
        if (value !== node) {
            found.push([keys, value]);
        }
    } else if (node && typeof node === 'object') {
        Object.keys(node).forEach((key) => collectRebranded(node[key], [...keys, key], found));
    }
    return found;
}

module.exports = function generateBrandTranslations() {
    const yaml = loadYaml();
    const appByLocale = {};
    yamlFiles(APP_TRANSLATIONS).forEach((file) => {
        appByLocale[file.replace(/\.ya?ml$/, '').toLowerCase()] = yaml.load(fs.readFileSync(path.join(APP_TRANSLATIONS, file), 'utf8')) || {};
    });

    const overridesByLocale = {};
    let strings = 0;
    packageTranslationDirs().forEach((dir) => {
        yamlFiles(dir).forEach((file) => {
            const locale = file.replace(/\.ya?ml$/, '').toLowerCase();
            const translations = yaml.load(fs.readFileSync(path.join(dir, file), 'utf8')) || {};
            collectRebranded(translations).forEach(([keys, value]) => {
                if (!hasPath(appByLocale[locale], keys)) {
                    overridesByLocale[locale] = overridesByLocale[locale] || {};
                    setPath(overridesByLocale[locale], keys, value);
                    strings++;
                }
            });
        });
    });

    fs.rmSync(OUTPUT_DIR, { recursive: true, force: true });
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
    Object.keys(overridesByLocale)
        .sort()
        .forEach((locale) => {
            fs.writeFileSync(path.join(OUTPUT_DIR, `${locale}.yaml`), yaml.dump(overridesByLocale[locale], { lineWidth: -1 }));
        });

    return { locales: Object.keys(overridesByLocale).length, strings };
};
