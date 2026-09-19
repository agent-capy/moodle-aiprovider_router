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

namespace aiprovider_router\condition;

use aiprovider_router\evaluation_context;

/**
 * One thing a rule requires of a request.
 *
 * A rule holds at most one condition of each type, and every one of them has to be met
 * for the rule to match. Choice within a condition is how alternatives are expressed:
 * a role condition listing two roles is met by either of them. So conditions narrow a
 * rule as they are added, which is what makes "put the specific rules above the general
 * ones" a rule of thumb an administrator can actually follow.
 *
 * A condition whose configuration is empty or unreadable is not met. That direction
 * matters: the other one would turn a damaged row into a rule that matches everything.
 *
 * @package    aiprovider_router
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
     * Whether this request satisfies the condition.
     *
     * @param evaluation_context $context The request being evaluated.
     * @return bool True when the condition is met.
     */
    abstract public function is_met(evaluation_context $context): bool;

    /**
     * The stored type name of this condition.
     *
     * @return string The type, as used in the database and in the editing form.
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
     * Add this condition's controls to the rule editing form.
     *
     * Each condition owns one row of a fixed form. There is no expression builder, on
     * purpose: a rule an administrator can read at a glance is worth more than one that
     * can express anything.
     *
     * @param \MoodleQuickForm $mform The form being built.
     */
    abstract public static function add_to_form(\MoodleQuickForm $mform): void;

    /**
     * Read this condition out of a submitted form.
     *
     * @param \stdClass $data The submitted data.
     * @return array|null The configuration to store, or null when the administrator did
     *                    not use this condition.
     */
    abstract public static function read_from_form(\stdClass $data): ?array;

    /**
     * Check what was typed into this condition's controls before the rule is saved.
     *
     * The default is that nothing here can be typed wrongly, which is true of every
     * condition built out of lists of things that already exist. A condition taking a
     * number has something to say, and says it beside the field rather than dropping
     * the condition silently: a rule that lost a condition matches more than it was
     * meant to.
     *
     * @param array $data The submitted data.
     * @return array Messages keyed by the form element they belong to.
     */
    public static function validate_form(array $data): array {
        return [];
    }

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
        return get_string('condition:' . static::get_type(), 'aiprovider_router');
    }
}
