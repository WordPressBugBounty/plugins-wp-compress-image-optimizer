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
