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

namespace local_airouter\record;

/**
 * The record read by person.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(person_reader::class)]
final class person_reader_test extends \advanced_testcase {
    /** @var int A fixed now: 10:00 server time. */
    private int $now;

    /** @var \local_airouter_generator The generator. */
    private \local_airouter_generator $generator;

    /** @var person_reader The reader under test. */
    private person_reader $reader;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->now = make_timestamp(2026, 9, 20, 10, 0, 0);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_airouter');
        $this->reader = new person_reader($DB);
    }

    /**
     * A finished request by somebody: a failed, charged call on target 1, then an answer from 2.
     *
     * @param int $userid Who asked.
     * @param int $ended When it ended.
     * @param string $keysource Whose key.
     * @param int|null $keyid Which key, for a brought one.
     * @return \stdClass The request.
     */
    private function request(int $userid, int $ended, string $keysource = 'site', ?int $keyid = null): \stdClass {
        $request = $this->generator->create_request([
            'userid' => $userid, 'answeredby' => 2, 'keysource' => $keysource, 'placement' => 'aiplacement_editor',
            'timestarted' => $ended - 10, 'timeended' => $ended,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'targetid' => 1, 'targetname' => 'One', 'model' => 'm1',
            'state' => attempt_state::FAILED, 'keysource' => $keysource, 'keyid' => $keyid,
            'prompttokens' => 100, 'completiontokens' => 0, 'cost' => 0.001, 'currency' => 'USD',
            'timestarted' => $ended - 8, 'timeended' => $ended - 5,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'targetid' => 2, 'targetname' => 'Two', 'model' => 'm2',
            'keysource' => $keysource, 'keyid' => $keyid,
            'prompttokens' => 100, 'completiontokens' => 50, 'cost' => 0.003, 'currency' => 'USD',
            'timestarted' => $ended - 4, 'timeended' => $ended,
        ]);

        return $request;
    }

    /**
     * The period the tests look at.
     *
     * @return int[] From and to.
     */
    private function week(): array {
        return [summariser::add_days(summariser::day_of($this->now), -6), $this->now + HOURSECS];
    }

    public function test_people_are_listed_busiest_first_with_their_money_kept_apart(): void {
        global $DB;
        $this->request(5, $this->now - DAYSECS);
        $this->request(5, $this->now, 'user', 9);
        $this->request(6, $this->now);
        [$from, $to] = $this->week();
        $before = $this->reader->get_people($from, $to);

        $this->assertSame([5, 6], array_column($before, 'userid'));
        $five = $before[0];
        $this->assertSame(2, $five->requests);
        $this->assertSame(1, $five->broughtrequests);
        $this->assertSame(500, $five->prompttokens + $five->completiontokens);
        // Money by provider, each in its currency, the site's key and the person's own apart.
        $this->assertSame(['aiprovider_mock|USD'], array_keys($five->sitecosts));
        $this->assertEqualsWithDelta(0.004, $five->sitecosts['aiprovider_mock|USD']['amount'], 0.000001);
        $this->assertSame('USD', $five->sitecosts['aiprovider_mock|USD']['currency']);
        $this->assertEqualsWithDelta(0.004, $five->broughtcosts['aiprovider_mock|USD']['amount'], 0.000001);
        $this->assertSame([], $before[1]->broughtcosts, 'Nothing brought is not zero brought.');

        (new summariser($DB))->run($this->now);
        $this->assertEquals($before, $this->reader->get_people($from, $to), 'Applying moves nothing.');
    }

    public function test_one_persons_days_leave_everybody_else_out(): void {
        global $DB;
        $this->request(5, $this->now - DAYSECS);
        $this->request(5, $this->now);
        $this->request(6, $this->now);
        [$from, $to] = $this->week();
        (new summariser($DB))->run($this->now);

        $days = $this->reader->get_days(5, $from, $to);
        // Newest day first; within a day, target One (failed call) and Two (answer, request).
        $this->assertSame(summariser::day_of($this->now), (int) $days[0]->daystart);
        $this->assertSame(summariser::day_of($this->now - DAYSECS), (int) end($days)->daystart);
        $this->assertSame(2, array_sum(array_column($days, 'requests')));
        $this->assertSame(4, array_sum(array_column($days, 'calls')));
        $targets = array_values(array_unique(array_column($days, 'targetname')));
        sort($targets);
        $this->assertSame(['One', 'Two'], $targets);
    }

    public function test_a_persons_requests_carry_what_every_call_for_them_used(): void {
        $request = $this->request(5, $this->now);
        $this->generator->create_request([
            'userid' => 5, 'state' => request_state::DECLINED, 'reason' => 'norule',
            'timestarted' => $this->now - 3600, 'timeended' => $this->now - 3600,
        ]);
        $this->request(6, $this->now);
        [$from, $to] = $this->week();

        $rows = $this->reader->get_requests(5, $from, $to);
        $this->assertCount(2, $rows);
        // Newest first.
        $this->assertSame((int) $request->id, $rows[0]->id);
        $this->assertSame(1, $rows[0]->success);
        $this->assertSame('Two', $rows[0]->targetname, 'The target that answered.');
        $this->assertSame('m2', $rows[0]->model);
        $this->assertSame(200, $rows[0]->prompttokens, 'Both calls, the failed one included.');
        $this->assertEqualsWithDelta(0.004, $rows[0]->cost, 0.000001);
        $this->assertSame('USD', $rows[0]->currency);
        $this->assertSame('aiplacement_editor', $rows[0]->placement);
        $this->assertSame(2, $rows[0]->attempts);
        // The declined one: nothing tried, nothing used, and it says why.
        $this->assertSame(0, $rows[1]->success);
        $this->assertSame('norule', $rows[1]->reason);
        $this->assertNull($rows[1]->targetname);
        $this->assertNull($rows[1]->prompttokens);
        $this->assertNull($rows[1]->cost);
    }

    public function test_a_request_charged_in_two_currencies_has_no_one_figure(): void {
        $request = $this->generator->create_request([
            'userid' => 5, 'timestarted' => $this->now, 'timeended' => $this->now,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'cost' => 1, 'currency' => 'USD', 'timeended' => $this->now,
        ]);
        $this->generator->create_attempt([
            'requestid' => $request->id, 'cost' => 100, 'currency' => 'JPY', 'timeended' => $this->now,
        ]);
        [$from, $to] = $this->week();

        $row = $this->reader->get_requests(5, $from, $to)[0];
        $this->assertNull($row->currency);
        $this->assertNull($row->cost);
    }

    public function test_what_each_brought_key_was_used_for(): void {
        $this->request(5, $this->now - DAYSECS, 'user', 9);
        $this->request(5, $this->now, 'user', 9);
        $this->request(6, $this->now, 'course', 12);
        $this->request(7, $this->now);
        [$from, $to] = $this->week();

        $usage = $this->reader->get_key_usage($from, $to);
        $this->assertSame([9, 12], array_map('intval', array_keys($usage)));
        $this->assertSame(2, (int) $usage[9]->requests);
        $this->assertEqualsWithDelta(0.008, (float) $usage[9]->cost, 0.000001);
        $this->assertSame(1, (int) $usage[12]->requests);
    }

    public function test_the_key_holders_carry_nothing_of_the_key_itself(): void {
        global $DB;
        set_config(
            \local_airouter\eligibility_policy::ACCESS_SETTING,
            \local_airouter\eligibility_policy::ACCESS_EVERYBODY,
            'local_airouter',
        );
        (new \local_airouter\key_repository($DB))->save(\local_airouter\key::SCOPE_USER, 5, 2, 'a-secret-key');

        $holders = $this->reader->get_key_holders();
        $this->assertCount(1, $holders);
        $this->assertObjectNotHasProperty('secret', $holders[0]);
        $this->assertObjectNotHasProperty('hint', $holders[0]);
        $this->assertSame(5, (int) $holders[0]->scopeid);
    }
}
