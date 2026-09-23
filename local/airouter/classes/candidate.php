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

use core_ai\provider as ai_provider;

/**
 * Somewhere a request could go, and whose money would pay for it.
 *
 * The two travel together because they cannot be worked out apart. An instance carrying
 * a brought key is a copy of the one the administrator configured, so the instance alone
 * no longer says who is paying, and who is paying decides what may be tried next when it
 * fails and what the person is told when it does.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class candidate {
    /**
     * Constructor.
     *
     * @param ai_provider $target The instance to delegate to, carrying the key if there is one.
     * @param string $keysource Whose key pays for it.
     * @param key|null $key The key it carries, for the history.
     */
    public function __construct(
        /** @var ai_provider The instance to delegate to. */
        public readonly ai_provider $target,
        /** @var string Whose key pays. */
        public readonly string $keysource = rule::KEYSOURCE_SITE,
        /** @var key|null The key it carries. */
        public readonly ?key $key = null,
    ) {
    }

    /**
     * Whether somebody is paying for this request out of their own pocket.
     *
     * @return bool True when a brought key pays.
     */
    public function is_byok(): bool {
        return $this->keysource !== rule::KEYSOURCE_SITE;
    }

    /**
     * Which key paid, as the history records it.
     *
     * @return int|null The key id, or null when the site's own key paid.
     */
    public function get_keyid(): ?int {
        return $this->key === null ? null : (int) $this->key->get('id');
    }

    /**
     * Which wallet paid, which is what a limit on the key is measured by.
     *
     * @return int The wallet id, or zero when the site's own key paid.
     */
    public function get_wallet(): int {
        return $this->key === null ? 0 : $this->key->get_wallet();
    }
}
