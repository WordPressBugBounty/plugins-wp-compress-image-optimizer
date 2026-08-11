var mobileWidth = 1;

var wpcIsMobile = false;

var jsDebug = false;

var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

if (ngf298gh738qwbdh0s87v_vars.js_debug == "true") {
    jsDebug = true;
}

function checkMobile() {
    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || window.innerWidth <= 580) {
        wpcIsMobile = true;
        mobileWidth = window.innerWidth;
    }
}

checkMobile();

var preloadRunned = false;

var wpcWindowWidth = window.innerWidth;

if (n489D_vars.linkPreload === "true") {
    document.addEventListener("DOMContentLoaded", (function() {
        const preloadedLinks = new Set;
        document.body.addEventListener("mouseover", (function() {
            const link = event.target.closest("a");
            if (!link || preloadedLinks.has(link.href)) return;
            const isExcluded = n489D_vars.excludeLink.some((function(excludeStr) {
                return link.href.indexOf(excludeStr) !== -1;
            }));
            if (!isExcluded && link.origin === location.origin) {
                preloadLink(link.href);
            }
        }));
        document.body.addEventListener("touchstart", (function() {
            const link = event.target.closest("a");
            if (!link || preloadedLinks.has(link.href)) return;
            const isExcluded = n489D_vars.excludeLink.some((function(excludeStr) {
                return link.href.indexOf(excludeStr) !== -1;
            }));
            if (!isExcluded && link.origin === location.origin) {
                preloadLink(link.href);
            }
        }));
        function preloadLink(url) {
            preloadedLinks.add(url);
            fetch(url, {
                method: "GET",
                mode: "no-cors"
            }).then((function() {})).catch((function(err) {}));
        }
    }));
}

function SetupNewApiURL(newApiURL, imgWidth, imageElement) {
    if (imgWidth > 0 && !imageElement.classList.contains("wpc-excluded-adaptive")) {
        if (imgWidth > 2560) {
            imgWidth = 2560;
        }
        newApiURL = newApiURL.replace(/w:(\d{1,5})/g, "w:" + imgWidth);
    }
    if (jsDebug) {
        console.log("Set new Width");
        console.log(imageElement);
        console.log(imageElement.width);
        console.log(imageElement.parentElement);
        console.log(imageElement.parentElement.offsetWidth);
        console.log(imgWidth);
    }
    if (window.devicePixelRatio >= 2 && ngf298gh738qwbdh0s87v_vars.retina_enabled == "true" || ngf298gh738qwbdh0s87v_vars.force_retina == "true") {
        newApiURL = newApiURL.replace(/r:0/g, "r:1");
        if (jsDebug) {
            console.log("Retina set to True");
            console.log("DevicePixelRation " + window.devicePixelRatio);
        }
    } else {
        newApiURL = newApiURL.replace(/r:1/g, "r:0");
        if (jsDebug) {
            console.log("Retina set to False");
            console.log("DevicePixelRation " + window.devicePixelRatio);
        }
    }
    if (ngf298gh738qwbdh0s87v_vars.webp_enabled == "true" && isSafari == false) {
        if (!imageElement.classList.contains("wpc-excluded-webp")) {
            newApiURL = newApiURL.replace(/wp:0/g, "wp:1");
        }
        if (jsDebug) {
            console.log("WebP set to True");
        }
    } else {
        newApiURL = newApiURL.replace(/wp:1/g, "wp:0");
        if (jsDebug) {
            console.log("WebP set to False");
        }
    }
    if (ngf298gh738qwbdh0s87v_vars.exif_enabled == "true") {
        newApiURL = newApiURL.replace(/e:0/g, "e:1");
    } else {
        newApiURL = newApiURL.replace(/\/e:1/g, "");
        newApiURL = newApiURL.replace(/\/e:0/g, "");
    }
    if (wpcIsMobile) {
        newApiURL = getSrcset(newApiURL.split(","), mobileWidth, imageElement);
    }
    return newApiURL;
}

