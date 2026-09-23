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
use local_airouter\price_book;
use local_airouter\retention_policy;
use local_airouter\record\ledger;
use local_airouter\record\summariser;
use local_airouter\target_resolver;
use local_airouter\usage_formatter;

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
 * What is measured is what the site pays for. Requests covered by a key somebody
 * brought are left out entirely, however expensive they were: a site's budget is not
 * touched by money the site did not spend. An owner limiting their own key does it on
 * the key itself.
 *
 * Spending that cannot be worked out does not satisfy this condition either way
 * round. Costs come from the site's own rate table, so a site with no rates entered
 * spends nothing however much it uses, and reading that as room in the budget would
 * make every limit here meaningless. The status report says so where a rule routes by
 * budget and the rates are missing.
 *
 * A budget in money names a provider and counts that provider's money alone, in the
 * currency the provider bills in: money is never added across providers, so a site
 * whose budget is one figure for all its AI sets a budget per provider and adds them
 * up itself. A budget can be counted in requests instead of money, and then none of
 * that applies: requests are counted rather than priced, so the figure is always
 * there, and every request counts whatever provider answered it. That is the measure
 * for a site running its own models, which cost nothing to price and everything to
 * queue, and for a provider whose free allowance is written in requests per month
 * rather than in money.
 *
 * @package    local_airouter
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

    /** @var bool Whether the last evaluation failed because the limit had been reached. */
    protected bool $exhausted = false;

    #[\Override]
    public function is_met(evaluation_context $context): bool {
        // Every path out of this method other than the last one leaves this false, which
        // is the point of it. See was_exhausted() for why the difference matters.
        $this->exhausted = false;

        $amount = $this->get_amount();
        $scope = $this->get_scope();
        $direction = $this->get_direction();
        $metric = $this->get_metric();
        $provider = $this->get_provider();
        if ($amount <= 0 || $scope === '' || $direction === '' || ($metric === ledger::METRIC_COST && $provider === '')) {
            // An unfinished condition narrows the rule rather than widening it.
            return false;
        }

        $scopeid = $this->get_scopeid($scope, $context);
        if ($scope !== ledger::SCOPE_SITE && $scopeid <= 0) {
            // A budget for a course, asked about a request that belongs to no course.
            // Not met either way round: there is no budget here to be inside or past.
            return false;
        }

        $spend = $this->get_ledger($context)->get_spend(
            $scope,
            $scopeid,
            $this->get_period(),
            $this->get_days(),
            time(),
            $metric,
        );
        $reached = $spend->has_reached($amount, $metric, $provider);
        if ($reached === null) {
            // Nobody can say what has been spent, so nobody can say there is room.
            // Only money reaches here: a count of requests is always known.
            return false;
        }

        if ($direction === self::DIRECTION_OVER) {
            // A rule that wants the limit to have been passed. Failing it means the
            // limit has not been passed, which is money still available rather than
            // money gone: the ordinary state of a site that has just started the month.
            return $reached;
        }

        $this->exhausted = $reached;

        return !$reached;
    }

    /**
     * Whether the last evaluation failed because the money had actually run out.
     *
     * The router stops a request outright when a rule fitted it in every respect except
     * its budget, on the grounds that spending the site refused is not for another
     * provider to spend instead. That reasoning only holds for one of the ways this
     * condition can fail. A rule asking to be used once a limit has been passed fails
     * every time the limit has not been passed, and a course budget asked about a
     * request belonging to no course fails because there is no budget here at all.
     * Neither is a refusal, and reading them as one stops requests the site never meant
     * to stop -- which in a site running the router alongside other providers is every
     * request it makes.
     *
     * Only meaningful straight after is_met() has been called with the same request.
     *
     * @return bool True when the limit was reached, rather than merely not satisfied.
     */
    public function was_exhausted(): bool {
        return $this->exhausted;
    }

    /**
     * Whose spending is being weighed.
     *
     * @return string One of the ledger's scopes, or an empty string when the stored
     *                value is not one of them.
     */
    public function get_scope(): string {
        $scope = (string) ($this->config['scope'] ?? '');

        return in_array($scope, ledger::get_scopes(), true) ? $scope : '';
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
     * What the limit counts.
     *
     * @return string One of the ledger's metrics. Money, where the stored value is not
     *                one of them, because that is what every budget written before
     *                this setting existed meant.
     */
    public function get_metric(): string {
        $metric = (string) ($this->config['metric'] ?? '');

        return $metric === ledger::METRIC_REQUESTS
            ? ledger::METRIC_REQUESTS
            : ledger::METRIC_COST;
    }

    /**
     * Whose money a budget in money counts.
     *
     * @return string The provider component, or an empty string where none is set,
     *                which for a budget in money is an unfinished condition.
     */
    public function get_provider(): string {
        return trim((string) ($this->config['provider'] ?? ''));
    }

    /**
     * The limit being compared against.
     *
     * @return float The amount, in the provider's currency, or a number of requests.
     */
    public function get_amount(): float {
        return (float) ($this->config['amount'] ?? 0);
    }

    /**
     * The limit as it is shown to somebody, with what it counts.
     *
     * @return string The figure and its unit.
     */
    public function get_amount_label(): string {
        return self::label_amount($this->get_amount(), $this->get_metric(), $this->get_provider());
    }

    /**
     * A limit with the unit that says what it counts.
     *
     * Requests are whole things and are shown as whole numbers. An amount of money is
     * shown to two decimals, not the four a recorded cost is kept to: this is a figure
     * somebody typed, and handing it back with more precision than they gave it reads
     * as a different number.
     *
     * An amount of money names the provider it is about and is in that provider's
     * currency, which is the currency of its rates; a provider with no rates yet has
     * no currency, and the figure is shown without one.
     *
     * @param float $amount The figure.
     * @param string $metric What it counts.
     * @param string $provider The provider a money figure is about.
     * @return string The figure and its unit.
     */
    public static function label_amount(float $amount, string $metric, string $provider = ''): string {
        global $DB;

        if ($metric === ledger::METRIC_REQUESTS) {
            return get_string(
                'condition:budget:requests',
                'local_airouter',
                number_format((float) round($amount)),
            );
        }
        $currency = $provider === '' ? null : (new price_book($DB))->currency_of($provider);
        $figure = format_float($amount, 2, true) . ($currency === null ? '' : ' ' . $currency);

        return get_string('condition:budget:cost', 'local_airouter', (object) [
            'amount' => $figure,
            'provider' => usage_formatter::provider_name($provider),
        ]);
    }

    /**
     * How the period is counted.
     *
     * @return string One of the ledger's periods.
     */
    public function get_period(): string {
        $period = (string) ($this->config['period'] ?? '');

        return $period === ledger::PERIOD_MONTH
            ? ledger::PERIOD_MONTH
            : ledger::PERIOD_ROLLING;
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
                '' => get_string('condition:budget:scope:none', 'local_airouter'),
                ledger::SCOPE_SITE => get_string('condition:budget:scope:site', 'local_airouter'),
                ledger::SCOPE_COURSE => get_string('condition:budget:scope:course', 'local_airouter'),
                ledger::SCOPE_USER => get_string('condition:budget:scope:user', 'local_airouter'),
            ]),
            // What is being counted comes before the figure, because it decides what
            // the figure means, and in both languages the sentence wants it there.
            $mform->createElement('select', 'budgetmetric', '', [
                ledger::METRIC_COST => get_string('condition:budget:metric:cost', 'local_airouter'),
                ledger::METRIC_REQUESTS => get_string(
                    'condition:budget:metric:requests',
                    'local_airouter',
                ),
            ]),
            $mform->createElement('select', 'budgetdirection', '', [
                self::DIRECTION_UNDER => get_string('condition:budget:under', 'local_airouter'),
                self::DIRECTION_OVER => get_string('condition:budget:over', 'local_airouter'),
            ]),
            $mform->createElement('text', 'budgetamount', '', ['size' => 10]),
            // Money is counted per provider, in the currency that provider bills in,
            // so a budget in money says which provider it is about. A budget in
            // requests counts every request whatever answered it, and hides this.
            $mform->createElement(
                'select',
                'budgetprovider',
                '',
                ['' => get_string('condition:budget:provider:choose', 'local_airouter')]
                    + target_resolver::get_provider_options(),
            ),
            $mform->createElement('static', 'budgetrequests', '', get_string(
                'condition:budget:unit:requests',
                'local_airouter',
            )),
            $mform->createElement('select', 'budgetperiod', '', [
                ledger::PERIOD_ROLLING => get_string('condition:budget:period:rolling', 'local_airouter'),
                ledger::PERIOD_MONTH => get_string('condition:budget:period:month', 'local_airouter'),
            ]),
            $mform->createElement('text', 'budgetdays', '', ['size' => 4]),
            $mform->createElement('static', 'budgetunit', '', get_string(
                'condition:budget:days',
                'local_airouter',
            )),
        ];
        $mform->addGroup($group, 'budgetgroup', self::get_label(), ' ', false);
        $mform->setType('budgetamount', PARAM_RAW_TRIMMED);
        $mform->setType('budgetdays', PARAM_INT);
        $mform->setDefault('budgetdirection', self::DIRECTION_UNDER);
        $mform->setDefault('budgetmetric', ledger::METRIC_COST);
        $mform->setDefault('budgetperiod', ledger::PERIOD_ROLLING);
        $mform->setDefault('budgetdays', self::DEFAULT_DAYS);
        $mform->hideIf('budgetdays', 'budgetperiod', 'eq', ledger::PERIOD_MONTH);
        $mform->hideIf('budgetunit', 'budgetperiod', 'eq', ledger::PERIOD_MONTH);
        $mform->hideIf('budgetprovider', 'budgetmetric', 'eq', ledger::METRIC_REQUESTS);
        $mform->hideIf('budgetrequests', 'budgetmetric', 'eq', ledger::METRIC_COST);
        $mform->addHelpButton('budgetgroup', 'condition:budget', 'local_airouter');
    }

    #[\Override]
    public static function validate_form(array $data): array {
        $scope = (string) ($data['budgetscope'] ?? '');
        $amount = trim((string) ($data['budgetamount'] ?? ''));
        if ($scope === '' && $amount === '') {
            return [];
        }
        if ($scope === '') {
            return ['budgetgroup' => get_string('condition:budget:error:scope', 'local_airouter')];
        }
        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            // Said rather than ignored. A budget that did not save because it was typed
            // wrongly is a rule that quietly matches every request instead of some.
            return ['budgetgroup' => get_string('condition:budget:error:amount', 'local_airouter')];
        }
        $metric = (string) ($data['budgetmetric'] ?? ledger::METRIC_COST);
        if ($metric === ledger::METRIC_REQUESTS && (float) $amount !== floor((float) $amount)) {
            // Half a request is not a thing anybody can make.
            return ['budgetgroup' => get_string('condition:budget:error:requests', 'local_airouter')];
        }
        if ($metric !== ledger::METRIC_REQUESTS && trim((string) ($data['budgetprovider'] ?? '')) === '') {
            // Money is one figure per provider, so a budget in money has to say which.
            return ['budgetgroup' => get_string('condition:budget:error:provider', 'local_airouter')];
        }

        $problem = self::retention_problem(
            (string) ($data['budgetperiod'] ?? ledger::PERIOD_ROLLING),
            (int) ($data['budgetdays'] ?? self::DEFAULT_DAYS),
        );

        return $problem === null ? [] : ['budgetgroup' => $problem];
    }

    /**
     * Why this budget cannot be measured on this site, if it cannot.
     *
     * A budget is worked out from what is still stored, so one that looks back further
     * than the site keeps its summaries is measured against part of its own period. The
     * figure that comes out is not unknown, which is the state this plugin is careful
     * about everywhere else -- it is simply too small, and a limit that has been reached
     * reads as a limit with room left. The retention screen already refuses the other
     * direction, and refusing this one is what makes the pair hold: the history a budget
     * needs cannot be thrown away, and a budget cannot ask for history the site is not
     * keeping.
     *
     * Asked here rather than in the form, because a rule can start setting a budget
     * without any form being submitted: switching a disabled rule back on does it, from
     * a link on the list.
     *
     * @param string $period One of the ledger's periods.
     * @param int $days How many days a rolling period counts.
     * @return string|null The problem to show somebody, or null when there is none.
     */
    public static function retention_problem(string $period, int $days): ?string {
        global $DB;

        $short = (new retention_policy($DB))->shortfall_of($period, $days);
        if ($short === null) {
            return null;
        }

        return get_string('condition:budget:error:retention', 'local_airouter', $short);
    }

    /**
     * Why the budget a stored condition describes cannot be measured, if it cannot.
     *
     * @param array $config The stored configuration of a budget condition.
     * @return string|null The problem, or null when there is none.
     */
    public static function stored_retention_problem(array $config): ?string {
        $short = self::stored_retention_shortfall($config);

        return $short === null ? null : get_string('condition:budget:error:retention', 'local_airouter', $short);
    }

    /**
     * By how much the summaries kept fall short of the budget a stored condition describes.
     *
     * @param array $config The stored configuration of a budget condition.
     * @return \stdClass|null The days the budget reaches and the days kept, or null when they cover it.
     */
    public static function stored_retention_shortfall(array $config): ?\stdClass {
        global $DB;

        $condition = new self($config);
        if ($condition->get_amount() <= 0 || $condition->get_scope() === '') {
            // An unfinished condition sets no budget, so it needs no history.
            return null;
        }

        return (new retention_policy($DB))->shortfall_of($condition->get_period(), $condition->get_days());
    }

    #[\Override]
    public static function read_from_form(\stdClass $data): ?array {
        $scope = (string) ($data->budgetscope ?? '');
        $amount = trim((string) ($data->budgetamount ?? ''));
        if ($scope === '' || $amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }
        $direction = (string) ($data->budgetdirection ?? self::DIRECTION_UNDER);
        $period = (string) ($data->budgetperiod ?? ledger::PERIOD_ROLLING);
        $metric = (string) ($data->budgetmetric ?? ledger::METRIC_COST);
        $metric = $metric === ledger::METRIC_REQUESTS
            ? ledger::METRIC_REQUESTS
            : ledger::METRIC_COST;

        return [
            'scope' => in_array($scope, ledger::get_scopes(), true) ? $scope : ledger::SCOPE_SITE,
            'direction' => $direction === self::DIRECTION_OVER ? self::DIRECTION_OVER : self::DIRECTION_UNDER,
            'metric' => $metric,
            'provider' => $metric === ledger::METRIC_REQUESTS ? '' : trim((string) ($data->budgetprovider ?? '')),
            'amount' => $metric === ledger::METRIC_REQUESTS
                ? (float) round((float) $amount)
                : (float) $amount,
            'period' => $period === ledger::PERIOD_MONTH
                ? ledger::PERIOD_MONTH
                : ledger::PERIOD_ROLLING,
            'days' => max(1, (int) ($data->budgetdays ?? self::DEFAULT_DAYS)),
        ];
    }

    #[\Override]
    public static function to_form_data(array $config): array {
        return [
            'budgetscope' => $config['scope'] ?? '',
            'budgetdirection' => $config['direction'] ?? self::DIRECTION_UNDER,
            'budgetmetric' => $config['metric'] ?? ledger::METRIC_COST,
            'budgetprovider' => $config['provider'] ?? '',
            'budgetamount' => isset($config['amount']) ? (string) $config['amount'] : '',
            'budgetperiod' => $config['period'] ?? ledger::PERIOD_ROLLING,
            'budgetdays' => $config['days'] ?? self::DEFAULT_DAYS,
        ];
    }

    #[\Override]
    public function get_description(): string {
        $scope = $this->get_scope();
        $period = $this->get_period() === ledger::PERIOD_MONTH
            ? get_string('condition:describe:budget:month', 'local_airouter')
            : get_string('condition:describe:budget:rolling', 'local_airouter', $this->get_days());

        // One string per metric as well as per direction. The verb differs between
        // spending money and making requests, and in Japanese so does the word order,
        // so neither can be assembled from parts here.
        return get_string(
            'condition:describe:budget:' . $this->get_metric()
                . ':' . ($this->get_direction() ?: self::DIRECTION_UNDER),
            'local_airouter',
            [
                'scope' => get_string(
                    'condition:budget:scope:' . ($scope ?: ledger::SCOPE_SITE),
                    'local_airouter',
                ),
                'amount' => $this->get_amount_label(),
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
            ledger::SCOPE_COURSE => (int) ($context->get_courseid() ?? 0),
            ledger::SCOPE_USER => $context->get_userid(),
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
     * @param evaluation_context $context The request being weighed, which holds one
     *                                    ledger for all of its limits.
     * @return ledger The ledger.
     */
    protected function get_ledger(evaluation_context $context): ledger {
        return $context->get_ledger();
    }
}
