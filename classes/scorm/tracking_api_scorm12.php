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
 * SCORM 1.2 API wrapping — LMSInitialize, LMSGetValue, and LMSSetValue interceptors.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\scorm;

defined('MOODLE_INTERNAL') || die();

class tracking_api_scorm12 {

    /**
     * Returns the JavaScript function wrapScorm12Api() that wraps SCORM 1.2 API calls.
     *
     * @return string JavaScript code
     */
    public static function get_js() {
        return <<<'JSEOF'
// === SCORM 1.2 API WRAPPING (wrapScorm12Api) ===
function wrapScorm12Api() {
if (typeof window.API === 'undefined' || !window.API || !window.API.LMSSetValue) { return false; }

// Save original references before any wrapping (needed for scheduled writes)
var origLMSGetValue12 = window.API.LMSGetValue;
var origLMSSetValue12 = window.API.LMSSetValue;

// CRITICAL FIX v2.0.51: Directly modify the backing store BEFORE wrapping.
// Storyline does NOT call LMSGetValue('cmi.suspend_data') during initialization.
// It reads directly from Moodle's pre-populated cmi JavaScript object.
// By calling the original LMSSetValue, we update the cmi object at the source,
// ensuring correct data regardless of how Storyline reads it.
if (pendingSlideNavigation && window.API.LMSGetValue && window.API.LMSSetValue) {
    try {
        // 1. Modify suspend_data in the backing store
        var currentSD = window.API.LMSGetValue.call(window.API, 'cmi.suspend_data');
        if (currentSD && currentSD.length > 5) {
            var modifiedSD = modifySuspendDataForSlide(currentSD, pendingSlideNavigation.slide);
            if (modifiedSD !== currentSD) {
                window.API.LMSSetValue.call(window.API, 'cmi.suspend_data', modifiedSD);
                console.log('[SCORM Navigation] DIRECTLY modified cmi.suspend_data in backing store for slide:', pendingSlideNavigation.slide);
            } else {
                console.log('[SCORM Navigation] Backing store suspend_data already has correct slide');
            }
        } else {
            console.log('[SCORM Navigation] No suspend_data in backing store to modify (empty or short)');
        }
        // 2. Set lesson_location directly in the backing store
        window.API.LMSSetValue.call(window.API, 'cmi.core.lesson_location', String(pendingSlideNavigation.slide));
        console.log('[SCORM Navigation] DIRECTLY set cmi.core.lesson_location in backing store to:', pendingSlideNavigation.slide);
    } catch (e) {
        console.log('[SCORM Navigation] Error modifying SCORM 1.2 backing store:', e.message);
    }
}

// v2.0.55: On resume (no tag navigation), if sessionStorage has a higher furthest
// slide than the DB, correct the backing store so Storyline resumes at the furthest.
// This is a best-effort optimization - data may not be populated yet when the
// defineProperty trap fires. The read/write interceptors below handle the case
// where this doesn't work.
var resumeReadInterceptCount = 0;
var resumeInterceptStartTime = Date.now(); // Time-based window for resume read intercepts
if (!pendingSlideNavigation && furthestSlide !== null && window.API.LMSGetValue && window.API.LMSSetValue) {
    try {
        var resumeSD = window.API.LMSGetValue.call(window.API, 'cmi.suspend_data');
        if (resumeSD && resumeSD.length > 5) {
            var dbSlide = parseSlideFromSuspendData(resumeSD);
            if (dbSlide !== null && dbSlide < furthestSlide) {
                var corrected = modifySuspendDataForSlide(resumeSD, furthestSlide);
                if (corrected !== resumeSD) {
                    window.API.LMSSetValue.call(window.API, 'cmi.suspend_data', corrected);
                    window.API.LMSSetValue.call(window.API, 'cmi.core.lesson_location', String(furthestSlide));
                    console.log('[SCORM Navigation] Corrected resume to furthest slide:', furthestSlide, '(DB had:', dbSlide, ')');
                }
            } else {
                console.log('[SCORM Navigation] DB slide', dbSlide, 'already at or beyond furthest', furthestSlide);
            }
        } else {
            console.log('[SCORM Navigation] suspend_data not yet populated at trap time, relying on read/write interceptors');
        }
    } catch (e) {
        console.log('[SCORM Navigation] Error correcting resume position:', e.message);
    }
}

// v2.0.72: When sessionStorage is empty (furthestSlide null), determine furthest
// from score.raw. Wrap LMSInitialize so the correction runs after the content
// iframe is loaded (TOTAL_SLIDES available) but before the content reads CMI data.
if (!pendingSlideNavigation && furthestSlide === null) {
    var origLMSInitialize12 = window.API.LMSInitialize;
    window.API.LMSInitialize = function(param) {
        var result = origLMSInitialize12.call(window.API, param);
        // v2.0.84: Changed from early return to conditional block.
        // Previously: if (furthestSlide !== null) return result;
        // Problem: the initial retry loop (detection.php) may set furthestSlide from
        // score.raw/lesson_location BEFORE LMSInitialize fires, but does NOT correct
        // suspend_data. The early return skipped suspend_data correction entirely.
        if (furthestSlide === null) {
        try {
            var scoreStr = origLMSGetValue12.call(window.API, 'cmi.core.score.raw');
            var locationStr = origLMSGetValue12.call(window.API, 'cmi.core.lesson_location');
            var score = parseFloat(scoreStr);
            var location = parseInt(locationStr, 10);

            if (!isNaN(score) && score > 0 && score <= 100) {
                // Detect total slides (content iframe should be loaded now)
                var content = findGenericScormContent();
                var total = content ? getGenericTotalSlides(content) : null;

                if (total && total > 1) {
                    var furthestFromScore = Math.round((score / 100) * total);
                    slidescount = total;

                    if (!isNaN(location) && furthestFromScore > location) {
                        furthestSlide = furthestFromScore;
                        origLMSSetValue12.call(window.API, 'cmi.core.lesson_location', String(furthestSlide));
                        var sd = origLMSGetValue12.call(window.API, 'cmi.suspend_data');
                        if (sd && sd.length > 5) {
                            var fixed = modifySuspendDataForSlide(sd, furthestSlide);
                            if (fixed !== sd) {
                                origLMSSetValue12.call(window.API, 'cmi.suspend_data', fixed);
                                lastSuspendData = fixed; // v2.0.79: Prevent DOM observer from treating boosted DB value as navigation
                            }
                        }
                        try { sessionStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide)); localStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide)); } catch (e) {}
                        console.log('[SCORM Plugin] Score-based resume correction: slide', location, '->', furthestSlide,
                            '(score:', score, ', total:', total, ')');
                    } else if (!isNaN(location) && location >= 1) {
                        furthestSlide = Math.max(location, furthestFromScore || 0);
                        try { sessionStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide)); localStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide)); } catch (e) {}
                    }
                }
            }
        } catch (e) {}
        } // end if (furthestSlide === null)

        // v2.0.84: Always correct suspend_data if furthestSlide is set and suspend_data
        // is behind. This handles two scenarios:
        // (a) The initial retry loop set furthestSlide before LMSInitialize (early return case)
        // (b) Score logic above set furthestSlide but didn't correct suspend_data because
        //     furthestFromScore <= lesson_location (e.g., Captivate has lesson_location=4
        //     but suspend_data cs=2 meaning slide 3)
        if (furthestSlide !== null && furthestSlide > 1) {
            try {
                var initSD = origLMSGetValue12.call(window.API, 'cmi.suspend_data');
                if (initSD && initSD.length > 5) {
                    var initSlide = parseSlideFromSuspendData(initSD);
                    if (initSlide !== null && initSlide < furthestSlide) {
                        var initFixed = modifySuspendDataForSlide(initSD, furthestSlide);
                        if (initFixed !== initSD) {
                            origLMSSetValue12.call(window.API, 'cmi.suspend_data', initFixed);
                            lastSuspendData = initFixed;
                            console.log('[SCORM Plugin] LMSInitialize resume: corrected suspend_data from slide', initSlide, 'to', furthestSlide);
                        }
                    }
                }
            } catch (e) {}
        }

        return result;
    };
}

