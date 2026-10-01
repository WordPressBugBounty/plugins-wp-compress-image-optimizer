// IsMobile
var mobileWidth = 1;
var wpcIsMobile = false;
var jsDebug = false;
var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

if (ngf298gh738qwbdh0s87v_vars.js_debug == 'true') {
    jsDebug = true;
}

function wpcIsPlaceholderSrc(e) {
    var s = e.getAttribute("src") || "";
    return s === "" || s.indexOf("data:image/svg+xml") === 0 || s.indexOf("placeholder.svg") !== -1;
}
function wpcIsPainted(e) {
    try { return e.getClientRects().length > 0 && getComputedStyle(e).visibility !== "hidden"; } catch (x) { return true; }
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
// Lazy
var lazyImages = [];
var active;
var activeRegular;
var browserWidth;
var jsDebug = 0;

function load() {
    browserWidth = window.innerWidth;
    lazyImages = [].slice.call(document.querySelectorAll("img"));
    elementorInvisible = [].slice.call(document.querySelectorAll("section.elementor-invisible"));
    active = false;
    activeRegular = false;
    lazyLoad();
}

if (ngf298gh738qwbdh0s87v_vars.js_debug == 'true') {
    jsDebug = 1;
    console.log('JS Debug is Enabled');
}

function lazyLoad() {
    if (active === false) {
        active = true;

        elementorInvisible.forEach(function (elementorSection) {
            if ((elementorSection.getBoundingClientRect().top <= window.innerHeight
                    && elementorSection.getBoundingClientRect().bottom >= 0)
                && getComputedStyle(elementorSection).display !== "none") {
                elementorSection.classList.remove('elementor-invisible');

                elementorInvisible = elementorInvisible.filter(function (section) {
                    return section !== elementorSection;
                });
            }
        });

        lazyImages.forEach(function (lazyImage) {

            if (lazyImage.classList.contains('wps-ic-loaded')) {
                return;
            }

            if ((lazyImage.getBoundingClientRect().top <= window.innerHeight + 500
                    && lazyImage.getBoundingClientRect().bottom >= 0)
                && wpcIsPainted(lazyImage)) {

                imageExtension = '';
                imageFilename = '';

                if (typeof lazyImage.dataset.src !== 'undefined') {

                    if (lazyImage.dataset.src.endsWith('url:https')) {
                        return;
                    }

                    imageFilename = lazyImage.dataset.src;
                    imageExtension = lazyImage.dataset.src.split('.').pop();
                } else if (typeof lazyImage.src !== 'undefined') {
                    if (lazyImage.src.endsWith('url:https')) {
                        return;
                    }
                    imageFilename = lazyImage.dataset.src;
                    imageExtension = lazyImage.src.split('.').pop();
                }


                if (imageExtension !== '') {
                    if (imageExtension !== 'jpg' && imageExtension !== 'jpeg' && imageExtension !== 'gif' && imageExtension !== 'png' && imageExtension !== 'svg' && lazyImage.src.includes('svg+xml') == false && lazyImage.src.includes('placeholder.svg') == false) {
                        return;
                    }
                }

                // Integrations
                masonry = lazyImage.closest(".masonry");

                var parentPicture = lazyImage.closest('picture');
                if (parentPicture) {
                    parentPicture.querySelectorAll('source[data-srcset]').forEach(function(s) {
                        s.srcset = s.dataset.srcset;
                        s.removeAttribute('data-srcset');
                    });
                }

                if (typeof lazyImage.dataset.srcset !== 'undefined' && wpcIsPlaceholderSrc(lazyImage)) {
                    lazyImage.srcset = lazyImage.dataset.srcset;
                }
                if (typeof lazyImage.dataset.src !== 'undefined' && typeof lazyImage.dataset.src !== undefined && wpcIsPlaceholderSrc(lazyImage)) {
                    lazyImage.src = lazyImage.dataset.src;
                }

                var imageSrc = lazyImage.src;
                //imageSrc = imageSrc.replace(/\.jpeg|\.jpg/g, '.webp');
                //lazyImage.src = imageSrc;

                lazyImage.classList.add("ic-fade-in");
                lazyImage.classList.add("wps-ic-loaded");
                lazyImage.classList.remove("wps-ic-lazy-image");

                lazyImages = lazyImages.filter(function (image) {
                    return image !== lazyImage;
                });

            }
        });

        active = false;
    }
}

window.addEventListener("resize", lazyLoad);
window.addEventListener("orientationchange", lazyLoad);
document.addEventListener("scroll", lazyLoad);
document.addEventListener("DOMContentLoaded", load);

wpcWatchInjected(load, "img[data-src]");
