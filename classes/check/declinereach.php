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

use core\check\result;

/**
 * Checks whether a request the router turns down can still be answered by somebody else.
 *
 * Core walks its provider order until something succeeds and has no way of being told
 * that a failure was a decision rather than a fault, so an enabled provider listed after
 * the router will answer a declined request on the site's own key. The router works
 * around this by throwing, which stops the loop, but that is a setting an administrator
 * can turn off, and it is not something to be taken on trust either way.
 *
 * This check answers the question the rules, the budgets and the BYOK settings all rest
 * on: if the router says no, does the site actually stop?
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class declinereach extends base {
    #[\Override]
    protected function depends_on_provider_order(): bool {
        return true;
    }

    #[\Override]
    protected function check_router(): result {
        $following = $this->inspector->get_following_instances();
        if (!$following) {
            // Nothing behind the router, so there is nowhere for a declined request to
            // go, whatever the setting says.
            return new result(result::OK, get_string('check:declinereach:alone', 'aiprovider_router'));
        }

        $names = [];
        foreach ($following as $instance) {
            $names[] = s($instance->name);
        }
        $details = get_string(
            'check:declinereach:details',
            'aiprovider_router',
            implode(', ', $names),
        );

        if ($this->inspector->get_primary_router()->is_strict_decline()) {
            return new result(
                result::OK,
                get_string('check:declinereach:strict', 'aiprovider_router', count($following)),
                $details,
            );
        }

        return new result(
            result::WARNING,
            get_string('check:declinereach:bypassable', 'aiprovider_router', count($following)),
            $details,
        );
    }
}
