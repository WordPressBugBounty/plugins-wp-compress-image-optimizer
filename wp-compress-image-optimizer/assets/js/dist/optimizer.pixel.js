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
function SetupNewApiURL(newApiURL, imgWidth, imageElement) {
    if (imgWidth > 0 && !imageElement.classList.contains('wpc-excluded-adaptive')) {
        if (imgWidth > 1100) {
            imgWidth = 1100;
        }
        newApiURL = newApiURL.replace(/w:(\d{1,5})/g, 'w:' + imgWidth);
    }

    if (jsDebug) {
        console.log('Set new Width');
        console.log(imageElement);
        console.log(imageElement.width);
        console.log(imageElement.parentElement);
        console.log(imageElement.parentElement.offsetWidth);
        console.log(imgWidth);
    }

    if ((window.devicePixelRatio >= 2 && ngf298gh738qwbdh0s87v_vars.retina_enabled == 'true') || ngf298gh738qwbdh0s87v_vars.force_retina == 'true') {
        newApiURL = newApiURL.replace(/r:0/g, 'r:1');

        if (jsDebug) {
            console.log('Retina set to True');
            console.log('DevicePixelRation ' + window.devicePixelRatio);
        }

    } else {
        newApiURL = newApiURL.replace(/r:1/g, 'r:0');

        if (jsDebug) {
            console.log('Retina set to False');
            console.log('DevicePixelRation ' + window.devicePixelRatio);
        }
    }

    if (ngf298gh738qwbdh0s87v_vars.webp_enabled == 'true' && isSafari == false) {
        if (!imageElement.classList.contains('wpc-excluded-webp')) {
            newApiURL = newApiURL.replace(/wp:0/g, 'wp:1');
        }

        if (jsDebug) {
            console.log('WebP set to True');
        }

    } else {
        newApiURL = newApiURL.replace(/wp:1/g, 'wp:0');

        if (jsDebug) {
            console.log('WebP set to False');
        }

    }

    if (ngf298gh738qwbdh0s87v_vars.exif_enabled == 'true') {
        newApiURL = newApiURL.replace(/e:0/g, 'e:1');
    } else {
        newApiURL = newApiURL.replace(/\/e:1/g, '');
        newApiURL = newApiURL.replace(/\/e:0/g, '');
    }

    if (isMobile) {
        newApiURL = getSrcset(newApiURL.split(","), mobileWidth, imageElement);
    }

    return newApiURL;
}
// OK
function srcSetUpdateWidth(srcSetUrl, imageWidth, imageElement) {

    if (imageElement.classList.contains('wpc-excluded-adaptive')) {
        imageWidth = 1;
    }

    var srcSetWidth = srcSetUrl.split(' ').pop();
    if (srcSetWidth.endsWith('w')) {
        // Remove w from width string
        var Width = srcSetWidth.slice(0, -1);
        if (parseInt(Width) <= 5) {
            Width = 1;
        }
        srcSetUrl = srcSetUrl.replace(/w:(\d{1,5})/g, 'w:' + Width);
    } else if (srcSetWidth.endsWith('x')) {
        var Width = srcSetWidth.slice(0, -1);
        if (parseInt(Width) <= 3) {
            Width = 1;
        }
        srcSetUrl = srcSetUrl.replace(/w:(\d{1,5})/g, 'w:' + Width);
    }
    return srcSetUrl;
}
// OK
function getSrcset(sourceArray, imageWidth, imageElement) {
    var changedSrcset = '';

    sourceArray.forEach(function (imageSource) {

        if (jsDebug) {
            console.log('Image src part from array');
            console.log(imageSource);
        }

        newApiURL = srcSetUpdateWidth(imageSource.trimStart(), imageWidth, imageElement);
        changedSrcset += newApiURL + ",";
    });

    return changedSrcset.slice(0, -1); // Remove last comma
}
// OK
function listHas(list, keyword) {
    var found = false;
    list.forEach(function (className) {
        if (className.includes(keyword)) {
            found = true;
        }
    });


    if (found) {
        return true;
    } else {
        return false;
    }

}