// Wrap LMSGetValue to intercept reads.
// For tag navigation: intercept lesson_location and suspend_data reads.
// v2.0.58: For resume correction: ALWAYS install when furthestSlide is known.
// Previously depended on pre-computed resumeCorrectedSD which failed when cmi
// data wasn't populated at defineProperty trap time. Now computes on-the-fly.
if (window.API.LMSGetValue && (pendingSlideNavigation || furthestSlide !== null)) {
    var originalGetValue = window.API.LMSGetValue;
    window.API.LMSGetValue = function(element) {
        var result = originalGetValue.call(window.API, element);

        // Intercept lesson_location reads WITHIN the intercept window.
        // During initialization, Storyline uses lesson_location for its resume position.
        // After the window, stop intercepting so the position bar tracks natural navigation.
        if (element === 'cmi.core.lesson_location' && pendingSlideNavigation) {
            // v2.0.65: Stop intercepting if user naturally navigated away from tag target
            if (locationInterceptDisabled) {
                return result;
            }
            var withinWindow = interceptStartTime !== null &&
                (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;
            if (!withinWindow) {
                return result; // Window expired - let actual value through
            }
            if (!isOurNavigationStillActive()) {
                console.log('[SCORM 1.2] lesson_location: navigation superseded, returning original:', result);
                return result;
            }
            console.log('[SCORM 1.2] Intercepting lesson_location, returning:', pendingSlideNavigation.slide);
            return String(pendingSlideNavigation.slide);
        }

        // v2.0.58: Resume correction read intercept (on-the-fly).
        // Computes correction at read time, not at trap time. This handles
        // the case where cmi data wasn't populated when defineProperty trap fired.
        if (element === 'cmi.suspend_data' && furthestSlide !== null && !pendingSlideNavigation) {
            var withinResumeWindow = (Date.now() - resumeInterceptStartTime) < INTERCEPT_WINDOW_MS;
            if (withinResumeWindow && result && result.length > 5) {
                var origSlide = parseSlideFromSuspendData(result);
                if (origSlide !== null && origSlide < furthestSlide) {
                    var correctedSD = modifySuspendDataForSlide(result, furthestSlide);
                    if (correctedSD !== result) {
                        resumeReadInterceptCount++;
                        console.log('[SCORM 1.2] Resume read intercept #' + resumeReadInterceptCount + ': corrected slide from', origSlide, 'to', furthestSlide);
                        return correctedSD;
                    }
                }
            }
        }
        if (element === 'cmi.core.lesson_location' && furthestSlide !== null && !pendingSlideNavigation) {
            var withinResumeWindow = (Date.now() - resumeInterceptStartTime) < INTERCEPT_WINDOW_MS;
            if (withinResumeWindow) {
                var locSlide = parseInt(result, 10);
                if (!isNaN(locSlide) && locSlide < furthestSlide) {
                    return String(furthestSlide);
                }
            }
        }

        // Intercept suspend_data reads within the time/count window
        // Storyline calls LMSGetValue multiple times during initialization
        if (element === 'cmi.suspend_data' && pendingSlideNavigation) {
            // CRITICAL: Check if our navigation is still active
            if (!isOurNavigationStillActive()) {
                console.log('[SCORM 1.2] suspend_data read: navigation superseded, returning original');
                return result;
            }

            // Start timer on first intercept
            if (interceptStartTime === null) {
                interceptStartTime = Date.now();
            }

            var withinWindow = (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;
            var underLimit = suspendDataInterceptCount < MAX_INTERCEPTS;

            if (withinWindow && underLimit) {
                suspendDataInterceptCount++;
                console.log('[SCORM 1.2] LMSGetValue intercept #' + suspendDataInterceptCount + ' for slide:', pendingSlideNavigation.slide);

                var modifiedData = modifySuspendDataForSlide(result, pendingSlideNavigation.slide);
                if (modifiedData !== result) {
                    console.log('[SCORM 1.2] Returning modified suspend_data');
                    return modifiedData;
                }
            } else if (suspendDataInterceptCount > 0) {
                // Window closed for suspend_data, but keep pendingSlideNavigation for lesson_location
                // lesson_location intercept must continue for the entire session (polling needs it)
                console.log('[SCORM 1.2] suspend_data intercept window closed after ' + suspendDataInterceptCount + ' intercepts');
                // DO NOT null pendingSlideNavigation - lesson_location intercept still needs it
            }
        }

        return result;
    };
    console.log('[SCORM Navigation] LMSGetValue interceptor installed' +
        (pendingSlideNavigation ? ' for navigation to slide: ' + pendingSlideNavigation.slide :
         ' for resume correction (furthest: ' + furthestSlide + ')'));
}

var originalSetValue = window.API.LMSSetValue;
// v2.0.66: Store reference to original SCORM 1.2 API for grade writing.
originalScormSetValue = function(el, val) { return originalSetValue.call(window.API, el, val); };
scormApiVersion = '1.2';
if (window.API.LMSCommit) {
    var origCommit = window.API.LMSCommit;
    originalScormCommit = function() { return origCommit.call(window.API, ''); };
}
window.API.LMSSetValue = function(element, value) {
    var valueToWrite = value;

    // Write interception for suspend_data during tag navigation.
    // During intercept window: force TAG TARGET so content initializes at correct slide.
    // v2.0.80: After intercept window, let natural value through (DB gets cs=current).
    if (element === 'cmi.suspend_data' && pendingSlideNavigation) {
        if (!isOurNavigationStillActive()) {
            console.log('[SCORM 1.2] LMSSetValue: navigation superseded, NOT intercepting write');
        } else {
            var inInterceptWindow = interceptStartTime !== null &&
                (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;

            if (inInterceptWindow) {
                // During initialization: force tag target
                var modifiedValue = modifySuspendDataForSlide(value, pendingSlideNavigation.slide);
                if (modifiedValue !== value) {
                    valueToWrite = modifiedValue;
                }
            }
            // v2.0.80: After intercept window, let natural value through.
            // DB gets cs=current so SmartLearning API polling shows correct position.
            // Resume is handled by pre-init backing store + read interceptors at page load.
        }
    }

    // v2.0.57: Score write interception - ensure score always reflects furthest progress.
    // Storyline writes score based on current position, which decreases when
    // navigating backwards or jumping via tag. Moodle uses this for gradebook/progress.
    if (element === 'cmi.core.score.raw' && furthestSlide !== null && slidescount > 1) {
        var writtenScore = parseFloat(value);
        if (!isNaN(writtenScore)) {
            var furthestScore = Math.min(Math.round((furthestSlide / slidescount) * 10000) / 100, 100);
            if (writtenScore < furthestScore) {
                console.log('[SCORM 1.2] Score corrected from', writtenScore, 'to', furthestScore,
                    '(furthest slide:', furthestSlide, '/', slidescount, ')');
                valueToWrite = String(furthestScore);
            }
        }
    }

    // v2.0.73: For lesson_location, write max(value, furthestSlide) to DB so that
    // on page refresh, the content resumes at the furthest slide, not the current.
    // Track the actual value (valueToWrite) for position bar updates.
    var dbWriteValue = valueToWrite;
    if (element === 'cmi.core.lesson_location' && furthestSlide !== null) {
        var locSlide = parseInt(valueToWrite, 10);
        if (!isNaN(locSlide) && locSlide < furthestSlide) {
            dbWriteValue = String(furthestSlide);
        }
    }

    // v2.0.78: Pre-set lastSuspendData BEFORE the DB write to prevent re-entrant tracking.
    // When originalSetValue writes modified suspend_data, Moodle's runtime may trigger
    // a re-entrant call back into this interceptor with the modified value. By pre-setting
    // lastSuspendData, the re-entrant call's value will match and be skipped.
    if (element === 'cmi.suspend_data') {
        lastSuspendData = valueToWrite;
    }

    var result = originalSetValue.call(window.API, element, dbWriteValue);

    // DEBUG: Log all SCORM API calls to understand what the content sends.
    console.log('[SCORM 1.2] LMSSetValue:', element, '=', valueToWrite && valueToWrite.substring ? valueToWrite.substring(0, 200) : valueToWrite);

    // Track lesson_location changes.
    // v2.0.64: Pass parsed slide as directSlide (not location) so backward navigation is allowed.
    // Only poll-based reads should suppress backward movement, not actual SCORM writes.
    // v2.0.77: Compare against lastWrittenLocation (actual content value) not lastLocation
    // (DB value, may be boosted by v2.0.74). Set lastLocation = dbWriteValue so the poll
    // doesn't re-report the boosted DB value as a position change.
    if (element === 'cmi.core.lesson_location' && valueToWrite !== lastWrittenLocation) {
        lastWrittenLocation = valueToWrite;
        lastLocation = dbWriteValue;
        lastApiChangeTime = Date.now();
        var parsedSlide = parseInt(valueToWrite, 10);
        sendProgressUpdate(null, lastStatus, null, isNaN(parsedSlide) ? null : parsedSlide);
        // v2.0.65: If user naturally navigated away from tag target, disable the
        // lesson_location read interceptor. Otherwise the poll picks up the stale
        // intercepted value and pushes position back up.
        if (pendingSlideNavigation && !locationInterceptDisabled &&
            String(valueToWrite) !== String(pendingSlideNavigation.slide)) {
            locationInterceptDisabled = true;
            console.log('[SCORM 1.2] User navigated away from tag target',
                pendingSlideNavigation.slide, '-> location interceptor disabled');
        }
    }
    // Track lesson_status changes.
    // v2.0.82: Pass null instead of lastLocation — lastLocation is boosted to
    // furthestSlide by the lesson_location interceptor, so passing it here would
    // override the position bar with furthest instead of current slide.
    if (element === 'cmi.core.lesson_status') {
        lastStatus = valueToWrite;
        sendProgressUpdate(null, valueToWrite, null, null);
    }
    // Track score changes.
    // IMPORTANT: Score represents FURTHEST PROGRESS, not current position.
    if (element === 'cmi.core.score.raw') {
        contentWritesScore = true; // v2.0.66: Mark that this SCORM manages its own scores
        var score = parseFloat(valueToWrite);
        if (!isNaN(score) && slidescount > 0 && score <= 100) {
            // Calculate slide from score percentage.
            var calculatedSlide = Math.round((score / 100) * slidescount);
            calculatedSlide = Math.max(1, Math.min(calculatedSlide, slidescount));

            // Check if we're in the intercept window (tag navigation in progress)
            var inInterceptWindow = pendingSlideNavigation && interceptStartTime !== null &&
                (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;

            // Check if user has naturally navigated beyond the tag target
            // This allows progress to resume after user surpasses the jumped-to slide
            var naturallyBeyondTarget = pendingSlideNavigation === null ||
                calculatedSlide > pendingSlideNavigation.slide;

            // IMPORTANT: Only update furthest during NATURAL navigation, not during tag jumps!
            // When user jumps via tag, they didn't VIEW the intermediate slides,
            // so furthest should NOT increase until they naturally navigate beyond the target.
            // The score from Storyline reflects current position, not actual progress.
            if (!inInterceptWindow && naturallyBeyondTarget &&
                (furthestSlide === null || calculatedSlide > furthestSlide)) {
                furthestSlide = calculatedSlide;
                console.log('[SCORM] Furthest progress updated:', furthestSlide);
            } else if (inInterceptWindow) {
                console.log('[SCORM] Ignoring score during intercept window:', calculatedSlide, '(keeping furthest:', furthestSlide, ')');
            } else if (pendingSlideNavigation && calculatedSlide <= pendingSlideNavigation.slide) {
                console.log('[SCORM] Ignoring score at/below tag target:', calculatedSlide, '(tag target:', pendingSlideNavigation.slide, ', keeping furthest:', furthestSlide, ')');
            }

            // Only use score-based slide for CURRENT position if no suspend_data AND not in intercept window.
            // During intercept window, we have a pending navigation target, so don't override with score.
            if (slideSource !== 'suspend_data' && lastSlide === null && !inInterceptWindow) {
                console.log('[SCORM] Using score-based slide (fallback):', calculatedSlide);
                slideSource = 'score';
                sendProgressUpdate(null, lastStatus, valueToWrite, calculatedSlide);
            } else {
                // Don't change currentSlide, but send update with furthestSlide for progress bar.
                // v2.0.82: Pass null instead of lastLocation — it's boosted to furthest
                // and would override position bar with wrong slide number.
                console.log('[SCORM] Score indicates furthest progress:', furthestSlide, '(current slide:', lastSlide, ')');
                sendProgressUpdate(null, lastStatus, valueToWrite, null);
            }
        } else {
            sendProgressUpdate(null, lastStatus, valueToWrite, null);
        }
    }
    // Track suspend_data changes for position and progress.
    // Use the ORIGINAL value (before write interception) for actual position.
    // The modified valueToWrite preserves the tag target in DB, but the
    // original value reflects Storyline's actual current slide.
    // v2.0.78: Also skip if value === lastSuspendData (re-entrant call from Moodle runtime
    // echoing the modified DB value back through our interceptor).
    if (element === 'cmi.suspend_data' && value !== lastSuspendDataOriginal && value !== lastSuspendData) {
        var inInterceptWindow = pendingSlideNavigation && interceptStartTime !== null &&
            (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;

        lastSuspendDataOriginal = value;
        lastSuspendData = valueToWrite;
        lastApiChangeTime = Date.now();
        if (!inInterceptWindow) {
            // Parse from ORIGINAL value to get actual position
            var slideNum = parseSlideFromSuspendData(value);
            if (slideNum !== null) {
                // v2.0.82: Detect Captivate periodic commits (cs unchanged = timer-based, not navigation).
                // Captivate writes suspend_data every ~30s with format cs=N,vs=...,qt=...,qr=...,ts=...
                // The cs field stays stale during periodic commits, causing wrong position updates.
                var isCaptivatePeriodicCommit = false;
                if (/\bcs=\d+/.test(value)) {
                    var csMatch = value.match(/\bcs=(\d+)/);
                    var currentCs = csMatch ? parseInt(csMatch[1], 10) : -1;
                    if (lastCaptivateCs !== null && currentCs === lastCaptivateCs) {
                        isCaptivatePeriodicCommit = true;
                    }
                    lastCaptivateCs = currentCs;
                }

                // v2.0.82: Extract Captivate vs (visited slides) for accurate furthestSlide.
                // vs=0:1:2:3 means slides 0-3 visited → furthest is 4 (0-based to 1-based).
                if (/\bvs=/.test(value)) {
                    var vsMatch = value.match(/\bvs=([0-9:]+)/);
                    if (vsMatch) {
                        var visited = vsMatch[1].split(':').map(function(s) { return parseInt(s, 10); }).filter(function(n) { return !isNaN(n); });
                        if (visited.length > 0) {
                            var maxVisited = Math.max.apply(null, visited) + 1; // 0-based to 1-based
                            if (furthestSlide === null || maxVisited > furthestSlide) {
                                furthestSlide = maxVisited;
                                console.log('[SCORM 1.2] Furthest progress updated from Captivate vs field:', furthestSlide);
                                try {
                                    sessionStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide));
                                    localStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide));
                                } catch (e) {}
                            }
                        }
                    }
                }

                // Update furthestSlide from parsed slide (only increases)
                if (furthestSlide === null || slideNum > furthestSlide) {
                    furthestSlide = slideNum;
                    console.log('[SCORM 1.2] Furthest progress updated from suspend_data:', furthestSlide);
                    try {
                        sessionStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide));
                        localStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide));
                    } catch (e) {}
                }

                // Send position update — skip if Captivate periodic commit (cs is stale)
                if (!isCaptivatePeriodicCommit && slideNum !== lastSlide) {
                    console.log('[SCORM 1.2] Position from suspend_data:', slideNum);
                    sendProgressUpdate(lastLocation, lastStatus, null, slideNum);
                } else if (isCaptivatePeriodicCommit) {
                    console.log('[SCORM 1.2] Captivate periodic commit, cs unchanged — skipping position update');
                }
            }
        } else {
            console.log('[SCORM 1.2] Skipping suspend_data tracking during intercept window');
        }
    }

    return result;
};

