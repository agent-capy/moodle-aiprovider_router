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

use aiprovider_router\order_inspector;
use core\check\check;
use core\check\result;

/**
 * Shared behaviour for the router's status checks.
 *
 * The checks all describe the same thing from different angles, so they share one
 * order_inspector and cannot end up contradicting each other. A site with no router
 * instance is not misconfigured, it has simply not been set up yet, so every check
 * reports NA rather than a problem.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends check {
    /**
     * Constructor.
     *
     * @param order_inspector|null $inspector The inspector to read the site through.
     */
    public function __construct(
        /** @var order_inspector|null The injected inspector, if any. */
        protected ?order_inspector $inspector = null,
    ) {
        $this->inspector ??= new order_inspector();
    }

    #[\Override]
    public function get_name(): string {
        return get_string('check:' . $this->get_id(), 'aiprovider_router');
    }

    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/ai/provider/router/order.php'),
            get_string('order:heading', 'aiprovider_router'),
        );
    }

    #[\Override]
    public function get_result(): result {
        if ($this->inspector->get_primary_router() === null) {
            return new result(result::NA, get_string('check:norouter', 'aiprovider_router'));
        }

        return $this->check_router();
    }

    /**
     * Run the check, knowing that a router instance exists.
     *
     * @return result The outcome.
     */
    abstract protected function check_router(): result;
}
