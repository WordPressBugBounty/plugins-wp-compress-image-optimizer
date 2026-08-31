


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
        }, {rootMargin: "800px"});


        wpcLazyObserver = wpcLazyObserver || new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var lazyImage = entry.target;

                    
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

                    


                    if (SlickSlider || SlickList || slides || owlSlider || masonry) {
                        if (typeof lazyImage.dataset.src !== 'undefined' && lazyImage.dataset.src != '') {
                            newApiURL = lazyImage.dataset.src;
                        } else {
                            newApiURL = lazyImage.src;
                        }

                        
                        if (typeof adaptiveImage.dataset.srcset !== 'undefined' && adaptiveImage.dataset.srcset != '') {
                            newApiURLSrcset = adaptiveImage.dataset.srcset;
                            adaptiveImage.srcset = newApiURLSrcset;
                        }

                        newApiURL = newApiURL.replace(/w:(\d{1,5})/g, 'w:1');
                        lazyImage.src = newApiURL;
                        lazyImage.classList.add("ic-fade-in");
                        lazyImage.classList.add("wpc-remove-lazy");
                        lazyImage.classList.remove("wps-ic-lazy-image");

                        
                        if (typeof adaptiveImage.dataset.src !== 'undefined' && adaptiveImage.dataset.src != '') {
                            adaptiveImage.removeAttribute('data-src'); 
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

                    
                    
                    

                    


                    if ((typeof lazyImage.dataset.src !== 'undefined' && lazyImage.dataset.src != '')) {
                        newApiURL = lazyImage.dataset.src;

                        newApiURL = SetupNewApiURL(newApiURL, imgWidth, lazyImage);

                        lazyImage.src = newApiURL;
                        if (typeof lazyImage.dataset.srcset !== 'undefined' && lazyImage.dataset.src != '') {
                            lazyImage.srcset = lazyImage.dataset.srcset;
                        }

                        
                        
                        
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

                    
                    wpcLazyObserver.unobserve(lazyImage);
                }
            });
        }, {rootMargin:"800px"});

        LazyBackgrounds.forEach(function (lazyImage) {
            lazyImage.setAttribute("data-wpc-lz", "1");
            wpcBgObserver.observe(lazyImage);
        });

        lazyImages.forEach(function (lazyImage) {
            lazyImage.setAttribute("data-wpc-lz", "1");
            wpcLazyObserver.observe(lazyImage);
        });

    } else {
        
    }
}

document.addEventListener("DOMContentLoaded", function () {
    runLazy();
});


function onScroll() {
    runLazy();
    window.removeEventListener('scroll', onScroll);
}


window.addEventListener('scroll', onScroll);

wpcWatchInjected(runLazy, "img[data-wpc-loaded='true']");
