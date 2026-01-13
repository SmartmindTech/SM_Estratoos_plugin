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
 * Export tokens to CSV or Excel.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

// Site administrators and company managers can access this page.
\local_sm_estratoos_plugin\util::require_token_admin();

// Get parameters.
$companyid = optional_param('companyid', 0, PARAM_INT);
$serviceid = optional_param('serviceid', 0, PARAM_INT);
$batchid = optional_param('batchid', '', PARAM_ALPHANUMEXT);
$includetoken = optional_param('includetoken', 0, PARAM_INT);
$format = optional_param('format', 'csv', PARAM_ALPHA);

// Check if site admin (for access control).
$issiteadmin = is_siteadmin();

// Get tokens based on filters.
$filters = [];
if ($serviceid > 0) {
    $filters['serviceid'] = $serviceid;
}
if (!empty($batchid)) {
    $filters['batchid'] = $batchid;
}

// Validate company access for non-site-admins.
if (!$issiteadmin) {
    $managedcompanies = \local_sm_estratoos_plugin\util::get_user_managed_companies();
    $managedids = array_keys($managedcompanies);

    if ($companyid > 0) {
        // Check if user can access this company.
        if (!in_array($companyid, $managedids)) {
            throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }
    } else {
        // Filter by all managed companies.
        $filters['companyids'] = $managedids;
    }
}

$tokens = \local_sm_estratoos_plugin\company_token_manager::get_company_tokens(
    $companyid > 0 ? $companyid : null,
    $filters
);

if (empty($tokens)) {
    redirect(
        new moodle_url('/local/sm_estratoos_plugin/manage.php'),
        get_string('notokens', 'local_sm_estratoos_plugin'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

if ($format === 'xlsx') {
    // Generate Excel file.
    \local_sm_estratoos_plugin\util::export_tokens_excel($tokens, (bool)$includetoken);
    exit;
} else {
    // Generate CSV.
    $csv = \local_sm_estratoos_plugin\util::export_tokens_csv($tokens, (bool)$includetoken);

    // Output CSV.
    $filename = 'sm_tokens_' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($csv));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $csv;
    exit;
}
