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
 * SCORM 2004 API wrapping — Initialize, GetValue, and SetValue interceptors.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\scorm;

defined('MOODLE_INTERNAL') || die();

class tracking_api_scorm2004 {

    /**
     * Returns the JavaScript for SCORM 2004 API wrapping.
     *
     * @return string JavaScript code defining wrapScorm2004Api().
     */
    public static function get_js() {
        return <<<'JSEOF'
// === SCORM 2004 API WRAPPING (wrapScorm2004Api) ===
function wrapScorm2004Api() {
if (typeof window.API_1484_11 === 'undefined' || window.API_1484_11 === null || !window.API_1484_11.SetValue) {
    return false;
}

// Save original references before any wrapping
var origGetValue2004 = window.API_1484_11.GetValue;
var origSetValue2004ref = window.API_1484_11.SetValue;

// CRITICAL FIX v2.0.51: Directly modify the backing store BEFORE wrapping.
// Same reasoning as SCORM 1.2 - Storyline reads from Moodle's pre-populated
// data model object, NOT through GetValue calls.
if (pendingSlideNavigation && window.API_1484_11.GetValue && window.API_1484_11.SetValue) {
    try {
        // 1. Modify suspend_data in the backing store
        var currentSD2004 = window.API_1484_11.GetValue.call(window.API_1484_11, 'cmi.suspend_data');
        if (currentSD2004 && currentSD2004.length > 5) {
            var modifiedSD2004 = modifySuspendDataForSlide(currentSD2004, pendingSlideNavigation.slide);
            if (modifiedSD2004 !== currentSD2004) {
                window.API_1484_11.SetValue.call(window.API_1484_11, 'cmi.suspend_data', modifiedSD2004);
                console.log('[SCORM Navigation] DIRECTLY modified cmi.suspend_data in SCORM 2004 backing store for slide:', pendingSlideNavigation.slide);
            } else {
                console.log('[SCORM Navigation] SCORM 2004 backing store suspend_data already has correct slide');
            }
        } else {
            console.log('[SCORM Navigation] No SCORM 2004 suspend_data in backing store to modify (empty or short)');
        }
        // 2. Set location directly in the backing store
        window.API_1484_11.SetValue.call(window.API_1484_11, 'cmi.location', String(pendingSlideNavigation.slide));
        console.log('[SCORM Navigation] DIRECTLY set cmi.location in SCORM 2004 backing store to:', pendingSlideNavigation.slide);
    } catch (e) {
        console.log('[SCORM Navigation] Error modifying SCORM 2004 backing store:', e.message);
    }
}

// v2.0.55: On resume (no tag navigation), correct backing store to furthest slide.
// Best-effort - data may not be populated at defineProperty trap time.
var resumeReadInterceptCount = 0;
var resumeInterceptStartTime = Date.now();
if (!pendingSlideNavigation && furthestSlide !== null && window.API_1484_11.GetValue && window.API_1484_11.SetValue) {
    try {
        var resumeSD2004 = window.API_1484_11.GetValue.call(window.API_1484_11, 'cmi.suspend_data');
        if (resumeSD2004 && resumeSD2004.length > 5) {
            var dbSlide2004 = parseSlideFromSuspendData(resumeSD2004);
            if (dbSlide2004 !== null && dbSlide2004 < furthestSlide) {
                var corrected2004 = modifySuspendDataForSlide(resumeSD2004, furthestSlide);
                if (corrected2004 !== resumeSD2004) {
                    window.API_1484_11.SetValue.call(window.API_1484_11, 'cmi.suspend_data', corrected2004);
                    window.API_1484_11.SetValue.call(window.API_1484_11, 'cmi.location', String(furthestSlide));
                    console.log('[SCORM Navigation] Corrected SCORM 2004 resume to furthest slide:', furthestSlide, '(DB had:', dbSlide2004, ')');
                }
            }
        } else {
            console.log('[SCORM Navigation] SCORM 2004 suspend_data not yet populated at trap time, relying on read/write interceptors');
        }
    } catch (e) {
        console.log('[SCORM Navigation] Error correcting SCORM 2004 resume position:', e.message);
    }
}

// v2.0.72: Score-based resume correction for SCORM 2004.
if (!pendingSlideNavigation && furthestSlide === null) {
    var origInitialize2004 = window.API_1484_11.Initialize;
    window.API_1484_11.Initialize = function(param) {
        var result = origInitialize2004.call(window.API_1484_11, param);
        if (furthestSlide !== null) return result;
        try {
            var scoreStr = origGetValue2004.call(window.API_1484_11, 'cmi.score.raw');
            var locationStr = origGetValue2004.call(window.API_1484_11, 'cmi.location');
            var score = parseFloat(scoreStr);
            var location = parseInt(locationStr, 10);

            if (!isNaN(score) && score > 0 && score <= 100) {
                var content = findGenericScormContent();
                var total = content ? getGenericTotalSlides(content) : null;

                if (total && total > 1) {
                    var furthestFromScore = Math.round((score / 100) * total);
                    slidescount = total;

                    if (!isNaN(location) && furthestFromScore > location) {
                        furthestSlide = furthestFromScore;
                        origSetValue2004ref.call(window.API_1484_11, 'cmi.location', String(furthestSlide));
                        var sd = origGetValue2004.call(window.API_1484_11, 'cmi.suspend_data');
                        if (sd && sd.length > 5) {
                            var fixed = modifySuspendDataForSlide(sd, furthestSlide);
                            if (fixed !== sd) {
                                origSetValue2004ref.call(window.API_1484_11, 'cmi.suspend_data', fixed);
                                lastSuspendData = fixed; // v2.0.79: Prevent DOM observer from treating boosted DB value as navigation
                            }
                        }
                        try { sessionStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide)); localStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide)); } catch (e) {}
                        console.log('[SCORM Plugin] Score-based resume correction (2004): slide', location, '->', furthestSlide,
                            '(score:', score, ', total:', total, ')');
                    } else if (!isNaN(location) && location >= 1) {
                        furthestSlide = Math.max(location, furthestFromScore || 0);
                        try { sessionStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide)); localStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide)); } catch (e) {}
                    }
                }
            }
        } catch (e) {}
        return result;
    };
}

