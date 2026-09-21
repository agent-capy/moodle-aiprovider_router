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

namespace aiprovider_router;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/ai/provider/router/db/upgrade.php');

/**
 * Tests for what an upgrade does to the figures a site already has.
 *
 * An upgrade that only changes the shape of the table leaves the meaning of what is in
 * it behind. That is what happened here: the column holding "how many of these were
 * priced" was renamed and kept, and what it held was a count made the old way, which
 * is the count that made a day holding a cost report that nothing in it was priced. A
 * budget reading such a day stops refusing. So the figures are migrated, not only the
 * columns.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class upgrade_test extends \advanced_testcase {
    /** @var usage_aggregator The aggregator, for its calendar. */
    protected usage_aggregator $aggregator;

    /** @var int Midnight of the day the fixtures describe. */
    protected int $day;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        self::setTimezone('UTC', 'UTC');
        $this->aggregator = new usage_aggregator($DB);
        $this->day = $this->aggregator->add_days($this->aggregator->day_of(time()), -1);
    }

    /**
     * Run the upgrade steps a site on the previous version would run.
     */
    protected function upgrade(): void {
        set_config('version', 2026092102, 'aiprovider_router');
        $this->assertTrue(xmldb_aiprovider_router_upgrade(2026092102));
    }

    /**
     * A summary row shaped the way the previous version wrote them.
     *
     * @param array $fields What to record, over the defaults.
     */
    protected function summary(array $fields = []): void {
        global $DB;

        $DB->insert_record(usage_aggregator::TABLE, (object) ($fields + [
            'daystart' => $this->day,
            'courseid' => null,
            'userid' => 5,
            'actionname' => 'generate_text',
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_mock',
            'model' => 'gpt-4o',
            'keysource' => usage_logger::KEY_SITE,
            'currency' => 'USD',
            'requests' => 1,
            'failures' => 1,
            'calls' => 1,
            'prompttokens' => 1000,
            'completiontokens' => 200,
            'cost' => 1.2,
            'costedcalls' => 0,
            'timecreated' => time(),
        ]));
    }

    /**
     * One detail row on the fixture day.
     *
     * @param array $fields What to record, over the defaults.
     */
    protected function log(array $fields = []): void {
        global $DB;

        $DB->insert_record(usage_logger::TABLE, (object) ($fields + [
            'timecreated' => $this->day + HOURSECS,
            'userid' => 5,
            'contextid' => 0,
            'courseid' => null,
            'actionname' => 'generate_text',
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_mock',
            'model' => 'gpt-4o',
            'currency' => 'USD',
            'success' => 1,
            'attempts' => 1,
            'prompttokens' => 1000,
            'completiontokens' => 200,
            'cost' => 1.2,
            'keysource' => usage_logger::KEY_SITE,
        ]));
    }

    public function test_a_day_that_still_has_its_detail_is_summarised_again(): void {
        global $DB;

        // What the previous version left behind: a day holding a cost of 1.20 whose
        // count of priced rows is nought, because the row that was priced was the
        // attempt that answered with nothing and was therefore not a request.
        $this->summary();
        $this->log(['counted' => 0, 'success' => 0]);
        // The row carrying the request reached no target at all, so it reports no
        // tokens either. That is what a refusal looks like in the log.
        $this->log([
            'cost' => null,
            'success' => 0,
            'targetid' => null,
            'targetname' => null,
            'targetprovider' => null,
            'model' => null,
            'prompttokens' => null,
            'completiontokens' => null,
        ]);

        $this->upgrade();

        // Two rows, because the call that answered with nothing reached a target and
        // the row carrying the request reached none, and the summary groups on that.
        // Added up, which is how everything reads them, the day is one request, two
        // calls and 1.20 that something was priced at.
        $totals = $DB->get_record_sql(
            'SELECT SUM(requests) AS requests, SUM(calls) AS calls,
                    SUM(costedcalls) AS costedcalls, SUM(cost) AS cost
               FROM {' . usage_aggregator::TABLE . '} WHERE daystart = :day',
            ['day' => $this->day],
        );
        $this->assertSame(1, (int) $totals->requests);
        $this->assertSame(2, (int) $totals->calls);
        $this->assertSame(1, (int) $totals->costedcalls);
        $this->assertEqualsWithDelta(1.2, (float) $totals->cost, 0.000001);
    }

    public function test_a_day_whose_detail_has_gone_keeps_its_money(): void {
        global $DB;

        // Nothing can rebuild this day. What must not happen is that its cost stops
        // counting against a budget: an amount exists because something was priced,
        // whatever the count beside it says.
        $this->summary();

        $this->upgrade();

        $row = $DB->get_record(usage_aggregator::TABLE, ['daystart' => $this->day]);
        $this->assertEqualsWithDelta(1.2, (float) $row->cost, 0.000001);
        $this->assertSame(1, (int) $row->costedcalls);
        $this->assertGreaterThanOrEqual(1, (int) $row->calls);

        $ledger = new spend_ledger($DB, $this->aggregator, false);
        set_config(usage_aggregator::LAST_SETTING, $this->day, 'aiprovider_router');
        $spend = $ledger->get_spend(spend_ledger::SCOPE_SITE, 0, spend_ledger::PERIOD_ROLLING, 30, time());
        $this->assertTrue($spend->is_known());
        $this->assertTrue($spend->has_reached(1.0));
    }

    public function test_a_day_that_has_lost_only_part_of_its_detail_is_left_alone(): void {
        global $DB;

        // Having some detail for a day is not having all of it. The purge removes
        // everything before a midnight in the timezone in force when it ran, and
        // after a change of timezone that midnight falls inside an older day: the
        // morning goes and the evening stays. Rebuilding such a day from what is left
        // replaces a figure that was right with one that is short, and a budget that
        // had been refusing falls open.
        $this->summary(['failures' => 0, 'requests' => 2, 'calls' => 2, 'costedcalls' => 2, 'cost' => 3.0]);
        $this->log(['timecreated' => $this->day + 20 * HOURSECS, 'cost' => 2.0]);

        $this->upgrade();

        $totals = $DB->get_record_sql(
            'SELECT SUM(requests) AS requests, SUM(cost) AS cost
               FROM {' . usage_aggregator::TABLE . '} WHERE daystart = :day',
            ['day' => $this->day],
        );
        $this->assertSame(2, (int) $totals->requests);
        $this->assertEqualsWithDelta(3.0, (float) $totals->cost, 0.000001);
    }

    public function test_a_day_whose_detail_has_gone_is_not_blanked(): void {
        global $DB;

        // The detail can be gone for reasons other than the daily purge, and
        // summarising a day that has none left would replace what is known about it
        // with nothing at all.
        $this->summary(['cost' => null, 'costedcalls' => 0]);

        $this->upgrade();

        $this->assertSame(1, $DB->count_records(usage_aggregator::TABLE, ['daystart' => $this->day]));
        $row = $DB->get_record(usage_aggregator::TABLE, ['daystart' => $this->day]);
        $this->assertSame(1, (int) $row->requests);
        // Nothing was priced that day, so nothing is invented.
        $this->assertSame(0, (int) $row->costedcalls);
    }

    public function test_losing_an_unpriced_attempt_does_not_make_a_day_fully_priced(): void {
        global $DB;

        // An attempt that answered with nothing and had no rate is counted as no
        // request and carries no cost, so losing one leaves the requests and the cost
        // of the day exactly as they were. Proving the rebuild by those two alone let
        // such a day go quietly from half priced to fully priced, and the note saying
        // the cost was incomplete disappeared with it.
        $this->summary([
            'failures' => 0,
            'requests' => 1,
            'calls' => 2,
            'costedcalls' => 1,
            'cost' => 1.0,
            'prompttokens' => 0,
            'completiontokens' => 0,
        ]);
        $this->log(['cost' => 1.0, 'prompttokens' => 0, 'completiontokens' => 0]);

        $this->upgrade();

        $totals = $DB->get_record_sql(
            'SELECT SUM(requests) AS requests, SUM(calls) AS calls,
                    SUM(costedcalls) AS costedcalls, SUM(cost) AS cost
               FROM {' . usage_aggregator::TABLE . '} WHERE daystart = :day',
            ['day' => $this->day],
        );
        $this->assertSame(1, (int) $totals->requests);
        $this->assertSame(2, (int) $totals->calls);
        $this->assertSame(1, (int) $totals->costedcalls);
        $this->assertEqualsWithDelta(1.0, (float) $totals->cost, 0.000001);
    }

    public function test_losing_an_attempt_does_not_lose_the_tokens_it_used(): void {
        global $DB;

        // The figure that catches this is the tokens. An attempt that answered with
        // nothing and had no rate is no request and carries no cost, and the count of
        // calls it would raise starts from the request count, so none of those three
        // moves when it goes. The provider charged for the tokens it used, and the
        // summary was the only place the site still had them.
        $this->summary([
            'failures' => 0,
            'requests' => 1,
            // What the version before last wrote: the calls of a day were taken to be
            // its requests, which is a floor and not the truth.
            'calls' => 1,
            'costedcalls' => 1,
            'cost' => 1.0,
            'prompttokens' => 1050,
            'completiontokens' => 210,
        ]);
        $this->log([
            'cost' => 1.0,
            'prompttokens' => 50,
            'completiontokens' => 10,
        ]);

        $this->upgrade();

        $totals = $DB->get_record_sql(
            'SELECT SUM(requests) AS requests, SUM(prompttokens) AS prompttokens,
                    SUM(completiontokens) AS completiontokens, SUM(cost) AS cost
               FROM {' . usage_aggregator::TABLE . '} WHERE daystart = :day',
            ['day' => $this->day],
        );
        $this->assertSame(1050, (int) $totals->prompttokens);
        $this->assertSame(210, (int) $totals->completiontokens);
        $this->assertSame(1, (int) $totals->requests);
        $this->assertEqualsWithDelta(1.0, (float) $totals->cost, 0.000001);
    }

    public function test_a_day_whose_detail_accounts_for_all_of_it_is_still_rebuilt(): void {
        global $DB;

        // The control for the test above. Nothing is missing here, so the rebuild
        // goes ahead and raises the calls off the floor, which is what it is for.
        $this->summary([
            'failures' => 0,
            'requests' => 1,
            'calls' => 1,
            'costedcalls' => 1,
            'cost' => 1.0,
            'prompttokens' => 1050,
            'completiontokens' => 210,
        ]);
        $this->log([
            'counted' => 0,
            'success' => 0,
            'cost' => null,
            'targetprovider' => null,
            'model' => null,
            'prompttokens' => 1000,
            'completiontokens' => 200,
        ]);
        $this->log(['cost' => 1.0, 'prompttokens' => 50, 'completiontokens' => 10]);

        $this->upgrade();

        $totals = $DB->get_record_sql(
            'SELECT SUM(requests) AS requests, SUM(calls) AS calls, SUM(costedcalls) AS costedcalls,
                    SUM(prompttokens) AS prompttokens, SUM(completiontokens) AS completiontokens
               FROM {' . usage_aggregator::TABLE . '} WHERE daystart = :day',
            ['day' => $this->day],
        );
        $this->assertSame(1, (int) $totals->requests);
        $this->assertSame(2, (int) $totals->calls);
        $this->assertSame(1, (int) $totals->costedcalls);
        $this->assertSame(1050, (int) $totals->prompttokens);
        $this->assertSame(210, (int) $totals->completiontokens);
    }

    public function test_a_site_that_had_already_discarded_history_is_given_a_starting_point(): void {
        // An older version discarded a month and wrote nothing down, and the site has
        // since put its retention back to unlimited. Reading the retention setting
        // said the site had discarded nothing, which is a claim about the past made
        // from a setting that only describes the present.
        set_config(usage_aggregator::SUMMARY_RETENTION_SETTING, 0, 'aiprovider_router');
        set_config(usage_aggregator::LAST_SETTING, $this->day, 'aiprovider_router');
        $this->log();

        $this->upgrade();

        // What it can still show, and nothing earlier.
        $this->assertSame($this->day + HOURSECS, $this->aggregator->get_history_from());
    }

    public function test_a_site_with_a_starting_point_already_keeps_it(): void {
        $earlier = $this->aggregator->add_days($this->day, -5);
        set_config(usage_aggregator::HISTORY_SETTING, $earlier, 'aiprovider_router');
        $this->log();

        $this->upgrade();

        $this->assertSame($earlier, $this->aggregator->get_history_from());
    }

    public function test_a_site_that_has_never_used_its_ai_is_left_alone(): void {
        $this->upgrade();

        // No history to be missing, so nothing to mark. Saying otherwise would warn
        // every fresh install about a period in which nothing happened.
        $this->assertSame(0, $this->aggregator->get_history_from());
    }

    public function test_a_day_left_alone_by_one_pass_is_not_disturbed_by_the_other(): void {
        global $DB;

        // A day that the old version got right: one request, one call, both priced.
        $this->summary(['failures' => 0, 'calls' => 1, 'costedcalls' => 1]);

        $this->upgrade();

        $row = $DB->get_record(usage_aggregator::TABLE, ['daystart' => $this->day]);
        $this->assertSame(1, (int) $row->requests);
        $this->assertSame(1, (int) $row->calls);
        $this->assertSame(1, (int) $row->costedcalls);
    }
}
