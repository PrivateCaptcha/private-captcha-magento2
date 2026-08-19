define([], function () {
    'use strict';

    var loads = {};

    return function (url) {
        if (loads[url]) {
            return loads[url];
        }

        loads[url] = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.async = true;
            script.src = url;
            script.setAttribute('data-private-captcha-src', url);
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });

        return loads[url];
    };
});
