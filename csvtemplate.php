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
 * Template download for batch token creation (CSV or Excel).
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

// Site administrators and company managers can access this page.
\local_sm_estratoos_plugin\util::require_token_admin();

// Get format parameter.
$format = optional_param('format', 'csv', PARAM_ALPHA);

// Header row.
$header = ['id', 'username', 'email'];

// Example rows to show the expected format.
$examples = [
    ['2', 'john.doe', 'john.doe@example.com'],
    ['3', 'jane.smith', 'jane.smith@example.com'],
    ['', 'student1', ''],
    ['', '', 'user@example.com'],
];

// Instructions as comments.
$instructions = [
    get_string('csvtemplate_instructions', 'local_sm_estratoos_plugin'),
    get_string('csvtemplate_id_only', 'local_sm_estratoos_plugin'),
    get_string('csvtemplate_username_only', 'local_sm_estratoos_plugin'),
    get_string('csvtemplate_email_only', 'local_sm_estratoos_plugin'),
];

if ($format === 'xlsx') {
    // Generate Excel file using Moodle's Excel library.
    require_once($CFG->libdir . '/excellib.class.php');

    $filename = 'token_users_template.xlsx';

    // Create workbook.
    $workbook = new MoodleExcelWorkbook($filename);
    $worksheet = $workbook->add_worksheet(get_string('userselection', 'local_sm_estratoos_plugin'));

    // Format for header row.
    $formatheader = $workbook->add_format(['bold' => 1, 'bg_color' => '#4472C4', 'color' => 'white']);
    $formatinstruction = $workbook->add_format(['italic' => 1, 'color' => '#666666']);

    // Write instructions.
    $row = 0;
    foreach ($instructions as $instruction) {
        $worksheet->write_string($row, 0, $instruction, $formatinstruction);
        $row++;
    }
    $row++; // Empty row.

    // Write header.
    $col = 0;
    foreach ($header as $headeritem) {
        $worksheet->write_string($row, $col, $headeritem, $formatheader);
        $col++;
    }
    $row++;

    // Write example data.
    foreach ($examples as $example) {
        $col = 0;
        foreach ($example as $cell) {
            $worksheet->write_string($row, $col, $cell);
            $col++;
        }
        $row++;
    }

    // Set column widths.
    $worksheet->set_column(0, 0, 10); // id
    $worksheet->set_column(1, 1, 20); // username
    $worksheet->set_column(2, 2, 30); // email

    $workbook->close();
    exit;

} else {
    // Generate CSV file.
    $bom = "\xEF\xBB\xBF";
    $delimiter = ';';

    $csv = $bom;

    // Add instructions as comments.
    foreach ($instructions as $instruction) {
        $csv .= "# " . $instruction . "\n";
    }
    $csv .= "\n";

    // Header row.
    $csv .= implode($delimiter, $header) . "\n";

    // Add sample data rows.
    foreach ($examples as $row) {
        $csv .= implode($delimiter, $row) . "\n";
    }

    // Set headers for download.
    $filename = 'token_users_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $csv;
}
