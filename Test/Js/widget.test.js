const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const SCRIPT_URL = 'https://cdn.example.test/widget.js';

function loadAmdModule(file, dependencies, globals = {}) {
    let exported;
    vm.runInNewContext(readFileSync(file, 'utf8'), {
        define: (names, factory) => {
            exported = factory(...names.map((name) => dependencies[name]));
        },
        Promise,
        ...globals,
    });

    return exported;
}

class EventTarget {
    constructor() {
        this.listeners = new Map();
    }

    addEventListener(name, listener) {
        this.listeners.set(name, [...(this.listeners.get(name) || []), listener]);
    }

    dispatch(name, event = {}) {
        this.listeners.get(name)?.forEach((listener) => listener(event));
    }
}

test('loads each hosted script URL once', async () => {
    const scripts = [];
    const document = {
        createElement: () => ({
            setAttribute: () => {},
        }),
        head: {
            appendChild: (script) => scripts.push(script),
        },
    };
    const loadScript = loadAmdModule(
        path.join(__dirname, '../../view/frontend/web/js/script-loader.js'),
        {},
        { document }
    );

    const loads = [loadScript(SCRIPT_URL), loadScript(SCRIPT_URL)];

    assert.equal(scripts.length, 1);
    scripts[0].onload();
    await Promise.all(loads);
});

test('keeps submit controls disabled until the widget finishes and fails closed', async () => {
    const window = new EventTarget();
    let loadCalls = 0;
    let setupCalls = 0;
    window.privateCaptcha = { setup: () => setupCalls++ };
    const controls = [{ disabled: false }, { disabled: false }];
    const form = new EventTarget();
    form.querySelectorAll = () => controls;
    const captcha = new EventTarget();
    const element = new EventTarget();
    element.querySelector = (selector) => selector === '.private-captcha' ? captcha : null;
    const widget = loadAmdModule(
        path.join(__dirname, '../../view/frontend/web/js/widget.js'),
        {
            'PrivateCaptcha_PrivateCaptcha/js/script-loader': () => {
                loadCalls++;

                return Promise.resolve();
            },
        },
        { window }
    );

    widget({ scriptUrl: SCRIPT_URL, form }, element);
    widget({ scriptUrl: SCRIPT_URL, form }, element);
    await Promise.resolve();

    assert.deepEqual([
        loadCalls,
        setupCalls,
        window.listeners.get('pageshow').length,
        form.listeners.get('submit').length,
    ], [1, 1, 1, 1]);
    assert.deepEqual(controls.map((control) => control.disabled), [true, true]);

    captcha.dispatch('privatecaptcha:finish');
    assert.deepEqual(controls.map((control) => control.disabled), [false, false]);
    const blockedSubmission = { prevented: false, preventDefault() { this.prevented = true; } };
    form.dispatch('submit', blockedSubmission);
    assert.equal(blockedSubmission.prevented, false);

    captcha.dispatch('privatecaptcha:error');
    assert.deepEqual(controls.map((control) => control.disabled), [true, true]);
    const failedSubmission = {
        prevented: false,
        stopped: false,
        preventDefault() { this.prevented = true; },
        stopImmediatePropagation() { this.stopped = true; },
    };
    form.dispatch('submit', failedSubmission);
    assert.deepEqual([failedSubmission.prevented, failedSubmission.stopped], [true, true]);

    captcha.dispatch('privatecaptcha:finish');
    window.dispatch('pageshow', { persisted: true });
    assert.deepEqual(controls.map((control) => control.disabled), [true, true]);
});

test('auto-scans replacement elements after loading the standard widget script', async () => {
    const window = new EventTarget();
    let setupCalls = 0;
    const scriptUrls = [];
    const createContainer = () => {
        const form = new EventTarget();
        form.querySelectorAll = () => [];
        const captcha = new EventTarget();
        const container = new EventTarget();
        container.closest = () => form;
        container.querySelector = (selector) => selector === '.private-captcha' ? captcha : null;

        return container;
    };
    const firstElement = createContainer();
    const replacementElement = createContainer();
    window.privateCaptcha = { setup: () => setupCalls++ };
    const widget = loadAmdModule(
        path.join(__dirname, '../../view/frontend/web/js/widget.js'),
        {
            'PrivateCaptcha_PrivateCaptcha/js/script-loader': (url) => {
                scriptUrls.push(url);

                return Promise.resolve();
            },
        },
        { window }
    );

    widget({ scriptUrl: SCRIPT_URL }, firstElement);
    await Promise.resolve();

    widget({ scriptUrl: SCRIPT_URL }, replacementElement);
    await Promise.resolve();

    assert.deepEqual(scriptUrls, [SCRIPT_URL, SCRIPT_URL]);
    assert.equal(setupCalls, 2);
});

