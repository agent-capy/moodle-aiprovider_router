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

use local_airouter\routing_harness;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/routing_harness.php');

/**
 * Closing the last attempt and its request when the database fails on the way.
 *
 * The target has answered by then, so the router must return the answer, and the target
 * must not be asked again. What was recorded is read on a second connection, and whether
 * a transaction was left behind is asked both of core and of the database server itself:
 * core can believe a transaction is over while the connection is still in it.
 *
 * What the person finally gets depends on core as well, which stores its own record of
 * the action after the router has returned, on the same connection. That is followed to
 * the end in the second test. Where the connection can be brought back, the person gets
 * the answer; where the database cannot even confirm a rollback, neither the answer nor
 * the record is guaranteed, and the test holds the behaviour the site was told about
 * (README, "Requests and attempts"; review R11-01, accepted as a known limit).
 *
 * The failures are injected into a driver on a connection of its own, and are armed only
 * after the target has answered, so everything before the end is recorded as usual.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage_recorder::class)]
final class closing_fault_test extends \advanced_testcase {
    use routing_harness;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
    }

    /**
     * A second connection, through a driver that fails where it is told to.
     *
     * @return \moodle_database The connection.
     */
    private function faulty_connection(): \moodle_database {
        global $CFG, $DB;

        if ($DB instanceof \mariadb_native_moodle_database) {
            require_once(__DIR__ . '/../fixtures/fault_mariadb_database.php');
            $faulty = new \local_airouter\fault_mariadb_database();
        } else if ($DB instanceof \pgsql_native_moodle_database) {
            require_once(__DIR__ . '/../fixtures/fault_pgsql_database.php');
            $faulty = new \local_airouter\fault_pgsql_database();
        } else {
            $this->markTestSkipped('Failures are injected into the MariaDB and PostgreSQL drivers only.');
        }
        $faulty->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);

        return $faulty;
    }

    /**
     * A delegator that asks the target, then arms the failures on the connection.
     *
     * @param \moodle_database $faulty The connection that fails.
     * @param int[] $failures Where it fails once the target has answered.
     * @return \local_airouter\delegator The delegator, counting how many times it asked.
     */
    private function delegator_that_arms(\moodle_database $faulty, array $failures): \local_airouter\delegator {
        return new class ($faulty, $failures) extends \local_airouter\delegator {
            /** @var int How many times a target was asked. */
            public int $calls = 0;

            /**
             * Constructor.
             *
             * @param \moodle_database $faulty The connection that fails.
             * @param int[] $failures Where it fails once the target has answered.
             */
            public function __construct(
                /** @var \moodle_database The connection that fails. */
                private readonly \moodle_database $faulty,
                /** @var int[] Where it fails once the target has answered. */
                private readonly array $failures,
            ) {
                parent::__construct($faulty);
            }

            #[\Override]
            public function delegate(
                \core_ai\provider $target,
                \core_ai\aiactions\base $action,
            ): \core_ai\aiactions\responses\response_base {
                $response = parent::delegate($target, $action);
                $this->calls++;
                $this->faulty->faults = $this->failures;

                return $response;
            }
        };
    }

    /**
     * Where the database fails, and what the record should say afterwards.
     *
     * @return array The cases: failures by point, the outcome, how many failures are counted.
     */
    public static function failures(): array {
        return [
            'nothing fails' => [[], 'priced', 0],
            'the lock cannot be asked for' => [['lock' => 1], 'unpriced', 1],
            'the transaction cannot begin' => [['begin' => 1], 'priced', 1],
            'the request cannot be closed' => [['request' => 1], 'priced', 1],
            'the commit is not sent' => [['commit_before' => 1], 'priced', 1],
            'the commit is answered and the answer lost' => [['commit_after' => 1], 'priced', 1],
            'core cannot roll back either' => [['commit_before' => 1, 'rollback' => 1], 'priced', 1],
            'the commit is not sent and the rollback does not go through' => [
                ['commit_before' => 1, 'rollback_statement' => 1],
                'unrecorded',
                0,
            ],
            'the request cannot be closed and nothing rolls back' => [
                ['request' => 1, 'rollback' => 1, 'rollback_statement' => 1],
                'unrecorded',
                0,
            ],
        ];
    }

    /**
     * Whatever fails while the end is recorded, the router returns the answer.
     *
     * @param int[] $failures Where the database fails, by point.
     * @param string $outcome priced, unpriced or unrecorded.
     * @param int $counted How many failures are counted where the status check reads them.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_a_failure_to_record_the_end_does_not_cost_the_routers_answer(
        array $failures,
        string $outcome,
        int $counted,
    ): void {
        global $DB;
        $this->add('to seven', 7);
        $this->rate('', 1.0);
        $original = $DB;
        $faulty = $this->faulty_connection();
        $delegator = $this->delegator_that_arms($faulty, $failures);

        $DB = $faulty;
        try {
            $response = $this->route([$this->target(7, \aiprovider_mock\provider::SUCCESS)], delegator: $delegator);
            $faulty->faults = [];
            $leftbycore = $faulty->is_transaction_started();
            $leftinthedatabase = $faulty->in_transaction_physically();
        } finally {
            $faulty->faults = [];
            if ($faulty->is_transaction_started() || $faulty->in_transaction_physically()) {
                // What core would do at the end of the request, so the test leaves nothing behind.
                $faulty->execute('ROLLBACK');
                $faulty->force_transaction_rollback();
            }
            $DB = $original;
            $faulty->dispose();
        }

        $this->assertTrue($response->get_success(), 'The person gets the answer.');
        $this->assertSame(1, $delegator->calls, 'The target is asked once.');
        $expected = [];
        foreach ($failures as $point => $times) {
            $expected = array_merge($expected, array_fill(0, $times, $point));
        }
        sort($expected);
        $happened = $faulty->happened;
        sort($happened);
        $this->assertSame($expected, $happened, 'Every failure asked for happened.');

        $request = $original->get_record(usage_recorder::REQUEST_TABLE, [], '*', MUST_EXIST);
        $attempt = $original->get_record(usage_recorder::ATTEMPT_TABLE, [], '*', MUST_EXIST);
        if ($outcome === 'unrecorded') {
            // The connection could not be shown to be out of the transaction, so nothing
            // was written on it again, and core's own record of the transaction is left
            // for core to roll back, and report, at the end of the request.
            $this->assertSame(request_state::OPEN, $request->state);
            $this->assertSame(attempt_state::STARTED, $attempt->state);
            $this->assertTrue($leftbycore, 'Left for core to roll back at the end of the request.');
            $this->assertTrue($leftinthedatabase);
        } else {
            $this->assertSame(request_state::SUCCEEDED, $request->state);
            $this->assertSame(attempt_state::SUCCEEDED, $attempt->state);
            $this->assertSame(11, (int) $attempt->prompttokens);
            $this->assertSame(22, (int) $attempt->completiontokens);
            $this->assertFalse($leftbycore, 'No transaction is left on core\'s stack.');
            $this->assertFalse($leftinthedatabase, 'No transaction is left open in the database.');
            if ($outcome === 'priced') {
                $this->assertNotNull($attempt->cost);
                $this->assertSame(0, (int) $attempt->unpriced);
            } else {
                // A lock that could not be asked for gives no right to a price.
                $this->assertNull($attempt->cost);
                $this->assertSame(1, (int) $attempt->unpriced);
            }
        }
        $failures = $original->get_field(
            'config_plugins',
            'value',
            ['plugin' => 'local_airouter', 'name' => usage_recorder::FAILURES_SETTING],
        );
        $this->assertSame($counted, (int) $failures);
        $this->assertDebuggingCalledCount($outcome === 'unrecorded' ? 2 : $counted);
    }

    /**
     * Where the database fails, and what reaches the person once core has stored its record.
     *
     * @return array The cases: failures by point, whether the answer reaches the person,
     *               whether the router's record and core's record were made durable.
     */
    public static function failures_through_core(): array {
        return [
            'nothing fails' => [[], true, true],
            'the lock cannot be asked for' => [['lock' => 1], true, true],
            'the transaction cannot begin' => [['begin' => 1], true, true],
            'the request cannot be closed' => [['request' => 1], true, true],
            'the commit is not sent' => [['commit_before' => 1], true, true],
            'the commit is answered and the answer lost' => [['commit_after' => 1], true, true],
            'core cannot roll back either' => [['commit_before' => 1, 'rollback' => 1], true, true],
            'the request cannot be closed and core cannot roll back' => [['request' => 1, 'rollback' => 1], true, true],
            // The connection cannot be brought back. Nothing is recorded; the answer still
            // arrives here, because nothing has told core to refuse its own commit.
            'the commit is not sent and the rollback does not go through' => [
                ['commit_before' => 1, 'rollback_statement' => 1],
                true,
                false,
            ],
            // Nor here, and core's own rollback, which failed, had already marked the
            // transaction as one to be rolled back: core refuses to commit its record and
            // the answer is lost with it. R11-01, accepted as a known limit.
            'the request cannot be closed and nothing rolls back' => [
                ['request' => 1, 'rollback' => 1, 'rollback_statement' => 1],
                false,
                false,
            ],
        ];
    }

    /**
     * What reaches the person once core has stored its own record of the action.
     *
     * The router's processor is made the way the harness makes it; everything after it is
     * core's own, unchanged: process_action(), the private store_action_result() and the
     * action's store().
     *
     * @param int[] $failures Where the database fails, by point.
     * @param bool $answered Whether the answer reaches the person.
     * @param bool $durable Whether the router's record and core's record are made durable.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures_through_core')]
    public function test_what_reaches_the_person_once_core_has_stored_its_record(
        array $failures,
        bool $answered,
        bool $durable,
    ): void {
        global $DB;
        $this->add('to seven', 7);
        $this->rate('', 1.0);
        $original = $DB;
        $faulty = $this->faulty_connection();
        $delegator = $this->delegator_that_arms($faulty, $failures);
        $action = new \core_ai\aiactions\generate_text(
            contextid: \context_system::instance()->id,
            userid: 2,
            prompttext: 'Hello',
        );
        $router = new \local_airouter\fixture_router(enabled: true, name: 'Router', config: '{"defaulttarget":7}', id: 1);
        $processor = fn() => $this->route(
            [$this->target(7, \aiprovider_mock\provider::SUCCESS)],
            delegator: $delegator,
            action: $action,
        );
        $dispatch = new class ($faulty, $router, $processor) extends \local_airouter\single_router_dispatch {
            /**
             * Constructor.
             *
             * @param \moodle_database $db The database.
             * @param \core_ai\provider $router The router.
             * @param \Closure $processor Runs the router's processor.
             */
            public function __construct(
                \moodle_database $db,
                \core_ai\provider $router,
                /** @var \Closure Runs the router's processor. */
                private readonly \Closure $processor,
            ) {
                parent::__construct($db, $router);
            }

            #[\Override]
            protected function call_action_provider(
                \core_ai\provider $provider,
                \core_ai\aiactions\base $action,
            ): \core_ai\aiactions\responses\response_base {
                return ($this->processor)();
            }
        };

        $DB = $faulty;
        $response = null;
        $thrown = null;
        try {
            try {
                $response = $dispatch->process_action($action);
            } catch (\Throwable $e) {
                $thrown = $e;
            }
            $faulty->faults = [];
            // Read before anything is rolled back: what another process sees now.
            $coresee = $original->count_records('ai_action_register');
        } finally {
            $faulty->faults = [];
            if ($faulty->is_transaction_started() || $faulty->in_transaction_physically()) {
                // What core does at the end of the request.
                $faulty->execute('ROLLBACK');
                $faulty->force_transaction_rollback();
            }
            $DB = $original;
            $faulty->dispose();
        }

        $this->assertSame(1, $delegator->calls, 'The target is asked once, whatever happens.');
        if ($answered) {
            $this->assertNull($thrown);
            $this->assertTrue($response->get_success(), 'The person gets the answer.');
        } else {
            $this->assertInstanceOf(\dml_transaction_exception::class, $thrown, 'Core refuses its own commit.');
        }
        $request = $original->get_record(usage_recorder::REQUEST_TABLE, [], '*', MUST_EXIST);
        $attempt = $original->get_record(usage_recorder::ATTEMPT_TABLE, [], '*', MUST_EXIST);
        if ($durable) {
            $this->assertSame(request_state::SUCCEEDED, $request->state);
            $this->assertSame(attempt_state::SUCCEEDED, $attempt->state);
            $this->assertSame(22, (int) $attempt->completiontokens);
            $this->assertSame(1, $coresee, 'Core\'s record of the action is committed.');
        } else {
            // Not turned into a success, nor into a call that cost nothing: left unfinished,
            // for the sweeper and the status check.
            $this->assertSame(request_state::OPEN, $request->state);
            $this->assertSame(attempt_state::STARTED, $attempt->state);
            $this->assertNull($attempt->cost);
            $this->assertSame(0, $coresee, 'Nothing core wrote on the connection was committed.');
            $this->assertSame(0, $original->count_records('ai_action_register'), 'Nor is it once rolled back.');
        }
        // One failure counted for anything that went wrong; one more, the report of what
        // was not recorded, where the connection could not be brought back.
        $this->assertDebuggingCalledCount($failures === [] ? 0 : ($durable ? 1 : 2));
    }
}
