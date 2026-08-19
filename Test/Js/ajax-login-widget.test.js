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
    const widgetCalls = [];
    const component = loadAmdModule(
        path.join(__dirname, '../../view/frontend/web/js/view/ajax-login-widget.js'),
        {
            'Magento_Customer/js/action/login': {
                registerLoginCallback: (callback) => callbacks.push(callback),
            },
            'PrivateCaptcha_PrivateCaptcha/js/widget': (config, element) => widgetCalls.push([config, element]),
            'mage/translate': (message) => message,
            'uiComponent': {
                extend: (definition) => definition,
            },
        }
    );
    const form = {};
    const captcha = {
        privateCaptcha_one: {
            reset: () => {
                captcha.resetCount = (captcha.resetCount || 0) + 1;
            },
        },
    };
    const element = {
        closest: () => form,
        querySelector: () => captcha,
    };
    const replacementCaptcha = {
        privateCaptcha_one: {
            reset: () => {
                replacementCaptcha.resetCount = (replacementCaptcha.resetCount || 0) + 1;
            },
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
    assert.equal(widgetCalls.length, 2);
    assert.equal(widgetCalls[0][0].form, form);
    assert.equal(widgetCalls[0][0].scriptUrl, 'https://cdn.example.test/widget.js');
    assert.equal(widgetCalls[1][0].form, form);
    assert.equal(widgetCalls[1][0].scriptUrl, 'https://cdn.example.test/widget.js');

    callbacks[0]({ privateCaptchaMarker: 'widget-two' });
    assert.equal(captcha.resetCount, undefined);

    callbacks[0]({ privateCaptchaMarker: 'widget-one' });
    assert.equal(captcha.resetCount, undefined);
    assert.equal(replacementCaptcha.resetCount, 1);
});