test('disambiguates new duplicate identities without changing attached widgets', () => {
    const window = new EventTarget();
    window.privateCaptcha = { setup() {} };
    const form = new EventTarget();
    form.querySelectorAll = () => [];
    const createElement = (captcha) => {
        const element = new EventTarget();
        element.closest = () => form;
        element.querySelector = (selector) => selector === '.private-captcha' ? captcha : null;

        return element;
    };
    const firstCaptcha = new EventTarget();
    firstCaptcha.id = 'private-captcha-shared';
    firstCaptcha.dataset = { storeVariable: 'privateCaptcha_shared' };
    const secondCaptcha = new EventTarget();
    secondCaptcha.id = 'private-captcha-shared';
    secondCaptcha.dataset = { storeVariable: 'privateCaptcha_shared' };
    const attachedCaptcha = new EventTarget();
    attachedCaptcha.id = 'private-captcha-attached';
    attachedCaptcha.dataset = { attached: '1', storeVariable: 'privateCaptcha_attached' };
    const newCaptcha = new EventTarget();
    newCaptcha.id = 'private-captcha-attached';
    newCaptcha.dataset = { storeVariable: 'privateCaptcha_attached' };
    const firstElement = createElement(firstCaptcha);
    const secondElement = createElement(secondCaptcha);
    const newElement = createElement(newCaptcha);
    const widget = loadAmdModule(
        path.join(__dirname, '../../view/frontend/web/js/widget.js'),
        { 'PrivateCaptcha_PrivateCaptcha/js/script-loader': () => Promise.resolve() },
        {
            document: {
                querySelectorAll: () => [firstCaptcha, secondCaptcha, attachedCaptcha, newCaptcha],
            },
            window,
        }
    );

    widget({ scriptUrl: SCRIPT_URL }, firstElement);
    widget({ scriptUrl: SCRIPT_URL }, secondElement);
    widget({ scriptUrl: SCRIPT_URL }, newElement);

    assert.deepEqual(
        [
            [firstCaptcha.id, firstCaptcha.dataset.storeVariable],
            [secondCaptcha.id, secondCaptcha.dataset.storeVariable],
            [attachedCaptcha.id, attachedCaptcha.dataset.storeVariable],
            [newCaptcha.id, newCaptcha.dataset.storeVariable],
        ],
        [
            ['private-captcha-shared-1', 'privateCaptcha_shared_1'],
            ['private-captcha-shared-2', 'privateCaptcha_shared_2'],
            ['private-captcha-attached', 'privateCaptcha_attached'],
            ['private-captcha-attached-1', 'privateCaptcha_attached_1'],
        ]
    );
});

test('shows an actionable error without enabling the form when script loading fails', async () => {
    const window = new EventTarget();
    const controls = [{ disabled: false }];
    const form = new EventTarget();
    form.querySelectorAll = () => controls;
    const captcha = new EventTarget();
    const error = { hidden: true, textContent: '' };
    const element = new EventTarget();
    element.closest = () => form;
    element.querySelector = (selector) => selector === '.private-captcha' ? captcha : error;
    const widget = loadAmdModule(
        path.join(__dirname, '../../view/frontend/web/js/widget.js'),
        { 'PrivateCaptcha_PrivateCaptcha/js/script-loader': () => Promise.reject(new Error('network failed')) },
        { window }
    );

    widget({ scriptUrl: SCRIPT_URL }, element);
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(controls[0].disabled, true);
    assert.deepEqual(error, {
        hidden: false,
        textContent: 'Private Captcha could not start. Please refresh and try again.',
    });
});

test('places widgets by adjacent native forms and keeps them independent', () => {
    const window = new EventTarget();
    window.privateCaptcha = { setup() {} };
    const createDetachedWidget = (withInterveningSibling) => {
        const controls = [{ disabled: false }];
        const toolbar = {
            parentNode: {
                insertBefore: (element, target) => {
                    element.insertedBefore = target;
                },
            },
        };
        const form = new EventTarget();
        form.tagName = 'FORM';
        form.querySelectorAll = () => controls;
        form.querySelector = () => toolbar;
        const captcha = new EventTarget();
        const element = new EventTarget();
        element.closest = () => null;
        element.previousElementSibling = withInterveningSibling ? {
            tagName: 'DIV',
            previousElementSibling: form,
        } : form;
        element.querySelector = (selector) => selector === '.private-captcha' ? captcha : null;

        return { captcha, controls, element, toolbar };
    };
    const first = createDetachedWidget(true);
    const second = createDetachedWidget(false);
    const widget = loadAmdModule(
        path.join(__dirname, '../../view/frontend/web/js/widget.js'),
        { 'PrivateCaptcha_PrivateCaptcha/js/script-loader': () => Promise.resolve() },
        { window }
    );

    widget({
        scriptUrl: SCRIPT_URL,
        placement: 'before-toolbar',
        detachedTarget: 'previous-form',
    }, first.element);
    widget({
        scriptUrl: SCRIPT_URL,
        placement: 'before-toolbar',
        detachedTarget: 'previous-form',
    }, second.element);

    first.captcha.dispatch('privatecaptcha:finish');

    assert.deepEqual(
        [first.controls[0].disabled, second.controls[0].disabled],
        [false, true]
    );
    assert.deepEqual(
        [first.element.insertedBefore, second.element.insertedBefore],
        [first.toolbar, second.toolbar]
    );
});
