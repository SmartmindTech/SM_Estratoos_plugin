# SmartMind Estratoos Plugin - Course Data API Guide

This guide covers four API functions for retrieving comprehensive course data from Moodle:

1. **get_course_content** - Retrieve all course content (SCORM, Quiz, Assignment, Lesson, etc.)
2. **get_course_students** - Retrieve students enrolled in a course
3. **get_course_teachers** - Retrieve teachers assigned to a course
4. **get_course_managers** - Retrieve managers for a course (course/category/company level)

---

## API Endpoint

All functions use the same REST endpoint:

```
POST https://{moodle-domain}/webservice/rest/server.php
```

### Required Headers
```
Content-Type: application/x-www-form-urlencoded
```

### Common Parameters
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `wstoken` | string | Yes | Web service authentication token |
| `wsfunction` | string | Yes | Function name (see below) |
| `moodlewsrestformat` | string | Yes | Always use `json` |

---

## 1. get_course_content

Retrieves comprehensive course content including all module types with their data.

### Function Name
```
local_sm_estratoos_plugin_get_course_content
```

### Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `courseids[0]` | int | Yes | - | Course ID (can pass multiple) |
| `options[includescormdetails]` | bool | No | true | Include SCORM SCOs and content files |
| `options[includefilecontents]` | bool | No | true | Include file URLs |
| `options[includepagecontent]` | bool | No | true | Include page/book HTML content |
| `options[includequizquestions]` | bool | No | true | Include quiz questions with answers |
| `options[includeassignmentdetails]` | bool | No | true | Include assignment submission details |
| `options[includelessonpages]` | bool | No | true | Include lesson pages with content |
| `options[includeuserdata]` | bool | No | true | Include user progress/attempts |

### Example Request

```bash
curl -X POST "https://moodle.example.com/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "wsfunction=local_sm_estratoos_plugin_get_course_content" \
  -d "moodlewsrestformat=json" \
  -d "courseids[0]=2" \
  -d "options[includequizquestions]=1" \
  -d "options[includeuserdata]=1"
```

### Response Structure

```json
{
  "courses": [
    {
      "id": 2,
      "fullname": "Course Name",
      "shortname": "COURSE",
      "summary": "<p>Course description HTML</p>",
      "startdate": 1765494000,
      "enddate": 1769900340,
      "visible": true,
      "sections": [
        {
          "id": 2,
          "name": "Section Name",
          "summary": "",
          "visible": true,
          "sectionnum": 0,
          "modules": [
            {
              "id": 1,
              "name": "Module Name",
              "modname": "scorm",
              "instance": 1,
              "visible": true,
              "uservisible": true,
              "description": "<p>Module intro HTML</p>",
              "completion": 0,
              "completionstate": 0,
              "url": "https://moodle/mod/scorm/view.php?id=1",
              "contents": [],

              // SCORM-specific (JSON string - MUST PARSE)
              "scorm": "{\"id\":1,\"name\":\"...\",\"scocount\":1,\"slidescount\":24,...}"
            }
          ]
        }
      ]
    }
  ],
  "warnings": []
}
```

### SCORM Data Structure (v1.7.75+)

The `scorm` field is a **JSON-encoded string**. You must parse it:

```javascript
const scormData = JSON.parse(module.scorm);
```

**SCORM Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | SCORM instance ID |
| `name` | string | SCORM name |
| `version` | string | SCORM version (SCORM_1.2, SCORM_2004, etc.) |
| `maxgrade` | float | Maximum grade |
| `grademethod` | int | Grading method |
| `maxattempt` | int | Maximum attempts (0 = unlimited) |
| `scormtype` | string | Type (local, external) |
| `launch` | string | Moodle player launch URL |
| `packageurl` | string | Package file URL |
| `scocount` | int | **Number of launchable SCOs** (reliable, from Moodle DB) |
| `slidescount` | int | **Best-effort slide count** (from authoring tool detection) |
| `slidedetection` | string | Detection method used (see below) |
| `authoringtool` | string\|null | Detected authoring tool name |
| `scos` | array | SCO objects with tracking data |
| `contentfiles` | array | Extracted package files |
| `userdata` | object | User progress data |

