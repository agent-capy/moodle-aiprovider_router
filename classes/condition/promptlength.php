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
 * Restricts a rule by how large the prompt is.
 *
 * The size is an estimate. Nothing has been sent anywhere when a rule is evaluated, so
 * there is no measured token count to compare against, and the estimate depends on the
 * ratios configured for the site and on the tokeniser the eventual target happens to
 * use. Everything showing this number says that it is an estimate, and the monitor
 * reports what targets actually charged so that the ratios can be calibrated.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class promptlength extends base {
    /** @var string At least this many tokens. */
    public const OPERATOR_GTE = 'gte';

    /** @var string At most this many tokens. */
    public const OPERATOR_LTE = 'lte';

    #[\Override]
    public function is_met(evaluation_context $context): bool {
        $tokens = (int) ($this->config['tokens'] ?? 0);
        if ($tokens <= 0) {
            // An unfinished condition narrows the rule rather than widening it.
            return false;
        }
        $estimate = $context->get_estimated_tokens();

        return match ($this->get_operator()) {
            self::OPERATOR_LTE => $estimate <= $tokens,
            self::OPERATOR_GTE => $estimate >= $tokens,
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
     * @return int The number of tokens.
     */
    public function get_tokens(): int {
        return (int) ($this->config['tokens'] ?? 0);
    }
    #[\Override]
    public static function add_to_form(\MoodleQuickForm $mform): void {
        $group = [
            $mform->createElement('select', 'promptlengthoperator', '', [
                self::OPERATOR_GTE => get_string('condition:promptlength:gte', 'aiprovider_router'),
                self::OPERATOR_LTE => get_string('condition:promptlength:lte', 'aiprovider_router'),
            ]),
            $mform->createElement('text', 'promptlengthtokens', '', ['size' => 8]),
        ];
        $mform->addGroup($group, 'promptlengthgroup', self::get_label(), ' ', false);
        $mform->setType('promptlengthtokens', PARAM_INT);
        $mform->setDefault('promptlengthoperator', self::OPERATOR_GTE);
        $mform->addHelpButton('promptlengthgroup', 'condition:promptlength', 'aiprovider_router');
    }

    #[\Override]
    public static function read_from_form(\stdClass $data): ?array {
        $tokens = (int) ($data->promptlengthtokens ?? 0);
        if ($tokens <= 0) {
            return null;
        }
        $operator = (string) ($data->promptlengthoperator ?? self::OPERATOR_GTE);

        return [
            'operator' => $operator === self::OPERATOR_LTE ? self::OPERATOR_LTE : self::OPERATOR_GTE,
            'tokens' => $tokens,
        ];
    }

    #[\Override]
    public static function to_form_data(array $config): array {
        return [
            'promptlengthoperator' => $config['operator'] ?? self::OPERATOR_GTE,
            'promptlengthtokens' => $config['tokens'] ?? '',
        ];
    }

    #[\Override]
    public function get_description(): string {
        // The wording says estimated every time it is shown. The number is a guess, and
        // presenting it as anything else would be the one thing this condition must not do.
        return get_string(
            'condition:describe:promptlength:' . ($this->get_operator() ?: self::OPERATOR_GTE),
            'aiprovider_router',
            $this->get_tokens(),
        );
    }
}
