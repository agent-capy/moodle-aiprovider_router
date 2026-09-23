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

namespace local_airouter\check;

use local_airouter\condition\budget;
use local_airouter\price_book;
use local_airouter\record\attempt_state;
use local_airouter\record\ledger;
use local_airouter\record\summariser;
use local_airouter\record\usage_recorder;
use local_airouter\rule;
use local_airouter\rule_repository;
use local_airouter\usage_formatter;
use core\check\result;

/**
 * Checks that a site routing by budget has the rates to measure one.
 *
 * A cost is worked out from the site's own rate table when a request is recorded, so a
 * request whose model has no rate entered has no cost at all. A site that has entered
 * no rates therefore spends nothing, for ever, however much it uses, and every budget
 * condition on it is unmeasurable.
 *
 * Nothing about that looks wrong from the outside. The rules are there, they are
 * enabled, and they simply never match. This is where it gets said instead.
 *
 * Only budgets counted in money are weighed here. A budget counted in requests needs no
 * rates at all - requests are counted rather than priced - so a site routing entirely
 * by request counts is in good order however empty its rate table is, and telling it
 * otherwise would send somebody looking for a problem it does not have.
 *
 * A budget in money names a provider and is in that provider's currency, which is the
 * currency of its rates. A budget naming a provider with no rates has no currency to
 * be in and nothing to measure, whatever the rest of the site has priced, so that is
 * said first and by name.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class budgetrates extends base {
    /** @var int How many days of requests are looked at. */
    public const WINDOW_DAYS = 7;

    /** @var float The share of priced requests below which this is worth saying. */
    public const THRESHOLD = 0.5;

    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/local/airouter/rates.php'),
            get_string('rates:heading', 'local_airouter'),
        );
    }

    #[\Override]
    protected function check_router(): result {
        global $DB;

        $providers = $this->providers_of_costed_rules();
        $rules = count(array_unique(array_merge(...array_values($providers ?: [[]]))));
        if ($rules === 0) {
            // Rates are worth entering anyway, for the monitor. They are only load
            // bearing once a rule routes on them, and that is what this check is about.
            return new result(result::NA, get_string('check:budgetrates:nobudget', 'local_airouter'));
        }

        $book = new price_book($DB);
        $unrated = [];
        $unratedrules = [];
        foreach ($providers as $provider => $ruleids) {
            if ($provider === '' || $book->currency_of($provider) === null) {
                $unrated[] = usage_formatter::provider_name($provider);
                $unratedrules = array_merge($unratedrules, $ruleids);
            }
        }
        if ($unrated) {
            return new result(
                result::ERROR,
                get_string('check:budgetrates:noprovider', 'local_airouter', [
                    'providers' => implode(', ', $unrated),
                    'rules' => count(array_unique($unratedrules)),
                ]),
                get_string('check:budgetrates:noprovider_details', 'local_airouter'),
            );
        }

        $disagreeing = $this->count_calls_in_another_currency($book);
        if ($disagreeing['calls'] > 0) {
            // Recorded in a currency the provider's rates are not in now, so a budget
            // cannot weigh them. Saving the provider's rate again works them out again.
            return new result(
                result::WARNING,
                get_string('check:budgetrates:othercurrency', 'local_airouter', [
                    'calls' => $disagreeing['calls'],
                    'providers' => implode(', ', $disagreeing['providers']),
                ]),
                get_string('check:budgetrates:othercurrency_details', 'local_airouter'),
            );
        }

        $counts = $DB->get_record_sql(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN cost IS NULL THEN 0 ELSE 1 END) AS costed
               FROM {' . usage_recorder::ATTEMPT_TABLE . '}
              WHERE state <> :started AND timeended IS NOT NULL AND timeended >= :from',
            ['started' => attempt_state::STARTED, 'from' => summariser::days_before(time(), self::WINDOW_DAYS)],
        );
        $total = (int) ($counts->total ?? 0);
        $costed = (int) ($counts->costed ?? 0);
        if ($total === 0) {
            return new result(result::NA, get_string('check:budgetrates:norequests', 'local_airouter'));
        }

        $share = $costed / $total;
        $a = ['costed' => $costed, 'total' => $total, 'percent' => round($share * 100)];
        if ($costed === 0) {
            // Every budget condition on this site is not met, whichever way round it is
            // written, and no rule that carries one can ever match.
            return new result(
                result::ERROR,
                get_string('check:budgetrates:unpriced', 'local_airouter', $a),
                get_string('check:budgetrates:unpriced_details', 'local_airouter', $rules),
            );
        }
        if ($share < self::THRESHOLD) {
            return new result(
                result::WARNING,
                get_string('check:budgetrates:uncosted', 'local_airouter', $a),
                get_string('check:budgetrates:uncosted_details', 'local_airouter'),
            );
        }

        return new result(result::OK, get_string('check:budgetrates:ok', 'local_airouter', $a));
    }

    /**
     * Calls recorded in a currency other than the one their provider's rates are in.
     *
     * @param price_book $book The rates.
     * @return array calls, and the names of the providers concerned.
     */
    protected function count_calls_in_another_currency(price_book $book): array {
        global $DB;

        $calls = 0;
        $providers = [];
        foreach ($book->get_provider_currencies() as $provider => $currency) {
            $count = $DB->count_records_select(
                usage_recorder::ATTEMPT_TABLE,
                'targetprovider = :provider AND currency IS NOT NULL AND currency <> :currency',
                ['provider' => $provider, 'currency' => $currency],
            );
            if ($count > 0) {
                $calls += $count;
                $providers[] = usage_formatter::provider_name($provider);
            }
        }

        return ['calls' => $calls, 'providers' => $providers];
    }

    /**
     * The providers named by enabled rules holding a budget in money, with their rules.
     *
     * The metric is inside the condition's configuration rather than in a column, so
     * the rows are read and decoded here. There are as many of them as there are
     * budget conditions on the site, which is a handful.
     *
     * @return int[][] Rule ids keyed by provider component; an empty component for a
     *                 budget that names none.
     */
    protected function providers_of_costed_rules(): array {
        global $DB;

        $records = $DB->get_records_sql(
            'SELECT c.id, c.ruleid, c.configdata
               FROM {' . rule_repository::CONDITION_TABLE . '} c
               JOIN {' . rule::TABLE . '} r ON r.id = c.ruleid
              WHERE c.type = :type AND r.enabled = 1',
            ['type' => budget::get_type()],
        );
        $providers = [];
        foreach ($records as $record) {
            $config = json_decode((string) $record->configdata, true);
            if (!is_array($config)) {
                continue;
            }
            $condition = new budget($config);
            if ($condition->get_metric() === ledger::METRIC_COST) {
                $providers[$condition->get_provider()][] = (int) $record->ruleid;
            }
        }

        return $providers;
    }
}