**Slide Detection Methods (`slidedetection`):**

| Value | Description |
|-------|-------------|
| `articulate_storyline` | Parsed from Articulate Storyline files |
| `articulate_rise` | Parsed from Articulate Rise content |
| `adobe_captivate` | Parsed from Adobe Captivate project |
| `ispring` | Parsed from iSpring presentation |
| `sco_count` | Fallback: using SCO count |
| `unknown` | Could not detect |

**Example SCORM Parsed Data:**

```json
{
  "id": 1,
  "name": "Safety Training",
  "version": "SCORM_1.2",
  "maxgrade": 100,
  "scocount": 1,
  "slidescount": 24,
  "slidedetection": "articulate_storyline",
  "authoringtool": "Articulate Storyline",
  "launch": "https://moodle/mod/scorm/player.php?scoid=1&cm=6",
  "packageurl": "https://moodle/pluginfile.php/.../package.zip",
  "scos": [
    {
      "id": 1,
      "title": "Module 1",
      "launch": "index.html",
      "scormtype": "sco",
      "usertrack": {
        "attempt": 1,
        "tracks": {
          "cmi.core.lesson_status": "completed",
          "cmi.core.score.raw": "85",
          "cmi.core.total_time": "00:15:30"
        }
      }
    }
  ],
  "contentfiles": [
    {
      "filename": "index.html",
      "filepath": "/",
      "filesize": 2048,
      "fileurl": "https://moodle/pluginfile.php/.../index.html",
      "mimetype": "text/html"
    }
  ],
  "userdata": {
    "attemptcount": 2,
    "grade": "85"
  }
}
```

### Other Module Types

**Quiz** (`module.quiz` - JSON string):
```json
{
  "id": 1,
  "name": "Quiz Name",
  "timelimit": 3600,
  "grade": 100,
  "questions": [
    {
      "id": 1,
      "slot": 1,
      "name": "Question 1",
      "questiontext": "<p>What is 2+2?</p>",
      "qtype": "multichoice",
      "answers": [
        {"id": 1, "answer": "<p>3</p>", "fraction": 0},
        {"id": 2, "answer": "<p>4</p>", "fraction": 1}
      ]
    }
  ],
  "userattempts": [...]
}
```

**Assignment** (`module.assignment` - JSON string):
```json
{
  "id": 1,
  "name": "Assignment Name",
  "duedate": 1765600000,
  "grade": 100,
  "submissiontypes": ["onlinetext", "file"],
  "usersubmission": {...},
  "usergrade": {...}
}
```

**Lesson** (`module.lesson` - JSON string):
```json
{
  "id": 1,
  "name": "Lesson Name",
  "pages": [
    {
      "id": 1,
      "qtype": 20,
      "qtypename": "content",
      "title": "Introduction",
      "contents": "<p>Welcome...</p>",
      "answers": [{"answer": "Continue", "jumpto": 2}]
    }
  ],
  "userprogress": {...}
}
```

---

## 2. get_course_students

Retrieves all students enrolled in a course.

### Function Name
```
local_sm_estratoos_plugin_get_course_students
```

### Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `courseid` | int | Yes | - | Course ID |
| `includeprofile` | bool | No | false | Include extended profile fields |
| `includegroups` | bool | No | false | Include user groups |

### Example Request

```bash
curl -X POST "https://moodle.example.com/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "wsfunction=local_sm_estratoos_plugin_get_course_students" \
  -d "moodlewsrestformat=json" \
  -d "courseid=2" \
  -d "includeprofile=1" \
  -d "includegroups=1"
```

### Response Structure

```json
{
  "students": [
    {
      "id": 5,
      "username": "student1",
      "email": "student1@example.com",
      "firstname": "John",
      "lastname": "Doe",
      "fullname": "John Doe",
      "idnumber": "STU001",
      "lastaccess": 1737450000,
      "profileimageurl": "https://moodle/pluginfile.php/.../f1.jpg",

      // Only if includeprofile=true
      "institution": "University",
      "department": "Engineering",
      "phone1": "+1234567890",
      "phone2": "",
      "city": "New York",
      "country": "US",
      "timezone": "America/New_York",
      "lang": "en",

      // Only if includegroups=true
      "groups": [
        {
          "id": 1,
          "name": "Group A",
          "idnumber": "GRP-A"
        }
      ]
    }
  ],
  "count": 25
}
```

