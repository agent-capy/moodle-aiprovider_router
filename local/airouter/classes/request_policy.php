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

/**
 * What the site had decided when this request arrived.
 *
 * Two things have to be true at once, and neither follows from the other. A request
 * must be decided by one set of settings from beginning to end, so that an
 * administrator saving a change halfway through cannot have half of it applied. And
 * the next request must see that change, including in a process that has been running
 * for hours.
 *
 * Reading through get_config() gives the first and not the second. Its cache keeps a
 * copy inside each process, and set_config() in another process deletes the shared
 * entry without reaching that copy, so a task runner started this morning is still
 * routing by this morning's rules. The settings are therefore read from the table
 * once when a request begins. One query, at the start of something about to call a
 * service over the network, is not a cost worth saving.
 *
 * This object is made per request and thrown away. Holding it anywhere longer would
 * put back the problem it exists to solve.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_policy {
    /**
     * Constructor.
     *
     * @param array $values The plugin's settings, by name.
     */
    private function __construct(
        /** @var array<string, string> The settings as they were read. */
        private readonly array $values,
    ) {
    }

    /**
     * Read the settings as they stand now.
     *
     * @param \moodle_database $db The database.
     * @return self The settings, fixed for this request.
     */
    public static function start(\moodle_database $db): self {
        global $CFG;

        $values = $db->get_records_menu(
            'config_plugins',
            ['plugin' => 'local_airouter'],
            '',
            'name, value',
        );

        // A site can put a setting beyond the reach of the settings screen by fixing
        // it in config.php, and then that is what the site means. Reading only the
        // table would leave the screen agreeing with the administrator while requests
        // went on being routed by something else. The rule is core's own, from
        // get_config(): a value that is null, an array or an object counts as the
        // setting not being there at all, and anything else replaces it as a string.
        foreach ($CFG->forced_plugin_settings['local_airouter'] ?? [] as $name => $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                unset($values[$name]);
                continue;
            }
            $values[$name] = (string) $value;
        }

        return new self($values);
    }

    /**
     * Read the settings the way a screen or a report should.
     *
     * The same settings, seen through core's own cache rather than the table. A screen
     * is drawn by a process that started moments ago, so the cache holds what was saved
     * and the reason start() goes to the table does not apply. What does apply is the
     * rest of this object: a setting fixed in config.php has to mean the same thing to
     * the administrator reading a status check as it does to the request being routed.
     *
     * Asking get_config() for the whole plugin rather than for one setting is what
     * makes that true. Core applies the config.php overlay differently in the two
     * cases: given a name it hands back the forced value cast to a string, so a null
     * arrives as an empty string and reads as a setting that has been turned off; given
     * only the plugin it drops null the way this class does, and the setting counts as
     * never having been made. The difference is invisible until a site forces a setting
     * whose absence means something, and then the screen and the request disagree.
     *
     * @return self The settings as they stand.
     */
    public static function current(): self {
        return new self(array_map(
            static fn($value): string => (string) $value,
            (array) get_config('local_airouter'),
        ));
    }

    /**
     * One setting.
     *
     * @param string $name The setting name.
     * @return string|null Its value, or null when the site has never set it.
     */
    public function get(string $name): ?string {
        return isset($this->values[$name]) ? (string) $this->values[$name] : null;
    }

    /**
     * Whether this site routes AI requests through the router at all.
     *
     * @return bool True when the router decides where requests go.
     */
    public function is_switched_on(): bool {
        $value = $this->get(managed_policy::SWITCH);

        // Absent means on, for the same reason as everywhere else: the switch was
        // added after the plugin, and Moodle writes its default at upgrade, so a
        // request arriving before that must not be turned away by its absence.
        return $value === null || (bool) $value;
    }

    /**
     * The action classes this site has placed under the router.
     *
     * @return string[] Fully qualified action class names, without a leading separator.
     */
    public function managed_actions(): array {
        return managed_policy::parse($this->get(managed_policy::SETTING) ?? '');
    }

    /**
     * Whether this site has placed the given action under the router.
     *
     * @param \core_ai\aiactions\base|string $action The action, or its class name.
     * @return bool True when the request must go through the router.
     */
    public function is_managed(\core_ai\aiactions\base|string $action): bool {
        $class = ltrim(is_string($action) ? $action : $action::class, '\\');

        return in_array($class, $this->managed_actions(), true);
    }
}
