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
 * Language strings for local_sm_estratoos_plugin.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General.
$string['pluginname'] = 'SmartMind Estratoos Plugin';
$string['plugindescription'] = 'Create and manage company-scoped API tokens for SmartMind - Estratoos multi-tenant installations.';

// Capabilities.
$string['sm_estratoos_plugin:managetokens'] = 'Manage all SmartMind tokens';
$string['sm_estratoos_plugin:managecompanytokens'] = 'Manage tokens for a company';
$string['sm_estratoos_plugin:createbatch'] = 'Create tokens in batch';
$string['sm_estratoos_plugin:viewreports'] = 'View token reports';
$string['sm_estratoos_plugin:export'] = 'Export tokens';
$string['sm_estratoos_plugin:createtokensapi'] = 'Create tokens via API';

// Dashboard.
$string['dashboard'] = 'Token and API Functions Management';
$string['dashboarddesc'] = 'Create and manage API tokens for your Moodle installation.';
$string['createadmintoken'] = 'Create Admin Token';
$string['createadmintokendesc'] = 'Create a system-wide token for the Moodle administrator with full access.';
$string['createcompanytokens'] = 'Create User Tokens';
$string['createcompanytokensdesc'] = 'Create API tokens for users in batch. In IOMAD mode, tokens are company-scoped and only return data for the selected company.';
$string['managetokens'] = 'Manage Tokens';
$string['managetokensdesc'] = 'View, edit, and revoke existing tokens.';

// Admin token page.
$string['admintoken'] = 'Admin Token';
$string['admintokendesc'] = 'Create a system-wide token for the site administrator. This token will have full access to all data.';
$string['createadmintokenbutton'] = 'Create Admin Token';
$string['admintokencreated'] = 'Admin token created successfully';
$string['admintokenwarning'] = 'Warning: This token provides full system access. Keep it secure!';

// Batch token page.
$string['batchtokens'] = 'Batch Token Creation';
$string['batchtokensdesc'] = 'Create tokens for multiple users at once with company-scoped access.';
$string['createbatchtokens'] = 'Create Batch Tokens';

// User selection.
$string['userselection'] = 'User Selection';
$string['selectionmethod'] = 'Selection method';
$string['bycompany'] = 'By company';
$string['bycsv'] = 'CSV upload';
$string['company'] = 'Company';
$string['selectcompany'] = 'Select company';
$string['department'] = 'Department';
$string['alldepartments'] = 'All departments';
$string['csvfile'] = 'CSV file';
$string['csvfield'] = 'CSV field for user identification';
$string['userid'] = 'User ID';
$string['csvhelp'] = 'Upload a CSV file with one user identifier per line. The first row can be a header.';
$string['csvhelp_help'] = 'The CSV file should contain one user identifier per line. You can use user IDs, usernames, or email addresses. If the first row is a header, it will be automatically skipped.';

// Service selection.
$string['serviceselection'] = 'Web Service';
$string['service'] = 'Service';
$string['selectservice'] = 'Select web service';
$string['noservicesenabled'] = 'No web services are enabled. Please enable at least one web service.';

// Token restrictions.
$string['tokenrestrictions'] = 'Token Restrictions';
$string['restricttocompany'] = 'Restrict to company';
$string['restricttocompany_desc'] = 'When enabled, API calls will only return data for the selected company.';
$string['restricttoenrolment'] = 'Restrict to enrollment';
$string['restricttoenrolment_desc'] = 'When enabled, users will only see courses they are enrolled in (in addition to company filtering).';

// Batch settings.
$string['batchsettings'] = 'Batch Settings';
$string['iprestriction'] = 'IP restriction';
$string['iprestriction_help'] = 'Enter allowed IP addresses or ranges (comma-separated). Leave empty for no restriction. Examples: 192.168.1.1, 10.0.0.0/8';
$string['validuntil'] = 'Valid until';
$string['validuntil_help'] = 'Set an expiration date for the tokens. Leave empty for tokens that never expire.';
$string['neverexpires'] = 'Never expires';

// Individual settings.
$string['individualoverrides'] = 'Individual Overrides';
$string['allowindividualoverrides'] = 'Allow individual token settings';
$string['allowindividualoverrides_desc'] = 'When enabled, you can modify IP restrictions and validity for individual tokens after creation.';

// Notes.
$string['notes'] = 'Notes';
$string['notes_help'] = 'Optional notes about this batch or token for administrative purposes.';

