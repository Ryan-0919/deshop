'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const html = fs.readFileSync(path.join(__dirname, '../app/admin/view/default/order/index.html'), 'utf8');
const script = html.match(/<script type="text\/javascript">([\s\S]*?)<\/script>/)[1];

function fixture(visible) {
    const inputs = visible ? [
        {name: 'fs_datep_start', value: '', date: true},
        {name: 'fs_datep_end', value: '', date: true},
    ] : [];
    inputs.push({name: 'fs_payp', value: '1'});
    const ids = {
        '#order-export-start-date': {value: '2026-09-01'},
        '#order-export-end-date': {value: '2026-09-28'},
    };
    const handlers = {};
    const events = [];
    const prompts = [];
    const document = {};
    const form = {kind: 'form'};

    class Selection {
        constructor(items) { this.items = items; this.length = items.length; }
        find(selector) {
            if (selector.includes('head-value-add_time')) {
                const suffix = selector.match(/name\$="([^"]+)"/)[1];
                return new Selection(inputs.filter(input => input.date && input.name.endsWith(suffix)));
            }
            const name = selector.match(/name="([^"]+)"/)[1];
            return new Selection(inputs.filter(input => input.name === name));
        }
        val(value) {
            if (arguments.length === 0) return this.items[0]?.value;
            this.items.forEach(item => { item.value = value; });
            return this;
        }
        attr(values) { this.items.forEach(item => Object.assign(item, values)); return this; }
        appendTo() { inputs.push(...this.items); return this; }
        remove() {
            this.items.forEach(item => { const index = inputs.indexOf(item); if (index >= 0) inputs.splice(index, 1); });
            return this;
        }
        data(name) { return this.items[0][name]; }
        on(event, selector, handler) { handlers[selector] = handler; return this; }
        trigger(event) { events.push({target: this.items[0].kind, event}); return this; }
    }
    function $(selector) {
        if (selector === document || typeof selector === 'object') return new Selection([selector]);
        if (selector === 'form.form-validation-search') return new Selection([form]);
        if (selector === '<input>') return new Selection([{}]);
        if (ids[selector]) return new Selection([ids[selector]]);
        if (selector === '.form-table-operate-top-export-excel-submit') return new Selection([{kind: 'export'}]);
        throw new Error('Unexpected selector: ' + selector);
    }
    $.each = (object, callback) => Object.entries(object).forEach(([key, value]) => callback(key, value));
    class FixedDate extends Date {
        constructor(...args) { super(...(args.length ? args : [2026, 8, 28, 12, 34, 56])); }
    }
    vm.runInNewContext(script, {$, document, Date: FixedDate, Prompt: value => prompts.push(value)});
    return {
        inputs, ids, events, prompts,
        range: range => handlers['.order-export-range'].call({range}),
        custom: () => handlers['.order-export-custom'].call({}),
        value: name => inputs.find(input => input.name === name)?.value,
    };
}

for (const visible of [false, true]) {
    const f = fixture(visible);
    const start = visible ? 'fs_datep_start' : 'add_time_start';
    const end = visible ? 'fs_datep_end' : 'add_time_end';
    f.range('today');
    assert.equal(f.value(start), '2026-09-28 00:00:00');
    assert.equal(f.value(end), '2026-09-28 23:59:59');
    assert.equal(f.events.at(-1).target, 'export');
    f.range('month');
    assert.equal(f.value(start), '2026-09-01 00:00:00');
    assert.equal(f.value(end), '2026-09-30 23:59:59');
    f.custom();
    assert.equal(f.value(end), '2026-09-28 23:59:59');
    assert.equal(f.value('fs_payp'), '1', 'Export must preserve the payment status filter');
    assert.equal(f.inputs.filter(input => input.name === start).length, 1, 'Repeated exports must not duplicate inputs');
    if (visible) {
        assert.equal(f.value('add_time_start'), undefined, 'Visible date fields must not leave overriding hidden aliases');
        f.inputs.find(input => input.name === start).value = '2026-09-10 00:00:00';
        assert.equal(f.value(start), '2026-09-10 00:00:00', 'Manual date edits must remain authoritative');
    }
    f.range('clear');
    assert.equal(f.value(start), '');
    assert.equal(f.value(end), '');
    assert.deepEqual(f.events.at(-1), {target: 'form', event: 'submit'});
    const count = f.events.length;
    f.ids['#order-export-start-date'].value = '';
    f.custom();
    assert.equal(f.events.length, count, 'Incomplete ranges must not export');
    f.ids['#order-export-start-date'].value = '2026-10-01';
    f.custom();
    assert.equal(f.events.length, count, 'Reversed ranges must not export');
    assert.equal(f.prompts.length, 2);
}
console.log('Order export range tests passed (visible/hidden dates, today, month, custom, clear, manual edits, status preservation).');