// Wrap GetValue FIRST to intercept suspend_data reads
// v2.0.58: Always install when furthestSlide is known (compute on-the-fly).
if (window.API_1484_11.GetValue && (pendingSlideNavigation || furthestSlide !== null)) {
    var originalGetValue2004 = window.API_1484_11.GetValue;
    window.API_1484_11.GetValue = function(element) {
        var result = originalGetValue2004.call(window.API_1484_11, element);

        // Intercept location reads WITHIN the intercept window.
        // During initialization, Storyline uses cmi.location for its resume position.
        // After the window, stop intercepting so the position bar tracks natural navigation.
        if (element === 'cmi.location' && pendingSlideNavigation) {
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
                console.log('[SCORM 2004] location: navigation superseded, returning original:', result);
                return result;
            }
            console.log('[SCORM 2004] Intercepting location, returning:', pendingSlideNavigation.slide);
            return String(pendingSlideNavigation.slide);
        }

        // v2.0.58: Resume correction read intercept (on-the-fly).
        if (element === 'cmi.suspend_data' && furthestSlide !== null && !pendingSlideNavigation) {
            var withinResumeWindow = (Date.now() - resumeInterceptStartTime) < INTERCEPT_WINDOW_MS;
            if (withinResumeWindow && result && result.length > 5) {
                var origSlide = parseSlideFromSuspendData(result);
                if (origSlide !== null && origSlide < furthestSlide) {
                    var correctedSD = modifySuspendDataForSlide(result, furthestSlide);
                    if (correctedSD !== result) {
                        resumeReadInterceptCount++;
                        console.log('[SCORM 2004] Resume read intercept #' + resumeReadInterceptCount + ': corrected slide from', origSlide, 'to', furthestSlide);
                        return correctedSD;
                    }
                }
            }
        }
        if (element === 'cmi.location' && furthestSlide !== null && !pendingSlideNavigation) {
            var withinResumeWindow = (Date.now() - resumeInterceptStartTime) < INTERCEPT_WINDOW_MS;
            if (withinResumeWindow) {
                var locSlide = parseInt(result, 10);
                if (!isNaN(locSlide) && locSlide < furthestSlide) {
                    return String(furthestSlide);
                }
            }
        }

        // Intercept suspend_data reads within the time/count window
        // Storyline calls GetValue multiple times during initialization
        if (element === 'cmi.suspend_data' && pendingSlideNavigation) {
            // CRITICAL: Check if our navigation is still active
            if (!isOurNavigationStillActive()) {
                console.log('[SCORM 2004] suspend_data read: navigation superseded, returning original');
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
                console.log('[SCORM 2004] GetValue intercept #' + suspendDataInterceptCount + ' for slide:', pendingSlideNavigation.slide);

                var modifiedData = modifySuspendDataForSlide(result, pendingSlideNavigation.slide);
                if (modifiedData !== result) {
                    console.log('[SCORM 2004] Returning modified suspend_data');
                    return modifiedData;
                }
            } else if (suspendDataInterceptCount > 0) {
                // Window closed for suspend_data, but keep pendingSlideNavigation for location
                // location intercept must continue for the entire session (polling needs it)
                console.log('[SCORM 2004] suspend_data intercept window closed after ' + suspendDataInterceptCount + ' intercepts');
                // DO NOT null pendingSlideNavigation - location intercept still needs it
            }
        }

        return result;
    };
    console.log('[SCORM Navigation] GetValue interceptor installed' +
        (pendingSlideNavigation ? ' for navigation to slide: ' + pendingSlideNavigation.slide :
         ' for resume correction (furthest: ' + furthestSlide + ')'));
}

