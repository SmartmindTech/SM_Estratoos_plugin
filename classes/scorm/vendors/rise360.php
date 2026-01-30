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
 * Articulate Rise 360 vendor-specific detection, polling, and navigation JavaScript.
 *
 * Extracted from tracking_js.php for modularity. Provides:
 * - findRise360Player() — Detects Rise 360 player in iframes (DOM patterns, state objects, nested iframes)
 * - getRise360CurrentSection() — Reads current section via hash, DOM blocks, nav items, internal state
 * - Polling setup — 1s interval after 4s delay
 * - navigateViaRise360() — Direct navigation via hash, internal APIs, nav item clicks, lesson links
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\scorm\vendors;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates Articulate Rise 360 detection, polling, and navigation JavaScript.
 */
class rise360 {

    /**
     * Returns the JavaScript code for Articulate Rise 360 detection, polling, and navigation.
     *
     * @return string JavaScript code as a nowdoc string.
     */
    public static function get_js() {
        return <<<'JSEOF'
// === ARTICULATE RISE 360 — Detection, Polling & Navigation ===

var rise360SectionIndex = null;
var rise360CheckInterval = null;

// Function to find the Rise 360 player in iframes.
function findRise360Player() {
    var iframes = document.querySelectorAll('iframe');
    for (var i = 0; i < iframes.length; i++) {
        try {
            var iframeWin = iframes[i].contentWindow;
            var iframeDoc = iframeWin.document;

            // Rise 360 has specific class patterns in its DOM
            if (iframeDoc.querySelector('.rise-blocks, .rise-lesson, [data-block-id], [class*="rise"]')) {
                console.log('[Rise 360] Found via rise-blocks/rise-lesson');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check for Rise's app container
            if (iframeDoc.querySelector('#app, .rise-app, [data-rise-version]')) {
                console.log('[Rise 360] Found via #app/rise-app');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check for Articulate Rise specific elements
            if (iframeDoc.querySelector('.blocks, .block-list, [class*="block-"], .outline, .outline__item')) {
                console.log('[Rise 360] Found via blocks/outline');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check for Rise navigation patterns
            if (iframeDoc.querySelector('.course-nav, .lesson-nav, .nav-sidebar, [class*="nav-"]')) {
                // Additional check to make sure it's Rise and not something else
                if (iframeDoc.querySelector('[class*="lesson"], [class*="block"], [class*="outline"]')) {
                    console.log('[Rise 360] Found via navigation patterns');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
            }
            // Check for Rise's internal state objects
            if (iframeWin.__RISE_STATE__ || iframeWin.Rise || iframeWin.riseState || iframeWin.riseNavigation) {
                console.log('[Rise 360] Found via internal state object');
                return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
            }
            // Check nested iframes
            var nestedIframes = iframeDoc.querySelectorAll('iframe');
            for (var j = 0; j < nestedIframes.length; j++) {
                try {
                    var nestedWin = nestedIframes[j].contentWindow;
                    var nestedDoc = nestedWin.document;
                    if (nestedDoc.querySelector('.rise-blocks, .rise-lesson, [class*="rise"], .blocks, .outline')) {
                        console.log('[Rise 360] Found in nested iframe');
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

// Function to get current section from Rise 360 player.
function getRise360CurrentSection(playerInfo) {
    if (!playerInfo || !playerInfo.document) return null;

    try {
        var doc = playerInfo.document;
        var win = playerInfo.window;

        // Method 1: Check URL hash for lesson/section index
        var hash = win.location.hash;
        if (hash) {
            // Rise 360 uses format like #/lessons/xxx or #/sections/xxx
            var match = hash.match(/(?:lessons?|sections?|pages?)[\/\-](\d+)/i);
            if (match) {
                console.log('[Rise 360] Hash section:', match[1]);
                return parseInt(match[1], 10);
            }
            // Also try just extracting number from hash
            match = hash.match(/\/(\d+)/);
            if (match) {
                console.log('[Rise 360] Hash index:', match[1]);
                return parseInt(match[1], 10);
            }
        }

        // Method 2: Count active/visible Rise blocks
        var blocks = doc.querySelectorAll('.rise-blocks > div, .rise-lesson, [data-block-id]');
        var visibleBlockIndex = 0;
        for (var i = 0; i < blocks.length; i++) {
            var block = blocks[i];
            var rect = block.getBoundingClientRect();
            // Check if block is in viewport
            if (rect.top < win.innerHeight && rect.bottom > 0) {
                // This block is at least partially visible
                var blockId = block.getAttribute('data-block-id');
                if (blockId) {
                    console.log('[Rise 360] Visible block ID:', blockId, 'at index', i + 1);
                }
                visibleBlockIndex = i + 1;
                break; // Take the first visible one
            }
        }
        if (visibleBlockIndex > 0) {
            return visibleBlockIndex;
        }

        // Method 3: Check for active navigation item
        var navItems = doc.querySelectorAll('.rise-nav-item, .lesson-nav-item, [data-lesson-index]');
        for (var i = 0; i < navItems.length; i++) {
            var item = navItems[i];
            if (item.classList.contains('active') || item.classList.contains('current') || item.getAttribute('aria-current') === 'true') {
                var idx = item.getAttribute('data-lesson-index') || i;
                console.log('[Rise 360] Active nav item index:', idx);
                return parseInt(idx, 10) + 1;
            }
        }

        // Method 4: Check Rise's internal state
        if (win.__RISE_STATE__ || win.riseState || win.Rise) {
            var state = win.__RISE_STATE__ || win.riseState || (win.Rise && win.Rise.state);
            if (state && state.currentLesson !== undefined) {
                console.log('[Rise 360] Internal state currentLesson:', state.currentLesson);
                return state.currentLesson + 1;
            }
            if (state && state.currentSection !== undefined) {
                console.log('[Rise 360] Internal state currentSection:', state.currentSection);
                return state.currentSection + 1;
            }
        }

    } catch (e) {
        console.log('[Rise 360] Error accessing player:', e.message);
    }

    return null;
}

// Start Rise 360-specific monitoring.
setTimeout(function() {
    rise360CheckInterval = setInterval(function() {
        var playerInfo = findRise360Player();
        if (playerInfo) {
            var currentSection = getRise360CurrentSection(playerInfo);
            if (currentSection !== null && currentSection !== rise360SectionIndex) {
                rise360SectionIndex = currentSection;
                if (currentSection !== lastSlide) {
                    console.log('[Rise 360] Section changed to:', currentSection);
                    sendProgressUpdate(null, null, null, currentSection);
                }
            }
        }
    }, 1000);
}, 4000);

window.addEventListener('beforeunload', function() {
    if (rise360CheckInterval) {
        clearInterval(rise360CheckInterval);
    }
});

// Navigate to a specific section/lesson via Rise 360 APIs.
function navigateViaRise360(targetSlide) {
    var rise360Player = findRise360Player();
    if (!rise360Player || !rise360Player.window) {
        return false;
    }

    try {
        var win = rise360Player.window;
        var doc = rise360Player.document;

        // Method 1: Rise 360 hash navigation (lessons/sections)
        // Rise uses URL hash like #/lessons/0, #/lessons/1, etc.
        var currentHash = win.location.hash;
        console.log('[SCORM Navigation] Rise 360 current hash:', currentHash);

        // Try different Rise 360 hash patterns
        var hashPatterns = [
            '#/lessons/' + (targetSlide - 1),
            '#/lesson/' + (targetSlide - 1),
            '#/sections/' + (targetSlide - 1),
            '#/section/' + (targetSlide - 1),
            '#/' + (targetSlide - 1)
        ];

        for (var p = 0; p < hashPatterns.length; p++) {
            try {
                win.location.hash = hashPatterns[p];
                console.log('[SCORM Navigation] Rise 360 hash navigation attempted:', hashPatterns[p]);
                // Give it a moment and check if it worked
                return true;
            } catch (e) {}
        }

        // Method 2: Rise 360 internal state/API
        if (win.__RISE_STATE__ && win.__RISE_STATE__.goToLesson) {
            win.__RISE_STATE__.goToLesson(targetSlide - 1);
            console.log('[SCORM Navigation] Rise 360 goToLesson called');
            return true;
        }
        if (win.Rise && win.Rise.navigation && win.Rise.navigation.goTo) {
            win.Rise.navigation.goTo(targetSlide - 1);
            console.log('[SCORM Navigation] Rise 360 Rise.navigation.goTo called');
            return true;
        }
        if (win.riseNavigation && win.riseNavigation.goToLesson) {
            win.riseNavigation.goToLesson(targetSlide - 1);
            console.log('[SCORM Navigation] Rise 360 riseNavigation.goToLesson called');
            return true;
        }

        // Method 3: Click on navigation item
        var navItems = doc.querySelectorAll('.rise-nav-item, .lesson-nav-item, [data-lesson-index], .nav-item, .outline__item');
        console.log('[SCORM Navigation] Rise 360 found', navItems.length, 'nav items');
        if (navItems.length >= targetSlide) {
            var targetNav = navItems[targetSlide - 1];
            if (targetNav) {
                // Try clicking the nav item or its link
                var clickTarget = targetNav.querySelector('a, button') || targetNav;
                clickTarget.click();
                console.log('[SCORM Navigation] Rise 360 clicked nav item', targetSlide - 1);
                return true;
            }
        }

        // Method 4: Look for and click lesson links in sidebar/outline
        var lessonLinks = doc.querySelectorAll('a[href*="lesson"], a[href*="section"], .lesson-link, .outline-link');
        console.log('[SCORM Navigation] Rise 360 found', lessonLinks.length, 'lesson links');
        for (var l = 0; l < lessonLinks.length; l++) {
            var link = lessonLinks[l];
            var href = link.getAttribute('href') || '';
            if (href.includes('/' + (targetSlide - 1)) || href.includes('/' + targetSlide)) {
                link.click();
                console.log('[SCORM Navigation] Rise 360 clicked lesson link:', href);
                return true;
            }
        }

    } catch (e) {
        console.log('[SCORM Navigation] Rise 360 navigation error:', e.message);
    }

    return false;
}
JSEOF;
    }
}
