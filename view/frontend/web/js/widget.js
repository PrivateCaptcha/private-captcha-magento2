define([
    'PrivateCaptcha_PrivateCaptcha/js/script-loader'
], function (loadScript) {
    'use strict';

    function setSubmitControlsDisabled(form, disabled) {
        form.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"], input[type="image"]')
            .forEach(function (control) {
                control.disabled = disabled;
            });
    }

    function isIdentityAvailable(captchas, candidateId, candidateStoreVariable) {
        return captchas.every(function (element) {
            return element.id !== candidateId &&
                (!candidateStoreVariable || element.dataset.storeVariable !== candidateStoreVariable);
        });
    }

    function disambiguateDuplicateIdentity(captcha) {
        var baseId = captcha.id;
        var captchas;
        var matchingCaptchas;

        if (baseId === '' || typeof document === 'undefined') {
            return;
        }

        captchas = Array.prototype.slice.call(document.querySelectorAll('.private-captcha'));
        matchingCaptchas = captchas.filter(function (element) {
            return element.id === baseId;
        });
        if (matchingCaptchas.length < 2) {
            return;
        }

        matchingCaptchas.filter(function (element) {
            return !element.privateCaptchaInitialized && element.dataset.attached !== '1';
        }).forEach(function (element) {
            var suffix = 1;
            var storeVariable = element.dataset.storeVariable;

            while (!isIdentityAvailable(
                captchas,
                baseId + '-' + suffix,
                storeVariable ? storeVariable + '_' + suffix : ''
            )) {
                suffix++;
            }

            element.id = baseId + '-' + suffix;
            if (storeVariable) {
                element.dataset.storeVariable = storeVariable + '_' + suffix;
            }
        });
    }

    function getForm(config, element) {
        var form = config.form || element.closest('form');

        if (form || config.detachedTarget !== 'previous-form') {
            return form;
        }

        form = element.previousElementSibling;
        while (form && form.tagName !== 'FORM') {
            form = form.previousElementSibling;
        }

        return form;
    }

    return function (config, element) {
        var captcha = element.querySelector('.private-captcha') || element;
        var form = getForm(config, element);
        var error = element.querySelector('.private-captcha-error');
        var ready = false;

        if (!form || captcha.privateCaptchaInitialized) {
            return;
        }

        disambiguateDuplicateIdentity(captcha);
        captcha.privateCaptchaInitialized = true;

        function disable() {
            ready = false;
            setSubmitControlsDisabled(form, true);
        }

        function enable() {
            ready = true;
            setSubmitControlsDisabled(form, false);
            if (error) {
                error.hidden = true;
            }
        }

        function showError() {
            disable();
            if (error) {
                error.hidden = false;
                error.textContent = config.errorMessage || 'Private Captcha could not start. Please refresh and try again.';
            }
        }

        function setup() {
            if (!window.privateCaptcha || typeof window.privateCaptcha.setup !== 'function') {
                throw new Error('Private Captcha setup API is unavailable.');
            }

            window.privateCaptcha.setup();
        }

        if (config.placement === 'before-toolbar') {
            var toolbar = form.querySelector('.actions-toolbar');
            if (toolbar) {
                toolbar.parentNode.insertBefore(element, toolbar);
            }
        }

        captcha.addEventListener('privatecaptcha:init', disable);
        captcha.addEventListener('privatecaptcha:reset', disable);
        captcha.addEventListener('privatecaptcha:finish', enable);
        captcha.addEventListener('privatecaptcha:error', showError);
        form.addEventListener('submit', function (event) {
            if (!ready) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                disable();
            }
        });

        disable();
        loadScript(config.scriptUrl).then(setup).catch(showError);
    };
});
