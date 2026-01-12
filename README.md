# SmartMind - Estratoos Plugin

A Moodle local plugin for SmartMind - Estratoos that provides batch API token creation capabilities, plus forum management functions. Works with both **IOMAD (multi-tenant)** and **standard Moodle** installations.

## Features

- **Batch Token Creation**: Create API tokens for hundreds/thousands of users at once
- **IOMAD & Standard Moodle Support**: Automatically detects if IOMAD is installed and adapts the UI
- **Company-Scoped API Responses** (IOMAD only): Tokens automatically filter results to the user's company
- **Enrollment Filtering**: Optionally restrict access to only enrolled courses
- **Admin System-Wide Tokens**: Create standard tokens for site administrators
- **User Selection**: Select specific users with role-based quick select (Students, Teachers, Managers)
- **Forum Management**: API functions to create, edit, and delete forums and discussions
- **Automatic Updates**: Receive update notifications through Moodle's plugin manager (via GitHub)
- **Multi-Language**: English, Spanish, and Portuguese translations included
- **Easy Installation**: Install via Moodle's plugin installer (ZIP upload)

## Requirements

- Moodle 4.1 or higher
- PHP 8.0 or higher
- IOMAD 5.0 or higher *(optional - plugin works without IOMAD)*

## Installation

### Method 1: Via Moodle Admin Interface (Recommended)

1. Download the `sm_estratoos_plugin.zip` file
2. Log in to Moodle as Site Administrator
3. Go to **Site Administration > Plugins > Install plugins**
4. Drag and drop the ZIP file or click "Choose a file"
5. Click **"Install plugin from ZIP file"**
6. Review the validation and click **"Continue"**
7. Follow the upgrade prompts to complete installation

### Method 2: Manual Installation

1. Extract the ZIP file
2. Upload the `sm_estratoos_plugin` folder to `/path/to/moodle/local/`
3. Go to **Site Administration > Notifications**
4. Moodle will detect the new plugin and prompt for installation

## Usage

### Accessing the Plugin

Only **Site Administrators** can access this plugin.

1. Go to **Site Administration > Plugins > Local plugins > SmartMind - Estratoos Plugin**
2. Or navigate directly to `/local/sm_estratoos_plugin/`

### Creating Admin Tokens

Admin tokens are system-wide tokens with full access (standard Moodle behavior):

1. Click **"Create Admin Token"** on the dashboard
2. Select the web service
3. Optionally set IP restriction and expiration
4. Click **"Create Admin Token"**
5. Copy the generated token (shown only once!)

### Creating Company Tokens (Batch)

Company tokens are scoped to a specific company:

1. Click **"Create Company Tokens"** on the dashboard
2. Choose user selection method:
   - **By Company**: Select users from a company with role filters
   - **CSV Upload**: Upload a CSV file with user IDs, usernames, or emails
3. Select individual users or use quick-select buttons (All, Students, Teachers, Managers)
4. Select the web service
5. Configure restrictions:
   - **Restrict to company**: API calls return only company data
   - **Restrict to enrollment**: Also filter by course enrollment
6. Set batch settings (IP restriction, expiration)
7. Click **"Create Tokens"**
8. Download or copy the generated tokens

### Managing Tokens

1. Click **"Manage Tokens"** on the dashboard
2. Filter by company or service
3. View token details (user, company, restrictions, last access)
4. Revoke individual tokens or bulk revoke selected tokens
5. Export tokens to CSV

## API Functions

### Token Management Functions

| Function | Description |
|----------|-------------|
| `local_sm_estratoos_plugin_create_batch` | Create tokens for multiple users |
| `local_sm_estratoos_plugin_get_tokens` | Get tokens for a company |
| `local_sm_estratoos_plugin_revoke` | Revoke tokens |
| `local_sm_estratoos_plugin_get_company_users` | Get users for a company |
| `local_sm_estratoos_plugin_get_companies` | Get list of companies |
| `local_sm_estratoos_plugin_get_services` | Get available web services |
| `local_sm_estratoos_plugin_create_admin_token` | Create admin token |
| `local_sm_estratoos_plugin_get_batch_history` | Get batch operation history |

### Forum Functions

| Function | Description |
|----------|-------------|
| `local_sm_estratoos_plugin_forum_create` | Create a new forum in a course |
| `local_sm_estratoos_plugin_forum_edit` | Edit forum settings |
| `local_sm_estratoos_plugin_forum_delete` | Delete a forum |
| `local_sm_estratoos_plugin_discussion_edit` | Edit a discussion |
| `local_sm_estratoos_plugin_discussion_delete` | Delete a discussion |

