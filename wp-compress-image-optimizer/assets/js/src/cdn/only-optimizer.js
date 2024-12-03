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