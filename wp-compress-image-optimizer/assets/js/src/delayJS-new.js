// Delay JS Script
var wpcEvents = ['keydown', 'mousemove', 'touchmove', 'touchstart', 'touchend', 'wheel', 'visibilitychange', 'resize'];
wpcEvents.forEach(function (eventName) {
    window.addEventListener(eventName, preload);
});

window.addEventListener('load', function () {
    var scrollTop = window.scrollY;
    if (scrollTop > 60) {
        preload();
    }
});

function loadDelayScripts(done) {
    const delayScripts = /** @type {NodeListOf<HTMLScriptElement>}*/ (
        document.querySelectorAll('script[type="wpc-delay-script"]')
    );

    /**
     * replace delayScript with actual script
     * @param {HTMLScriptElement} delayScript
     * @param {boolean} hasSrc
     * @param {boolean} isLast
     * @returns
     */
    function replaceScript(delayScript, hasSrc, isLast) {
        delayScript.removeAttribute('type');

        /**
         * New script needs to be created because FireFox
         * does not allow executing existing delay script tag
         * just by removing the type attribute and appending to body
         */
        const script = document.createElement('script');

        if (hasSrc) {
            script.src = delayScript.src;
        } else {
            script.textContent = delayScript.textContent;
        }

        // copy all attributes from delayed script to newly created script tag
        for (const attribute of delayScript.attributes) {
            script.setAttribute(attribute.name, attribute.value);
        }

        // must not load asynchronously, load them now!
        script.async = false;

        // replace the script with newly created script
        delayScript.replaceWith(script);

        if (isLast && done) {
            if (hasSrc) {
                script.addEventListener('load', done);
            } else {
                done();
            }
        }

        return script;
    }

    let prevSrcScript;

    delayScripts.forEach((delayScript, i) => {
        const hasSrc = delayScript.hasAttribute('src');
        const isLast = i === delayScripts.length - 1;

        if (hasSrc) {
            prevSrcScript = replaceScript(delayScript, hasSrc, isLast);
        } else if (prevSrcScript) {
            // execute the inline delay-script after the delay-script before it has loaded and executed
            prevSrcScript.addEventListener('load', () => replaceScript(delayScript, hasSrc, isLast));
        } else {
            // if no script with src before it
            // load it right now
            replaceScript(delayScript, hasSrc, isLast);
        }
    });
}

function preload() {
    var all_iframe = [].slice.call(document.querySelectorAll("iframe.wpc-iframe-delay"));
    var all_scripts = [].slice.call(document.querySelectorAll('script[type="wpc-delay-script"]'));
    var all_styles = [].slice.call(document.querySelectorAll('[rel="wpc-stylesheet"]'));
    var mobile_styles = [].slice.call(document.querySelectorAll('[rel="wpc-mobile-stylesheet"]'));

    var mobileStyles = [];
    var styles = [];
    var iframes = [];


    mobile_styles.forEach(function (element, index) {
        mobileStyles.push(element);
    });

    all_styles.forEach(function (element, index) {
        styles.push(element);
    });

    all_iframe.forEach(function (element, index) {
        iframes.push(element);
    });

    var customPromiseFlag = [];

    var i = 0;
    loadDelayScripts();

    styles.forEach(function (element, index) {
        var promise = new Promise(function (resolve, reject) {
            element.setAttribute('rel', 'stylesheet');
            element.setAttribute('type', 'text/css');
            element.addEventListener('load', function () {
                resolve();
            });

            element.addEventListener('error', function () {
                reject();
            });
        });
        customPromiseFlag.push(promise);
    });

    styles = [];

    iframes.forEach(function (element, index) {
        var promise = new Promise(function (resolve, reject) {
            var iframeUrl = element.getAttribute('data-src');
            element.setAttribute('src', iframeUrl);
            element.addEventListener('load', function () {
                resolve();
            });

            element.addEventListener('error', function () {
                reject();
            });
        });
        customPromiseFlag.push(promise);
    });

    iframes = [];

    mobileStyles.forEach(function (element, index) {
        var promise = new Promise(function (resolve, reject) {
            element.setAttribute('rel', 'stylesheet');
            element.setAttribute('type', 'text/css');
            element.addEventListener('load', function () {
                resolve();
            });

            element.addEventListener('error', function () {
                reject();
            });
        });
        customPromiseFlag.push(promise);
    });

    mobileStyles = [];

    Promise.all(customPromiseFlag).then(function () {
        var criticalCss = document.querySelector('#wpc-critical-css');
        if (criticalCss) {
            //criticalCss.remove();
        }
    }).catch(function () {
        styles.forEach(function (element, index) {
            element.setAttribute('rel', 'stylesheet');
            element.setAttribute('type', 'text/css');
        });
    });

    wpcEvents.forEach(function (eventName) {
        window.removeEventListener(eventName, preload);
    });

}