'use strict';

/**
 * The product name shown to users in place of the upstream "Fleetbase" brand.
 *
 * Only display text is rebranded. Identifiers stay as they are: npm and Composer
 * package names (@fleetbase/*), PHP namespaces (Fleetbase\...), translation keys,
 * CSS classes, Docker image names and URLs, because the code and the published
 * packages look them up by those exact names.
 */
const BRAND_NAME = 'Contrust';

// The upstream brand as a standalone word in prose: "Fleetbase", "fleetbase", but not
// part of an identifier, path, namespace, email or domain ("@fleetbase/ember-ui",
// "Fleetbase\Models", "fleetbase.io", "fleetbase-message", "fleetbase_users").
const UPSTREAM_BRAND = /(?<![\w@/\\.-])[Ff]leetbase(?![\w\\/]|-[a-z]|\.[a-z])/g;

// Things that really are Fleetbase's own services, not part of this product: the docs
// site, the `flb` CLI, the extension registry and its tokens, the hosted Taler
// merchant backend. Calling them "Contrust" would misinform users, so the brand is
// dropped instead. Applied before the general replacement.
const NEUTRAL_PHRASES = [
    ['Fleetbase Documentation', 'Documentation'],
    ['the official Fleetbase documentation', 'the official documentation'],
    ['the Fleetbase documentation', 'the documentation'],
    ['Fleetbase documentation', 'documentation'],
    ['Fleetbase CLI GitHub page', 'CLI GitHub page'],
    ['the Fleetbase CLI', 'the CLI'],
    ['the Fleetbase extension registry', 'the extension registry'],
    ['a Fleetbase registry', 'a registry'],
    ['Fleetbase Token', 'Registry Token'],
    ['hosted Fleetbase Merchant Backend', 'hosted Merchant Backend'],
];

// Legal statements (licensing, copyright) name the upstream copyright holder and its
// licenses on purpose, so text that mentions them is never rebranded.
const LEGAL_TEXT = /licen[cs]|copyright|©|Pte\.? Ltd/i;

function rebrand(text) {
    if (typeof text !== 'string' || LEGAL_TEXT.test(text)) {
        return text;
    }

    const neutral = NEUTRAL_PHRASES.reduce((result, [from, to]) => result.split(from).join(to), text);
    return neutral.replace(UPSTREAM_BRAND, BRAND_NAME);
}

module.exports = { BRAND_NAME, UPSTREAM_BRAND, NEUTRAL_PHRASES, rebrand };