var originalSetValue2004 = window.API_1484_11.SetValue;
// v2.0.66: Store reference to original SCORM 2004 API for grade writing.
if (!originalScormSetValue) {
    originalScormSetValue = function(el, val) { return originalSetValue2004.call(window.API_1484_11, el, val); };
    scormApiVersion = '2004';
}
if (!originalScormCommit && window.API_1484_11.Commit) {
    var origCommit2004 = window.API_1484_11.Commit;
    originalScormCommit = function() { return origCommit2004.call(window.API_1484_11, ''); };
}
window.API_1484_11.SetValue = function(element, value) {
    var valueToWrite = value;

    // Write interception for suspend_data during tag navigation.
    // During intercept window: force TAG TARGET so content initializes at correct slide.
    // v2.0.80: After intercept window, let natural value through (DB gets cs=current).
    if (element === 'cmi.suspend_data' && pendingSlideNavigation) {
        if (!isOurNavigationStillActive()) {
            console.log('[SCORM 2004] SetValue: navigation superseded, NOT intercepting write');
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
    if (element === 'cmi.score.raw' && furthestSlide !== null && slidescount > 1) {
        var writtenScore = parseFloat(value);
        if (!isNaN(writtenScore)) {
            var furthestScore = Math.min(Math.round((furthestSlide / slidescount) * 10000) / 100, 100);
            if (writtenScore < furthestScore) {
                console.log('[SCORM 2004] Score corrected from', writtenScore, 'to', furthestScore,
                    '(furthest slide:', furthestSlide, '/', slidescount, ')');
                valueToWrite = String(furthestScore);
            }
        }
    }

    // v2.0.73: For cmi.location, write max(value, furthestSlide) to DB so that
    // on page refresh, the content resumes at the furthest slide, not the current.
    var dbWriteValue2004 = valueToWrite;
    if (element === 'cmi.location' && furthestSlide !== null) {
        var locSlide2004 = parseInt(valueToWrite, 10);
        if (!isNaN(locSlide2004) && locSlide2004 < furthestSlide) {
            dbWriteValue2004 = String(furthestSlide);
        }
    }

    // v2.0.78: Pre-set lastSuspendData BEFORE the DB write to prevent re-entrant tracking.
    // When originalSetValue2004 writes modified suspend_data, Moodle's runtime may trigger
    // a re-entrant call back into this interceptor with the modified value. By pre-setting
    // lastSuspendData, the re-entrant call's value will match and be skipped.
    if (element === 'cmi.suspend_data') {
        lastSuspendData = valueToWrite;
    }

    var result = originalSetValue2004.call(window.API_1484_11, element, dbWriteValue2004);

    // DEBUG: Log all SCORM API calls to understand what the content sends.
    console.log('[SCORM 2004] SetValue:', element, '=', valueToWrite && valueToWrite.substring ? valueToWrite.substring(0, 200) : valueToWrite);

    // Track location changes.
    // v2.0.64: Pass parsed slide as directSlide (not location) so backward navigation is allowed.
    // Only poll-based reads should suppress backward movement, not actual SCORM writes.
    // v2.0.77: Compare against lastWrittenLocation (actual content value) not lastLocation
    // (DB value, may be boosted by v2.0.74). Set lastLocation = dbWriteValue2004 so the poll
    // doesn't re-report the boosted DB value as a position change.
    if (element === 'cmi.location' && valueToWrite !== lastWrittenLocation) {
        lastWrittenLocation = valueToWrite;
        lastLocation = dbWriteValue2004;
        lastApiChangeTime = Date.now();
        var parsedSlide = parseInt(valueToWrite, 10);
        sendProgressUpdate(null, lastStatus, null, isNaN(parsedSlide) ? null : parsedSlide);
        // v2.0.65: If user naturally navigated away from tag target, disable the
        // location read interceptor. Otherwise the poll picks up the stale
        // intercepted value and pushes position back up.
        if (pendingSlideNavigation && !locationInterceptDisabled &&
            String(valueToWrite) !== String(pendingSlideNavigation.slide)) {
            locationInterceptDisabled = true;
            console.log('[SCORM 2004] User navigated away from tag target',
                pendingSlideNavigation.slide, '-> location interceptor disabled');
        }
    }
    // Track completion_status changes.
    // v2.0.82: Pass null instead of lastLocation — lastLocation is boosted to
    // furthestSlide by the location interceptor, so passing it here would
    // override the position bar with furthest instead of current slide.
    if (element === 'cmi.completion_status') {
        lastStatus = valueToWrite;
        sendProgressUpdate(null, valueToWrite, null, null);
    }
    // Track score changes.
    // IMPORTANT: Score represents FURTHEST PROGRESS, not current position.
    if (element === 'cmi.score.raw') {
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
                console.log('[SCORM 2004] Furthest progress updated:', furthestSlide);
            } else if (inInterceptWindow) {
                console.log('[SCORM 2004] Ignoring score during intercept window:', calculatedSlide, '(keeping furthest:', furthestSlide, ')');
            } else if (pendingSlideNavigation && calculatedSlide <= pendingSlideNavigation.slide) {
                console.log('[SCORM 2004] Ignoring score at/below tag target:', calculatedSlide, '(tag target:', pendingSlideNavigation.slide, ', keeping furthest:', furthestSlide, ')');
            }

            // Only use score-based slide for CURRENT position if no suspend_data AND not in intercept window.
            // During intercept window, we have a pending navigation target, so don't override with score.
            if (slideSource !== 'suspend_data' && lastSlide === null && !inInterceptWindow) {
                console.log('[SCORM 2004] Using score-based slide (fallback):', calculatedSlide);
                slideSource = 'score';
                sendProgressUpdate(null, lastStatus, valueToWrite, calculatedSlide);
            } else {
                // Don't change currentSlide, but send update with furthestSlide for progress bar.
                // v2.0.82: Pass null instead of lastLocation — it's boosted to furthest
                // and would override position bar with wrong slide number.
                console.log('[SCORM 2004] Score indicates furthest progress:', furthestSlide, '(current slide:', lastSlide, ')');
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
                                console.log('[SCORM 2004] Furthest progress updated from Captivate vs field:', furthestSlide);
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
                    console.log('[SCORM 2004] Furthest progress updated from suspend_data:', furthestSlide);
                    try {
                        sessionStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide));
                        localStorage.setItem('scorm_furthest_slide_' + cmid, String(furthestSlide));
                    } catch (e) {}
                }

                // Send position update — skip if Captivate periodic commit (cs is stale)
                if (!isCaptivatePeriodicCommit && slideNum !== lastSlide) {
                    console.log('[SCORM 2004] Position from suspend_data:', slideNum);
                    sendProgressUpdate(lastLocation, lastStatus, null, slideNum);
                } else if (isCaptivatePeriodicCommit) {
                    console.log('[SCORM 2004] Captivate periodic commit, cs unchanged — skipping position update');
                }
            }
        } else {
            console.log('[SCORM 2004] Skipping suspend_data tracking during intercept window');
        }
    }

    return result;
};

