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

use aiprovider_router\condition\budget;

/**
 * Tests for saying, once, that a budget has been reached.
 *
 * Saying it once is the whole difficulty. A daily task looking at a figure that is still
 * over its limit would repeat itself every morning, and remembering the period a notice
 * was about does not help for a rolling period, whose start moves every day. What is
 * remembered here is that a threshold is over, and it is forgotten when the spending
 * falls back under: a calendar month then resets itself on the first, without anything
 * having to know about months.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(budget_notifier::class)]
final class budget_notifier_test extends \advanced_testcase {
    /** @var budget_notifier The notifier under test. */
    protected budget_notifier $notifier;

    /** @var int A fixed moment. */
    protected int $now;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        self::setTimezone('UTC', 'UTC');
        $this->preventResetByRollback();
        $this->notifier = new budget_notifier($DB, new spend_ledger($DB, null, false));
        $this->now = time();
    }

    /**
     * Give the site a rule carrying a budget.
     *
     * @param string $scope Whose spending it is about.
     * @param float $amount The limit.
     * @param string $period How the period is counted.
     * @param string $metric What the limit counts.
     * @return rule The saved rule.
     */
    protected function budget_rule(
        string $scope,
        float $amount,
        string $period = spend_ledger::PERIOD_MONTH,
        string $metric = spend_ledger::METRIC_COST,
    ): rule {
        global $DB;

        $record = new rule();
        $record->set('name', 'While there is money left');
        $record->set('targetid', 3);

        return (new rule_repository($DB))->save($record, ['budget' => [
            'scope' => $scope,
            'direction' => budget::DIRECTION_UNDER,
            'metric' => $metric,
            'amount' => $amount,
            'period' => $period,
            'days' => 30,
        ]]);
    }

    /**
     * Write one recorded request the site paid for.
     *
     * @param float|null $cost What it cost.
     * @param array $fields Anything else to record.
     */
    protected function spent(?float $cost, array $fields = []): void {
        global $DB;

        $DB->insert_record(usage_logger::TABLE, (object) ($fields + [
            'timecreated' => time() - MINSECS,
            'userid' => 0,
            'contextid' => 0,
            'courseid' => null,
            'actionname' => 'generate_text',
            'targetid' => 1,
            'targetname' => 'Target one',
            'targetprovider' => 'aiprovider_openai',
            'model' => 'gpt-4o',
            'currency' => 'USD',
            'success' => 1,
            'attempts' => 1,
            'cost' => $cost,
            'keysource' => usage_logger::KEY_SITE,
        ]));
    }

    public function test_a_budget_that_has_been_reached_is_announced_once(): void {
        $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0);
        $this->spent(10.0);
        set_config(budget_notifier::SHARE_SETTING, 0, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $first = $this->notifier->run($this->now);
        $second = $this->notifier->run($this->now);

        $this->assertSame(1, $first['sent']);
        // The second morning. Still over, and nothing more to say about it.
        $this->assertSame(0, $second['sent']);
        $this->assertCount(1, $sink->get_messages());
    }

    public function test_an_early_warning_and_the_budget_itself_are_told_apart(): void {
        $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0);
        $this->spent(8.0);
        set_config(budget_notifier::SHARE_SETTING, 80, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $warned = $this->notifier->run($this->now);
        $this->spent(2.0);
        $reached = $this->notifier->run($this->now);

        $this->assertSame(1, $warned['sent']);
        $this->assertSame(1, $reached['sent']);
        $this->assertCount(2, $sink->get_messages());
    }

    public function test_spending_that_eases_off_can_be_announced_again(): void {
        global $DB;
        $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0, spend_ledger::PERIOD_ROLLING);
        $this->spent(10.0);
        set_config(budget_notifier::SHARE_SETTING, 0, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $this->notifier->run($this->now);
        // The next calendar month, or a rolling window that has moved past the spike.
        $DB->delete_records(usage_logger::TABLE);
        $eased = $this->notifier->run($this->now);
        $this->spent(10.0);
        $again = $this->notifier->run($this->now);

        // This is what lets a monthly budget speak once a month without anything
        // here knowing what a month is.
        $this->assertSame(1, $eased['cleared']);
        $this->assertSame(1, $again['sent']);
        $this->assertCount(2, $sink->get_messages());
    }

    public function test_spending_nobody_can_price_is_not_announced(): void {
        $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0);
        // A site with no rates entered. The status report is where that gets said; by
        // mail, every morning, would be a worse way to make the same point.
        $this->spent(null);
        $this->spent(null);

        $sink = $this->redirectMessages();
        $result = $this->notifier->run($this->now);

        $this->assertSame(0, $result['sent']);
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_a_site_can_switch_the_notices_off(): void {
        $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0);
        $this->spent(10.0);
        set_config(budget_notifier::ENABLED_SETTING, 0, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $result = $this->notifier->run($this->now);

        $this->assertSame(0, $result['sent']);
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_a_course_budget_tells_the_course_without_telling_it_the_money(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->budget_rule(spend_ledger::SCOPE_COURSE, 10.0);
        $this->spent(10.0, ['courseid' => (int) $course->id]);
        set_config(budget_notifier::SHARE_SETTING, 0, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $this->notifier->run($this->now);

        $theirs = array_values(array_filter(
            $sink->get_messages(),
            fn($message) => (int) $message->useridto === (int) $teacher->id,
        ));
        $this->assertCount(1, $theirs);
        // The teacher hears that the budget is reached and what it means, and no
        // figure at all. What the site spends is not shown to teachers anywhere else
        // either, and a notice must not be the way round that.
        $this->assertStringNotContainsString('10.00', $theirs[0]->fullmessage);
        $this->assertStringContainsString($course->fullname, $theirs[0]->fullmessage);
    }

    public function test_the_people_who_watch_the_spending_are_told_the_figures(): void {
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role(['archetype' => 'manager']);
        role_assign($roleid, $manager->id, \context_system::instance()->id);
        $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0);
        $this->spent(10.0);
        set_config(budget_notifier::SHARE_SETTING, 0, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $this->notifier->run($this->now);

        $theirs = array_values(array_filter(
            $sink->get_messages(),
            fn($message) => (int) $message->useridto === (int) $manager->id,
        ));
        $this->assertCount(1, $theirs);
        $this->assertStringContainsString('10.00', $theirs[0]->fullmessage);
    }

    public function test_a_key_limit_is_announced_to_its_owner(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $repository = new key_repository($DB);
        $saved = $repository->save(key::SCOPE_USER, (int) $user->id, 4, 'sk-their-own-key');
        $repository->set_cap($saved, 5.0, spend_ledger::PERIOD_MONTH, 30);
        $this->spent(5.0, [
            'userid' => (int) $user->id,
            'targetid' => 4,
            'keysource' => rule::KEYSOURCE_USER,
        ]);
        set_config(budget_notifier::SHARE_SETTING, 0, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $this->notifier->run($this->now);

        $theirs = array_values(array_filter(
            $sink->get_messages(),
            fn($message) => (int) $message->useridto === (int) $user->id,
        ));
        $this->assertCount(1, $theirs);
        // Their own key and their own money, so their own figures.
        $this->assertStringContainsString('5.00', $theirs[0]->fullmessage);
    }

    public function test_two_rules_asking_for_the_same_budget_are_one_budget(): void {
        global $DB;
        $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0);
        $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0);
        $this->spent(10.0);
        set_config(budget_notifier::SHARE_SETTING, 0, 'aiprovider_router');

        $this->assertCount(1, (new rule_repository($DB))->get_budgets());

        $sink = $this->redirectMessages();
        $result = $this->notifier->run($this->now);

        // One limit to think about, so one notice about it.
        $this->assertSame(1, $result['sent']);
        $this->assertCount(1, $sink->get_messages());
    }

    public function test_a_notice_nobody_could_be_sent_is_not_remembered_as_sent(): void {
        global $DB;

        // A site whose administrators have all been removed is not a real site, but it
        // is the shape of one that has told nobody about its spending. The point is
        // that nothing is written down as said when it was not.
        set_config('siteadmins', '');
        $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0);
        $this->spent(10.0);
        set_config(budget_notifier::SHARE_SETTING, 0, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $result = $this->notifier->run($this->now);

        $this->assertSame(0, $result['sent']);
        $this->assertCount(0, $sink->get_messages());
        $this->assertSame(0, $DB->count_records(budget_notifier::TABLE));
    }

    public function test_the_administrators_hear_about_it_without_holding_a_role(): void {
        // The core function get_users_by_capability() does not return administrators,
        // so a list built from it alone would leave most sites hearing nothing at all.
        $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0);
        $this->spent(10.0);
        set_config(budget_notifier::SHARE_SETTING, 0, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $this->notifier->run($this->now);

        $admins = array_keys(get_admins());
        $recipients = array_map(fn($message) => (int) $message->useridto, $sink->get_messages());
        $this->assertNotEmpty($recipients);
        $this->assertContains((int) reset($admins), $recipients);
    }

    public function test_a_disabled_rule_sets_no_budget(): void {
        global $DB;
        $record = $this->budget_rule(spend_ledger::SCOPE_SITE, 10.0);
        $record->set('enabled', 0);
        $record->update();
        $this->spent(10.0);

        $sink = $this->redirectMessages();
        $result = $this->notifier->run($this->now);

        $this->assertSame(0, $result['sent']);
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_a_budget_in_requests_is_announced_on_a_site_with_no_rates(): void {
        $this->budget_rule(spend_ledger::SCOPE_SITE, 3.0, spend_ledger::PERIOD_MONTH, spend_ledger::METRIC_REQUESTS);
        $this->spent(null);
        $this->spent(null);
        $this->spent(null);
        set_config(budget_notifier::SHARE_SETTING, 0, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $result = $this->notifier->run($this->now);

        // A budget in money would have said nothing at all here, because nothing on
        // this site can be priced. Three requests against three is reached.
        $this->assertSame(1, $result['sent']);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('3', $messages[0]->fullmessage);
        $this->assertStringNotContainsString('{$a', $messages[0]->fullmessage);
    }

    public function test_two_limits_of_the_same_size_do_not_silence_each_other(): void {
        // Three requests, and three of whatever the site's money is called. The two
        // limits are the same number and are reached at different moments, so what has
        // been said about one must not count as having been said about the other.
        $this->budget_rule(spend_ledger::SCOPE_SITE, 3.0, spend_ledger::PERIOD_MONTH, spend_ledger::METRIC_REQUESTS);
        $this->budget_rule(spend_ledger::SCOPE_SITE, 3.0, spend_ledger::PERIOD_MONTH, spend_ledger::METRIC_COST);
        $this->spent(1.0);
        $this->spent(1.0);
        $this->spent(1.0);
        set_config(budget_notifier::SHARE_SETTING, 0, 'aiprovider_router');

        $sink = $this->redirectMessages();
        $result = $this->notifier->run($this->now);

        $this->assertSame(2, $result['sent']);
        $this->assertCount(2, $sink->get_messages());
    }
}
