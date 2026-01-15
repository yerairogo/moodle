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

declare(strict_types=1);

namespace core_customfield\external;

use core_customfield\customfield\shared_handler;
use core_customfield\handler;
use core_customfield\category;
use core_external\external_api;
use core_external\external_value;
use core_external\external_function_parameters;

/**
 * External method for migrating custom field categories to shared categories.
 *
 * @package     core_customfield
 * @copyright   2026 Yerai Rodríguez <yerai.rodriguez@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class migrate_category extends external_api {
    /**
     * External method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category ID'),
            'component' => new external_value(PARAM_COMPONENT, 'Component'),
            'area' => new external_value(PARAM_AREA, 'Area'),
            'itemid' => new external_value(PARAM_INT, 'Item ID'),
        ]);
    }

    /**
     * External method execution
     *
     * @param int $categoryid
     * @param string $component
     * @param string $area
     * @param int $itemid
     * @return bool
     */
    public static function execute(int $categoryid, string $component, string $area, int $itemid): bool {
        [
            'categoryid' => $categoryid,
            'component' => $component,
            'area' => $area,
            'itemid' => $itemid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'component' => $component,
            'area' => $area,
            'itemid' => $itemid,
        ]);

        // Validate context.
        $context = \core\context\system::instance();
        self::validate_context($context);

        $handler = handler::get_handler($component, $area, $itemid);
        $sharedhandler = shared_handler::get_handler('core_customfield', 'shared');
        if (!$handler->can_configure() || !$sharedhandler->can_configure()) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('customfield:configureshared', 'core_role'));
        }

        // Get the short names of the custom fields in the category to migrate.
        $migratingcategory = null;
        foreach ($handler->get_categories_with_fields() as $category) {
            if ($category->get('id') == $categoryid) {
                $migratingcategory = $category;
                break;
            }
        }
        $migratingcategoryfields = array_map(fn($field) => $field->get('shortname'), $migratingcategory->get_fields());

        // Get the short names of the existing shared custom fields.
        $sharedfieldnames = array_map(fn($field) => $field->get('shortname'), $sharedhandler->get_fields());

        // Make sure no other shared custom fields with the same short name exist.
        $duplicatefields = array_intersect($migratingcategoryfields, $sharedfieldnames);
        if (!empty($duplicatefields)) {
            throw new \moodle_exception(
                errorcode: 'sharedcustomfieldalreadyexists',
                module: 'core_customfield',
                a: implode(', ', $duplicatefields),
            );
        }

        // Migrate category to shared.
        $record = new category($categoryid);
        $record->set_many([
            'component' => 'core_customfield',
            'area' => 'shared',
            'shared' => 1,
        ]);
        $record->save();

        // Enable the shared category for the current component and area.
        toggle_shared_category::execute($categoryid, $component, $area, $itemid, true);

        return true;
    }

    /**
     * External method return value
     *
     * @return external_value
     */
    public static function execute_returns(): external_value {
        return new external_value(PARAM_BOOL);
    }
}
