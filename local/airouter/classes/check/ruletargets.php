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

namespace local_airouter\check;

use local_airouter\rule_repository;
use local_airouter\target_resolver;
use core\check\result;

/**
 * Checks that every rule still has somewhere to send a request.
 *
 * A rule names its target by instance id, and nothing stops that instance being deleted
 * afterwards; the schema deliberately holds no foreign key, so that deleting a provider
 * instance in core cannot fail on this plugin's account. The cost of that choice is a
 * rule that quietly stops being honoured, which is what this reports.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ruletargets extends base {
    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/local/airouter/rules.php'),
            get_string('rules:manage', 'local_airouter'),
        );
    }

    #[\Override]
    protected function check_router(): result {
        global $DB;

        $targets = target_resolver::get_delegation_targets();
        $broken = [];
        foreach ((new rule_repository($DB))->get_all() as $rule) {
            if (!isset($targets[(int) $rule->get('targetid')])) {
                $broken[] = $rule->get('name');
            }
        }

        if (!$broken) {
            return new result(result::OK, get_string('check:ruletargets:ok', 'local_airouter'));
        }

        return new result(
            result::WARNING,
            get_string('check:ruletargets:found', 'local_airouter', count($broken)),
            get_string('check:ruletargets:found_details', 'local_airouter', s(implode(', ', $broken))),
        );
    }
}
