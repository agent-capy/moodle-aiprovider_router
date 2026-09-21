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

namespace local_airouter;

use core_ai\aiactions\base as action_base;
use core_ai\aiactions\responses\response_base;

/**
 * Builds the response object an action expects, for a request that failed.
 *
 * The router has to answer in whatever type the action declares, including actions
 * defined outside core. Guessing the class from the action's basename would build a
 * name that does not exist for those, so the action is asked: get_response_classname()
 * has been part of the action contract since 5.0 and each action names its own type.
 *
 * The constructor of that type is not the same in every release. Moodle 5.1 added an
 * error name between the code and the message, so passing arguments by position would
 * put the message in the wrong field on one release or the other. They are passed by
 * name, and the name that does not exist yet is left out.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response_factory {
    /** @var array<string, bool> Whether a response class takes an error name, by class. */
    protected static array $takeserror = [];

    /**
     * A failed response of the type the action declares.
     *
     * @param action_base $action The action that failed.
     * @param int $errorcode An HTTP style status code.
     * @param string $error The short stable error name, for the administrator.
     * @param string $errormessage The message shown to the person who asked.
     * @return response_base The response.
     */
    public static function failure(
        action_base $action,
        int $errorcode,
        string $error,
        string $errormessage,
    ): response_base {
        $classname = $action::get_response_classname();

        $args = [
            'success' => false,
            'errorcode' => $errorcode,
            'errormessage' => $errormessage,
        ];
        if (self::takes_error_name($classname)) {
            $args['error'] = $error;
        }

        return new $classname(...$args);
    }

    /**
     * Whether this release's response class carries a separate error name.
     *
     * @param string $classname The response class.
     * @return bool True when the constructor accepts an error name.
     */
    protected static function takes_error_name(string $classname): bool {
        if (isset(self::$takeserror[$classname])) {
            return self::$takeserror[$classname];
        }

        $takes = false;
        $constructor = (new \ReflectionClass($classname))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === 'error') {
                $takes = true;
                break;
            }
        }
        self::$takeserror[$classname] = $takes;

        return $takes;
    }
}