// v2.0.80: Schedule a proactive write after the intercept window closes.
// Only write lesson_location and score for resume/progress.
// Do NOT boost suspend_data.cs - let DB have real current position
// so SmartLearning API polling shows correct position bar.
if (pendingSlideNavigation) {
    setTimeout(function() {
        if (furthestSlide === null) return;
        try {
            var currentLoc = origLMSGetValue12.call(window.API, 'cmi.core.lesson_location');
            var locSlide = parseInt(currentLoc, 10);
            if (isNaN(locSlide) || locSlide < furthestSlide) {
                origLMSSetValue12.call(window.API, 'cmi.core.lesson_location', String(furthestSlide));
                console.log('[SCORM 1.2] Post-intercept: wrote lesson_location:', furthestSlide);
            }
            if (slidescount > 1) {
                var currentScore = origLMSGetValue12.call(window.API, 'cmi.core.score.raw');
                var furthestScore = Math.min(Math.round((furthestSlide / slidescount) * 10000) / 100, 100);
                if (!currentScore || parseFloat(currentScore) < furthestScore) {
                    origLMSSetValue12.call(window.API, 'cmi.core.score.raw', String(furthestScore));
                    console.log('[SCORM 1.2] Post-intercept: corrected score to', furthestScore);
                }
            }
            window.API.LMSCommit('');
        } catch (e) {
            console.log('[SCORM 1.2] Post-intercept write error:', e.message);
        }
    }, INTERCEPT_WINDOW_MS + 2000);
}

