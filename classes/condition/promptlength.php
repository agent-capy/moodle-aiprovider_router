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
 * Restricts a rule by how long the prompt is, in characters.
 *
 * Characters rather than tokens, even though tokens are the unit an administrator thinks
 * in when weighing cost and context windows. A token count could only be an estimate at
 * this point, since nothing has been sent anywhere yet, and an estimate depends on the
 * language of the prompt and on the tokeniser the eventual target happens to use. A rule
 * reading "at least 2000 tokens" would then fire at around 2000 characters of Japanese
 * and around 8000 of English, and nobody could say what the rule meant.
 *
 * A character count is the same number however it is arrived at, so the rule means one
 * thing. The estimate is still shown next to the count wherever an administrator is
 * choosing a threshold, which is where the two units need to be related.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class promptlength extends base {
    /** @var string At least this many characters. */
    public const OPERATOR_GTE = 'gte';

    /** @var string At most this many characters. */
    public const OPERATOR_LTE = 'lte';

    #[\Override]
    public function is_met(evaluation_context $context): bool {
        $characters = $this->get_characters();
        if ($characters <= 0) {
            // An unfinished condition narrows the rule rather than widening it.
            return false;
        }
        $length = $context->get_prompt_length();

        return match ($this->get_operator()) {
            self::OPERATOR_LTE => $length <= $characters,
            self::OPERATOR_GTE => $length >= $characters,
            default => false,
        };
    }

    /**
     * Which way round the comparison goes.
     *
     * @return string One of the OPERATOR_ constants, or an empty string when the stored
     *                value is not one of them.
     */
    public function get_operator(): string {
        $operator = (string) ($this->config['operator'] ?? '');

        return in_array($operator, [self::OPERATOR_GTE, self::OPERATOR_LTE], true) ? $operator : '';
    }

    /**
     * The threshold being compared against.
     *
     * @return int The number of characters.
     */
    public function get_characters(): int {
        return (int) ($this->config['characters'] ?? 0);
    }

    #[\Override]
    public static function add_to_form(\MoodleQuickForm $mform): void {
        $group = [
            $mform->createElement('select', 'promptlengthoperator', '', [
                self::OPERATOR_GTE => get_string('condition:promptlength:gte', 'aiprovider_router'),
                self::OPERATOR_LTE => get_string('condition:promptlength:lte', 'aiprovider_router'),
            ]),
            $mform->createElement('text', 'promptlengthcharacters', '', ['size' => 8]),
            $mform->createElement('static', 'promptlengthunit', '', get_string(
                'condition:promptlength:unit',
                'aiprovider_router',
            )),
        ];
        $mform->addGroup($group, 'promptlengthgroup', self::get_label(), ' ', false);
        $mform->setType('promptlengthcharacters', PARAM_INT);
        $mform->setDefault('promptlengthoperator', self::OPERATOR_GTE);
        $mform->addHelpButton('promptlengthgroup', 'condition:promptlength', 'aiprovider_router');
    }

    #[\Override]
    public static function read_from_form(\stdClass $data): ?array {
        $characters = (int) ($data->promptlengthcharacters ?? 0);
        if ($characters <= 0) {
            return null;
        }
        $operator = (string) ($data->promptlengthoperator ?? self::OPERATOR_GTE);

        return [
            'operator' => $operator === self::OPERATOR_LTE ? self::OPERATOR_LTE : self::OPERATOR_GTE,
            'characters' => $characters,
        ];
    }

    #[\Override]
    public static function to_form_data(array $config): array {
        return [
            'promptlengthoperator' => $config['operator'] ?? self::OPERATOR_GTE,
            'promptlengthcharacters' => $config['characters'] ?? '',
        ];
    }

    #[\Override]
    public function get_description(): string {
        // The wording says characters every time it is shown, so that a threshold is
        // never mistaken for a token count.
        return get_string(
            'condition:describe:promptlength:' . ($this->get_operator() ?: self::OPERATOR_GTE),
            'aiprovider_router',
            $this->get_characters(),
        );
    }
}
