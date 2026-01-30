<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * iSpring SCORM player detection, slide tracking, and navigation.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\scorm\vendors;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides iSpring-specific JavaScript for SCORM player detection, slide tracking,
 * and navigation.
 */
class ispring {

    /**
     * Returns the iSpring detection, polling, and navigation JavaScript.
     *
     * @return string JavaScript code for iSpring support.
     */
    public static function get_js() {
        return <<<'JSEOF'
// === ISPRING — Detection, Polling & Navigation ===

// ==========================================================================
// ISPRING SPECIFIC: Slide detection from iSpring Presentation API
// ==========================================================================

var iSpringSlideIndex = null;
var iSpringCheckInterval = null;

// Function to find the iSpring player in iframes.
function findISpringPlayer() {
    var iframes = document.querySelectorAll('iframe');
    for (var i = 0; i < iframes.length; i++) {
        try {
            var iframeWin = iframes[i].contentWindow;
            var iframeDoc = iframeWin.document;

            // iSpring exposes iSpringPresentationAPI or window.frames.content
            if (iframeWin.iSpringPresentationAPI ||
                iframeWin.ispringPresentationConnector ||
                iframeWin.ISPRING) {
                console.log('[iSpring] Found via API objects');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check for iSpring's player object
            if (iframeWin.player && typeof iframeWin.player.view !== 'undefined') {
                console.log('[iSpring] Found via player.view');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check for iSpring's g_oPresentation global (PowerPoint export)
            if (iframeWin.g_oPresentation || iframeWin.g_oPres || iframeWin.oPresentation) {
                console.log('[iSpring] Found via g_oPresentation');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check for iSpring's PresentationAPI or PresentationManager
            if (iframeWin.PresentationAPI || iframeWin.PresentationManager || iframeWin.presentationApi) {
                console.log('[iSpring] Found via PresentationAPI/Manager');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check for iSpring's slide navigation functions
            if (iframeWin.gotoSlide || iframeWin.goToSlide || iframeWin.navigateToSlide) {
                console.log('[iSpring] Found via gotoSlide function');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check for iSpring's player UI elements
            if (iframeDoc.querySelector('#ispring-player, .ispring-player, [class*="ispring"], #presentation-container')) {
                console.log('[iSpring] Found via DOM elements');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check for iSpring's slide container
            if (iframeDoc.querySelector('#slide-container, .slide-container, #slides-container, .slides-wrapper')) {
                console.log('[iSpring] Found via slide container');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check nested iframes (iSpring often uses nested structure)
            var nestedIframes = iframeDoc.querySelectorAll('iframe');
            for (var j = 0; j < nestedIframes.length; j++) {
                try {
                    var nestedWin = nestedIframes[j].contentWindow;
                    var nestedDoc = nestedWin.document;
                    if (nestedWin.iSpringPresentationAPI || nestedWin.g_oPresentation ||
                        nestedWin.gotoSlide || nestedWin.player) {
                        console.log('[iSpring] Found in nested iframe');
                        return { iframe: nestedIframes[j], window: nestedWin, document: nestedDoc };
                    }
                } catch (e) {
                    // Cross-origin nested iframe
                }
            }
        } catch (e) {
            // Cross-origin, skip.
        }
    }
    return null;
}

// Function to get current slide from iSpring player.
function getISpringCurrentSlide(playerInfo) {
    if (!playerInfo || !playerInfo.window) return null;

    try {
        var win = playerInfo.window;

        // Method 1: iSpring Presentation API (newer versions)
        if (win.iSpringPresentationAPI) {
            var api = win.iSpringPresentationAPI;
            if (api.slidesCount && api.currentSlideIndex !== undefined) {
                console.log('[iSpring] API currentSlideIndex:', api.currentSlideIndex);
                return api.currentSlideIndex + 1; // 0-based to 1-based
            }
            if (api.player && api.player.currentSlide !== undefined) {
                console.log('[iSpring] API player.currentSlide:', api.player.currentSlide);
                return api.player.currentSlide;
            }
        }

        // Method 2: iSpring presentation connector
        if (win.ispringPresentationConnector) {
            var connector = win.ispringPresentationConnector;
            if (connector.currentSlideIndex !== undefined) {
                console.log('[iSpring] Connector currentSlideIndex:', connector.currentSlideIndex);
                return connector.currentSlideIndex + 1;
            }
        }

        // Method 3: Direct player object
        if (win.player) {
            if (win.player.currentSlideIndex !== undefined) {
                console.log('[iSpring] player.currentSlideIndex:', win.player.currentSlideIndex);
                return win.player.currentSlideIndex + 1;
            }
            if (win.player.currentSlide !== undefined) {
                console.log('[iSpring] player.currentSlide:', win.player.currentSlide);
                return win.player.currentSlide;
            }
        }

        // Method 4: Check for ISPRING global object
        if (win.ISPRING && win.ISPRING.presentation) {
            var pres = win.ISPRING.presentation;
            if (pres.slideIndex !== undefined) {
                console.log('[iSpring] ISPRING.presentation.slideIndex:', pres.slideIndex);
                return pres.slideIndex + 1;
            }
        }

        // Method 5: Look for iSpring-specific DOM elements
        var slideElements = win.document.querySelectorAll('.ispring-slide, .slide-wrapper, [data-slide-index]');
        for (var i = 0; i < slideElements.length; i++) {
            var elem = slideElements[i];
            var style = win.getComputedStyle(elem);
            if (style.display !== 'none' && style.visibility !== 'hidden') {
                var idx = elem.getAttribute('data-slide-index');
                if (idx) {
                    console.log('[iSpring] DOM data-slide-index:', idx);
                    return parseInt(idx, 10) + 1;
                }
            }
        }

    } catch (e) {
        console.log('[iSpring] Error accessing player:', e.message);
    }

    return null;
}

// Start iSpring-specific monitoring.
setTimeout(function() {
    iSpringCheckInterval = setInterval(function() {
        var playerInfo = findISpringPlayer();
        if (playerInfo) {
            var currentSlide = getISpringCurrentSlide(playerInfo);
            if (currentSlide !== null && currentSlide !== iSpringSlideIndex) {
                iSpringSlideIndex = currentSlide;
                if (currentSlide !== lastSlide) {
                    console.log('[iSpring] Slide changed to:', currentSlide);
                    sendProgressUpdate(null, null, null, currentSlide);
                }
            }
        }
    }, 1000);
}, 4000);

window.addEventListener('beforeunload', function() {
    if (iSpringCheckInterval) {
        clearInterval(iSpringCheckInterval);
    }
});

// Navigate to a specific slide via iSpring APIs.
// Returns true on success, false if iSpring player not found or navigation failed.
function navigateViaISpring(targetSlide) {
    var iSpringPlayer = findISpringPlayer();
    if (!iSpringPlayer || !iSpringPlayer.window) {
        return false;
    }

    try {
        var win = iSpringPlayer.window;
        var doc = iSpringPlayer.document;

        console.log('[SCORM Navigation] iSpring player found, trying navigation methods');

        // Method 1: iSpringPresentationAPI.gotoSlide
        if (win.iSpringPresentationAPI && win.iSpringPresentationAPI.gotoSlide) {
            win.iSpringPresentationAPI.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] iSpring API gotoSlide called');
            return true;
        }

        // Method 2: ispringPresentationConnector.gotoSlide
        if (win.ispringPresentationConnector && win.ispringPresentationConnector.gotoSlide) {
            win.ispringPresentationConnector.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] iSpring connector gotoSlide called');
            return true;
        }

        // Method 3: Direct gotoSlide/goToSlide function
        if (win.gotoSlide) {
            win.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] iSpring gotoSlide() called');
            return true;
        }
        if (win.goToSlide) {
            win.goToSlide(targetSlide - 1);
            console.log('[SCORM Navigation] iSpring goToSlide() called');
            return true;
        }

        // Method 4: g_oPresentation (PowerPoint export)
        if (win.g_oPresentation && win.g_oPresentation.gotoSlide) {
            win.g_oPresentation.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] iSpring g_oPresentation.gotoSlide called');
            return true;
        }
        if (win.g_oPres && win.g_oPres.gotoSlide) {
            win.g_oPres.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] iSpring g_oPres.gotoSlide called');
            return true;
        }

        // Method 5: PresentationAPI/Manager
        if (win.PresentationAPI && win.PresentationAPI.gotoSlide) {
            win.PresentationAPI.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] iSpring PresentationAPI.gotoSlide called');
            return true;
        }
        if (win.PresentationManager && win.PresentationManager.gotoSlide) {
            win.PresentationManager.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] iSpring PresentationManager.gotoSlide called');
            return true;
        }

        // Method 6: player object
        if (win.player) {
            if (win.player.gotoSlide) {
                win.player.gotoSlide(targetSlide - 1);
                console.log('[SCORM Navigation] iSpring player.gotoSlide called');
                return true;
            }
            if (win.player.goToSlide) {
                win.player.goToSlide(targetSlide - 1);
                console.log('[SCORM Navigation] iSpring player.goToSlide called');
                return true;
            }
            if (win.player.setSlideIndex) {
                win.player.setSlideIndex(targetSlide - 1);
                console.log('[SCORM Navigation] iSpring player.setSlideIndex called');
                return true;
            }
        }

        // Method 7: ISPRING global object
        if (win.ISPRING) {
            if (win.ISPRING.gotoSlide) {
                win.ISPRING.gotoSlide(targetSlide - 1);
                console.log('[SCORM Navigation] iSpring ISPRING.gotoSlide called');
                return true;
            }
            if (win.ISPRING.presentation && win.ISPRING.presentation.gotoSlide) {
                win.ISPRING.presentation.gotoSlide(targetSlide - 1);
                console.log('[SCORM Navigation] iSpring ISPRING.presentation.gotoSlide called');
                return true;
            }
        }

        // Method 8: Try clicking slide thumbnail/navigation
        var slideNavItems = doc.querySelectorAll('.slide-thumbnail, .slide-nav-item, [data-slide-index], .outline-item, .toc-item');
        console.log('[SCORM Navigation] iSpring found', slideNavItems.length, 'slide nav items');
        if (slideNavItems.length >= targetSlide) {
            var targetItem = slideNavItems[targetSlide - 1];
            if (targetItem) {
                var clickTarget = targetItem.querySelector('a, button') || targetItem;
                clickTarget.click();
                console.log('[SCORM Navigation] iSpring clicked slide nav item', targetSlide - 1);
                return true;
            }
        }

        // Method 9: Try dispatching custom events that iSpring might listen to
        var slideEvent = new CustomEvent('gotoSlide', { detail: { slideIndex: targetSlide - 1 } });
        doc.dispatchEvent(slideEvent);
        win.dispatchEvent(slideEvent);
        console.log('[SCORM Navigation] iSpring dispatched gotoSlide event');

    } catch (e) {
        console.log('[SCORM Navigation] iSpring navigation error:', e.message);
    }

    return false;
}

// Navigate via iSpring APIs found in an inner iframe window.
// Returns true on success, false otherwise.
function navigateISpringInnerFrame(iframeWin, targetSlide) {
    try {
        if (iframeWin.iSpringPresentationAPI && iframeWin.iSpringPresentationAPI.gotoSlide) {
            iframeWin.iSpringPresentationAPI.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] Inner iframe iSpring API gotoSlide called');
            return true;
        }
        if (iframeWin.g_oPresentation && iframeWin.g_oPresentation.gotoSlide) {
            iframeWin.g_oPresentation.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] Inner iframe iSpring g_oPresentation.gotoSlide called');
            return true;
        }
        if (iframeWin.ispringPresentationConnector && iframeWin.ispringPresentationConnector.gotoSlide) {
            iframeWin.ispringPresentationConnector.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] Inner iframe iSpring connector gotoSlide called');
            return true;
        }
        if (iframeWin.player && iframeWin.player.gotoSlide) {
            iframeWin.player.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] Inner iframe iSpring player.gotoSlide called');
            return true;
        }
        if (iframeWin.ISPRING && iframeWin.ISPRING.gotoSlide) {
            iframeWin.ISPRING.gotoSlide(targetSlide - 1);
            console.log('[SCORM Navigation] Inner iframe iSpring ISPRING.gotoSlide called');
            return true;
        }
    } catch (e) {
        console.log('[SCORM Navigation] Inner iframe iSpring navigation error:', e.message);
    }

    return false;
}
JSEOF;
    }
}
