// IsMobile
var mobileWidth = 1;
var wpcIsMobile = false;
var jsDebug = false;
var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

if (ngf298gh738qwbdh0s87v_vars.js_debug == 'true') {
    jsDebug = true;
}

function checkMobile() {
    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth <= 580) {
        wpcIsMobile = true;
        mobileWidth = window.innerWidth;
    }
}

checkMobile();

/**
 * v7.21.197 — RE-ARM FOR DOM-INJECTED MARKUP.
 * Every lane in these bundles snapshots its images at DOMContentLoaded (and at best once
 * more on the first scroll — onScroll removes itself). Markup injected LATER by a "Load
 * More", an infinite scroll, an AJAX filter or any partial re-render was therefore never
 * processed, and its images sat on the rewriter's placeholder forever. Field receipt:
 * harmonytree.net/our-work, where Responsive Lightbox's Load More injects a fully rendered
 * page of parked <img>. Sibling of the quiet-wire re-arm in cdn-rewrite.php (wpc-qw-restore).
 * Each bundle calls this once with its own rescan entry point; that entry point must be safe
 * to re-run, since it is called again for every injected batch.
 * childList only + the match test means a lane's own src writes cannot re-enter the callback.
 */
var wpcInjectedObserver = null;

function wpcWatchInjected(rescan, sel) {
    try {
        if (wpcInjectedObserver || !window.MutationObserver) {
            return;
        }
        wpcInjectedObserver = new MutationObserver(function (mutations) {
            var found = false, m, n, node, added;
            for (m = 0; m < mutations.length && !found; m++) {
                added = mutations[m].addedNodes;
                if (!added) {
                    continue;
                }
                for (n = 0; n < added.length; n++) {
                    node = added[n];
                    if (!node || node.nodeType !== 1) {
                        continue;
                    }
                    if (node.tagName === "IMG") {
                        if (node.matches && node.matches(sel)) {
                            found = true;
                            break;
                        }
                    } else if (node.querySelector && node.querySelector(sel)) {
                        found = true;
                        break;
                    }
                }
            }
            if (found) {
                rescan();
            }
        });
        wpcInjectedObserver.observe(document.documentElement, {childList: true, subtree: true});
    } catch (e) {
    }
}

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
function SetupNewApiURL(newApiURL, imgWidth, imageElement) {
    if (imgWidth > 0 && !imageElement.classList.contains('wpc-excluded-adaptive')) {
        if (imgWidth > 2560) {
            imgWidth = 2560;
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

    if (wpcIsMobile) {
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
    // old-browser safe: classList is a DOMTokenList whose .forEach is unsupported on
    // some engines (the "a.forEach is not a function" crash that halted lazy/adaptive + the
    // WooCommerce gallery + Elementor). Iterate a real array with a for-loop + indexOf instead.
    var _wpcArr = Array.prototype.slice.call(list || []);
    for (var _i = 0; _i < _wpcArr.length; _i++) {
        if (String(_wpcArr[_i]).indexOf(keyword) !== -1) {
            found = true;
        }
    }


    if (found) {
        return true;
    } else {
        return false;
    }

}

// v7.21.197 — observers hoisted to module scope: runLazy() is re-run for every injected
// batch, and a fresh pair per call would leak observers and re-process the whole page.
// data-wpc-lz marks what has already been claimed.
var wpcBgObserver = null;
var wpcLazyObserver = null;

function runLazy() {
    var lazyImages = [].slice.call(document.querySelectorAll("img[data-wpc-loaded='true']:not([data-wpc-lz])"));
    var LazyBackgrounds = [].slice.call(document.querySelectorAll(".wpc-bgLazy:not([data-wpc-lz])"));

    if ("IntersectionObserver" in window) {
        wpcBgObserver = wpcBgObserver || new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var lazyBGImage = entry.target;
                    lazyBGImage.classList.remove("wpc-bgLazy");
                    wpcBgObserver.unobserve(lazyBGImage);
                }
            });
        }, {rootMargin: "500px"});


        wpcLazyObserver = wpcLazyObserver || new IntersectionObserver(function (entries, observer) {
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
                            imgWidth = window.innerWidth || 1;
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

                        // Handle <picture> <source> lazy loading — promote the lazy AVIF/WebP
                        // <source data-srcset> so the browser self-selects the right format once
                        // (no eager pre-fetch + no runLazy re-fetch double-load). Mirrors local/lazy.js.
                        var parentPicture = lazyImage.closest('picture');
                        if (parentPicture) {
                            parentPicture.querySelectorAll('source[data-srcset]').forEach(function(s) {
                                s.srcset = s.dataset.srcset;
                                s.removeAttribute('data-srcset');
                            });
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
                    wpcLazyObserver.unobserve(lazyImage);
                }
            });
        }, {rootMargin:"500px"});

        LazyBackgrounds.forEach(function (lazyImage) {
            lazyImage.setAttribute("data-wpc-lz", "1");
            wpcBgObserver.observe(lazyImage);
        });

        lazyImages.forEach(function (lazyImage) {
            lazyImage.setAttribute("data-wpc-lz", "1");
            wpcLazyObserver.observe(lazyImage);
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

wpcWatchInjected(runLazy, "img[data-wpc-loaded='true']");
