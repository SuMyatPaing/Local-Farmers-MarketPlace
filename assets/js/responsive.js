(function () {
    'use strict';

    /*
     * Browser zoom is not exposed as a perfect standard API. On desktop
     * Chrome/Edge, devicePixelRatio follows browser zoom closely. We use it
     * only for the requested high-zoom navigation switch, while real small
     * screens always use compact navigation by width.
     *
     * 100% / 110% / 125% => normal desktop navigation
     * ~175% and above     => hamburger navigation
     */
    var COMPACT_ZOOM = 1.60;
    var MOBILE_WIDTH = 768;

    function estimateBrowserZoom() {
        var innerWidth = Math.max(1, Number(window.innerWidth || 1));
        var availableWidth = Number((window.screen && window.screen.availWidth) || 0);
        var outerWidth = Number(window.outerWidth || 0);

        /*
         * On a maximized Chrome/Edge window, screen.availWidth / innerWidth
         * tracks browser zoom while naturally cancelling Windows display
         * scaling. This is much safer for the requested 100/110/125/175
         * behavior than using devicePixelRatio alone.
         */
        if (availableWidth > 0 && outerWidth > 0) {
            var maximizedEnough = outerWidth >= availableWidth * 0.82;
            var ratio = availableWidth / innerWidth;

            if (maximizedEnough && ratio >= 0.75 && ratio <= 3) {
                return ratio;
            }
        }

        /* Fallback for non-maximized windows. */
        return 1;
    }

    function shouldUseCompactNavigation() {
        var smallScreen = window.innerWidth < MOBILE_WIDTH;
        var browserZoom = estimateBrowserZoom();
        var highZoom = browserZoom >= COMPACT_ZOOM;

        return smallScreen || highZoom;
    }

    function applyResponsiveMode() {
        var compact = shouldUseCompactNavigation();
        document.body.classList.toggle('fm-compact-nav', compact);
        document.documentElement.classList.toggle('fm-compact-nav', compact);

        document.dispatchEvent(new CustomEvent('fm:responsivechange', {
            detail: {
                compact: compact,
                zoom: estimateBrowserZoom(),
                dpr: Number(window.devicePixelRatio || 1),
                width: window.innerWidth
            }
        }));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyResponsiveMode, { once: true });
    } else {
        applyResponsiveMode();
    }

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(applyResponsiveMode, 80);
    });
})();
