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
        $this->log(['cost' => null, 'targetprovider' => null, 'model' => null, 'success' => 0]);

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
