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
}