// v2.0.80: Schedule a proactive write to ensure DB has correct resume data.
// Only write lesson_location and score - NOT suspend_data.cs.
if (!pendingSlideNavigation && furthestSlide !== null) {
    setTimeout(function() {
        if (furthestSlide === null) return;
        try {
            var currentLoc = origLMSGetValue12.call(window.API, 'cmi.core.lesson_location');
            var locSlide = parseInt(currentLoc, 10);
            if (isNaN(locSlide) || locSlide < furthestSlide) {
                origLMSSetValue12.call(window.API, 'cmi.core.lesson_location', String(furthestSlide));
                console.log('[SCORM 1.2] Resume post-init: wrote lesson_location:', furthestSlide);
            }
            if (slidescount > 1) {
                var currentScore = origLMSGetValue12.call(window.API, 'cmi.core.score.raw');
                var furthestScore = Math.min(Math.round((furthestSlide / slidescount) * 10000) / 100, 100);
                if (!currentScore || parseFloat(currentScore) < furthestScore) {
                    origLMSSetValue12.call(window.API, 'cmi.core.score.raw', String(furthestScore));
                    console.log('[SCORM 1.2] Resume post-init: corrected score to', furthestScore);
                }
            }
            window.API.LMSCommit('');
        } catch (e) {
            console.log('[SCORM 1.2] Resume post-init write error:', e.message);
        }
    }, INTERCEPT_WINDOW_MS + 2000);
}

return true;
}
JSEOF;
    }
}