function runLazy() {
    var lazyImages = [].slice.call(document.querySelectorAll("img[data-wpc-loaded='true']"));
    var LazyIFrames = [].slice.call(document.querySelectorAll("iframe.wpc-iframe-delay"));
    var LazyBackgrounds = [].slice.call(document.querySelectorAll(".wpc-bgLazy"));

    if ("IntersectionObserver" in window) {
        var LazyBackgroundsObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var lazyBGImage = entry.target;
                    lazyBGImage.classList.remove("wpc-bgLazy");
                    LazyBackgroundsObserver.unobserve(lazyBGImage);
                }
            });
        }, {rootMargin: "800px"});

        var lazyIframesObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var lazyIframe = entry.target;

                    var src = lazyIframe.dataset.src
                    lazyIframe.src = src;

                    lazyIframesObserver.unobserve(lazyIframe);
                }
            });
        });

        var lazyImageObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var lazyImage = entry.target;

                    // Integrations
                    masonry = lazyImage.closest(".masonry");
                    owlSlider = lazyImage.closest(".owl-carousel");
                    SlickSlider = lazyImage.closest(".slick-slider");
                    SlickList = lazyImage.closest(".slick-list");
                    slides = lazyImage.closest(".slides");

                    if (jsDebug) {
                        console.log(masonry);
                        console.log(owlSlider);
                        console.log(SlickSlider);
                        console.log(SlickList);
                        console.log(slides);
                    }

                    /**
                     * Is SlickSlider/List?
                     */
                    if (SlickSlider || SlickList || slides || owlSlider || masonry) {
                        if (typeof lazyImage.dataset.src !== 'undefined' && lazyImage.dataset.src != '') {
                            newApiURL = lazyImage.dataset.src;
                        } else {
                            newApiURL = lazyImage.src;
                        }

                        // Check and update the srcset attribute if data-srcset exists
                        if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.srcset != '') {
                            newApiURLSrcset = adaptiveImage.dataset.srcset;
                            adaptiveImage.srcset = newApiURLSrcset;
                        }

                        newApiURL = newApiURL.replace(/w:(\d{1,5})/g, 'w:1');
                        lazyImage.src = newApiURL;
                        lazyImage.classList.add("ic-fade-in");
                        lazyImage.classList.add("wpc-remove-lazy");
                        lazyImage.classList.remove("wps-ic-lazy-image");

                        // Remove Dataset
                        if (typeof adaptiveImage.dataset.src !== 'undefined' && adaptiveImage.dataset.src != '') {
                            adaptiveImage.removeAttribute('data-src'); // Remove dataset.src
                        }

                        if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.srcset != '') {
                            adaptiveImage.removeAttribute('data-srcset');
                        }

                        return;
                    }


                    if (ngf298gh738qwbdh0s87v_vars.adaptive_enabled == 'false' || lazyImage.classList.toString().includes('logo')) {
                        imgWidth = 1;
                    } else {
                        imageStyle = window.getComputedStyle(lazyImage);

                        imgWidth = Math.round(parseInt(imageStyle.width));

                        if (typeof imgWidth == 'undefined' || !imgWidth || imgWidth == 0 || isNaN(imgWidth)) {
                            imgWidth = 1;
                        }

                        if (listHas(lazyImage.classList, 'slide')) {
                            imgWidth = 1;
                        }
                    }

                    if (jsDebug) {
                        console.log('Image Stuff');
                        console.log(lazyImage);
                        console.log(imageStyle);
                        console.log(imgWidth);
                        console.log('Image Stuff END');
                    }

                    // if (isMobile) {
                    //     imgWidth = mobileWidth;
                    // }

                    /**
                     * Setup Image SRC only if srcset is empty
                     */
                    if ((typeof lazyImage.dataset.src !== 'undefined' && lazyImage.dataset.src != '')) {
                        newApiURL = lazyImage.dataset.src;

                        newApiURL = SetupNewApiURL(newApiURL, imgWidth, lazyImage);

                        lazyImage.src = newApiURL;
                        if (typeof lazyImage.dataset.srcset !== 'undefined' && lazyImage.dataset.src != '') {
                            lazyImage.srcset = lazyImage.dataset.srcset;
                        }
                    } else if (typeof lazyImage.src !== 'undefined' && lazyImage.src != '') {
                        newApiURL = lazyImage.src;

                        newApiURL = SetupNewApiURL(newApiURL, imgWidth, lazyImage);

                        lazyImage.src = newApiURL;
                        if (typeof lazyImage.dataset.srcset !== 'undefined' && lazyImage.dataset.src != '') {
                            lazyImage.srcset = lazyImage.dataset.srcset;
                        }
                    }

                    lazyImage.classList.add("ic-fade-in");
                    lazyImage.classList.remove("wps-ic-lazy-image");

                    //lazyImage.removeAttribute('data-src'); => Had issues with Woo Zoom
                    lazyImage.removeAttribute('data-srcset');

                    srcSetAPI = '';
                    if (typeof lazyImage.srcset !== 'undefined' && lazyImage.srcset != '') {
                        srcSetAPI = newApiURL = lazyImage.srcset;

                        if (jsDebug) {
                            console.log('Image has srcset');
                            console.log(lazyImage.srcset);
                            console.log(newApiURL);
                        }

                        newApiURL = SetupNewApiURL(newApiURL, imgWidth, lazyImage);

                        lazyImage.srcset = newApiURL;
                    } else if (typeof lazyImage.dataset.srcset !== 'undefined' && lazyImage.dataset.srcset != '') {
                        srcSetAPI = newApiURL = lazyImage.dataset.srcset;
                        if (jsDebug) {
                            console.log('Image does not have srcset');
                            console.log(newApiURL);
                        }

                        newApiURL = SetupNewApiURL(newApiURL, imgWidth, lazyImage);

                        lazyImage.srcset = newApiURL;
                    }

                    //lazyImage.classList.remove("lazy");
                    lazyImageObserver.unobserve(lazyImage);
                }
            });
        }, {rootMargin:"800px"});

        LazyBackgrounds.forEach(function (lazyImage) {
            LazyBackgroundsObserver.observe(lazyImage);
        });

        lazyImages.forEach(function (lazyImage) {
            lazyImageObserver.observe(lazyImage);
        });

        LazyIFrames.forEach(function (lazyIframes) {
            lazyIframesObserver.observe(lazyIframes);
        });
    } else {
        // Possibly fall back to event handlers here
    }
}

