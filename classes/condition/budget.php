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
use aiprovider_router\price_book;
use aiprovider_router\spend_ledger;

/**
 * Restricts a rule by how much has been spent already.
 *
 * Written both ways round on purpose. A rule can require that there is still room in a
 * budget, or that the budget has been reached, and between the two an administrator can
 * say what should happen either side of a limit without this plugin inventing a setting
 * for it:
 *
 *     1. "Budget has room"   -> the expensive instance
 *     2. (no conditions)     -> the cheap instance
 *
 * Deleting the second rule, on a site that refuses requests matching nothing, turns the
 * same pair into a block. There is no "what to do when the budget runs out" option
 * because the order of the rules already says it, and a second place to say it could
 * disagree with the first.
 *
 * ⚠ What is measured is what the site pays for. Requests covered by a key somebody
 * brought are left out entirely, however expensive they were: a site's budget is not
 * touched by money the site did not spend. An owner limiting their own key does it on
 * the key itself.
 *
 * ⚠ Spending that cannot be worked out does not satisfy this condition either way
 * round. Costs come from the site's own rate table, so a site with no rates entered
 * spends nothing however much it uses, and reading that as room in the budget would
 * make every limit here meaningless. The status report says so where a rule routes by
 * budget and the rates are missing.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class budget extends base {
    /** @var string There is still room in the budget. */
    public const DIRECTION_UNDER = 'under';

    /** @var string The budget has been reached. */
    public const DIRECTION_OVER = 'over';

    /** @var int The rolling period used when none was chosen. */
    public const DEFAULT_DAYS = 30;

    #[\Override]
    public function is_met(evaluation_context $context): bool {
        $amount = $this->get_amount();
        $scope = $this->get_scope();
        $direction = $this->get_direction();
        if ($amount <= 0 || $scope === '' || $direction === '') {
            // An unfinished condition narrows the rule rather than widening it.
            return false;
        }

        $scopeid = $this->get_scopeid($scope, $context);
        if ($scope !== spend_ledger::SCOPE_SITE && $scopeid <= 0) {
            // A budget for a course, asked about a request that belongs to no course.
            // Not met either way round: there is no budget here to be inside or past.
            return false;
        }

        $spend = $this->get_ledger()->get_spend(
            $scope,
            $scopeid,
            $this->get_period(),
            $this->get_days(),
            time(),
        );
        $reached = $spend->has_reached($amount);
        if ($reached === null) {
            // Nobody can say what has been spent, so nobody can say there is room.
            return false;
        }

        return $direction === self::DIRECTION_OVER ? $reached : !$reached;
    }

    /**
     * Whose spending is being weighed.
     *
     * @return string One of the ledger's scopes, or an empty string when the stored
     *                value is not one of them.
     */
    public function get_scope(): string {
        $scope = (string) ($this->config['scope'] ?? '');

        return in_array($scope, spend_ledger::get_scopes(), true) ? $scope : '';
    }

    /**
     * Which side of the limit the rule wants.
     *
     * @return string One of the DIRECTION_ constants, or an empty string when the
     *                stored value is not one of them.
     */
    public function get_direction(): string {
        $direction = (string) ($this->config['direction'] ?? '');

        return in_array($direction, [self::DIRECTION_UNDER, self::DIRECTION_OVER], true) ? $direction : '';
    }

    /**
     * The limit being compared against.
     *
     * @return float The amount, in the site currency.
     */
    public function get_amount(): float {
        return (float) ($this->config['amount'] ?? 0);
    }

    /**
     * How the period is counted.
     *
     * @return string One of the ledger's periods.
     */
    public function get_period(): string {
        $period = (string) ($this->config['period'] ?? '');

        return $period === spend_ledger::PERIOD_MONTH
            ? spend_ledger::PERIOD_MONTH
            : spend_ledger::PERIOD_ROLLING;
    }

    /**
     * How many days a rolling period covers.
     *
     * @return int The number of days.
     */
    public function get_days(): int {
        return max(1, (int) ($this->config['days'] ?? self::DEFAULT_DAYS));
    }

    #[\Override]
    public static function add_to_form(\MoodleQuickForm $mform): void {
        $group = [
            $mform->createElement('select', 'budgetscope', '', [
                '' => get_string('condition:budget:scope:none', 'aiprovider_router'),
                spend_ledger::SCOPE_SITE => get_string('condition:budget:scope:site', 'aiprovider_router'),
                spend_ledger::SCOPE_COURSE => get_string('condition:budget:scope:course', 'aiprovider_router'),
                spend_ledger::SCOPE_USER => get_string('condition:budget:scope:user', 'aiprovider_router'),
            ]),
            $mform->createElement('select', 'budgetdirection', '', [
                self::DIRECTION_UNDER => get_string('condition:budget:under', 'aiprovider_router'),
                self::DIRECTION_OVER => get_string('condition:budget:over', 'aiprovider_router'),
            ]),
            $mform->createElement('text', 'budgetamount', '', ['size' => 10]),
            $mform->createElement('static', 'budgetcurrency', '', price_book::get_currency()),
            $mform->createElement('select', 'budgetperiod', '', [
                spend_ledger::PERIOD_ROLLING => get_string('condition:budget:period:rolling', 'aiprovider_router'),
                spend_ledger::PERIOD_MONTH => get_string('condition:budget:period:month', 'aiprovider_router'),
            ]),
            $mform->createElement('text', 'budgetdays', '', ['size' => 4]),
            $mform->createElement('static', 'budgetunit', '', get_string(
                'condition:budget:days',
                'aiprovider_router',
            )),
        ];
        $mform->addGroup($group, 'budgetgroup', self::get_label(), ' ', false);
        $mform->setType('budgetamount', PARAM_RAW_TRIMMED);
        $mform->setType('budgetdays', PARAM_INT);
        $mform->setDefault('budgetdirection', self::DIRECTION_UNDER);
        $mform->setDefault('budgetperiod', spend_ledger::PERIOD_ROLLING);
        $mform->setDefault('budgetdays', self::DEFAULT_DAYS);
        $mform->hideIf('budgetdays', 'budgetperiod', 'eq', spend_ledger::PERIOD_MONTH);
        $mform->hideIf('budgetunit', 'budgetperiod', 'eq', spend_ledger::PERIOD_MONTH);
        $mform->addHelpButton('budgetgroup', 'condition:budget', 'aiprovider_router');
    }

    #[\Override]
    public static function validate_form(array $data): array {
        $scope = (string) ($data['budgetscope'] ?? '');
        $amount = trim((string) ($data['budgetamount'] ?? ''));
        if ($scope === '' && $amount === '') {
            return [];
        }
        if ($scope === '') {
            return ['budgetgroup' => get_string('condition:budget:error:scope', 'aiprovider_router')];
        }
        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            // Said rather than ignored. A budget that did not save because it was typed
            // wrongly is a rule that quietly matches every request instead of some.
            return ['budgetgroup' => get_string('condition:budget:error:amount', 'aiprovider_router')];
        }

        return [];
    }

    #[\Override]
    public static function read_from_form(\stdClass $data): ?array {
        $scope = (string) ($data->budgetscope ?? '');
        $amount = trim((string) ($data->budgetamount ?? ''));
        if ($scope === '' || $amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }
        $direction = (string) ($data->budgetdirection ?? self::DIRECTION_UNDER);
        $period = (string) ($data->budgetperiod ?? spend_ledger::PERIOD_ROLLING);

        return [
            'scope' => in_array($scope, spend_ledger::get_scopes(), true) ? $scope : spend_ledger::SCOPE_SITE,
            'direction' => $direction === self::DIRECTION_OVER ? self::DIRECTION_OVER : self::DIRECTION_UNDER,
            'amount' => (float) $amount,
            'period' => $period === spend_ledger::PERIOD_MONTH
                ? spend_ledger::PERIOD_MONTH
                : spend_ledger::PERIOD_ROLLING,
            'days' => max(1, (int) ($data->budgetdays ?? self::DEFAULT_DAYS)),
        ];
    }

    #[\Override]
    public static function to_form_data(array $config): array {
        return [
            'budgetscope' => $config['scope'] ?? '',
            'budgetdirection' => $config['direction'] ?? self::DIRECTION_UNDER,
            'budgetamount' => isset($config['amount']) ? (string) $config['amount'] : '',
            'budgetperiod' => $config['period'] ?? spend_ledger::PERIOD_ROLLING,
            'budgetdays' => $config['days'] ?? self::DEFAULT_DAYS,
        ];
    }

    #[\Override]
    public function get_description(): string {
        $scope = $this->get_scope();
        $period = $this->get_period() === spend_ledger::PERIOD_MONTH
            ? get_string('condition:describe:budget:month', 'aiprovider_router')
            : get_string('condition:describe:budget:rolling', 'aiprovider_router', $this->get_days());

        return get_string(
            'condition:describe:budget:' . ($this->get_direction() ?: self::DIRECTION_UNDER),
            'aiprovider_router',
            [
                'scope' => get_string(
                    'condition:budget:scope:' . ($scope ?: spend_ledger::SCOPE_SITE),
                    'aiprovider_router',
                ),
                // Two decimals, not the four a recorded cost is shown to. This is a
                // figure somebody typed as an amount of money, and showing it back to
                // them with more precision than they gave it reads as a different number.
                'amount' => format_float($this->get_amount(), 2, true) . ' ' . price_book::get_currency(),
                'period' => $period,
            ],
        );
    }

    /**
     * Which course or which person the request belongs to.
     *
     * @param string $scope The scope being measured.
     * @param evaluation_context $context The request being evaluated.
     * @return int The subject, or zero when the request has none.
     */
    protected function get_scopeid(string $scope, evaluation_context $context): int {
        return match ($scope) {
            spend_ledger::SCOPE_COURSE => (int) ($context->get_courseid() ?? 0),
            spend_ledger::SCOPE_USER => $context->get_userid(),
            default => 0,
        };
    }

    /**
     * The ledger this condition is measured against.
     *
     * Cached, because this runs on the path of every AI request a budget rule is
     * weighed against. What that costs in accuracy is written down where the cache is
     * defined.
     *
     * @return spend_ledger The ledger.
     */
    protected function get_ledger(): spend_ledger {
        global $DB;

        return new spend_ledger($DB);
    }
}