// Actions.
$string['createtokens'] = 'Create Tokens';
$string['cancel'] = 'Cancel';
$string['back'] = 'Back';
$string['revoke'] = 'Revoke';
$string['revokeselected'] = 'Revoke Selected';
$string['export'] = 'Export';
$string['exportselected'] = 'Export Selected';
$string['exportcsv'] = 'Export as CSV';
$string['edit'] = 'Edit';
$string['delete'] = 'Delete';
$string['apply'] = 'Apply';
$string['filter'] = 'Filter';

// Results.
$string['batchcomplete'] = 'Batch token creation complete';
$string['tokenscreated'] = '{$a} tokens created successfully';
$string['tokensfailed'] = '{$a} tokens failed to create';
$string['errors'] = 'Errors';
$string['createdtokens'] = 'Created Tokens';
$string['tokensshownonce'] = 'Token strings are shown only once. Make sure to save them before leaving this page.';
$string['batchid'] = 'Batch ID';
$string['createnewbatch'] = 'Create New Batch';
$string['recentbatches'] = 'Recent Batches';
$string['createdby'] = 'Created by';

// Token list.
$string['tokenlist'] = 'Token List';
$string['notokens'] = 'No tokens found';
$string['token'] = 'Token';
$string['tokens'] = 'tokens';
$string['user'] = 'User';
$string['restrictions'] = 'Restrictions';
$string['companyonly'] = 'Company only';
$string['enrolledonly'] = 'Enrolled only';

// Statistics.
$string['companytokens_stat'] = 'Tokens created for users associated with companies';
$string['stat_success'] = 'Success';
$string['stat_failed'] = 'Failed';
$string['lastaccess'] = 'Last access';
$string['actions'] = 'Actions';
$string['bulkactions'] = 'Bulk actions...';
$string['selectall'] = 'Select all';

// Confirmation messages.
$string['confirmrevoke'] = 'Are you sure you want to revoke this token? This action cannot be undone.';
$string['confirmrevokeselected'] = 'Are you sure you want to revoke the selected tokens? This action cannot be undone.';
$string['tokenrevoked'] = 'Token revoked successfully';
$string['tokensrevoked'] = '{$a} tokens revoked successfully';
$string['tokenstatusactive'] = 'Active';
$string['tokenstatussuspended'] = 'Suspended';

// Error messages.
$string['accessdenied'] = 'Access denied. Only site administrators can access this page.';
$string['invalidcompany'] = 'Invalid company selected';
$string['invalidservice'] = 'Invalid service selected';
$string['usernotincompany'] = 'User {$a->userid} is not a member of company {$a->companyid}';
$string['coursenotincompany'] = 'This course does not belong to your company';
$string['usernotenrolled'] = 'You are not enrolled in this course';
$string['forumnotincompany'] = 'This forum does not belong to your company';
$string['discussionnotincompany'] = 'This discussion does not belong to your company';
$string['invalidtoken'] = 'Invalid token';
$string['tokennotfound'] = 'Token not found';
$string['tokensuspended'] = 'This token has been suspended because the company access is disabled. Contact your administrator.';
$string['invalidiprestriction'] = 'Invalid IP restriction format';
$string['csverror'] = 'Error processing CSV file: {$a}';
$string['nousersfound'] = 'No users found matching the criteria';
$string['emptycsv'] = 'The CSV file is empty or contains no valid users';

// Settings.
$string['settings'] = 'SmartMind Tokens Settings';
$string['defaultvaliditydays'] = 'Default validity (days)';
$string['defaultvaliditydays_desc'] = 'Default number of days before tokens expire. Set to 0 for tokens that never expire.';
$string['cleanupexpiredtokens'] = 'Clean up expired tokens';
$string['cleanupexpiredtokens_desc'] = 'Automatically remove expired company token records during cron.';
$string['defaultrestricttocompany'] = 'Default: Restrict to company';
$string['defaultrestricttocompany_desc'] = 'Default value for company restriction when creating new tokens.';
$string['defaultrestricttoenrolment'] = 'Default: Restrict to enrollment';
$string['defaultrestricttoenrolment_desc'] = 'Default value for enrollment restriction when creating new tokens.';