### Role Detection

Students are identified by roles with:
- Archetype: `student`
- Shortname containing: `student`, `alumno`, `estudiante`, `aluno`, `aprendiz`

---

## 3. get_course_teachers

Retrieves all teachers assigned to a course with their specific role.

### Function Name
```
local_sm_estratoos_plugin_get_course_teachers
```

### Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `courseid` | int | Yes | - | Course ID |
| `includeprofile` | bool | No | false | Include extended profile fields |
| `includegroups` | bool | No | false | Include user groups |

### Example Request

```bash
curl -X POST "https://moodle.example.com/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "wsfunction=local_sm_estratoos_plugin_get_course_teachers" \
  -d "moodlewsrestformat=json" \
  -d "courseid=2" \
  -d "includeprofile=1"
```

### Response Structure

```json
{
  "teachers": [
    {
      "id": 3,
      "username": "teacher1",
      "email": "teacher1@example.com",
      "firstname": "Jane",
      "lastname": "Smith",
      "fullname": "Jane Smith",
      "idnumber": "TCH001",
      "lastaccess": 1737450000,
      "profileimageurl": "https://moodle/pluginfile.php/.../f1.jpg",

      // Teacher's role in this course
      "role": {
        "id": 3,
        "shortname": "editingteacher",
        "name": "Teacher",
        "archetype": "editingteacher"
      },

      // Only if includeprofile=true
      "institution": "University",
      "department": "Science",
      "phone1": "+1234567890",
      "city": "Boston",
      "country": "US",

      // Only if includegroups=true
      "groups": [...]
    }
  ],
  "count": 3
}
```

### Role Detection

Teachers are identified by roles with:
- Archetype: `teacher`, `editingteacher`
- Shortname containing: `teacher`, `profesor`, `professor`, `tutor`, `docente`, `formador`, `maestro`, `instructor`

---

## 4. get_course_managers

Retrieves managers for a course at multiple levels.

### Function Name
```
local_sm_estratoos_plugin_get_course_managers
```

### Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `courseid` | int | Yes | - | Course ID |
| `includeprofile` | bool | No | false | Include extended profile fields |
| `includecategorymanagers` | bool | No | true | Include category-level managers |
| `includecompanymanagers` | bool | No | true | Include IOMAD company managers |

### Example Request

```bash
curl -X POST "https://moodle.example.com/webservice/rest/server.php" \
  -d "wstoken=YOUR_TOKEN" \
  -d "wsfunction=local_sm_estratoos_plugin_get_course_managers" \
  -d "moodlewsrestformat=json" \
  -d "courseid=2" \
  -d "includeprofile=1" \
  -d "includecategorymanagers=1" \
  -d "includecompanymanagers=1"
```

### Response Structure

```json
{
  "managers": [
    {
      "id": 2,
      "username": "manager1",
      "email": "manager1@example.com",
      "firstname": "Bob",
      "lastname": "Wilson",
      "fullname": "Bob Wilson",
      "idnumber": "MGR001",
      "lastaccess": 1737450000,
      "profileimageurl": "https://moodle/pluginfile.php/.../f1.jpg",

      // Manager scope
      "scope": "course",  // "course", "category", or "company"

      // Manager's role
      "role": {
        "id": 1,
        "shortname": "manager",
        "name": "Manager",
        "archetype": "manager"
      },

      // Only for IOMAD company managers
      "managertype": 2,  // 1=Department Manager, 2=Company Manager

      // Only if includeprofile=true
      "institution": "Corp",
      "department": "Admin",
      "city": "Chicago",
      "country": "US"
    }
  ],
  "count": 2
}
```

### Manager Scopes

