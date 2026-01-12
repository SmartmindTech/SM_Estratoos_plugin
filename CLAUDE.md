# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SmartMind - Estratoos Plugin is a Moodle local plugin (`local_sm_estratoos_plugin`) that provides batch API token creation with company-scoped filtering for IOMAD multi-tenant environments. It also works with standard Moodle installations (without IOMAD).

**Requirements:** Moodle 4.1+, PHP 8.0+, optionally IOMAD 5.0+

## Architecture

### Core Components

- **`company_token_manager.php`** - Main business logic for token CRUD operations, batch creation, and CSV user import
- **`webservice_filter.php`** - Intercepts web service responses and filters results by company (courses, users, categories)
- **`util.php`** - Utilities including IOMAD detection (`is_iomad_installed()`), token extraction from requests, CSV export
- **`lib.php`** - Web service hooks (`local_sm_estratoos_plugin_post_processor`) that apply company filtering

### Web Service Functions

External API functions are defined in `db/services.php` and implemented in `classes/external/`:

| Function | Class |
|----------|-------|
| `local_sm_estratoos_plugin_create_batch` | `create_batch_tokens.php` |
| `local_sm_estratoos_plugin_get_tokens` | `get_company_tokens.php` |
| `local_sm_estratoos_plugin_revoke` | `revoke_company_tokens.php` |
| `local_sm_estratoos_plugin_forum_*` | `forum_functions.php` |

### Company-Scoped Token Flow

1. Token created via `company_token_manager::create_token()` with company ID
2. Metadata stored in `local_sm_estratoos_plugin` table linking to `external_tokens`
3. On API call, `lib.php::local_sm_estratoos_plugin_post_processor()` intercepts response
4. `webservice_filter` filters results based on `company_course`, `company_users` IOMAD tables

### Database Tables

- `local_sm_estratoos_plugin` - Links standard tokens to company context (tokenid, companyid, restrictions)
- `local_sm_estratoos_plugin_batch` - Tracks batch creation operations with success/fail counts

## Development

### Plugin Installation Path

This plugin must be installed at `/path/to/moodle/local/sm_estratoos_plugin/`

### Version Updates

When releasing a new version:
1. Update `version.php`: increment `$plugin->version` (YYYYMMDDXX format) and `$plugin->release`
2. Update `update.xml`: match version, release, download URL, and release notes
3. Create GitHub release with tag `v1.x.x` and upload ZIP

### Key Patterns

- IOMAD detection is cached in `util::$isiomad` - check with `util::is_iomad_installed()`
- Forms are in `classes/form/` (batch_token_form, admin_token_form, individual_token_form)
- Language strings in `lang/{en,es,pt_br}/local_sm_estratoos_plugin.php`
- AMD JavaScript module in `amd/src/userselection.js` for user selection UI

### Moodle Conventions

- All PHP files must start with `defined('MOODLE_INTERNAL') || die();`
- External functions use Moodle's external API pattern: `_parameters()`, `execute()`, `_returns()`
- Capabilities defined in `db/access.php`
- Scheduled tasks in `db/tasks.php` (e.g., `cleanup_expired_tokens`)
