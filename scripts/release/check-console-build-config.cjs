#!/usr/bin/env node
'use strict';
// Parse shipped JavaScript; never execute bundle code to inspect configuration.
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const consoleRequire = createRequire(path.resolve(__dirname, '../../console/package.json'));
const { parseSync } = consoleRequire('@babel/core');
function fail(message) { throw new Error(message); }
function property(node, name) {
    if (!node || node.type !== 'ObjectExpression') fail('Expected static configuration object');
    const values = node.properties.filter(p => p.type === 'ObjectProperty' && !p.computed && (p.key.name ?? p.key.value) === name);
    if (values.length !== 1) fail('Missing or ambiguous configuration field: ' + name);
    return values[0].value;
}
function literal(node) {
    if (['StringLiteral', 'NumericLiteral', 'BooleanLiteral'].includes(node.type)) return node.value;
    if (node.type === 'UnaryExpression' && node.operator === '!' && node.argument.type === 'NumericLiteral') return !node.argument.value;
    fail('Non-literal production configuration');
}
function visit(node, fn) {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) { node.forEach(x => visit(x, fn)); return; }
    if (typeof node.type === 'string') fn(node);
    for (const [key, value] of Object.entries(node)) if (!['loc', 'start', 'end', 'extra', 'comments'].includes(key) && value && typeof value === 'object') visit(value, fn);
}
try {
    const root = path.resolve(process.argv[2] || 'console/dist');
    const folder = path.join(root, 'assets/@fleetbase');
    if (!fs.statSync(root).isDirectory() || fs.lstatSync(root).isSymbolicLink() || fs.lstatSync(folder).isSymbolicLink()) fail('Unsafe artifact directory');
    const files = fs.readdirSync(folder).filter(n => /^console-[a-f0-9]+\.js$/.test(n));
    if (files.length !== 1) fail('Expected one current console application bundle');
    const file = path.join(folder, files[0]);
    if (!fs.lstatSync(file).isFile() || fs.lstatSync(file).isSymbolicLink()) fail('Unsafe artifact');
    const tree = parseSync(fs.readFileSync(file, 'utf8'), { babelrc: false, configFile: false, sourceType: 'script' });
    const factories = [];
    visit(tree.program, n => {
        if (n.type === 'CallExpression' && n.callee.type === 'Identifier' && n.callee.name === 'define' && n.arguments[0]?.type === 'StringLiteral' && n.arguments[0].value === '@fleetbase/console/config/environment') factories.push(n.arguments[n.arguments.length - 1]);
    });
    if (factories.length !== 1 || factories[0].type !== 'FunctionExpression') fail('Missing or ambiguous application environment module');
    const configs = [];
    visit(factories[0].body, n => {
        if (n.type === 'ObjectExpression' && n.properties.some(p => p.type === 'ObjectProperty' && !p.computed && (p.key.name ?? p.key.value) === 'modulePrefix')) configs.push(n);
    });
    if (configs.length !== 1) fail('Expected one static application environment');
    const config = configs[0];
    const expected = {
        API: { host: 'https://api.contrust.website', namespace: 'int/v1' },
        osrm: { host: 'https://api.contrust.website/routing' },
        socket: { hostname: 'api.contrust.website', secure: true, port: 443, path: '/socketcluster/' },
        APP: { disableRuntimeConfig: true, autoboot: true },
    };
    if (literal(property(config, 'environment')) !== 'production') fail('Not a production bundle');
    if (literal(property(config, 'modulePrefix')) !== '@fleetbase/console') fail('Unexpected application');
    for (const [section, fields] of Object.entries(expected)) {
        const object = property(config, section);
        for (const [key, value] of Object.entries(fields)) if (literal(property(object, key)) !== value) fail('Unexpected production field: ' + section + '.' + key);
    }
    const source = process.env.SOURCE_CODE_URL;
    if (!source || !/^https:\/\/github\.com\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\/tree\/[a-f0-9]{40}$/.test(source)) fail('Exact commit source URL required');
    if (literal(property(property(config, 'APP'), 'sourceCodeUrl')) !== source) fail('Source URL does not match build');
    console.log(JSON.stringify({ production_config_verified: true, api_https: true, routing_https: true, secure_socket_443: true, runtime_override_disabled: true, exact_source_link_verified: true }));
} catch (error) {
    console.error('Production configuration check failed: ' + error.message);
    process.exitCode = 1;
}
