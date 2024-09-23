// setTimeout(function () {
//     //mouseMoveFun();
// }, 10);

window.addEventListener('load', function() {
    mouseMoveFun();
});


function preload() {

    if (!preloading) {
        preloading = true;
    } else {
        return;
    }

    var iframes = [].slice.call(document.querySelectorAll("iframe.wpc-iframe-delay"));
    var styles = [].slice.call(document.querySelectorAll('[rel="wpc-stylesheet"],[type="wpc-stylesheet"]'));
    var mobileStyles = [].slice.call(document.querySelectorAll('[rel="wpc-mobile-stylesheet"],[type="wpc-mobile-stylesheet"]'));

    var customPromiseFlag = [];

    styles.forEach(function (element, index) {
        var promise = new Promise(function (resolve, reject) {
            if (element.tagName.toLowerCase() === 'link') {
                element.setAttribute('rel', 'stylesheet');
            }
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
            if (element.tagName.toLowerCase() === 'link') {
                element.setAttribute('rel', 'stylesheet');
            }
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
        preloading = false;
        iframes = [].slice.call(document.querySelectorAll("iframe.wpc-iframe-delay"));
        styles = [].slice.call(document.querySelectorAll('[rel="wpc-stylesheet"],[type="wpc-stylesheet"]'));
        mobileStyles = [].slice.call(document.querySelectorAll('[rel="wpc-mobile-stylesheet"],[type="wpc-mobile-stylesheet"]'));

        if (iframes.length !== 0 || styles.length !== 0 || mobileStyles.length !== 0) {
            preload();
        }

        var criticalCss = document.querySelector('#wpc-critical-css');
        if (criticalCss) {
            criticalCss.remove();
        }
    }).catch(function () {
        styles.forEach(function (element, index) {
            if (element.tagName.toLowerCase() === 'link') {
                element.setAttribute('rel', 'stylesheet');
            }
            element.setAttribute('type', 'text/css');
        });
    });

    window.removeEventListener('load', preload);
}

function mouseMoveFun() {
    window.removeEventListener('mousemove', mouseMoveFun);

    allScripts = [].slice.call(document.querySelectorAll('script[type="wpc-delay-script"]')).filter(function (script) {
        return !(/-before$|-after$|-extra$/).test(script.id);
    });

    //elementor sticky error fix
    let stickyScriptsExist = allScripts.some(script => script.src && script.src.includes('jquery.sticky'));
    if (stickyScriptsExist) {
        // Adjust the detection of jQuery script to account for URLs with query parameters
        let jQueryIndex = -1;
        allScripts.forEach((script, index) => {
            if (script.src && (script.src.includes('jquery.min.js') || script.src.includes('jquery.js'))) {
                // Check if 'jquery.min.js' or 'jquery.js' occurs before any query parameters
                const queryParamsIndex = script.src.indexOf('?');
                const scriptBaseURL = queryParamsIndex !== -1 ? script.src.substring(0, queryParamsIndex) : script.src;
                if (scriptBaseURL.endsWith('jquery.min.js') || scriptBaseURL.endsWith('jquery.js')) {
                    jQueryIndex = index;
                }
            }
        });
        if (jQueryIndex !== -1) {
            // Filter and reinsert "jquery.sticky" scripts as before
            let stickyScripts = allScripts.filter(script => script.src && script.src.includes('jquery.sticky'));
            allScripts = allScripts.filter(script => !(script.src && script.src.includes('jquery.sticky')));

            stickyScripts.forEach((stickyScript, index) => {
                allScripts.splice(jQueryIndex + 1 + index, 0, stickyScript);
            });
        }
    }


    // Remove Preloader
    const aFPreloader = document.getElementById('af-preloader');
    if (aFPreloader) {
        aFPreloader.remove();
    }

    if (allScripts.length > 0) {
        allScripts
        //preloadJS();
        loadJs();
    }

    preload();
}

//window.addEventListener('mousemove', mouseMoveFun);

if (isTouchDevice) {
    window.addEventListener('touchstart', mouseMoveFun);
}


var loadJsRunning = false;
var dispatchedEvents = false;

function preloadJS() {
    allScripts.forEach(function (script) {
        if (script.src) {
            var preloadLink = document.createElement("link");
            preloadLink.rel = "preload";
            preloadLink.href = script.src;
            preloadLink.as = "script";
            preloadLink.crossOrigin = "anonymous";
            document.head.appendChild(preloadLink);
        }
    });
}

function loadJsNext() {
    if (allScripts.length === 0) {

        allScripts = [].slice.call(document.querySelectorAll('script[type="wpc-delay-script"]')).filter(function (script) {
            return !(/-before$|-after$|-extra$/).test(script.id);
        });

        // Remove Preloader
        const aFPreloader = document.getElementById('af-preloader');
        if (aFPreloader) {
            aFPreloader.remove();
        }

        if (allScripts.length > 0) {
            loadJs();
            return
        }
        console.log('triggering');
        if (!dispatchedEvents) {
            dispatchedEvents = true;

            if (typeof triggerDomEvent !== 'undefined' && triggerDomEvent !== "false") {
                document.dispatchEvent(new Event("DOMContentLoaded"));
                window.dispatchEvent(new Event("resize"));
                window.dispatchEvent(new Event('load'));
            }

            if (wpcIntegrationActive === 'undefined') {
                document.dispatchEvent(new Event('WPCContentLoaded'));
            }

            if (typeof elementorFrontend !== 'undefined' && delayOn !== "false") {
                elementorFrontend.init();
            }
        }

        setTimeout(function () {
            var slider = document.getElementsByClassName('banner-image');
            if (slider !== null && slider.length !== 0 && slider !== undefined) {
                // Iterate through each element using a for loop
                for (var i = 0; i < slider.length; i++) {
                    // Apply changes to each element
                    slider[i].style.display = 'block';
                }
            }

            var gForm = document.getElementsByClassName('gform_wrapper');
            if (gForm !== null && gForm.length !== 0 && gForm !== undefined) {
                // Iterate through each element using a for loop
                for (var i = 0; i < gForm.length; i++) {
                    // Apply changes to each element
                    gForm[i].style.display = 'block';
                }
            }
        }, 300);

    } else {
        loadJs();
    }
}

var dispatchedEventsLoadJs = false;

function loadJs() {
    return new Promise((resolve) => {
        function loadScript(element) {
            const elementID = element.id;
            const scriptSrc = element.getAttribute('src');
            const jsBefore = document.getElementById(elementID + '-before');
            const jsAfter = document.getElementById(elementID + '-after');
            const jsExtra = document.getElementById(elementID + '-extra');

            return new Promise((resolveScript) => {
                function loadElement(scriptElement, id) {
                    console.log(id)
                    if (!scriptElement) {
                        return Promise.resolve(); // Immediately resolve if there's no element
                    }

                    const newElement = createScript(scriptElement);
                    const scriptSrc = scriptElement.getAttribute('src');

                    return new Promise((resolveLoad, rejectLoad) => {
                        newElement.onload = () => {
                            resolveLoad();
                        };

                        newElement.onerror = () => {
                            console.error('Error loading script ' + scriptSrc);
                            rejectLoad(new Error('Error loading script ' + scriptSrc));
                        };

                        if (!newElement.addEventListener && newElement.readyState) {
                            newElement.onreadystatechange = newElement.onload;
                        }

                        document.head.appendChild(newElement);
                        scriptElement.remove();

                        if (!scriptSrc) {
                            resolveLoad(); // Immediately resolve if the script is inline
                        }
                    });
                }

                var loadSequentially = ['i18n']

                if (scriptSrc && loadSequentially.some(part => scriptSrc.includes(part))) {

                    // Chain loading of elements to ensure they load in sequence
                    loadElement(jsBefore, elementID + '-before')
                        .then(() => loadElement(jsExtra, elementID + '-extra'))
                        .then(() => loadElement(element, elementID))
                        .then(() => loadElement(jsAfter, elementID + '-after'))
                        .then(resolveScript) // Resolve the outer promise once all scripts have loaded
                        .catch(error => {
                            console.error('Script loading sequence failed:', error);
                            resolveScript(); // Resolve to continue with the script loading sequence, replace with rejectScript(error) if you want to halt on failure.
                        });
                } else {
                    // Loading all at the same time
                    loadElement(jsBefore, elementID + '-before')
                        .then(() => loadElement(jsExtra, elementID + '-extra'))
                        .then(() => loadElement(element, elementID))
                        .then(() => loadElement(jsAfter, elementID + '-after'))
                        .then(resolveScript) // Resolve the outer promise once all scripts have loaded
                        .catch(error => {
                            console.error('Script loading sequence failed:', error);
                            resolveScript(); // Resolve to continue with the script loading sequence, replace with rejectScript(error) if you want to halt on failure.
                        });
                    // loadElement(jsBefore, elementID + '-before')
                    // loadElement(jsExtra, elementID + '-extra')
                    // loadElement(element, elementID)
                    // loadElement(jsAfter, elementID + '-after')
                    //     .then(resolveScript) // Resolve the outer promise once all scripts have loaded
                    //     .catch(error => {
                    //         console.error('Script loading sequence failed:', error);
                    //         resolveScript(); // Resolve to continue with the script loading sequence, replace with rejectScript(error) if you want to halt on failure.
                    //     });
                }
            });
        }

        function loadNextScript(index) {
            if (index >= allScripts.length) {
                loadLastScripts();
                resolve(true);
                return;
            }

            const script = allScripts[index];
            console.log('index: ' + index);
            console.log(script);
            loadScript(script)
                .then(() => {
                    loadNextScript(index + 1);
                }).catch((error) => {
                console.error(error);
                resolve(false);
            });
        }

        function loadLastScripts() {
            allScripts = [].slice.call(document.querySelectorAll('script[type="wpc-delay-last-script"]')).filter(function (script) {
                return !(/-before$|-after$|-extra$/).test(script.id);
            });

            allScripts.forEach(function (script, index) {
                loadScript(script)
                    .then(() => loadNextScript(index + 1))
                    .catch((error) => {
                        console.error(error);
                        resolve(false);
                    });
            });

            dispatchedEvents = true;

            if (typeof triggerDomEvent !== 'undefined' && triggerDomEvent !== "false") {
                window.dispatchEvent(new Event("DOMContentLoaded"));
                document.dispatchEvent(new Event("DOMContentLoaded"));
                window.dispatchEvent(new Event("resize"));
                window.dispatchEvent(new Event('load'));
                document.dispatchEvent(new Event('WPCContentLoaded'));
            }

            if (typeof elementorFrontend !== 'undefined' && delayOn !== "false") {

                elementorFrontend.init()
            }
        }

        loadNextScript(0);
    });
}


function createScript(sourceElement) {
    if (sourceElement !== null) {
        var newElement = document.createElement('script');
        newElement.setAttribute('type', 'text/javascript');
        newElement.setAttribute('id', sourceElement.getAttribute('id'));
        newElement.setAttribute('data-loaded', 'createdScript');
        newElement.async = false;

        if (sourceElement !== null) {
            if (sourceElement.getAttribute('src') !== null) {
                newElement.setAttribute('src', sourceElement.getAttribute('src'));
            } else {
                newElement.textContent = sourceElement.textContent;
            }
        }

        return newElement;
    }
}