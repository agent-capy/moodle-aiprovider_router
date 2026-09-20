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

namespace aiprovider_router\check;

use aiprovider_router\eligibility\profilefield;
use aiprovider_router\eligibility_policy;
use core\check\result;

/**
 * Checks whether people can admit themselves to bringing their own key.
 *
 * A policy written on a custom profile field is only as good as the field. Where the
 * person the field describes can put a value into it -- on their own profile, or on
 * the registration form -- the condition asks them whether they qualify, and they
 * answer. An administrator choosing "staff type is staff" is unlikely to mean that.
 *
 * It is not always wrong. "I understand what this costs" is a self declaration by
 * design, and so is a preference. So this reports rather than refuses, and says
 * which field and by which route, which is what an administrator needs in order to
 * decide whether it matters here.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byokeligibility extends base {
    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/ai/provider/router/byok.php'),
            get_string('byok:manage', 'aiprovider_router'),
        );
    }

    #[\Override]
    protected function check_router(): result {
        $policy = $this->get_policy();
        if ($policy->get_access() !== eligibility_policy::ACCESS_CONDITIONS) {
            // Nobody may bring one, or everybody may. Either way no field decides it.
            return new result(result::NA, get_string('check:byokeligibility:noconditions', 'aiprovider_router'));
        }

        $condition = $policy->get_conditions()['profilefield'] ?? null;
        if (!$condition instanceof profilefield || !$condition->is_self_declared()) {
            return new result(result::OK, get_string('check:byokeligibility:ok', 'aiprovider_router'));
        }

        $status = $policy->get_match() === eligibility_policy::MATCH_ALL ? result::INFO : result::WARNING;

        return new result(
            $status,
            get_string('check:byokeligibility:selfdeclared', 'aiprovider_router'),
            get_string(
                'check:byokeligibility:selfdeclared_details',
                'aiprovider_router',
                $condition->get_description(),
            ),
        );
    }

    /**
     * The policy to read.
     *
     * @return eligibility_policy The policy.
     */
    protected function get_policy(): eligibility_policy {
        return new eligibility_policy();
    }
}
