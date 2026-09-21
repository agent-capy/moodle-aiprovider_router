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

namespace local_airouter\eligibility;

/**
 * One thing the site requires of somebody before they may bring their own key.
 *
 * Whether all of them have to hold or any one of them is enough is the site's choice,
 * because a policy is a single statement with no list behind it: routing rules express a
 * choice by being several rules, and this cannot. Choice within one condition is always
 * an or, exactly as for routing rules.
 *
 * They differ from routing conditions in when they are asked: again on every request, not
 * only when a key is registered, so that a site which tightens its policy stops using the
 * keys it no longer allows without anybody having to go and find them.
 *
 * A condition with no configuration is not met, under either matching rule. A policy that
 * fell open when somebody saved an empty form would hand the site's AI to everybody.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /**
     * Constructor.
     *
     * @param array $config The stored configuration for this condition.
     */
    public function __construct(
        /** @var array The stored configuration. */
        protected readonly array $config,
    ) {
    }

    /**
     * Whether this person satisfies the condition.
     *
     * @param int $userid The user being judged.
     * @return bool True when the condition is met.
     */
    abstract public function is_met(int $userid): bool;

    /**
     * The stored type name of this condition.
     *
     * @return string The type, as used in the settings and in the editing form.
     */
    public static function get_type(): string {
        $parts = explode('\\', static::class);

        return (string) end($parts);
    }

    /**
     * The configuration this condition was built from.
     *
     * @return array The configuration.
     */
    public function get_config(): array {
        return $this->config;
    }

    /**
     * Add this condition's controls to the policy form.
     *
     * @param \MoodleQuickForm $mform The form being built.
     */
    abstract public static function add_to_form(\MoodleQuickForm $mform): void;

    /**
     * The names of the controls this condition puts on the form.
     *
     * Declared rather than guessed at by the form, so that adding a condition type is a
     * matter of writing the class and listing it in the registry.
     *
     * @return string[] The element names.
     */
    abstract public static function get_form_elements(): array;

    /**
     * Read this condition out of a submitted form.
     *
     * @param \stdClass $data The submitted data.
     * @return array|null The configuration to store, or null when it was not used.
     */
    abstract public static function read_from_form(\stdClass $data): ?array;

    /**
     * Turn stored configuration back into form values.
     *
     * @param array $config The stored configuration.
     * @return array Values keyed by form element name.
     */
    abstract public static function to_form_data(array $config): array;

    /**
     * What this condition requires, in a sentence an administrator can read.
     *
     * @return string The description.
     */
    abstract public function get_description(): string;

    /**
     * The name this condition is shown under.
     *
     * @return string The label.
     */
    public static function get_label(): string {
        return get_string('eligibility:' . static::get_type(), 'local_airouter');
    }
}
