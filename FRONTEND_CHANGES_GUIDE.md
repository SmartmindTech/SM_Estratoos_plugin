# Frontend Changes Guide for SCORM Tag Navigation

This guide outlines the frontend changes needed in `SmartLearning/inboxfrontend/` to support all 7 SCORM navigation scenarios.

---

## Summary of Scenarios

| # | Action | Expected Progress | Expected Position |
|---|--------|-------------------|-------------------|
| 1 | Navigate forward to slide 23 | 17% (23/139) | 23/139 |
| 2 | Navigate backwards to slide 13, create tag | 17% (unchanged) | 13/139 |
| 3 | Continue backwards to slide 8 | 17% (unchanged) | 8/139 |
| 4 | Click tag to jump to slide 13 | 17% (unchanged) | 13/139 |
| 5 | Click activity in sidebar OR "go back" button | 17% (unchanged) | 8/139 |
| 6 | Click tag again | 17% (unchanged) | 13/139 |
| 7 | Natural nav to 5, then tag-jump to 13 | ~3.6% (5/139) | 13/139 |

---

## Current State Analysis

### What Already Works (Plugin Side - v2.0.44)
- furthestSlide only updates during natural forward navigation
- Tag jumps don't increase furthestSlide (even after 10s window)
- Position (currentSlide) always reflects actual slide
- PostMessage sends both `currentSlide` and `furthestSlide`

### What Needs Frontend Changes

1. **Scenario 5**: Clicking activity in sidebar should return to previous slide (before tag navigation)
2. **Verification**: Ensure progress bar uses `furthestSlide`, position bar uses `currentSlide`

---

## Required Changes

### Change 1: Activity Sidebar Click Returns to Previous Slide

**File:** `pages/courses/[id].vue`

**Current behavior:** Clicking the same activity in sidebar does nothing.

**Expected behavior:** If user navigated via tag, clicking the activity returns to the "previous slide" (position before tag navigation).

**Find the `handleActivitySelect` function (around line 359-390) and modify:**

```typescript
// BEFORE (current code)
function handleActivitySelect(module: CourseModule) {
  // If same activity already selected, do nothing
  if (selectedModule.value?.id === module.id) {
    return  // User can use "Go back" button instead
  }
  // ... rest of function
}

// AFTER (updated code)
function handleActivitySelect(module: CourseModule) {
  // If same activity already selected, check for previous slide to return to
  if (selectedModule.value?.id === module.id) {
    // Check if there's a previous slide from tag navigation
    const previousSlideKey = `scorm_previous_slide_${module.id}`
    const storedPrevious = sessionStorage.getItem(previousSlideKey)

    if (storedPrevious) {
      const previousSlide = parseInt(storedPrevious, 10)
      if (!isNaN(previousSlide) && previousSlide > 0) {
        // Set the target slide for the embed to load
        sessionStorage.setItem(`scorm_initial_slide_${module.id}`, storedPrevious)
        // Clear the previous slide since we're returning to it
        sessionStorage.removeItem(previousSlideKey)

        // Force reload the activity
        selectedModule.value = null
        nextTick(() => {
          selectedModule.value = module
        })
        return
      }
    }
    // No previous slide, do nothing
    return
  }

  // ... rest of function (unchanged)
}
```

---

### Change 2: Ensure Progress Bar Uses furthestSlide

**File:** `composables/useScormProgress.ts` or `pages/courses/[id].vue`

**Verify this logic exists in the postMessage handler:**

```typescript
// When receiving scorm-progress postMessage
onScormProgress((msg) => {
  if (selectedModule.value?.modname === 'scorm' && selectedModule.value?.id === msg.cmid) {

    // POSITION BAR: Use currentSlide (where user is now)
    if (msg.currentSlide !== null) {
      setScormPosition(selectedModule.value, msg.currentSlide, msg.totalSlides)
    }

    // PROGRESS BAR: Use furthestSlide (maximum reached via natural navigation)
    if (msg.furthestSlide !== null) {
      const progressPercent = (msg.furthestSlide / msg.totalSlides) * 100
      setScormProgress(selectedModule.value, progressPercent)

      // Store for persistence across reloads
      sessionStorage.setItem(`scorm_furthest_slide_${msg.cmid}`, String(msg.furthestSlide))
    }
  }
})
```

---

### Change 3: Clear Previous Slide After "Go Back"

**File:** `components/activity/ActivityEmbed.vue`

**In the `resumeActivity` function, clear the sessionStorage after navigating back:**

```typescript
// BEFORE
function resumeActivity() {
  if (previousSlide.value !== null) {
    emit('position-change', previousSlide.value)
    loadEmbed(false, previousSlide.value)
    return
  }
  // ...
}

// AFTER
function resumeActivity() {
  if (previousSlide.value !== null) {
    const targetSlide = previousSlide.value

    // Clear the previous slide - we're returning to it
    sessionStorage.removeItem(`scorm_previous_slide_${props.activityId}`)
    previousSlide.value = null

    emit('position-change', targetSlide)
    loadEmbed(false, targetSlide)
    return
  }
  // ...
}
```

**Why:** After returning to the previous slide, the "Go back" button should disappear since there's no longer a "previous" position to return to.

---

### Change 4: Handle Multiple Tag Navigations

**File:** `pages/courses/[id].vue`

**In `handleNavigateToPosition`, only store previous_slide if not already in a tag navigation:**

