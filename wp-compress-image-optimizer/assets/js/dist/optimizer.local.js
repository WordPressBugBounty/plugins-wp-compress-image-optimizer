// IsMobile
var mobileWidth = 1;
var isMobile = false;
var jsDebug = false;
var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

if (ngf298gh738qwbdh0s87v_vars.js_debug == 'true') {
    jsDebug = true;
}

function checkMobile() {
    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth <= 580) {
        isMobile = true;
        mobileWidth = window.innerWidth;
    }
}

checkMobile();
var preloadRunned = false;
var wpcWindowWidth = window.innerWidth;


if (n489D_vars.linkPreload === 'true') {
    document.addEventListener('DOMContentLoaded', function () {
        const preloadedLinks = new Set(); // To avoid duplicate preloads

        document.body.addEventListener('mouseover', function () {
            // Check if the hovered element is a link
            const link = event.target.closest('a');
            if (!link || preloadedLinks.has(link.href)) return; // Skip if not a link or already preloaded

            // Check if the link contains any excluded strings
            // const isExcluded = n489D_vars.excludeLink.some(excludeStr =>
            //     link.href.includes(excludeStr)
            // );
            const isExcluded = n489D_vars.excludeLink.some(function(excludeStr) {
                return link.href.indexOf(excludeStr) !== -1;
            });

            // Only preload if link is not excluded and is same origin
            if (!isExcluded && link.origin === location.origin) {
                preloadLink(link.href);
            }
        });

        document.body.addEventListener('touchstart', function () {
            const link = event.target.closest('a');
            if (!link || preloadedLinks.has(link.href)) return;

            // Check if the link contains any excluded strings
            // const isExcluded = n489D_vars.excludeLink.some(excludeStr =>
            //     link.href.includes(excludeStr)
            // );
            const isExcluded = n489D_vars.excludeLink.some(function(excludeStr) {
                return link.href.indexOf(excludeStr) !== -1;
            });

            // Only preload if link is not excluded and is same origin
            if (!isExcluded && link.origin === location.origin) {
                preloadLink(link.href);
            }
        });

        function preloadLink(url) {
            preloadedLinks.add(url); // Mark this URL as preloaded
            fetch(url, {
                method: 'GET',
                mode: 'no-cors'
            })
                .then(function () { // Use traditional function syntax
                    //console.log('Preloaded: ' + url);
                })
                .catch(function (err) { // Use traditional function syntax
                    //console.error('Preload failed for: ' + url, err);
                });
        }
    });
}


window.addEventListener('DOMContentLoaded', function () {
    //registerEvents();
});

// Delay JS Script
var wpcEvents = ['keydown', 'mousemove', 'touchmove', 'touchstart', 'touchend', 'wheel', 'visibilitychange', 'resize'];
function registerEvents() {
    wpcEvents.forEach(function (eventName) {

        if (jsDebug) {
            console.log('Event registered: ' + eventName);
        }

        window.addEventListener(eventName, function () {
            preloadTimeout(eventName);
        });
    });
}

function preloadTimeout(event) {

    if (jsDebug) {
        console.log('Running Preload Timeout');
    }

    if (!preloadRunned) {

        if (jsDebug) {
            console.log('Event name in preload is ');
            console.log(event);
            console.log('Before width: ' + wpcWindowWidth);
            console.log('After width: ' + window.innerWidth);
        }

        if (event == 'resize') {
            if (wpcWindowWidth === window.innerWidth) {
                // Nothing changed, ignore the event
                return false;
            }
        }

        preloadRunned = true;
        setTimeout(function () {
            if (jsDebug) {
                console.log('Inside Preload Timeout');
            }
            preload();
            removeEventListeners();
        }, 50);
    }
}

function removeEventListeners() {
    wpcEvents.forEach(function (eventName) {
        window.removeEventListener(eventName, preloadTimeout);
    });
}

window.addEventListener('load', function () {
    var scrollTop = window.scrollY;
    return true;
    if (scrollTop > 60) {
        preload();
    }
});

function preloadStyles() {
    var customPromiseFlag = [];
    var styles = [].slice.call(document.querySelectorAll('[rel="wpc-stylesheet"],[type="wpc-stylesheet"]'));
    var mobileStyles = [].slice.call(document.querySelectorAll('[rel="wpc-mobile-stylesheet"],[type="wpc-mobile-stylesheet"]'));

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
        var criticalCss = document.querySelector('#wpc-critical-css');
        if (criticalCss) {
            //criticalCss.remove();
        }
    }).catch(function () {
        styles.forEach(function (element, index) {

            if (element.tagName.toLowerCase() === 'link') {
                element.setAttribute('rel', 'stylesheet');
            }

            element.setAttribute('type', 'text/css');
        });
    });

    wpcEvents.forEach(function (eventName) {
        window.removeEventListener(eventName, preload);
    });
}

