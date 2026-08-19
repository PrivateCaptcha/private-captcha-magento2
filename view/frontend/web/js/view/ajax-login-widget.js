define([
    'uiComponent',
    'Magento_Customer/js/action/login',
    'PrivateCaptcha_PrivateCaptcha/js/widget',
    'mage/translate'
], function (Component, loginAction, initializeWidget, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'PrivateCaptcha_PrivateCaptcha/ajax-login-widget',
            widget: {},
            markerField: 'privateCaptchaMarker',
            requestMarker: '',
            errorMessage: ''
        },

        initialize: function () {
            this._super();
            this.callbackRegistered = false;
            this.errorMessage = this.errorMessage || $t('Private Captcha could not start. Please refresh and try again.');

            return this;
        },

        afterRender: function (element) {
            var captcha;
            var form;

            if (!this.widget || !this.widget.id || !this.widget.site_key || !this.widget.script_url ||
                !this.widget.store_variable) {
                return;
            }

            form = element.closest('form');
            captcha = element.querySelector('.private-captcha');

            if (!form || !captcha) {
                return;
            }

            if (!this.callbackRegistered) {
                loginAction.registerLoginCallback(this.onLoginComplete.bind(this));
                this.callbackRegistered = true;
            }

            this.element = captcha;
            initializeWidget({
                form: form,
                scriptUrl: this.widget.script_url,
                errorMessage: this.errorMessage
            }, element);
        },

        onLoginComplete: function (loginData) {
            var captcha;

            if (!this.requestMarker || !loginData || loginData[this.markerField] !== this.requestMarker ||
                !this.element) {
                return;
            }

            captcha = this.element[this.widget.store_variable];
            if (captcha && typeof captcha.reset === 'function') {
                captcha.reset();
            }
        }
    });
});
