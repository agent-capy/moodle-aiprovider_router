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

namespace local_airouter\condition;

use local_airouter\evaluation_context;

/**
 * A condition satisfied by any one of the values it lists.
 *
 * Most conditions are of this shape: the request has some set of courses, roles or
 * actions attached to it, and the condition names the ones the rule is interested in.
 * Listing several is how a rule says "teacher or manager" without needing a second rule
 * or a general purpose expression builder.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class set_base extends base {
    /**
     * The configuration key holding the chosen values.
     *
     * @return string The key.
     */
    abstract protected static function get_config_key(): string;

    /**
     * The values this request actually has.
     *
     * @param evaluation_context $context The request being evaluated.
     * @return array The values, of the same kind as the configured ones.
     */
    abstract protected function get_actual(evaluation_context $context): array;

    /**
     * The values the rule is looking for.
     *
     * @return array The configured values.
     */
    public function get_values(): array {
        $values = $this->config[static::get_config_key()] ?? [];
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_map([static::class, 'cast'], $values)));
    }

    #[\Override]
    public function is_met(evaluation_context $context): bool {
        $wanted = $this->get_values();
        if (!$wanted) {
            // Nothing chosen is not the same as no restriction. A condition that is
            // present but empty is a condition the administrator has not finished, and
            // treating it as "anything" would widen the rule behind their back.
            return false;
        }

        return array_intersect($wanted, $this->get_actual($context)) !== [];
    }

    #[\Override]
    public static function add_to_form(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'autocomplete',
            static::get_type(),
            static::get_label(),
            static::get_options(),
            ['multiple' => true],
        );
        $mform->addHelpButton(static::get_type(), 'condition:' . static::get_type(), 'local_airouter');
    }

    #[\Override]
    public static function read_from_form(\stdClass $data): ?array {
        $values = (array) ($data->{static::get_type()} ?? []);
        $values = array_values(array_filter($values, static fn($value): bool => $value !== '' && $value !== null));

        return $values ? [static::get_config_key() => $values] : null;
    }

    #[\Override]
    public static function to_form_data(array $config): array {
        return [static::get_type() => $config[static::get_config_key()] ?? []];
    }

    #[\Override]
    public function get_description(): string {
        $labels = static::get_labels($this->get_values());
        $named = [];
        foreach ($this->get_values() as $value) {
            // A value whose name cannot be found is one that has been deleted since the
            // rule was written. Saying so is more use than leaving a blank, because that
            // condition can no longer be met and the rule may never fire again.
            $named[] = $labels[$value]
                ?? get_string('condition:describe:missing', 'local_airouter', $value);
        }

        return get_string(
            'condition:describe:' . static::get_type(),
            'local_airouter',
            implode(', ', $named),
        );
    }

    /**
     * The choices offered for this condition.
     *
     * @return array Labels keyed by the value they stand for.
     */
    protected static function get_options(): array {
        return [];
    }

    /**
     * Names for the values a rule has chosen.
     *
     * Separate from the options so that a condition whose choices are too numerous to
     * list, such as courses, can look up only the ones it needs.
     *
     * @param array $values The chosen values.
     * @return array Names keyed by value. A value with no name is left out.
     */
    protected static function get_labels(array $values): array {
        return array_intersect_key(static::get_options(), array_flip($values));
    }

    /**
     * Bring a stored value to the type it is compared as.
     *
     * Values arrive from JSON and from form submissions, so an id may turn up as a
     * string. Comparison is strict once they are here, so they are made to agree first.
     *
     * @param mixed $value The stored value.
     * @return int|string The value to compare with.
     */
    protected static function cast(mixed $value): int|string {
        return (int) $value;
    }
}
