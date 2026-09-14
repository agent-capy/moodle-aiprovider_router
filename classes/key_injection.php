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

namespace aiprovider_router;

use core_ai\provider as ai_provider;

/**
 * The outcome of trying to pay for a request with somebody's own key.
 *
 * Carries the target to use when there is one, so that callers never have to ask twice,
 * and the key it was paid with, which the history records alongside who paid.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_injection {
    /**
     * Constructor.
     *
     * @param key_status $status What happened.
     * @param ai_provider|null $target The instance to delegate to, when there is one.
     * @param key|null $key The key that was used.
     */
    public function __construct(
        /** @var key_status What happened. */
        public readonly key_status $status,
        /** @var ai_provider|null The instance carrying the key. */
        public readonly ?ai_provider $target = null,
        /** @var key|null The key it carries. */
        public readonly ?key $key = null,
    ) {
    }

    /**
     * Whether there is a target to send the request to.
     *
     * @return bool True when the key was found and applied.
     */
    public function is_usable(): bool {
        return $this->status === key_status::OK && $this->target !== null;
    }
}