function srcSetUpdateWidth(srcSetUrl, imageWidth, imageElement) {
    if (imageElement.classList.contains("wpc-excluded-adaptive")) {
        imageWidth = 1;
    }
    var srcSetWidth = srcSetUrl.split(" ").pop();
    if (srcSetWidth.endsWith("w")) {
        var Width = srcSetWidth.slice(0, -1);
        if (parseInt(Width) <= 5) {
            Width = 1;
        }
        srcSetUrl = srcSetUrl.replace(/w:(\d{1,5})/g, "w:" + Width);
    } else if (srcSetWidth.endsWith("x")) {
        var Width = srcSetWidth.slice(0, -1);
        if (parseInt(Width) <= 3) {
            Width = 1;
        }
        srcSetUrl = srcSetUrl.replace(/w:(\d{1,5})/g, "w:" + Width);
    }
    return srcSetUrl;
}

function getSrcset(sourceArray, imageWidth, imageElement) {
    var changedSrcset = "";
    sourceArray.forEach((function(imageSource) {
        if (jsDebug) {
            console.log("Image src part from array");
            console.log(imageSource);
        }
        newApiURL = srcSetUpdateWidth(imageSource.trimStart(), imageWidth, imageElement);
        changedSrcset += newApiURL + ",";
    }));
    return changedSrcset.slice(0, -1);
}