// v2.0.80: Schedule a proactive write after the intercept window closes (SCORM 2004).
// Only write location and score for resume/progress.
// Do NOT boost suspend_data.cs - let DB have real current position.
if (pendingSlideNavigation) {
    setTimeout(function() {
        if (furthestSlide === null) return;
        try {
            var currentLoc = origGetValue2004.call(window.API_1484_11, 'cmi.location');
            var locSlide = parseInt(currentLoc, 10);
            if (isNaN(locSlide) || locSlide < furthestSlide) {
                origSetValue2004ref.call(window.API_1484_11, 'cmi.location', String(furthestSlide));
                console.log('[SCORM 2004] Post-intercept: wrote location:', furthestSlide);
            }
            if (slidescount > 1) {
                var currentScore = origGetValue2004.call(window.API_1484_11, 'cmi.score.raw');
                var furthestScore = Math.min(Math.round((furthestSlide / slidescount) * 10000) / 100, 100);
                if (!currentScore || parseFloat(currentScore) < furthestScore) {
                    origSetValue2004ref.call(window.API_1484_11, 'cmi.score.raw', String(furthestScore));
                    console.log('[SCORM 2004] Post-intercept: corrected score to', furthestScore);
                }
            }
            window.API_1484_11.Commit('');
        } catch (e) {
            console.log('[SCORM 2004] Post-intercept write error:', e.message);
        }
    }, INTERCEPT_WINDOW_MS + 2000);
}