document.addEventListener("DOMContentLoaded", function () {
    runLazy();
});


function onScroll() {
    runLazy();
    window.removeEventListener('scroll', onScroll);
}

// Attach the scroll event listener
window.addEventListener('scroll', onScroll);

const wpcObserver = new MutationObserver(function (mutationsList) {
    // Iterate over each mutation
    for (var i = 0; i < mutationsList.length; i++) {
        var mutation = mutationsList[i];

        // Check if nodes were added
        if (
            mutation.type === 'childList' &&
            mutation.addedNodes.length > 0 &&
            mutation.addedNodes[0].tagName &&
            mutation.addedNodes[0].tagName.toLowerCase() === 'img'
        ) {
            // Process the added nodes
            for (var j = 0; j < mutation.addedNodes.length; j++) {
                var node = mutation.addedNodes[j];

                // Check if the added node is an image
                if (node.tagName && node.tagName.toLowerCase() === 'img') {
                    adaptiveImage = node;
                    /**
                     * Setup Image SRC only if srcset is empty
                     */
                    if ((typeof adaptiveImage.dataset.src !== 'undefined' && adaptiveImage.dataset.src != '')) {
                        newApiURL = adaptiveImage.dataset.src;

                        newApiURL = SetupNewApiURL(newApiURL, imgWidth, adaptiveImage);

                        adaptiveImage.src = newApiURL;
                        if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.src != '') {
                            adaptiveImage.srcset = adaptiveImage.dataset.srcset;
                        }
                    }
                    else if (typeof adaptiveImage.src !== 'undefined' && adaptiveImage.src != '') {
                        newApiURL = adaptiveImage.src;

                        newApiURL = SetupNewApiURL(newApiURL, imgWidth, adaptiveImage);

                        adaptiveImage.src = newApiURL;
                        if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.src != '') {
                            adaptiveImage.srcset = adaptiveImage.dataset.srcset;
                        }
                    }

                    adaptiveImage.classList.add("ic-fade-in");
                    adaptiveImage.classList.remove("wps-ic-lazy-image");

                    adaptiveImage.removeAttribute('data-srcset');

                    srcSetAPI = '';
                    if (typeof adaptiveImage.srcset !== 'undefined' && adaptiveImage.srcset != '') {
                        srcSetAPI = newApiURL = adaptiveImage.srcset;

                        if (jsDebug) {
                            console.log('Image has srcset');
                            console.log(adaptiveImage.srcset);
                            console.log(newApiURL);
                        }

                        newApiURL = SetupNewApiURL(newApiURL, 0, adaptiveImage);

                        adaptiveImage.srcset = newApiURL;
                    }
                    else if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.srcset != '') {
                        srcSetAPI = newApiURL = adaptiveImage.dataset.srcset;
                        if (jsDebug) {
                            console.log('Image does not have srcset');
                            console.log(newApiURL);
                        }

                        newApiURL = SetupNewApiURL(newApiURL, 0, adaptiveImage);

                        adaptiveImage.srcset = newApiURL;
                    }
                }
            }
        }
    }
});