function preload() {
    var iframes = [].slice.call(document.querySelectorAll("iframe.wpc-iframe-delay"));
    var allScripts = [];
    var styles = [].slice.call(document.querySelectorAll('[rel="wpc-stylesheet"],[type="wpc-stylesheet"]'));
    var mobileStyles = [].slice.call(document.querySelectorAll('[rel="wpc-mobile-stylesheet"],[type="wpc-mobile-stylesheet"]'));

    var wpScripts = [];
    var customPromiseFlag = [];

    if (jsDebug) {
        console.log('Found scripts');
        console.log(allScripts);
    }

    // Move wp-include scripts into wpScripts array to load them first
    for (var i = 0; i < allScripts.length; i++) {
        var script = allScripts[i];
        if (script.src && script.src.includes('wp-includes')) {
            wpScripts.push(script);
            allScripts.splice(i, 1);
            i--;
        }
    }

    if (jsDebug) {
        console.log('Found WP scripts');
        console.log(wpScripts);
    }

    wpScripts.forEach(function (element, index) {
        var newScript = document.createElement('script');
        newScript.setAttribute('src', element.getAttribute('src'));
        newScript.setAttribute('type', 'text/javascript');
        document.body.appendChild(newScript);
    });

    wpScripts = [];

    allScripts.forEach(function (element, index) {
        var elementID = element.id;

        if (jsDebug) {
            console.log(element);
        }

        if (!element.hasAttribute('src') && !element.id.includes('-before') && !element.id.includes('-after') && !element.id.includes('-extra')) {
            var newElement = document.createElement('script');
            newElement.textContent = element.textContent;
            newElement.setAttribute('type', 'text/javascript');
            newElement.async = false;
            document.head.appendChild(newElement);
        } else {
            // External script
            var jsBefore = document.getElementById(elementID + '-before');
            var jsAfter = document.getElementById(elementID + '-after');
            var jsExtra = document.getElementById(elementID + '-extra');

            if (jsBefore !== null) {
                var newElementBefore = document.createElement('script');
                newElementBefore.textContent = jsBefore.textContent;
                newElementBefore.setAttribute('type', 'text/javascript');
                newElementBefore.async = false;
                document.head.appendChild(newElementBefore);
            }

            if (jsAfter !== null) {
                //jsAfter.setAttribute('type', 'text/javascript');
                // eval(jsAfter.textContent);
            }

            if (jsExtra !== null) {
                var newElementExtra = document.createElement('script');
                newElementExtra.textContent = jsExtra.textContent;
                newElementExtra.setAttribute('type', 'text/javascript');
                newElementExtra.async = false;
                document.head.appendChild(newElementExtra);
            }

            if (element !== null) {
                var new_element = document.createElement('script');
                if (element.getAttribute('src') !== null) {
                    new_element.setAttribute('src', element.getAttribute('src'));
                    new_element.setAttribute('type', 'text/javascript');
                    new_element.async = false;
                    document.head.appendChild(new_element);
                } else {
                    new_element.textContent = element.textContent;
                    new_element.setAttribute('type', 'text/javascript');
                    new_element.async = false;
                    document.head.appendChild(new_element);
                }

                new_element.onload = function () {
                    if (jsAfter !== null) {
                        var new_elementAfter = document.createElement('script');
                        new_elementAfter.textContent = jsAfter.textContent;
                        new_elementAfter.setAttribute('type', 'text/javascript');
                        document.head.appendChild(new_elementAfter);
                        jsAfter.remove();
                    }
                };
            }


            if (element !== null) {
                element.remove();
            }

            if (jsBefore !== null) {
                jsBefore.remove();
            }

            if (jsExtra !== null) {
                jsExtra.remove();
            }
        }

        // Remove the element from the array
        //allScripts.splice(index, 1);
    });

    allScripts = [];

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
        var criticalCss = document.querySelector('#wpc-critical-css');
        if (criticalCss) {
            //criticalCss.remove();
        }
    }).catch(function () {
        styles.forEach(function (element, index) {
            if (element.tagName.toLowerCase() === 'link') {
                element.setAttribute('rel', 'stylesheet');
            }
            element.setAttribute('type', 'text/css');
        });
    });

    wpcEvents.forEach(function (eventName) {
        window.removeEventListener(eventName, preload);
    });

}
// Lazy
var regularImages = [];
var active;
var activeRegular;
var img_count = 1;
var browserWidth;
var forceWidth = 0;
var jsDebug = 0;

function load() {
    browserWidth = window.innerWidth;
    regularImages = [].slice.call(document.querySelectorAll("img"));
    active = false;
    activeRegular = false;
    regularLoad();
}

if (ngf298gh738qwbdh0s87v_vars.js_debug == 'true') {
    jsDebug = 1;
    console.log('JS Debug is Enabled');
}


if (jsDebug) {
    console.log('Safari: ' + isSafari);
}

function regularLoad() {
    if (activeRegular === false) {
        activeRegular = true;

        regularImages.forEach(function (Image) {

            if (Image.classList.contains('wps-ic-loaded')) {
                return;
            }

            Image.classList.add("ic-fade-in");
            Image.classList.add("wps-ic-loaded");
        });

        activeRegular = false;
    }
}

window.addEventListener("resize", regularLoad);
window.addEventListener("orientationchange", regularLoad);
document.addEventListener("scroll", regularLoad);
document.addEventListener("DOMContentLoaded", load);