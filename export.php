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
 * Export tokens to CSV.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

// Only site administrators can access this page.
if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
}

// Get parameters.
$companyid = optional_param('companyid', 0, PARAM_INT);
$serviceid = optional_param('serviceid', 0, PARAM_INT);
$batchid = optional_param('batchid', '', PARAM_ALPHANUMEXT);
$includetoken = optional_param('includetoken', 0, PARAM_INT);

// Get tokens based on filters.
$filters = [];
if ($serviceid > 0) {
    $filters['serviceid'] = $serviceid;
}
if (!empty($batchid)) {
    $filters['batchid'] = $batchid;
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

// Generate CSV.
$csv = \local_sm_estratoos_plugin\util::export_tokens_csv($tokens, (bool)$includetoken);

// Output CSV.
$filename = 'sm_estratoos_plugin_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($csv));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $csv;
exit;