function listHas(list, keyword) {
    var found = false;
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

function wpcLateCssPending() {
    return !!document.querySelector("link[rel='wpc-late-stylesheet'],[type='wpc-late-stylesheet']");
}

var wpcAdaptiveDeferred = false;

function runAdaptiveWhenStyled() {
    if (!wpcLateCssPending()) {
        runAdaptive();
        return;
    }
    if (wpcAdaptiveDeferred) {
        return;
    }
    wpcAdaptiveDeferred = true;
    var fired = false;
    var fire = function() {
        if (fired) {
            return;
        }
        fired = true;
        wpcAdaptiveDeferred = false;
        runAdaptive();
    };
    window.addEventListener("wpc-latecss-applied", fire, {
        once: true
    });
    var wpcT0 = (new Date).getTime();
    (function wpcPoll() {
        if (fired) {
            return;
        }
        if (!wpcLateCssPending() || (new Date).getTime() - wpcT0 > 12e3) {
            fire();
            return;
        }
        setTimeout(wpcPoll, 250);
    })();
}

function runAdaptive() {
    var adaptiveImages = [].slice.call(document.querySelectorAll("img[data-wpc-loaded='true']"));
    if (adaptiveImages.length === 0) {
        return;
    }
    var wpcWinW116 = window.innerWidth || 1;
    var wpcMeasured116 = adaptiveImages.map((function(img) {
        try {
            return Math.round(parseInt(window.getComputedStyle(img).width)) || 0;
        } catch (e) {
            return 0;
        }
    }));
    adaptiveImages.forEach((function(entry, wpcIdx116) {
        var adaptiveImage = entry;
        if (adaptiveImage.hasAttribute("data-excluded-adaptive")) {
            return;
        }
        if (adaptiveImage.classList.contains("wpc-lcp-optimized") || adaptiveImage.getAttribute("fetchpriority") === "high") {
            adaptiveImage.removeAttribute("data-wpc-loaded");
            return;
        }
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
        if (wpc_SlickSlider || wpc_SlickList || wpc_slides || wpc_owlSlider || wpc_masonry) {
            if (typeof adaptiveImage.dataset.src !== "undefined" && adaptiveImage.dataset.src != "") {
                newApiURL = adaptiveImage.dataset.src;
            } else {
                newApiURL = adaptiveImage.src;
            }
            if (typeof adaptiveImage.dataset.srcset !== "undefined" && adaptiveImage.dataset.srcset != "") {
                newApiURLSrcset = adaptiveImage.dataset.srcset;
                adaptiveImage.srcset = newApiURLSrcset;
            }
            newApiURL = newApiURL.replace(/w:(\d{1,5})/g, "w:1");
            adaptiveImage.src = newApiURL;
            adaptiveImage.classList.add("ic-fade-in");
            adaptiveImage.classList.add("wpc-remove-lazy");
            adaptiveImage.classList.remove("wps-ic-lazy-image");
            adaptiveImage.removeAttribute("data-wpc-loaded");
            if (typeof adaptiveImage.dataset.src !== "undefined" && adaptiveImage.dataset.src != "") {
                adaptiveImage.removeAttribute("data-src");
            }
            if (typeof adaptiveImage.dataset.srcset !== "undefined" && adaptiveImage.dataset.srcset != "") {
                adaptiveImage.removeAttribute("data-srcset");
            }
            return;
        }
        if (ngf298gh738qwbdh0s87v_vars.adaptive_enabled == "false" || adaptiveImage.classList.toString().includes("logo")) {
            imgWidth = 1;
        } else {
            imgWidth = wpcMeasured116[wpcIdx116];
            if (typeof imgWidth == "undefined" || !imgWidth || imgWidth == 0 || isNaN(imgWidth)) {
                imgWidth = wpcWinW116;
            }
            if (listHas(adaptiveImage.classList, "slide")) {
                imgWidth = 1;
            }
        }
        if (jsDebug) {
            console.log("Image Stuff 2");
            console.log(adaptiveImage.parentElement.offsetWidth);
            console.log(adaptiveImage.offsetWidth);
            console.log(imgWidth);
            console.log("Image Stuff END");
        }
        if (typeof adaptiveImage.dataset.src !== "undefined" && adaptiveImage.dataset.src != "") {
            newApiURL = adaptiveImage.dataset.src;
            newApiURL = SetupNewApiURL(newApiURL, imgWidth, adaptiveImage);
            adaptiveImage.src = newApiURL;
            if (typeof adaptiveImage.dataset.srcset !== "undefined" && adaptiveImage.dataset.src != "") {
                adaptiveImage.srcset = adaptiveImage.dataset.srcset;
            }
            var parentPicture = adaptiveImage.closest("picture");
            if (parentPicture) {
                parentPicture.querySelectorAll("source[data-srcset]").forEach((function(s) {
                    s.srcset = s.dataset.srcset;
                    s.removeAttribute("data-srcset");
                }));
            }
        } else if (typeof adaptiveImage.src !== "undefined" && adaptiveImage.src != "") {
            newApiURL = adaptiveImage.src;
            newApiURL = SetupNewApiURL(newApiURL, imgWidth, adaptiveImage);
            adaptiveImage.src = newApiURL;
            if (typeof adaptiveImage.dataset.srcset !== "undefined" && adaptiveImage.dataset.src != "") {
                adaptiveImage.srcset = adaptiveImage.dataset.srcset;
            }
        }
        var wpcBoxW = wpcMeasured116[wpcIdx116];
        if (wpcBoxW > 1 && !adaptiveImage.classList.contains("wpc-excluded-adaptive") && typeof adaptiveImage.srcset === "string" && /\d+w(\s|,|$)/.test(adaptiveImage.srcset)) {
            adaptiveImage.sizes = wpcBoxW + "px";
        }
        adaptiveImage.classList.add("ic-fade-in");
        adaptiveImage.classList.remove("wps-ic-lazy-image");
        adaptiveImage.removeAttribute("data-wpc-loaded");
        adaptiveImage.removeAttribute("data-srcset");
        srcSetAPI = "";
        if (typeof adaptiveImage.srcset !== "undefined" && adaptiveImage.srcset != "") {
            srcSetAPI = newApiURL = adaptiveImage.srcset;
            if (jsDebug) {
                console.log("Image has srcset");
                console.log(adaptiveImage.srcset);
                console.log(newApiURL);
            }
            newApiURL = SetupNewApiURL(newApiURL, 0, adaptiveImage);
            adaptiveImage.srcset = newApiURL;
        } else if (typeof adaptiveImage.dataset.srcset !== "undefined" && adaptiveImage.dataset.srcset != "") {
            srcSetAPI = newApiURL = adaptiveImage.dataset.srcset;
            if (jsDebug) {
                console.log("Image does not have srcset");
                console.log(newApiURL);
            }
            newApiURL = SetupNewApiURL(newApiURL, 0, adaptiveImage);
            adaptiveImage.srcset = newApiURL;
        }
    }));
}

document.addEventListener("WPCContentLoaded", (function() {
    runAdaptiveWhenStyled();
}));

const wpcObserver = new MutationObserver((function(mutationsList) {
    for (var i = 0; i < mutationsList.length; i++) {
        console.log("running observer");
        var mutation = mutationsList[i];
        if (mutation.type === "childList" && mutation.addedNodes.length > 0 && mutation.addedNodes[0].tagName && mutation.addedNodes[0].tagName.toLowerCase() === "img") {
            for (var j = 0; j < mutation.addedNodes.length; j++) {
                var node = mutation.addedNodes[j];
                if (node.tagName && node.tagName.toLowerCase() === "img") {
                    adaptiveImage = node;
                    if (typeof adaptiveImage.dataset.src !== "undefined" && adaptiveImage.dataset.src != "") {
                        newApiURL = adaptiveImage.dataset.src;
                        newApiURL = SetupNewApiURL(newApiURL, imgWidth, adaptiveImage);
                        adaptiveImage.src = newApiURL;
                        if (typeof adaptiveImage.dataset.srcset !== "undefined" && adaptiveImage.dataset.src != "") {
                            adaptiveImage.srcset = adaptiveImage.dataset.srcset;
                        }
                    } else if (typeof adaptiveImage.src !== "undefined" && adaptiveImage.src != "") {
                        newApiURL = adaptiveImage.src;
                        newApiURL = SetupNewApiURL(newApiURL, imgWidth, adaptiveImage);
                        adaptiveImage.src = newApiURL;
                        if (typeof adaptiveImage.dataset.srcset !== "undefined" && adaptiveImage.dataset.src != "") {
                            adaptiveImage.srcset = adaptiveImage.dataset.srcset;
                        }
                    }
                    adaptiveImage.classList.add("ic-fade-in");
                    adaptiveImage.classList.remove("wps-ic-lazy-image");
                    adaptiveImage.removeAttribute("data-wpc-loaded");
                    adaptiveImage.removeAttribute("data-srcset");
                    srcSetAPI = "";
                    if (typeof adaptiveImage.srcset !== "undefined" && adaptiveImage.srcset != "") {
                        srcSetAPI = newApiURL = adaptiveImage.srcset;
                        if (jsDebug) {
                            console.log("Image has srcset");
                            console.log(adaptiveImage.srcset);
                            console.log(newApiURL);
                        }
                        newApiURL = SetupNewApiURL(newApiURL, 0, adaptiveImage);
                        adaptiveImage.srcset = newApiURL;
                    } else if (typeof adaptiveImage.dataset.srcset !== "undefined" && adaptiveImage.dataset.srcset != "") {
                        srcSetAPI = newApiURL = adaptiveImage.dataset.srcset;
                        if (jsDebug) {
                            console.log("Image does not have srcset");
                            console.log(newApiURL);
                        }
                        newApiURL = SetupNewApiURL(newApiURL, 0, adaptiveImage);
                        adaptiveImage.srcset = newApiURL;
                    }
                }
            }
        }
    }
}));

var wpcScrollQueued116 = false;

function onScroll() {
    if (wpcScrollQueued116) {
        return;
    }
    wpcScrollQueued116 = true;
    requestAnimationFrame((function() {
        wpcScrollQueued116 = false;
        runAdaptiveWhenStyled();
    }));
}

window.addEventListener("scroll", onScroll);