## How Company Filtering Works

When a user makes an API call with a company-scoped token:

1. The plugin intercepts the web service response
2. It looks up the token's company from the `local_sm_estratoos_plugin` table
3. It filters the results to only include:
   - Courses belonging to the company (`company_course` table)
   - Users belonging to the company (`company_users` table)
   - Categories belonging to the company

### Filtered Web Services

| Function | What's Filtered |
|----------|-----------------|
| `core_course_get_courses` | Returns only company courses |
| `core_course_get_categories` | Returns only company category and subcategories |
| `core_user_get_users` | Returns only company users |
| `core_user_get_users_by_field` | Returns only company users |
| `core_enrol_get_enrolled_users` | Returns only company users enrolled in the course |
| `core_enrol_get_users_courses` | Returns only company courses the user is enrolled in |

## Settings

Configure default behavior at **Site Administration > Plugins > Local plugins > SmartMind - Estratoos Plugin**:

- **Default validity (days)**: How long tokens are valid by default
- **Default: Restrict to company**: Default value for company restriction
- **Default: Restrict to enrollment**: Default value for enrollment restriction
- **Allow individual overrides**: Allow editing individual token settings
- **Clean up expired tokens**: Automatically remove expired token records

## Database Tables

### `local_sm_estratoos_plugin`

Links standard `external_tokens` to company context:

| Field | Description |
|-------|-------------|
| tokenid | Reference to external_tokens.id |
| companyid | Company this token is scoped to |
| batchid | UUID grouping tokens from same batch |
| restricttocompany | Filter results by company |
| restricttoenrolment | Also filter by enrollment |
| iprestriction | IP restriction override |
| validuntil | Expiration override |

### `local_sm_estratoos_plugin_batch`

Tracks batch creation operations:

| Field | Description |
|-------|-------------|
| batchid | Unique batch identifier |
| companyid | Company ID |
| serviceid | Web service ID |
| totalusers | Total users in batch |
| successcount | Successful creations |
| failcount | Failed creations |
| source | 'company' or 'csv' |

## Troubleshooting

### Token not filtering results

1. Verify the token exists in `local_sm_estratoos_plugin` table
2. Check `restricttocompany` is set to 1
3. Ensure the company has courses in `company_course` table

### User not found in CSV

1. Check the CSV field selection matches your data
2. Ensure users exist and are not deleted
3. Verify users belong to the selected company

### Access denied errors

1. Only site administrators can use this plugin
2. Check user has `is_siteadmin()` status

## IOMAD vs Standard Moodle

The plugin automatically detects whether IOMAD is installed:

### IOMAD Mode (Multi-tenant)
- Shows company selection dropdown
- Enables company-scoped token filtering
- Users can be selected by company/department
- API responses are filtered by company

### Standard Moodle Mode
- No company fields are shown
- Users are selected directly from all Moodle users
- Tokens work like standard Moodle tokens
- Role-based filtering still available (Students, Teachers, Managers)

## Automatic Updates

This plugin supports automatic update notifications through Moodle's plugin manager.

### How It Works

1. The plugin checks `https://raw.githubusercontent.com/SmartmindTech/SM_Estratoos_plugin/main/update.xml` for updates
2. When a new version is available, Moodle shows a notification in the admin area
3. Administrators can download and install the update directly

### For Developers: Releasing a New Version

To release a new version:

1. Update `version.php`:
   - Increment `$plugin->version` (YYYYMMDDXX format)
   - Update `$plugin->release` (semantic version)

2. Update `update.xml`:
   - Update `<version>` to match version.php
   - Update `<release>` to match version.php
   - Update `<download>` URL to point to the new release
   - Update `<releasenotes>` with changes

3. Create a GitHub release:
   - Tag: `v1.x.x` (matching the release version)
   - Upload `sm_estratoos_plugin.zip` as release asset

4. Commit and push changes to main branch

### Example Release Process

```bash
# 1. Update version.php and update.xml
# 2. Create ZIP package
cd /path/to/moodle/local
zip -r sm_estratoos_plugin.zip sm_estratoos_plugin/

# 3. Commit changes
git add .
git commit -m "Release v1.2.0"
git tag v1.2.0
git push origin main --tags

# 4. Create GitHub release and upload ZIP
```

## License

This plugin is licensed under the GNU GPL v3 or later.

## Credits

Developed by SmartMind Technologies.