| Scope | Description |
|-------|-------------|
| `course` | Manager role assigned at course level |
| `category` | Manager role assigned at course category (or parent) level |
| `company` | IOMAD company manager (managertype > 0 in company_users) |

### Role Detection

Managers are identified by roles with:
- Archetype: `manager`
- Shortname containing: `manager`, `admin`, `gestor`, `gerente`, `administrador`, `coordinador`, `coordinator`, `companymanager`

---

## IOMAD Company Filtering

All four functions support **automatic company filtering** when using IOMAD tokens:

1. If the token is associated with a company (via `local_sm_estratoos_plugin` table)
2. And the token has `restricttocompany` enabled
3. Results are automatically filtered to only include users from that company

**No additional parameters needed** - filtering is applied automatically based on the token.

---

## Code Examples

### JavaScript/Node.js

```javascript
const axios = require('axios');

const MOODLE_URL = 'https://moodle.example.com/webservice/rest/server.php';
const TOKEN = 'your_token_here';

// Get course content
async function getCourseContent(courseId) {
  const response = await axios.post(MOODLE_URL, new URLSearchParams({
    wstoken: TOKEN,
    wsfunction: 'local_sm_estratoos_plugin_get_course_content',
    moodlewsrestformat: 'json',
    'courseids[0]': courseId,
    'options[includescormdetails]': 1,
    'options[includequizquestions]': 1,
    'options[includeuserdata]': 1
  }));

  const data = response.data;

  // Process each module
  for (const course of data.courses) {
    for (const section of course.sections) {
      for (const module of section.modules) {
        // Parse SCORM data
        if (module.modname === 'scorm' && module.scorm) {
          const scormData = JSON.parse(module.scorm);
          console.log(`SCORM: ${scormData.name}`);
          console.log(`  SCOs: ${scormData.scocount}`);
          console.log(`  Slides: ${scormData.slidescount} (${scormData.slidedetection})`);
          console.log(`  Tool: ${scormData.authoringtool || 'Unknown'}`);
        }

        // Parse Quiz data
        if (module.modname === 'quiz' && module.quiz) {
          const quizData = JSON.parse(module.quiz);
          console.log(`Quiz: ${quizData.name} - ${quizData.questions.length} questions`);
        }
      }
    }
  }

  return data;
}

// Get course participants
async function getCourseParticipants(courseId) {
  const [students, teachers, managers] = await Promise.all([
    axios.post(MOODLE_URL, new URLSearchParams({
      wstoken: TOKEN,
      wsfunction: 'local_sm_estratoos_plugin_get_course_students',
      moodlewsrestformat: 'json',
      courseid: courseId,
      includeprofile: 1,
      includegroups: 1
    })),
    axios.post(MOODLE_URL, new URLSearchParams({
      wstoken: TOKEN,
      wsfunction: 'local_sm_estratoos_plugin_get_course_teachers',
      moodlewsrestformat: 'json',
      courseid: courseId,
      includeprofile: 1
    })),
    axios.post(MOODLE_URL, new URLSearchParams({
      wstoken: TOKEN,
      wsfunction: 'local_sm_estratoos_plugin_get_course_managers',
      moodlewsrestformat: 'json',
      courseid: courseId,
      includeprofile: 1
    }))
  ]);

  return {
    students: students.data.students,
    studentCount: students.data.count,
    teachers: teachers.data.teachers,
    teacherCount: teachers.data.count,
    managers: managers.data.managers,
    managerCount: managers.data.count
  };
}

// Usage
(async () => {
  const content = await getCourseContent(2);
  const participants = await getCourseParticipants(2);

  console.log(`Students: ${participants.studentCount}`);
  console.log(`Teachers: ${participants.teacherCount}`);
  console.log(`Managers: ${participants.managerCount}`);
})();
```

### Python (using SmartLearning's `make_moodle_api_call`)

This example uses the `make_moodle_api_call` function from `inboxbackend/services/moodle/api.py`:

```python
import json
from services.moodle.api import make_moodle_api_call

# These would typically come from your session/database
TOKEN = 'your_token_here'
BASE_URL = 'https://moodle.example.com'


def get_course_content(course_id: int, request=None):
    """
    Get comprehensive course content including SCORM, Quiz, Assignment, Lesson, etc.

    Args:
        course_id: Moodle course ID
        request: Optional FastAPI request for connection status tracking

    Returns:
        Course content data with parsed module information
    """
    data = make_moodle_api_call(
        token=TOKEN,
        function='local_sm_estratoos_plugin_get_course_content',
        base_url=BASE_URL,
        method='POST',
        extra_params={
            'courseids[0]': course_id,
            'options[includescormdetails]': 1,
            'options[includequizquestions]': 1,
            'options[includeassignmentdetails]': 1,
            'options[includelessonpages]': 1,
            'options[includeuserdata]': 1
        },
        request=request
    )

    if not data:
        return None

    # Process and parse JSON-encoded module data
    for course in data.get('courses', []):
        for section in course.get('sections', []):
            for module in section.get('modules', []):
                # Parse SCORM data
                if module.get('modname') == 'scorm' and module.get('scorm'):
                    module['scorm_parsed'] = json.loads(module['scorm'])

                # Parse Quiz data
                if module.get('modname') == 'quiz' and module.get('quiz'):
                    module['quiz_parsed'] = json.loads(module['quiz'])

                # Parse Assignment data
                if module.get('modname') == 'assign' and module.get('assignment'):
                    module['assignment_parsed'] = json.loads(module['assignment'])

                # Parse Lesson data
                if module.get('modname') == 'lesson' and module.get('lesson'):
                    module['lesson_parsed'] = json.loads(module['lesson'])

    return data


def get_course_students(course_id: int, include_profile: bool = True, include_groups: bool = True, request=None):
    """
    Get students enrolled in a course.

    Args:
        course_id: Moodle course ID
        include_profile: Include extended profile fields (institution, department, phone, etc.)
        include_groups: Include user group memberships
        request: Optional FastAPI request for connection status tracking

    Returns:
        Dict with 'students' list and 'count'
    """
    return make_moodle_api_call(
        token=TOKEN,
        function='local_sm_estratoos_plugin_get_course_students',
        base_url=BASE_URL,
        method='POST',
        extra_params={
            'courseid': course_id,
            'includeprofile': 1 if include_profile else 0,
            'includegroups': 1 if include_groups else 0
        },
        request=request
    )


def get_course_teachers(course_id: int, include_profile: bool = True, include_groups: bool = False, request=None):
    """
    Get teachers assigned to a course with their specific role.

    Args:
        course_id: Moodle course ID
        include_profile: Include extended profile fields
        include_groups: Include user group memberships
        request: Optional FastAPI request for connection status tracking

    Returns:
        Dict with 'teachers' list (including role info) and 'count'
    """
    return make_moodle_api_call(
        token=TOKEN,
        function='local_sm_estratoos_plugin_get_course_teachers',
        base_url=BASE_URL,
        method='POST',
        extra_params={
            'courseid': course_id,
            'includeprofile': 1 if include_profile else 0,
            'includegroups': 1 if include_groups else 0
        },
        request=request
    )


def get_course_managers(
    course_id: int,
    include_profile: bool = True,
    include_category_managers: bool = True,
    include_company_managers: bool = True,
    request=None
):
    """
    Get managers for a course at course, category, and company levels.

    Args:
        course_id: Moodle course ID
        include_profile: Include extended profile fields
        include_category_managers: Include managers from parent categories
        include_company_managers: Include IOMAD company managers
        request: Optional FastAPI request for connection status tracking

    Returns:
        Dict with 'managers' list (including scope and role info) and 'count'
    """
    return make_moodle_api_call(
        token=TOKEN,
        function='local_sm_estratoos_plugin_get_course_managers',
        base_url=BASE_URL,
        method='POST',
        extra_params={
            'courseid': course_id,
            'includeprofile': 1 if include_profile else 0,
            'includecategorymanagers': 1 if include_category_managers else 0,
            'includecompanymanagers': 1 if include_company_managers else 0
        },
        request=request
    )


def get_course_participants_summary(course_id: int, request=None):
    """
    Get a complete summary of all participants in a course.

    Returns:
        Dict with students, teachers, managers and their counts
    """
    students_data = get_course_students(course_id, request=request)
    teachers_data = get_course_teachers(course_id, request=request)
    managers_data = get_course_managers(course_id, request=request)

    return {
        'students': students_data.get('students', []) if students_data else [],
        'student_count': students_data.get('count', 0) if students_data else 0,
        'teachers': teachers_data.get('teachers', []) if teachers_data else [],
        'teacher_count': teachers_data.get('count', 0) if teachers_data else 0,
        'managers': managers_data.get('managers', []) if managers_data else [],
        'manager_count': managers_data.get('count', 0) if managers_data else 0,
    }


# ============================================================================
# Usage Examples
# ============================================================================

def example_get_scorm_info(course_id: int):
    """Example: Extract SCORM slide information from course content."""
    content = get_course_content(course_id)

    if not content:
        print("Failed to fetch course content")
        return

    for course in content.get('courses', []):
        print(f"\nCourse: {course['fullname']}")

        for section in course.get('sections', []):
            for module in section.get('modules', []):
                if module.get('modname') == 'scorm' and module.get('scorm_parsed'):
                    scorm = module['scorm_parsed']
                    print(f"\n  SCORM: {scorm['name']}")
                    print(f"    SCOs (reliable): {scorm['scocount']}")
                    print(f"    Slides (detected): {scorm['slidescount']}")
                    print(f"    Detection method: {scorm['slidedetection']}")
                    print(f"    Authoring tool: {scorm.get('authoringtool') or 'Unknown'}")

                    # User progress if available
                    if scorm.get('userdata'):
                        print(f"    User attempts: {scorm['userdata'].get('attemptcount', 0)}")
                        print(f"    User grade: {scorm['userdata'].get('grade', 'N/A')}")


def example_list_participants(course_id: int):
    """Example: List all participants in a course by role."""
    summary = get_course_participants_summary(course_id)

    print(f"\n=== Course {course_id} Participants ===")
    print(f"Total: {summary['student_count']} students, {summary['teacher_count']} teachers, {summary['manager_count']} managers")

    print("\nTeachers:")
    for teacher in summary['teachers']:
        role_name = teacher['role']['name']
        print(f"  - {teacher['fullname']} ({role_name})")

    print("\nManagers:")
    for manager in summary['managers']:
        role_name = manager['role']['name']
        scope = manager['scope']
        print(f"  - {manager['fullname']} ({role_name}) - {scope} level")

    print(f"\nStudents ({summary['student_count']}):")
    for student in summary['students'][:5]:  # Show first 5
        groups = ', '.join([g['name'] for g in student.get('groups', [])])
        groups_str = f" [{groups}]" if groups else ""
        print(f"  - {student['fullname']}{groups_str}")

    if summary['student_count'] > 5:
        print(f"  ... and {summary['student_count'] - 5} more")


def example_get_quiz_questions(course_id: int):
    """Example: Extract quiz questions from course content."""
    content = get_course_content(course_id)

    if not content:
        return

    for course in content.get('courses', []):
        for section in course.get('sections', []):
            for module in section.get('modules', []):
                if module.get('modname') == 'quiz' and module.get('quiz_parsed'):
                    quiz = module['quiz_parsed']
                    print(f"\nQuiz: {quiz['name']}")
                    print(f"  Time limit: {quiz.get('timelimit', 0)} seconds")
                    print(f"  Questions: {len(quiz.get('questions', []))}")

                    for q in quiz.get('questions', []):
                        print(f"\n  Q{q['slot']}: {q['name']} ({q['qtype']})")
                        # For multichoice, show answers
                        if q['qtype'] == 'multichoice':
                            for ans in q.get('answers', []):
                                correct = "✓" if ans['fraction'] == 1 else "✗"
                                print(f"    {correct} {ans['answer'][:50]}...")
```