```typescript
function handleNavigateToPosition(event: CustomEvent) {
  const { cmid, position } = event.detail

  // Get current position
  const currentSlide = getCurrentSlideForActivity(cmid)

  // Only store previous_slide if we're NOT already viewing a navigated slide
  // This preserves the original position before any tag jumps
  const existingPrevious = sessionStorage.getItem(`scorm_previous_slide_${cmid}`)
  if (!existingPrevious && currentSlide) {
    sessionStorage.setItem(`scorm_previous_slide_${cmid}`, String(currentSlide))
  }

  // Always set the target slide
  sessionStorage.setItem(`scorm_initial_slide_${cmid}`, String(position))

  // Force reload...
}
```

**Why:** In scenario 6, when clicking the tag again from slide 8 (after going back), the previous_slide should remain 8 (or be cleared). This change ensures the original position is preserved across multiple tag navigations.

**Actually, reconsider this:** After scenario 5 (go back to slide 8), the previous_slide is cleared. In scenario 6 (click tag again), the user is at slide 8, so previous_slide should be set to 8. The current logic might already be correct. Let me revise:

```typescript
function handleNavigateToPosition(event: CustomEvent) {
  const { cmid, position } = event.detail

  // Get current position
  const currentSlide = getCurrentSlideForActivity(cmid)

  // Always store the current position as previous (for "go back")
  if (currentSlide) {
    sessionStorage.setItem(`scorm_previous_slide_${cmid}`, String(currentSlide))
  }

  // Set the target slide
  sessionStorage.setItem(`scorm_initial_slide_${cmid}`, String(position))

  // Force reload...
}
```

This ensures that wherever the user is when they click a tag, that position is saved for "go back".

---

## SessionStorage Keys Reference

| Key | Purpose | Set By | Cleared By |
|-----|---------|--------|------------|
| `scorm_initial_slide_{cmid}` | Target slide for navigation | Tag click handler | ActivityEmbed.loadEmbed() after reading |
| `scorm_previous_slide_{cmid}` | Position before tag navigation | Tag click handler | "Go back" click or activity sidebar click |
| `scorm_furthest_slide_{cmid}` | Maximum progress reached | PostMessage handler | Never (persists for session) |
| `scorm_pending_navigation_{cmid}` | Navigation data for plugin | Tag click handler | Plugin after reading |

---

## Verification Test Plan

### Test Scenario 1: Natural Forward Navigation
1. Open SCORM activity
2. Navigate forward through slides 1 → 23
3. **Verify:** Progress bar increases to ~17%, Position shows 23/139

### Test Scenario 2: Backward Navigation + Tag Creation
1. From slide 23, navigate backwards to slide 13
2. Create a comment with activity tag
3. **Verify:** Progress stays at 17%, Position shows 13/139, Tag shows "Slide 13"

### Test Scenario 3: Continue Backward
1. Continue backwards to slide 8
2. **Verify:** Progress stays at 17%, Position shows 8/139

### Test Scenario 4: Tag Jump
1. Click the tag in the comment (jump to slide 13)
2. **Verify:** Progress stays at 17%, Position shows 13/139, "Go back" button appears

### Test Scenario 5: Go Back (Two Methods)
**Method A - "Go back" button:**
1. Click "Go back to Slide 8" button
2. **Verify:** Position shows 8/139, "Go back" button disappears, Progress stays at 17%

**Method B - Activity sidebar:**
1. (After scenario 4) Click the activity in the right sidebar
2. **Verify:** Position shows 8/139, Progress stays at 17%

### Test Scenario 6: Tag Jump Again
1. From slide 8, click the tag again
2. **Verify:** Position shows 13/139, "Go back" button shows "Slide 8", Progress stays at 17%

### Test Scenario 7: Limited Progress from Tag Jump
1. **Fresh start:** Navigate naturally to slide 5
2. **Verify:** Progress shows ~3.6% (5/139)
3. Click tag to jump to slide 13
4. **Verify:** Position shows 13/139, Progress stays at ~3.6%
5. Wait 15+ seconds (past intercept window)
6. **Verify:** Progress STILL at ~3.6%
7. Navigate forward to slide 14
8. **Verify:** Progress updates to ~10% (14/139)

---

## Files to Modify Summary

| File | Changes |
|------|---------|
| `pages/courses/[id].vue` | 1. handleActivitySelect: return to previous slide<br>2. handleNavigateToPosition: ensure previous_slide is stored correctly |
| `components/activity/ActivityEmbed.vue` | resumeActivity: clear previous_slide after use |
| `composables/useScormProgress.ts` | Verify: progress bar uses furthestSlide, position uses currentSlide |

---

## PostMessage Data Reference

The plugin sends this data structure:

```javascript
{
  type: 'scorm-progress',
  cmid: 123,                    // Activity ID
  scormid: 456,                 // SCORM package ID
  currentSlide: 13,             // Current position (use for position bar)
  totalSlides: 139,             // Total slides
  furthestSlide: 23,            // Maximum reached (use for progress bar)
  lessonLocation: '13',         // Raw lesson_location value
  lessonStatus: 'incomplete',   // SCORM status
  score: 9.35,                  // Score percentage
  slideSource: 'navigation',    // 'suspend_data', 'navigation', or 'score'
  timestamp: 1706470800000      // Unix timestamp
}
```

**Key distinction:**
- `currentSlide` = where user IS now (for position bar: "Slide 13/139")
- `furthestSlide` = maximum reached via natural navigation (for progress bar: "17%")
