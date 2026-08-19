const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function loadAmdModule(path, dependencies, globals = {}) {
    let exported;
    vm.runInNewContext(readFileSync(path, 'utf8'), {
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

    listenerCount(name) {
        return (this.listeners.get(name) || []).length;
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

    const first = loadScript('https://cdn.example.test/widget.js');
    const second = loadScript('https://cdn.example.test/widget.js');

    assert.equal(scripts.length, 1);
    scripts[0].onload();
    await Promise.all([first, second]);
});

test('keeps submit controls local and disabled until the widget finishes', async () => {
    const window = new EventTarget();
    let setupCalls = 0;
    window.privateCaptcha = {
        setup: () => setupCalls++,
    };
    const controls = [{ disabled: false }, { disabled: false }];
    const toolbar = {
        parentNode: {
            insertBefore: (element, target) => {
                element.insertedBefore = target;
            },
        },
    };
    const form = new EventTarget();
    form.querySelectorAll = () => controls;
    form.querySelector = () => toolbar;
    const captcha = new EventTarget();
    const element = new EventTarget();
    element.closest = () => null;
    element.error = { hidden: true, textContent: '' };
    element.querySelector = (selector) => selector === '.private-captcha' ? captcha : element.error;
    const widget = loadAmdModule(
        path.join(__dirname, '../../view/frontend/web/js/widget.js'),
        { 'PrivateCaptcha_PrivateCaptcha/js/script-loader': () => Promise.resolve() },
        { window }
    );

    widget({ scriptUrl: 'https://cdn.example.test/widget.js', placement: 'before-toolbar', form }, element);
    widget({ scriptUrl: 'https://cdn.example.test/widget.js', placement: 'before-toolbar', form }, element);
    assert.deepEqual(controls.map((control) => control.disabled), [true, true]);
    assert.equal(element.insertedBefore, toolbar);
    assert.equal(window.listenerCount('pageshow'), 1);

    captcha.dispatch('privatecaptcha:finish');
    assert.deepEqual(controls.map((control) => control.disabled), [false, false]);
    const blockedSubmission = { prevented: false, preventDefault() { this.prevented = true; } };
    form.dispatch('submit', blockedSubmission);
    assert.equal(blockedSubmission.prevented, false);

    captcha.dispatch('privatecaptcha:error');
    assert.deepEqual(controls.map((control) => control.disabled), [true, true]);
    assert.equal(element.error.hidden, false);
    assert.equal(element.error.textContent, 'Private Captcha could not start. Please refresh and try again.');
    const failedSubmission = {
        prevented: false,
        stopped: false,
        preventDefault() { this.prevented = true; },
        stopImmediatePropagation() { this.stopped = true; },
    };
    form.dispatch('submit', failedSubmission);
    assert.equal(failedSubmission.prevented, true);
    assert.equal(failedSubmission.stopped, true);

    captcha.dispatch('privatecaptcha:finish');
    window.dispatch('pageshow', { persisted: true });
    assert.deepEqual(controls.map((control) => control.disabled), [true, true]);

    await Promise.resolve();
    assert.equal(setupCalls, 1);
});

test('auto-scans replacement elements after loading the standard widget script', async () => {
    const window = new EventTarget();
    let setupCalls = 0;
    const scriptUrls = [];
    const createForm = () => {
        const form = new EventTarget();
        form.querySelectorAll = () => [];

        return form;
    };
    const createContainer = () => {
        const captcha = new EventTarget();
        const container = new EventTarget();
        container.closest = () => createForm();
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

    widget({ scriptUrl: 'https://cdn.example.test/widget.js' }, firstElement);
    await Promise.resolve();

    widget({ scriptUrl: 'https://cdn.example.test/widget.js' }, replacementElement);
    await Promise.resolve();

    assert.deepEqual(scriptUrls, [
        'https://cdn.example.test/widget.js',
        'https://cdn.example.test/widget.js',
    ]);
    assert.equal(setupCalls, 2);
});

test('disambiguates new duplicate identities without changing attached widgets', async () => {
    const window = new EventTarget();
    let setupCalls = 0;
    const createForm = () => {
        const form = new EventTarget();
        form.querySelectorAll = () => [];

        return form;
    };
    const createElement = (form, captcha) => {
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
    const firstElement = createElement(createForm(), firstCaptcha);
    const secondElement = createElement(createForm(), secondCaptcha);
    const newElement = createElement(createForm(), newCaptcha);
    window.privateCaptcha = { setup: () => setupCalls++ };
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

    widget({ scriptUrl: 'https://cdn.example.test/widget.js' }, firstElement);
    widget({ scriptUrl: 'https://cdn.example.test/widget.js' }, secondElement);
    widget({ scriptUrl: 'https://cdn.example.test/widget.js' }, newElement);
    await Promise.resolve();

    assert.deepEqual(
        [firstCaptcha.id, secondCaptcha.id],
        ['private-captcha-shared-1', 'private-captcha-shared-2']
    );
    assert.deepEqual(
        [firstCaptcha.dataset.storeVariable, secondCaptcha.dataset.storeVariable],
        ['privateCaptcha_shared_1', 'privateCaptcha_shared_2']
    );
    assert.equal(attachedCaptcha.id, 'private-captcha-attached');
    assert.equal(attachedCaptcha.dataset.storeVariable, 'privateCaptcha_attached');
    assert.equal(newCaptcha.id, 'private-captcha-attached-1');
    assert.equal(newCaptcha.dataset.storeVariable, 'privateCaptcha_attached_1');
    assert.equal(setupCalls, 3);
});

test('shows an actionable error without enabling the form when script loading fails', async () => {
    const window = new EventTarget();
    const controls = [{ disabled: false }];
    const form = new EventTarget();
    form.querySelectorAll = () => controls;
    const captcha = new EventTarget();
    const element = new EventTarget();
    element.closest = () => form;
    element.error = { hidden: true, textContent: '' };
    element.querySelector = (selector) => selector === '.private-captcha' ? captcha : element.error;
    const widget = loadAmdModule(
        path.join(__dirname, '../../view/frontend/web/js/widget.js'),
        { 'PrivateCaptcha_PrivateCaptcha/js/script-loader': () => Promise.reject(new Error('network failed')) },
        { window }
    );

    widget({ scriptUrl: 'https://cdn.example.test/widget.js' }, element);
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(controls[0].disabled, true);
    assert.equal(element.error.hidden, false);
    assert.equal(element.error.textContent, 'Private Captcha could not start. Please refresh and try again.');
});

test('uses adjacent native forms and keeps detached widgets independent', async () => {
    const window = new EventTarget();
    let setupCalls = 0;
    window.privateCaptcha = {
        setup: () => setupCalls++,
    };
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
        element.error = { hidden: true, textContent: '' };
        element.querySelector = (selector) => selector === '.private-captcha' ? captcha : element.error;

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
        scriptUrl: 'https://cdn.example.test/widget.js',
        placement: 'before-toolbar',
        detachedTarget: 'previous-form',
    }, first.element);
    widget({
        scriptUrl: 'https://cdn.example.test/widget.js',
        placement: 'before-toolbar',
        detachedTarget: 'previous-form',
    }, second.element);
    await Promise.resolve();

    assert.equal(first.controls[0].disabled, true);
    assert.equal(second.controls[0].disabled, true);
    first.captcha.dispatch('privatecaptcha:finish');

    assert.equal(first.controls[0].disabled, false);
    assert.equal(second.controls[0].disabled, true);
    assert.equal(first.element.insertedBefore, first.toolbar);
    assert.equal(second.element.insertedBefore, second.toolbar);
    assert.equal(setupCalls, 2);
});