### PHP

```php
<?php
define('MOODLE_URL', 'https://moodle.example.com/webservice/rest/server.php');
define('TOKEN', 'your_token_here');

function callMoodleAPI($function, $params = []) {
    $params['wstoken'] = TOKEN;
    $params['wsfunction'] = $function;
    $params['moodlewsrestformat'] = 'json';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, MOODLE_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function getCourseContent($courseId) {
    return callMoodleAPI('local_sm_estratoos_plugin_get_course_content', [
        'courseids[0]' => $courseId,
        'options[includescormdetails]' => 1,
        'options[includequizquestions]' => 1,
        'options[includeuserdata]' => 1
    ]);
}

function getCourseStudents($courseId, $includeProfile = true, $includeGroups = true) {
    return callMoodleAPI('local_sm_estratoos_plugin_get_course_students', [
        'courseid' => $courseId,
        'includeprofile' => $includeProfile ? 1 : 0,
        'includegroups' => $includeGroups ? 1 : 0
    ]);
}

function getCourseTeachers($courseId, $includeProfile = true) {
    return callMoodleAPI('local_sm_estratoos_plugin_get_course_teachers', [
        'courseid' => $courseId,
        'includeprofile' => $includeProfile ? 1 : 0
    ]);
}

function getCourseManagers($courseId, $includeProfile = true) {
    return callMoodleAPI('local_sm_estratoos_plugin_get_course_managers', [
        'courseid' => $courseId,
        'includeprofile' => $includeProfile ? 1 : 0,
        'includecategorymanagers' => 1,
        'includecompanymanagers' => 1
    ]);
}

// Usage
$courseId = 2;

$content = getCourseContent($courseId);
$students = getCourseStudents($courseId);
$teachers = getCourseTeachers($courseId);
$managers = getCourseManagers($courseId);

echo "Course $courseId Summary:\n";
echo "  Students: {$students['count']}\n";
echo "  Teachers: {$teachers['count']}\n";
echo "  Managers: {$managers['count']}\n";

// Process SCORM modules
foreach ($content['courses'] as $course) {
    foreach ($course['sections'] as $section) {
        foreach ($section['modules'] as $module) {
            if ($module['modname'] === 'scorm' && !empty($module['scorm'])) {
                $scormData = json_decode($module['scorm'], true);
                echo "\nSCORM: {$scormData['name']}\n";
                echo "  SCOs: {$scormData['scocount']}\n";
                echo "  Slides: {$scormData['slidescount']} ({$scormData['slidedetection']})\n";
                echo "  Tool: " . ($scormData['authoringtool'] ?? 'Unknown') . "\n";
            }
        }
    }
}
```