// Privacy.
$string['privacy:metadata:local_sm_estratoos_plugin'] = 'Information about company-scoped tokens';
$string['privacy:metadata:local_sm_estratoos_plugin:tokenid'] = 'The ID of the external token';
$string['privacy:metadata:local_sm_estratoos_plugin:companyid'] = 'The company this token is scoped to';
$string['privacy:metadata:local_sm_estratoos_plugin:createdby'] = 'The user who created this token';
$string['privacy:metadata:local_sm_estratoos_plugin:timecreated'] = 'When the token was created';

// Tasks.
$string['task:cleanupexpiredtokens'] = 'Clean up expired company tokens';

// User selection.
$string['quickselect'] = 'Quick select';
$string['selectallusers'] = 'All Users';
$string['selectnone'] = 'None';
$string['selectstudents'] = 'Students';
$string['selectteachers'] = 'Teachers';
$string['selectmanagers'] = 'Managers';
$string['selectothers'] = 'Others';
$string['selectedusers'] = 'users selected';
$string['searchusers'] = 'Search users...';
$string['loadingusers'] = 'Loading users...';
$string['nousersselected'] = 'Please select at least one user';
$string['companymanager'] = 'Company Manager';

// Role name badges.
$string['role_student'] = 'Student';
$string['role_teacher'] = 'Teacher';
$string['role_manager'] = 'Manager';
$string['role_other'] = 'Other';

// Role names for token naming (uppercase, no accents).
$string['tokenrole_student'] = 'STUDENT';
$string['tokenrole_teacher'] = 'TEACHER';
$string['tokenrole_manager'] = 'MANAGER';
$string['tokenrole_other'] = 'OTHER';

// IOMAD detection.
$string['iomaddetected'] = 'IOMAD multi-tenant mode detected';
$string['standardmoodle'] = 'Standard Moodle mode (no companies)';
$string['moodlemode'] = 'Moodle Mode';

// Non-IOMAD mode.
$string['createusertokens'] = 'Create User Tokens';
$string['createusertokensdesc'] = 'Create API tokens for users in batch.';
$string['selectusers'] = 'Select Users';
$string['allusers'] = 'All users';
$string['searchandselect'] = 'Search and select users';
$string['nousersavailable'] = 'No users available';

// Update notifications.
$string['task:checkforupdates'] = 'Check for plugin updates';
$string['messageprovider:updatenotification'] = 'Plugin update notifications';
$string['updateavailable_subject'] = 'SmartMind Plugin update available: v{$a}';
$string['updateavailable_message'] = 'A new version of SmartMind - Estratoos Plugin is available.

Current version: {$a->currentversion}
New version: {$a->newversion}

To install the update, go to:
{$a->updateurl}';
$string['updateavailable_message_html'] = '<p>A new version of <strong>SmartMind - Estratoos Plugin</strong> is available.</p>
<table>
<tr><td><strong>Current version:</strong></td><td>{$a->currentversion}</td></tr>
<tr><td><strong>New version:</strong></td><td>{$a->newversion}</td></tr>
</table>
<p><a href="{$a->updateurl}" class="btn btn-primary">Install update</a></p>';

// Update page strings.
$string['checkforupdates'] = 'Check for updates';
$string['updateplugin'] = 'Update SmartMind Plugin';
$string['updateavailable'] = 'Update available';
$string['currentversion'] = 'Current version';
$string['newversion'] = 'New version';
$string['updateconfirm'] = 'Are you sure you want to update the SmartMind - Estratoos Plugin? The plugin files will be replaced with the latest version.';
$string['updatingplugin'] = 'Updating plugin...';
$string['downloadingupdate'] = 'Downloading update...';
$string['extractingupdate'] = 'Extracting files...';
$string['installingupdate'] = 'Installing update...';
$string['updatesuccessful'] = 'Update successful!';
$string['updatesuccessful_desc'] = 'The plugin has been updated. Click continue to complete the database upgrade.';
$string['updatefailed'] = 'Update failed';
$string['updatefetcherror'] = 'Could not fetch update information from the server.';
$string['alreadyuptodate'] = 'The plugin is already up to date.';
$string['downloadfailed'] = 'Failed to download the update package.';
$string['extractfailed'] = 'Failed to extract the update package.';
$string['installfailed'] = 'Failed to install the update.';

// Deletion history.
$string['deletionhistory'] = 'Deletion History';
$string['tokensdeleted'] = 'tokens deleted';
$string['deletedby'] = 'Deleted by';
$string['clicktoexpand'] = 'click to expand';

