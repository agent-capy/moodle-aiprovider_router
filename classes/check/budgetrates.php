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

namespace aiprovider_router\check;

use aiprovider_router\condition\budget;
use aiprovider_router\rule;
use aiprovider_router\rule_repository;
use aiprovider_router\spend_ledger;
use aiprovider_router\usage_logger;
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
 * @package    aiprovider_router
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
            new \moodle_url('/ai/provider/router/rates.php'),
            get_string('rates:heading', 'aiprovider_router'),
        );
    }

    #[\Override]
    protected function check_router(): result {
        global $DB;

        $rules = $this->count_costed_rules();
        if ($rules === 0) {
            // Rates are worth entering anyway, for the monitor. They are only load
            // bearing once a rule routes on them, and that is what this check is about.
            return new result(result::NA, get_string('check:budgetrates:nobudget', 'aiprovider_router'));
        }

        $counts = $DB->get_record_sql(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN cost IS NULL THEN 0 ELSE 1 END) AS costed
               FROM {' . usage_logger::TABLE . '}
              WHERE timecreated >= :from',
            ['from' => time() - self::WINDOW_DAYS * DAYSECS],
        );
        $total = (int) ($counts->total ?? 0);
        $costed = (int) ($counts->costed ?? 0);
        if ($total === 0) {
            return new result(result::NA, get_string('check:budgetrates:norequests', 'aiprovider_router'));
        }

        $share = $costed / $total;
        $a = ['costed' => $costed, 'total' => $total, 'percent' => round($share * 100)];
        if ($costed === 0) {
            // Every budget condition on this site is not met, whichever way round it is
            // written, and no rule that carries one can ever match.
            return new result(
                result::ERROR,
                get_string('check:budgetrates:unpriced', 'aiprovider_router', $a),
                get_string('check:budgetrates:unpriced_details', 'aiprovider_router', $rules),
            );
        }
        if ($share < self::THRESHOLD) {
            return new result(
                result::WARNING,
                get_string('check:budgetrates:uncosted', 'aiprovider_router', $a),
                get_string('check:budgetrates:uncosted_details', 'aiprovider_router'),
            );
        }

        return new result(result::OK, get_string('check:budgetrates:ok', 'aiprovider_router', $a));
    }

    /**
     * How many enabled rules hold a budget that has to be priced to work.
     *
     * The metric is inside the condition's configuration rather than in a column, so
     * the rows are read and decoded here. There are as many of them as there are
     * budget conditions on the site, which is a handful.
     *
     * @return int The number of rules.
     */
    protected function count_costed_rules(): int {
        global $DB;

        $records = $DB->get_records_sql(
            'SELECT c.id, c.ruleid, c.configdata
               FROM {' . rule_repository::CONDITION_TABLE . '} c
               JOIN {' . rule::TABLE . '} r ON r.id = c.ruleid
              WHERE c.type = :type AND r.enabled = 1',
            ['type' => budget::get_type()],
        );
        $ruleids = [];
        foreach ($records as $record) {
            $config = json_decode((string) $record->configdata, true);
            if (!is_array($config)) {
                continue;
            }
            if ((new budget($config))->get_metric() === spend_ledger::METRIC_COST) {
                $ruleids[(int) $record->ruleid] = true;
            }
        }

        return count($ruleids);
    }
}