---

## Error Handling

### Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| `invalidtoken` | Token is invalid or expired | Check token validity |
| `accessdenied` | User lacks capability | Ensure token user has `moodle/course:viewparticipants` |
| `invalidrecord` | Course doesn't exist | Verify course ID |
| `requireloginerror` | Token not provided | Include `wstoken` parameter |

### Example Error Response

```json
{
  "exception": "webservice_access_exception",
  "errorcode": "accessdenied",
  "message": "Access denied"
}
```

---

## Best Practices

1. **Parse JSON Fields**: Module-specific data (`scorm`, `quiz`, `assignment`, `lesson`, `forum`, `book`) are JSON-encoded strings. Always parse them.

2. **Check for null/empty**: Some fields may be null or empty depending on module configuration.

3. **Use File Tokens**: For accessing files (SCORM content, profile images), append `?token=YOUR_TOKEN` to URLs.

4. **Batch Requests**: For multiple courses, use `courseids[0]`, `courseids[1]`, etc. in get_course_content.

5. **IOMAD Awareness**: Results are automatically filtered by company when using company-scoped tokens.

6. **Slide Count Reliability**:
   - `scocount` is always reliable (from Moodle DB)
   - `slidescount` is best-effort (depends on authoring tool detection)
   - Check `slidedetection` to know how slides were counted

---

## Version Requirements

- **Plugin Version**: 1.7.76+
- **Moodle Version**: 4.1+
- **IOMAD** (optional): 5.0+ for company filtering

---

## Support

- **GitHub**: https://github.com/SmartmindTech/SM_Estratoos_plugin
- **Issues**: https://github.com/SmartmindTech/SM_Estratoos_plugin/issues
