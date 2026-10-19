import { module, test } from 'qunit';
import { mergeAttributes } from '@tiptap/core';
import { DOMSerializer, Schema } from '@tiptap/pm/model';
import { EditorState } from '@tiptap/pm/state';
import { EditorView } from '@tiptap/pm/view';

module('Unit | dependency security audit', function () {
    test('Tiptap merges untrusted prototype keys without inherited DOM attributes', function (assert) {
        const attributes = mergeAttributes(JSON.parse('{"__proto__":{"data-inherited-canary":"present","onerror":"unexpected()"}}'));
        const schema = new Schema({ nodes: { doc: { content: 'image' }, image: { toDOM: () => ['img', attributes] }, text: {} } });
        const doc = document.implementation.createHTMLDocument();
        const fragment = DOMSerializer.fromSchema(schema).serializeFragment(schema.node('doc', null, [schema.node('image')]).content, { document: doc });
        assert.strictEqual(Object.getPrototypeOf(attributes), Object.prototype);
        assert.true(Object.hasOwn(attributes, '__proto__'));
        assert.strictEqual(attributes.onerror, undefined);
        assert.strictEqual(fragment.firstChild.getAttribute('onerror'), null);
        assert.strictEqual(fragment.firstChild.getAttribute('data-inherited-canary'), null);
    });

    test('Tiptap retains ordinary class, style and attribute merging', function (assert) {
        const result = mergeAttributes({ class: 'first shared', style: 'color: red', title: 'old' }, { class: 'shared second', style: 'color: green; display: block', title: 'new' });
        assert.strictEqual(result.class, 'first shared second');
        assert.strictEqual(result.style, 'color: green; display: block');
        assert.strictEqual(result.title, 'new');
    });

    test('ProseMirror validates clipboard slice context before constructing nodes', function (assert) {
        let validations = 0;
        const schema = new Schema({
            nodes: {
                doc: { content: 'block+' },
                paragraph: { group: 'block', content: 'text*', parseDOM: [{ tag: 'p' }], toDOM: () => ['p', 0] },
                blockquote: {
                    group: 'block',
                    content: 'block+',
                    attrs: {
                        tone: {
                            default: 'safe',
                            validate(value) {
                                validations++;
                                if (value !== 'safe') {
                                    throw new RangeError('Synthetic invalid clipboard attribute');
                                }
                            },
                        },
                    },
                    toDOM: (node) => ['blockquote', { 'data-tone': node.attrs.tone }, 0],
                },
                text: {},
            },
        });
        const container = document.createElement('div');
        document.body.appendChild(container);
        const view = new EditorView(container, { state: EditorState.create({ schema }) });
        try {
            const paragraph = document.createElement('p');
            paragraph.textContent = 'Clipboard canary';
            paragraph.setAttribute('data-pm-slice', '1 1 ' + JSON.stringify(['blockquote', { tone: 'unsafe-canary' }]));
            assert.true(view.pasteHTML(paragraph.outerHTML));
            assert.ok(validations > 0, 'clipboard-provided wrapper attributes were validated');
            assert.strictEqual(view.state.doc.textContent, 'Clipboard canary');
            assert.false(JSON.stringify(view.state.doc.toJSON()).includes('unsafe-canary'));
        } finally {
            view.destroy();
            container.remove();
        }
    });
});
