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

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use core_course_category;

/**
 * Get course categories, scoped to token's company.
 *
 * This function works with category-scoped tokens (CONTEXT_COURSECAT) and returns
 * only categories that belong to the token's company category tree. It mirrors
 * core_course_get_categories but validates against category context instead of system context.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_categories extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'criteria' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHA,
                        'The category column to search: id, ids (comma-separated), name, parent, idnumber, visible, theme'),
                    'value' => new external_value(PARAM_RAW, 'The value to match'),
                ]),
                'Criteria to filter categories (empty = all company categories)',
                VALUE_DEFAULT,
                []
            ),
            'addsubcategories' => new external_value(PARAM_BOOL,
                'Return subcategories (1 - default) or only matching categories (0)',
                VALUE_DEFAULT,
                true
            ),
        ]);
    }

    /**
     * Get categories, filtered to the token's company category tree.
     *
     * @param array $criteria Search criteria.
     * @param bool $addsubcategories Whether to include subcategories.
     * @return array Array of category objects.
     */
    public static function execute(array $criteria = [], bool $addsubcategories = true): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'criteria' => $criteria,
            'addsubcategories' => $addsubcategories,
        ]);

        // Get token and company restrictions.
        $token = \local_sm_estratoos_plugin\util::get_current_request_token();
        if (!$token) {
            throw new \moodle_exception('invalidtoken', 'webservice');
        }

        $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
        if (!$restrictions || !$restrictions->companyid) {
            throw new \moodle_exception('invalidtoken', 'local_sm_estratoos_plugin');
        }

        // Get company and validate at CATEGORY context (works with company-scoped tokens).
        $company = $DB->get_record('company', ['id' => $restrictions->companyid], '*', MUST_EXIST);
        $companycontext = \context_coursecat::instance($company->category);
        self::validate_context($companycontext);

        // Get company's category record to know its path.
        $companycategory = $DB->get_record('course_categories', ['id' => $company->category], '*', MUST_EXIST);

        // Build base query to get categories within company's tree.
        // Categories in the tree have a path that starts with company category's path.
        $basesql = "SELECT cc.*
                    FROM {course_categories} cc
                    WHERE (cc.id = :companycat OR cc.path LIKE :pathpattern)";
        $baseparams = [
            'companycat' => $company->category,
            'pathpattern' => $companycategory->path . '/%',
        ];

        // Apply criteria filters.
        $conditions = [];
        $critparams = [];

        foreach ($params['criteria'] as $crit) {
            $key = trim($crit['key']);
            $value = $crit['value'];

            switch ($key) {
                case 'id':
                    $value = clean_param($value, PARAM_INT);
                    $conditions[] = "cc.id = :critid";
                    $critparams['critid'] = $value;
                    break;

                case 'ids':
                    $value = clean_param($value, PARAM_SEQUENCE);
                    $ids = explode(',', $value);
                    if (!empty($ids)) {
                        list($idsql, $idparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'critids');
                        $conditions[] = "cc.id {$idsql}";
                        $critparams = array_merge($critparams, $idparams);
                    }
                    break;

                case 'name':
                    $value = clean_param($value, PARAM_TEXT);
                    $conditions[] = "cc.name = :critname";
                    $critparams['critname'] = $value;
                    break;

                case 'parent':
                    $value = clean_param($value, PARAM_INT);
                    $conditions[] = "cc.parent = :critparent";
                    $critparams['critparent'] = $value;
                    break;

                case 'idnumber':
                    // Check capability for idnumber search.
                    if (has_capability('moodle/category:manage', $companycontext)) {
                        $value = clean_param($value, PARAM_RAW);
                        $conditions[] = "cc.idnumber = :critidnumber";
                        $critparams['critidnumber'] = $value;
                    } else {
                        throw new \moodle_exception('criteriaerror', 'webservice', '', null,
                            'You don\'t have the permissions to search on the "idnumber" field.');
                    }
                    break;

                case 'visible':
                    // Check capability for visible search.
                    if (has_capability('moodle/category:viewhiddencategories', $companycontext)) {
                        $value = clean_param($value, PARAM_INT);
                        $conditions[] = "cc.visible = :critvisible";
                        $critparams['critvisible'] = $value;
                    } else {
                        throw new \moodle_exception('criteriaerror', 'webservice', '', null,
                            'You don\'t have the permissions to search on the "visible" field.');
                    }
                    break;

                case 'theme':
                    // Check capability for theme search.
                    if (has_capability('moodle/category:manage', $companycontext)) {
                        $value = clean_param($value, PARAM_THEME);
                        $conditions[] = "cc.theme = :crittheme";
                        $critparams['crittheme'] = $value;
                    } else {
                        throw new \moodle_exception('criteriaerror', 'webservice', '', null,
                            'You don\'t have the permissions to search on the "theme" field.');
                    }
                    break;

                default:
                    // Unknown criteria key - skip it.
                    break;
            }
        }

        // Build final SQL.
        $sql = $basesql;
        if (!empty($conditions)) {
            $sql .= ' AND (' . implode(' AND ', $conditions) . ')';
        }
        $sql .= ' ORDER BY cc.sortorder ASC';

        $allparams = array_merge($baseparams, $critparams);
        $categories = $DB->get_records_sql($sql, $allparams);

        // If addsubcategories is true and we have specific criteria, include subcategories.
        if ($params['addsubcategories'] && !empty($params['criteria'])) {
            $matchedids = array_keys($categories);
            if (!empty($matchedids)) {
                // Get subcategories of matched categories.
                foreach ($matchedids as $catid) {
                    $cat = $categories[$catid];
                    $subsql = "SELECT cc.*
                               FROM {course_categories} cc
                               WHERE cc.path LIKE :subpath
                                 AND cc.id != :catid
                                 AND (cc.id = :companycat2 OR cc.path LIKE :pathpattern2)
                               ORDER BY cc.sortorder ASC";
                    $subparams = [
                        'subpath' => $cat->path . '/%',
                        'catid' => $catid,
                        'companycat2' => $company->category,
                        'pathpattern2' => $companycategory->path . '/%',
                    ];
                    $subcats = $DB->get_records_sql($subsql, $subparams);
                    foreach ($subcats as $subcat) {
                        if (!isset($categories[$subcat->id])) {
                            $categories[$subcat->id] = $subcat;
                        }
                    }
                }
            }
        }

        // Build the result array.
        $result = [];
        foreach ($categories as $category) {
            // Verify the category is visible to the user.
            try {
                $catcontext = \context_coursecat::instance($category->id);
                if (!core_course_category::can_view_category($category)) {
                    continue;
                }
            } catch (\Exception $e) {
                continue;
            }

            $categoryinfo = [
                'id' => (int)$category->id,
                'name' => \core_external\util::format_string($category->name, $catcontext),
                'parent' => (int)$category->parent,
                'sortorder' => (int)$category->sortorder,
                'coursecount' => (int)$category->coursecount,
                'depth' => (int)$category->depth,
                'path' => $category->path,
            ];

            // Format description.
            list($categoryinfo['description'], $categoryinfo['descriptionformat']) =
                \core_external\util::format_text(
                    $category->description,
                    $category->descriptionformat,
                    $catcontext,
                    'coursecat',
                    'description',
                    null
                );

            // Additional fields for users with manage capability.
            if (has_capability('moodle/category:manage', $catcontext)) {
                $categoryinfo['idnumber'] = $category->idnumber ?? '';
                $categoryinfo['visible'] = (int)$category->visible;
                $categoryinfo['visibleold'] = (int)$category->visibleold;
                $categoryinfo['timemodified'] = (int)$category->timemodified;
                $categoryinfo['theme'] = clean_param($category->theme ?? '', PARAM_THEME);
            }

            $result[] = $categoryinfo;
        }

        // Sort by sortorder.
        usort($result, function($a, $b) {
            return $a['sortorder'] <=> $b['sortorder'];
        });

        return $result;
    }

    /**
     * Describes the return value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category ID'),
                'name' => new external_value(PARAM_RAW, 'Category name'),
                'description' => new external_value(PARAM_RAW, 'Category description', VALUE_OPTIONAL),
                'descriptionformat' => new external_value(PARAM_INT, 'Description format', VALUE_OPTIONAL),
                'parent' => new external_value(PARAM_INT, 'Parent category ID'),
                'sortorder' => new external_value(PARAM_INT, 'Sort order'),
                'coursecount' => new external_value(PARAM_INT, 'Number of courses in this category'),
                'depth' => new external_value(PARAM_INT, 'Category depth'),
                'path' => new external_value(PARAM_RAW, 'Category path'),
                // Admin-only fields.
                'idnumber' => new external_value(PARAM_RAW, 'Category ID number', VALUE_OPTIONAL),
                'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)', VALUE_OPTIONAL),
                'visibleold' => new external_value(PARAM_INT, 'Previous visibility state', VALUE_OPTIONAL),
                'timemodified' => new external_value(PARAM_INT, 'Last modification timestamp', VALUE_OPTIONAL),
                'theme' => new external_value(PARAM_TEXT, 'Category theme', VALUE_OPTIONAL),
            ])
        );
    }
}
