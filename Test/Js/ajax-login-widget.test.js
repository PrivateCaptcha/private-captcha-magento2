const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function loadAmdModule(file, dependencies) {
    let exported;
    vm.runInNewContext(readFileSync(file, 'utf8'), {
        define: (names, factory) => {
            exported = factory(...names.map((name) => dependencies[name]));
        },
    });

    return exported;
}

test('initializes only its owning form and resets only its matching login request', () => {
    const callbacks = [];
    const resetCalls = [];
    const widgetCalls = [];
    const component = loadAmdModule(
        path.join(__dirname, '../../view/frontend/web/js/view/ajax-login-widget.js'),
        {
            'Magento_Customer/js/action/login': {
                registerLoginCallback: (callback) => callbacks.push(callback),
            },
            'PrivateCaptcha_PrivateCaptcha/js/widget': (config, element) => {
                widgetCalls.push([config.form, config.scriptUrl, element]);
            },
            'mage/translate': (message) => message,
            'uiComponent': {
                extend: (definition) => definition,
            },
        }
    );
    const form = {};
    const captcha = {
        privateCaptcha_one: {
            reset: () => resetCalls.push('original'),
        },
    };
    const element = {
        closest: () => form,
        querySelector: () => captcha,
    };
    const replacementCaptcha = {
        privateCaptcha_one: {
            reset: () => resetCalls.push('replacement'),
        },
    };
    const replacement = {
        closest: () => form,
        querySelector: () => replacementCaptcha,
    };
    const instance = Object.assign({}, component, component.defaults, {
        _super: () => instance,
        widget: {
            id: 'private-captcha-one',
            site_key: 'site-key',
            script_url: 'https://cdn.example.test/widget.js',
            store_variable: 'privateCaptcha_one',
        },
        requestMarker: 'widget-one',
    });

    instance.initialize();
    instance.afterRender(element);
    instance.afterRender(replacement);

    assert.equal(callbacks.length, 1);
    assert.deepEqual(widgetCalls, [
        [form, 'https://cdn.example.test/widget.js', element],
        [form, 'https://cdn.example.test/widget.js', replacement],
    ]);

    callbacks[0]({ privateCaptchaMarker: 'widget-two' });
    assert.deepEqual(resetCalls, []);

    callbacks[0]({ privateCaptchaMarker: 'widget-one' });
    assert.deepEqual(resetCalls, ['replacement']);
});
