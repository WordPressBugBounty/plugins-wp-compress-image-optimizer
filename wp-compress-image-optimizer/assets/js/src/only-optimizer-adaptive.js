function runAdaptive() {
    var adaptiveImages = [].slice.call(document.querySelectorAll("img[data-wpc-loaded='true']"));

    adaptiveImages.forEach(function (entry) {
        var adaptiveImage = entry;

        if (adaptiveImage.hasAttribute("data-excluded-adaptive")) {
            return; // skip this image
        }

        // Integrations
        wpc_masonry = adaptiveImage.closest(".masonry");
        wpc_owlSlider = adaptiveImage.closest(".owl-carousel");
        wpc_SlickSlider = adaptiveImage.closest(".slick-slider");
        wpc_SlickList = adaptiveImage.closest(".slick-list");
        wpc_slides = adaptiveImage.closest(".slides");

        if (jsDebug) {
            console.log(wpc_masonry);
            console.log(wpc_owlSlider);
            console.log(wpc_SlickSlider);
            console.log(wpc_SlickList);
            console.log(wpc_slides);
        }

        /**
         * Is SlickSlider/List?
         */
        if (wpc_SlickSlider || wpc_SlickList || wpc_slides || wpc_owlSlider || wpc_masonry) {
            if (typeof adaptiveImage.dataset.src !== 'undefined' && adaptiveImage.dataset.src != '') {
                newApiURL = adaptiveImage.dataset.src;
            } else {
                newApiURL = adaptiveImage.src;
            }

            // Check and update the srcset attribute if data-srcset exists
            if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.srcset != '') {
                newApiURLSrcset = adaptiveImage.dataset.srcset;
                adaptiveImage.srcset = newApiURLSrcset;
            }

            newApiURL = newApiURL.replace(/w:(\d{1,5})/g, 'w:1');
            adaptiveImage.src = newApiURL;
            adaptiveImage.classList.add("ic-fade-in");
            adaptiveImage.classList.add("wpc-remove-lazy");
            adaptiveImage.classList.remove("wps-ic-lazy-image");
            adaptiveImage.removeAttribute('data-wpc-loaded');

            // Remove Dataset
            if (typeof adaptiveImage.dataset.src !== 'undefined' && adaptiveImage.dataset.src != '') {
                adaptiveImage.removeAttribute('data-src'); // Remove dataset.src
            }

            if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.srcset != '') {
                adaptiveImage.removeAttribute('data-srcset');
            }

            return;
        }

        if (ngf298gh738qwbdh0s87v_vars.adaptive_enabled == 'false' || adaptiveImage.classList.toString().includes('logo')) {
            imgWidth = 1;
        } else {
            imageStyle = window.getComputedStyle(adaptiveImage);
            imgWidth = Math.round(parseInt(imageStyle.width));

            if (typeof imgWidth == 'undefined' || !imgWidth || imgWidth == 0 || isNaN(imgWidth)) {
                imgWidth = window.innerWidth || 1;
            }

            if (listHas(adaptiveImage.classList, 'slide')) {
                imgWidth = 1;
            }
        }

        if (jsDebug) {
            console.log('Image Stuff 2');
            console.log(adaptiveImage.parentElement.offsetWidth);
            console.log(adaptiveImage.offsetWidth);
            console.log(imgWidth);
            console.log('Image Stuff END');
        }

        // if (isMobile) {
        //     imgWidth = mobileWidth;
        // }

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

            // Handle <picture> <source> lazy loading — promote lazy AVIF/WebP <source data-srcset>
            // so the browser self-selects once (no eager pre-fetch + runLazy re-fetch). Mirrors local/lazy.js.
            var parentPicture = adaptiveImage.closest('picture');
            if (parentPicture) {
                parentPicture.querySelectorAll('source[data-srcset]').forEach(function(s) {
                    s.srcset = s.dataset.srcset;
                    s.removeAttribute('data-srcset');
                });
            }
        } else if (typeof adaptiveImage.src !== 'undefined' && adaptiveImage.src != '') {
            newApiURL = adaptiveImage.src;

            newApiURL = SetupNewApiURL(newApiURL, imgWidth, adaptiveImage);

            adaptiveImage.src = newApiURL;
            if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.src != '') {
                adaptiveImage.srcset = adaptiveImage.dataset.srcset;
            }
        }

        // (v7.03.54) Wire box-measurement to NATURAL srcsets. SetupNewApiURL only rewrites /w: TRANSFORM
        // URLs — a no-op now that srcsets are natural (-WxH) — so the natural ladder kept the theme's stale
        // sizes (e.g. "620px") and the browser over-fetched the biggest rung. Set sizes from the REAL
        // measured box so the browser picks the right -WxH (DPR-aware → retina still gets 2x). Use a FRESH
        // measurement (NOT imgWidth — it falls back to window.innerWidth for a 0-width/hidden image, which
        // would over-fetch); skip unless it's a genuine laid-out width + a width-descriptor srcset is present.
        var wpcBoxW = Math.round(parseInt(window.getComputedStyle(adaptiveImage).width)) || 0;
        if (wpcBoxW > 1
            && !adaptiveImage.classList.contains('wpc-excluded-adaptive')
            && typeof adaptiveImage.srcset === 'string'
            && /\d+w(\s|,|$)/.test(adaptiveImage.srcset)) {
            adaptiveImage.sizes = wpcBoxW + 'px';
        }

        adaptiveImage.classList.add("ic-fade-in");
        adaptiveImage.classList.remove("wps-ic-lazy-image");
        adaptiveImage.removeAttribute('data-wpc-loaded');
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
        } else if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.srcset != '') {
            srcSetAPI = newApiURL = adaptiveImage.dataset.srcset;
            if (jsDebug) {
                console.log('Image does not have srcset');
                console.log(newApiURL);
            }

            newApiURL = SetupNewApiURL(newApiURL, 0, adaptiveImage);

            adaptiveImage.srcset = newApiURL;
        }


    });

}

document.addEventListener("WPCContentLoaded", function () {
    runAdaptive();
});

const wpcObserver = new MutationObserver(function (mutationsList) {
    // Iterate over each mutation
    for (var i = 0; i < mutationsList.length; i++) {
        console.log('running observer');
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

                // if (isMobile) {
                //     imgWidth = mobileWidth;
                // }

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
                    } else if (typeof adaptiveImage.src !== 'undefined' && adaptiveImage.src != '') {
                        newApiURL = adaptiveImage.src;

                        newApiURL = SetupNewApiURL(newApiURL, imgWidth, adaptiveImage);

                        adaptiveImage.src = newApiURL;
                        if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.src != '') {
                            adaptiveImage.srcset = adaptiveImage.dataset.srcset;
                        }
                    }

                    adaptiveImage.classList.add("ic-fade-in");
                    adaptiveImage.classList.remove("wps-ic-lazy-image");
                    adaptiveImage.removeAttribute('data-wpc-loaded');
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
                    } else if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.srcset != '') {
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

function onScroll() {
    runAdaptive();
}

// Attach the scroll event listener
window.addEventListener('scroll', onScroll);