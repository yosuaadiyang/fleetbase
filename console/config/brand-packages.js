'use strict';

/**
 * Rebrands the display text hard-coded in the extension packages' templates
 * (node_modules/@fleetbase/<package>/addon/**), which no translation reaches: labels
 * such as "Fleetbase AI" or "Fleetbase Ledger".
 *
 * It runs before the build, so Ember compiles, fingerprints and integrity-hashes the
 * rebranded sources as usual. Templates (.hbs) are display markup and get the general
 * rebrand (config/brand.js), which leaves identifiers like <FleetbaseAttribution> and
 * fleetbase-* alone. JavaScript gets only the exact display strings listed below,
 * because a bare "Fleetbase" there can be an identifier.
 *
 * A changed file is unlinked before it is rewritten: pnpm hard-links package files
 * from its global store, and writing in place would change that store for every
 * other project on the machine.
 */
const fs = require('fs');
const path = require('path');
const { BRAND_NAME, rebrand } = require('./brand');

const CONSOLE_ROOT = path.join(__dirname, '..');

// Display strings in package JavaScript (labels, descriptions, messages).
const JS_STRINGS = [
    'Enter a path on the Fleetbase API, not a full URL',
    'Fleetbase could not load the full provider device list.',
    'Vendor with native API integration into Fleetbase',
    'Fleetbase Token',
    'Discover, install, and publish extensions to the Fleetbase extension registry.',
    'Fleetbase Documentation',
];

// Labels the IAM module takes from the API, which names platform-wide roles and
// policies after the upstream brand ("FLB Managed", "Fleetbase Managed"). Ember Data
// records go through the console's brand-label transform; these cover the filter
// option and the dashboard widgets, which read the API without a model.
const EXACT_REPLACEMENTS = [
    ["name: 'FLB Managed'", `name: '${BRAND_NAME} Managed'`],
    ['{{role.type}}', `{{if (eq role.type "Fleetbase Managed") "${BRAND_NAME} Managed" role.type}}`],
];

// The upstream legal notice: legal text naming its copyright holder, left as written.
// (The console shows its own notice instead; see app/components/fleetbase-attribution.)
const SKIP = [path.join('ember-ui', 'addon', 'components', 'modals', 'fleetbase-legal-notice.hbs')];

// Every installed copy of every @fleetbase package. pnpm keeps one copy per set of peer
// dependencies (for example an ember-ui per engine), and the build may use any of them.
function packageDirs() {
    const store = path.join(CONSOLE_ROOT, 'node_modules', '.pnpm');
    const dirs = new Set();
    if (fs.existsSync(store)) {
        fs.readdirSync(store)
            .filter((entry) => entry.startsWith('@fleetbase+'))
            .forEach((entry) => {
                const scope = path.join(store, entry, 'node_modules', '@fleetbase');
                if (fs.existsSync(scope)) {
                    fs.readdirSync(scope).forEach((name) => dirs.add(fs.realpathSync(path.join(scope, name))));
                }
            });
    }
    const top = path.join(CONSOLE_ROOT, 'node_modules', '@fleetbase');
    if (fs.existsSync(top)) {
        fs.readdirSync(top).forEach((name) => dirs.add(fs.realpathSync(path.join(top, name))));
    }
    return [...dirs].sort();
}

function walk(dir, files = []) {
    fs.readdirSync(dir, { withFileTypes: true }).forEach((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory() && entry.name !== 'node_modules') {
            walk(full, files);
        } else if (entry.isFile() && /\.(hbs|js)$/.test(entry.name)) {
            files.push(full);
        }
    });
    return files;
}

// Operands of an (eq ...) comparison are data values, not display text: rebranding
// them would stop the comparison from ever matching what the API sends.
const COMPARISON = /\(eq [^)]*\)/g;

function rebrandTemplate(source) {
    // Line by line, so a license line keeps its wording without blocking the rest.
    return source
        .split('\n')
        .map((line) => {
            const kept = [];
            const masked = line.replace(COMPARISON, (match) => `__BRAND_KEEP_${kept.push(match) - 1}__`);
            return rebrand(masked).replace(/__BRAND_KEEP_(\d+)__/g, (match, index) => kept[Number(index)]);
        })
        .join('\n');
}

function rebrandScript(source) {
    return JS_STRINGS.reduce((result, phrase) => result.split(phrase).join(rebrand(phrase)), source);
}

function writeCopy(file, contents) {
    fs.unlinkSync(file);
    fs.writeFileSync(file, contents);
}

module.exports = function rebrandPackages() {
    let files = 0;
    packageDirs().forEach((dir) => {
        const addon = path.join(dir, 'addon');
        if (!fs.existsSync(addon)) {
            return;
        }
        walk(addon).forEach((file) => {
            if (SKIP.some((skip) => file.endsWith(path.sep + skip))) {
                return;
            }
            const source = fs.readFileSync(file, 'utf8');
            const rebranded = file.endsWith('.hbs') ? rebrandTemplate(source) : rebrandScript(source);
            const output = EXACT_REPLACEMENTS.reduce((result, [from, to]) => result.split(from).join(to), rebranded);
            if (output !== source) {
                writeCopy(file, output);
                files++;
            }
        });
    });
    return { files };
};
