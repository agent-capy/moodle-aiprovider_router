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

/**
 * AI Router provider.
 *
 * Skeleton implementation. The declared actions are the four core actions common to
 * Moodle 5.0 through 5.2, matching the "full router mode" design in which the router
 * declares every action and delegates the actual work to another provider.
 *
 * Rule evaluation arrives in WP3. Until then the router delegates to the configured
 * default target.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider extends \core_ai\provider {
    #[\Override]
    public static function get_action_list(): array {
        return [
            \core_ai\aiactions\generate_text::class,
            \core_ai\aiactions\generate_image::class,
            \core_ai\aiactions\summarise_text::class,
            \core_ai\aiactions\explain_text::class,
        ];
    }

    /** @var string Delegate everything, and expect to be first in the provider order. */
    public const MODE_FULL = 'full';

    /** @var string Sit alongside other providers and decline what no rule matches. */
    public const MODE_COEXIST = 'coexist';

    /**
     * The operating mode of this router instance.
     *
     * @return string One of the MODE_ constants.
     */
    public function get_mode(): string {
        $mode = $this->config['mode'] ?? self::MODE_FULL;

        return $mode === self::MODE_COEXIST ? self::MODE_COEXIST : self::MODE_FULL;
    }

    /**
     * The instance the router falls back to when no rule picks a target.
     *
     * @return int|null The provider instance id, or null when none is set.
     */
    public function get_default_target_id(): ?int {
        $targetid = (int) ($this->config['defaulttarget'] ?? 0);

        return $targetid > 0 ? $targetid : null;
    }

    #[\Override]
    public function is_provider_configured(): bool {
        // A router with nothing to delegate to would take every request and fail it,
        // so it reports itself unconfigured and core skips it.
        return $this->get_default_target_id() !== null;
    }
}