// v2.0.80: Schedule a proactive write to ensure DB has correct resume data (SCORM 2004).
// Only write location and score - NOT suspend_data.cs.
if (!pendingSlideNavigation && furthestSlide !== null) {
    setTimeout(function() {
        if (furthestSlide === null) return;
        try {
            var currentLoc = origGetValue2004.call(window.API_1484_11, 'cmi.location');
            var locSlide = parseInt(currentLoc, 10);
            if (isNaN(locSlide) || locSlide < furthestSlide) {
                origSetValue2004ref.call(window.API_1484_11, 'cmi.location', String(furthestSlide));
                console.log('[SCORM 2004] Resume post-init: wrote location:', furthestSlide);
            }
            if (slidescount > 1) {
                var currentScore = origGetValue2004.call(window.API_1484_11, 'cmi.score.raw');
                var furthestScore = Math.min(Math.round((furthestSlide / slidescount) * 10000) / 100, 100);
                if (!currentScore || parseFloat(currentScore) < furthestScore) {
                    origSetValue2004ref.call(window.API_1484_11, 'cmi.score.raw', String(furthestScore));
                    console.log('[SCORM 2004] Resume post-init: corrected score to', furthestScore);
                }
            }
            window.API_1484_11.Commit('');
        } catch (e) {
            console.log('[SCORM 2004] Resume post-init write error:', e.message);
        }
    }, INTERCEPT_WINDOW_MS + 2000);
}

return true;
}
JSEOF;
    }
}
