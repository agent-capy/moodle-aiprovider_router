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

use aiprovider_router\rule_repository;
use aiprovider_router\spend_ledger;
use aiprovider_router\usage_aggregator;
use aiprovider_router\usage_logger;
use core\check\result;

/**
 * Says when a budget is looking back further than the site can remember.
 *
 * A budget is worked out from what is still stored. The history a budget needs is
 * protected from the purge and cannot be thrown away while the budget exists, and a
 * budget longer than the site keeps its summaries is refused when it is written. What
 * none of that can do is bring back history that had already gone when the budget was
 * written: a site that ran with a short retention, then lengthened it and set a
 * thirty day budget, has a budget counting thirty days over a table that holds three.
 *
 * The figure it produces is not unknown, and must not be made unknown -- a budget that
 * cannot be measured stops restricting anything, which is the opposite of what somebody
 * setting a limit wanted. It is simply smaller than the spending was, and it stays that
 * way until the history catches up. Nothing can fix that. This is where it gets said,
 * with the date the counting really starts from, so that an administrator reading a
 * budget at forty per cent knows whether to believe it.
 *
 * Which date that is comes from what the purge wrote down when it discarded something,
 * not from the oldest row that happens to be left. The two are not the same question,
 * and reading the second as the first got the worst case backwards: a site whose
 * history had been discarded entirely has two empty tables, exactly like a site that
 * has never used its AI, and this said that nothing had been recorded yet and moved
 * on. The one site that most needed telling was the one told there was nothing wrong.
 *
 * What the mark means is "this site can account for its AI use from here", and it is
 * not only the purge that sets it. A site upgrading from a version that kept no such
 * mark is credited with what it can still show and nothing earlier, because a day
 * that was removed by an older version leaves nothing to find it by. So the warning
 * says what can be said -- that the period before that date cannot be accounted for
 * -- rather than asserting how it came to be that way.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class budgethistory extends base {
    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/ai/provider/router/rules.php'),
            get_string('rules:heading', 'aiprovider_router'),
        );
    }

    #[\Override]
    protected function check_router(): result {
        global $DB;

        $budgets = (new rule_repository($DB))->get_budgets(null, true);
        if (!$budgets) {
            return new result(result::NA, get_string('check:budgethistory:nobudget', 'aiprovider_router'));
        }

        $aggregator = new usage_aggregator($DB);
        $from = $aggregator->get_history_from();
        if ($from === 0) {
            // This site can account for the whole of its own history: it has never
            // discarded anything, and it has been keeping the mark since it was
            // installed. Said as a fact about what happened rather than guessed from
            // the rows, which cannot tell a site that never used its AI from a site
            // whose history was taken away.
            $earliest = self::earliest_record($DB);

            return new result(result::OK, $earliest === null
                ? get_string('check:budgethistory:noneyet', 'aiprovider_router')
                : get_string('check:budgethistory:complete', 'aiprovider_router', userdate($earliest)));
        }

        $ledger = new spend_ledger($DB, $aggregator, false);
        $now = time();
        $shortest = null;
        foreach ($budgets as $budget) {
            [$start] = $ledger->get_window((string) $budget->period, (int) $budget->days, $now);
            if ($start >= $from) {
                continue;
            }
            $missing = (int) round(($from - $start) / DAYSECS);
            $shortest = max($shortest ?? 0, $missing);
        }

        if ($shortest === null) {
            return new result(result::OK, get_string('check:budgethistory:ok', 'aiprovider_router', userdate($from)));
        }

        return new result(
            result::WARNING,
            get_string('check:budgethistory:missing', 'aiprovider_router', [
                'from' => userdate($from),
                'days' => $shortest,
            ]),
            get_string('check:budgethistory:missing_details', 'aiprovider_router'),
        );
    }

    /**
     * The earliest moment the site still has any record of.
     *
     * Both tables, because a report reads the summaries for the older part of a period
     * and the detail for the newer, and a budget does the same.
     *
     * @param \moodle_database $db The database to read.
     * @return int|null The moment, or null when nothing is recorded at all.
     */
    public static function earliest_record(\moodle_database $db): ?int {
        $found = [];
        $detail = $db->get_field_sql('SELECT MIN(timecreated) FROM {' . usage_logger::TABLE . '}');
        if (!empty($detail)) {
            $found[] = (int) $detail;
        }
        $summary = $db->get_field_sql('SELECT MIN(daystart) FROM {' . usage_aggregator::TABLE . '}');
        if (!empty($summary)) {
            $found[] = (int) $summary;
        }

        return $found ? min($found) : null;
    }
}