// Manual update instructions.
$string['manualupdate_title'] = 'Manual Update Required';
$string['manualupdate_intro'] = 'The automatic update could not be completed due to file permissions. Please follow these steps to update manually:';
$string['manualupdate_step1'] = 'Download the latest plugin version:';
$string['manualupdate_download'] = 'Download ZIP';
$string['manualupdate_step2'] = 'Go to Moodle\'s plugin installer:';
$string['manualupdate_installer'] = 'Open Plugin Installer';
$string['manualupdate_step3'] = 'Upload the ZIP file and follow the installation prompts.';
$string['manualupdate_cli_title'] = 'Alternative: Command Line (if you have server access):';

// Non-IOMAD batch description.
$string['batchtokensdesc_standard'] = 'Create API tokens for multiple users at once.';

// CSV template.
$string['downloadcsvtemplate'] = 'Download CSV Template';
$string['downloadexceltemplate'] = 'Download Excel Template';
$string['csvtemplate_instructions'] = 'Fill in one of the columns per row. You only need one identifier per user.';
$string['csvtemplate_id_only'] = 'To identify by ID: fill only the id column';
$string['csvtemplate_username_only'] = 'To identify by username: fill only the username column';
$string['csvtemplate_email_only'] = 'To identify by email: fill only the email column';

// File upload.
$string['uploadfile'] = 'Upload file (CSV or Excel)';
$string['exportexcel'] = 'Export as Excel';
$string['fileprocessingerrors'] = 'File processing errors';
$string['line'] = 'Line';

// Web service functions management.
$string['manageservices'] = 'Manage Web Services';
$string['manageservicesdesc'] = 'Add or remove functions from any web service, including built-in services like Moodle mobile.';
$string['servicefunctions'] = 'Service Functions';
$string['managefunctions'] = 'Manage Functions';
$string['component'] = 'Component';
$string['builtin'] = 'Built-in';
$string['noservices'] = 'No web services found.';
$string['functionsadded'] = 'Functions added successfully.';
$string['functionremoved'] = 'Function removed successfully.';
$string['removefunctionconfirm'] = 'Are you sure you want to remove the function "{$a->function}" from the service "{$a->service}"?';
$string['allfunctionsadded'] = 'All available functions have already been added to this service.';
$string['selectfunctionstoadd'] = 'Select the functions you want to add to this service. Hold Ctrl (or Cmd on Mac) to select multiple functions.';
$string['searchfunctions'] = 'Search functions...';
$string['functionselecthelp'] = 'Use Ctrl+Click to select multiple functions. Use Shift+Click to select a range.';

// API function tag (displayed in UI).
$string['apifunctiontag'] = 'SM Estratoos API Function';
$string['apifunctiontag_desc'] = 'This function is provided by the SmartMind Estratoos Plugin';

// Category-context function errors.
$string['usernotincompany'] = 'The specified user is not in the token\'s company.';
$string['categorynotincompany'] = 'The specified category is not in the company\'s category tree.';

// Health Check API strings.
$string['healthcheck'] = 'Health Check';
$string['healthcheck_desc'] = 'Lightweight connectivity check for SmartLearning platform';
$string['healthcheck_maintenance'] = 'Moodle is currently in maintenance mode. Please try again later.';
$string['healthcheck_invalid_token'] = 'Your session has expired or the token is invalid. Please reconnect your Moodle account.';
$string['healthcheck_user_suspended'] = 'Your user account has been suspended. Please contact your administrator.';
$string['healthcheck_internal_error'] = 'An unexpected error occurred. Please try again later.';

// Company access control.
$string['managecompanyaccess'] = 'Manage Company Access';
$string['managecompanyaccessdesc'] = 'Control which IOMAD companies can access the SmartMind Estratoos Plugin.';
$string['companiesaccessupdated'] = 'Company access settings updated successfully.';
$string['searchcompanies'] = 'Search companies...';
$string['deselectall'] = 'Deselect All';
$string['companiesenabled'] = 'companies enabled';
$string['nocompanies'] = 'No companies found.';
$string['noiomad'] = 'This feature is only available in IOMAD multi-tenant installations.';
$string['backtodashboard'] = 'Back to Dashboard';
$string['tokensuspended'] = 'Token suspended';
$string['tokenactive'] = 'Token active';
$string['companydisabled'] = 'Company disabled - tokens suspended';
$string['companyenabled'] = 'Company enabled - tokens reactivated';
$string['tokensuspendedwarning'] = 'This token is suspended because the company access has been disabled.';
