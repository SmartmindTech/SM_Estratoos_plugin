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
 * Template download for user creation (CSV or Excel).
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
$header = [
    'firstname', 'lastname', 'email', 'username', 'password',
    'phone_intl_code', 'phone', 'birthdate', 'city',
    'state_province', 'country', 'timezone',
];

// Example rows.
$examples = [
    ['John', 'Doe', 'john@example.com', '', '', '+1', '5551234567', '1990-05-15', 'New York', 'New York', 'United States', 'America/New_York'],
    ['Jane', 'Smith', 'jane@example.com', '', '', '+55', '11999998888', '1985-12-25', 'São Paulo', 'São Paulo', 'Brazil', ''],
    ['Carlos', 'García', 'carlos@example.com', '', '', '+34', '612345678', '1992-03-10', 'Madrid', 'Madrid', 'España', ''],
];

// Instructions.
$instructions = [
    get_string('csvtemplate_users_instructions', 'local_sm_estratoos_plugin'),
];

if ($format === 'xlsx') {
    // Generate Excel file using Moodle's Excel library.
    require_once($CFG->libdir . '/excellib.class.php');

    $filename = 'create_users_template.xlsx';

    // Create workbook.
    $workbook = new MoodleExcelWorkbook($filename);
    $worksheet = $workbook->add_worksheet(get_string('createusers', 'local_sm_estratoos_plugin'));

    // Formats.
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
    $worksheet->set_column(0, 0, 15);  // firstname
    $worksheet->set_column(1, 1, 15);  // lastname
    $worksheet->set_column(2, 2, 25);  // email
    $worksheet->set_column(3, 3, 15);  // username
    $worksheet->set_column(4, 4, 15);  // password
    $worksheet->set_column(5, 5, 15);  // phone_intl_code
    $worksheet->set_column(6, 6, 15);  // phone
    $worksheet->set_column(7, 7, 12);  // birthdate
    $worksheet->set_column(8, 8, 15);  // city
    $worksheet->set_column(9, 9, 15);  // state_province
    $worksheet->set_column(10, 10, 15); // country
    $worksheet->set_column(11, 11, 20); // timezone

    $workbook->close();
    exit;

} else {
    // Generate CSV file.
    $bom = "\xEF\xBB\xBF";
    $delimiter = ',';

    $csv = $bom;

    // Add instructions as comments.
    foreach ($instructions as $instruction) {
        $csv .= "# " . $instruction . "\n";
    }
    $csv .= "\n";

    // Header row.
    $csv .= implode($delimiter, $header) . "\n";

    // Example data rows.
    foreach ($examples as $row) {
        // Quote fields that may contain commas or special chars.
        $quoted = array_map(function($field) {
            if (strpos($field, ',') !== false || strpos($field, '"') !== false) {
                return '"' . str_replace('"', '""', $field) . '"';
            }
            return $field;
        }, $row);
        $csv .= implode($delimiter, $quoted) . "\n";
    }

    // Set headers for download.
    $filename = 'create_users_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $csv;
}
