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

use local_airouter\evaluation_context;
use local_airouter\rule;
use local_airouter\routing_harness;
use core_ai\aiactions\generate_text;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/routing_harness.php');

/**
 * Closing the last attempt and its request in one commit.
 *
 * What is looked at here is what another process would see, so the rows are read on a
 * second connection rather than the one that wrote them: a row the writer can read back
 * is not yet a row that has been made durable. Every test runs outside the transaction
 * PHPUnit otherwise wraps a test in on PostgreSQL, because a write inside that
 * transaction is never committed at all and would make every test here pass or fail
 * for the wrong reason.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(usage_recorder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_ending::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(request_ending::class)]
final class closing_test extends \advanced_testcase {
    use routing_harness;

    /** @var int The clock the recorder reads. */
    private int $clock = 1_800_000_000;

    /** @var \moodle_database[] Connections other than the test's own. */
    private array $elsewhere = [];

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        \local_airouter\provider::get_instance_ids(true);
    }

    #[\Override]
    protected function tearDown(): void {
        foreach ($this->elsewhere as $other) {
            $other->dispose();
        }
        $this->elsewhere = [];
        parent::tearDown();
    }

    /**
     * A second connection to the same database, as another process has.
     *
     * @return \moodle_database The connection.
     */
    private function elsewhere(): \moodle_database {
        global $CFG;

        if (!$this->elsewhere) {
            $other = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
            $other->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
            $this->elsewhere[] = $other;
        }

        return $this->elsewhere[0];
    }

    /**
     * Whether another process could take the record lock now.
     *
     * @return bool True when it is free.
     */
    private function lock_is_free_elsewhere(): bool {
        global $DB;

        $original = $DB;
        try {
            // The site's lock factory binds to the connection that is $DB when it is made.
            $DB = $this->elsewhere();
            $lock = usage_recorder::lock_factory()->get_lock(usage_recorder::LOCK, 0);
            if ($lock) {
                $lock->release();
            }
        } finally {
            $DB = $original;
        }

        return (bool) $lock;
    }

    /**
     * A row as another process sees it.
     *
     * @param string $table The table.
     * @param int $id The row.
     * @return \stdClass|false The row, or false when it is not there.
     */
    private function seen(string $table, int $id): \stdClass|false {
        return $this->elsewhere()->get_record($table, ['id' => $id]);
    }

    /**
     * A rate, so that an ending under the lock has a price.
     */
    private function priced(): void {
        $rate = new \local_airouter\price();
        $rate->set('provider', 'aiprovider_openai');
        $rate->set('currency', 'USD');
        $rate->set('model', '');
        $rate->set('promptrate', 1.0);
        $rate->set('timefrom', 0);
        $rate->create();
    }

    /**
     * A request with one attempt started, as a routed request has once the target is asked.
     *
     * @param usage_recorder $recorder The recorder to open them with.
     * @return int[] The request and the attempt.
     */
    private function started(usage_recorder $recorder): array {
        $action = new generate_text(contextid: \context_system::instance()->id, userid: 2, prompttext: 'Hi');
        $request = $recorder->begin_request(new evaluation_context($action, placement: 'aiplacement_editor'));
        $target = new \aiprovider_openai\provider(enabled: true, name: 'Target', config: '{}', id: 42);
        $attempt = $recorder->begin_attempt($request, 1, $target, 'aiprovider_openai', rule::KEYSOURCE_SITE, null);

        return [$request, $attempt];
    }

    /**
     * How a successful attempt ended.
     *
     * @return attempt_ending The ending.
     */
    private function answered(): attempt_ending {
        return new attempt_ending(attempt_state::SUCCEEDED, new usage(1000000, 20), 'm', null, 'aiprovider_openai');
    }

    /**
     * How a successful request ended.
     *
     * @return request_ending The ending.
     */
    private function succeeded(): request_ending {
        return new request_ending(request_state::SUCCEEDED, null, null, 42, null, rule::KEYSOURCE_SITE, 1);
    }

    /**
     * A recorder whose steps can be made to fail, or be watched, by a test.
     *
     * @param array $hooks Closures by step: request (before the request's ending is
     *                     written), commit (in place of the commit, given the transaction
     *                     and the real commit), deferred (before pricing others' endings).
     * @return usage_recorder The recorder.
     */
    private function recorder(array $hooks = []): usage_recorder {
        global $DB;

        return new class ($DB, fn(): int => $this->clock, $hooks) extends usage_recorder {
            /**
             * Constructor.
             *
             * @param \moodle_database $db The database.
             * @param \Closure $clock The clock.
             * @param array $hooks The test's hooks.
             */
            public function __construct(
                \moodle_database $db,
                \Closure $clock,
                /** @var array The test's hooks. */
                private array $hooks,
            ) {
                parent::__construct($db, null, $clock);
            }

            #[\Override]
            protected function write_request_ending(int $requestid, request_ending $ending): void {
                if (isset($this->hooks['request'])) {
                    ($this->hooks['request'])();
                }
                parent::write_request_ending($requestid, $ending);
            }

            #[\Override]
            protected function commit(\moodle_transaction $transaction): void {
                if (isset($this->hooks['commit'])) {
                    ($this->hooks['commit'])($transaction, fn() => parent::commit($transaction));

                    return;
                }
                parent::commit($transaction);
            }

            #[\Override]
            public function price_deferred(bool $always = false): int {
                if (isset($this->hooks['deferred'])) {
                    ($this->hooks['deferred'])();
                }

                return parent::price_deferred($always);
            }
        };
    }

    public function test_the_two_endings_are_one_commit_made_under_the_lock(): void {
        global $DB;
        $this->priced();
        $seen = [];
        $recorder = $this->recorder([
            'request' => function () use (&$seen, $DB): void {
                $seen['transaction'] = $DB->is_transaction_started();
            },
            'commit' => function (\moodle_transaction $transaction, \Closure $commit) use (&$seen, &$attempt): void {
                // Both written, neither yet visible to anybody else, and the lock still ours.
                $seen['attempt before commit'] = $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt)->state;
                $seen['lock before commit'] = $this->lock_is_free_elsewhere();
                $commit();
            },
        ]);
        [$request, $attempt] = $this->started($recorder);

        $recorder->end_last_attempt($attempt, $this->answered(), $request, $this->succeeded());

        $this->assertTrue($seen['transaction'], 'The request is written in the same transaction as the attempt.');
        $this->assertSame(attempt_state::STARTED, $seen['attempt before commit']);
        $this->assertFalse($seen['lock before commit'], 'The lock is held until the commit has happened.');
        $this->assertSame(attempt_state::SUCCEEDED, $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt)->state);
        $this->assertSame('USD', $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt)->currency);
        $this->assertSame(request_state::SUCCEEDED, $this->seen(usage_recorder::REQUEST_TABLE, $request)->state);
        $this->assertTrue($this->lock_is_free_elsewhere());
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame(0, usage_recorder::get_failure_count());
    }

    public function test_a_request_that_cannot_be_closed_does_not_take_the_attempts_usage_with_it(): void {
        global $DB;
        $this->priced();
        $recorder = $this->recorder(['request' => function (): void {
            throw new \dml_write_exception('The request row could not be written');
        }]);
        [$request, $attempt] = $this->started($recorder);

        $recorder->end_last_attempt($attempt, $this->answered(), $request, $this->succeeded());

        // Rolled back together, and the attempt written again on its own: what the
        // target used is on record although the request could not be closed.
        $row = $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt);
        $this->assertSame(attempt_state::SUCCEEDED, $row->state);
        $this->assertSame(1000000, (int) $row->prompttokens);
        $this->assertEqualsWithDelta(1.0, (float) $row->cost, 0.000001);
        // The request is left open, where the sweeper and the status check find it.
        $this->assertSame(request_state::OPEN, $this->seen(usage_recorder::REQUEST_TABLE, $request)->state);
        $this->assertSame(2, usage_recorder::get_failure_count());
        $this->assertDebuggingCalledCount(2);
        $this->assertFalse($DB->is_transaction_started(), 'No transaction is left open.');
        $this->assertTrue($this->lock_is_free_elsewhere(), 'No lock is left held.');
    }

    public function test_endings_that_could_not_be_committed_together_are_written_one_at_a_time(): void {
        $this->priced();
        $fail = true;
        $recorder = $this->recorder(['request' => function () use (&$fail): void {
            if ($fail) {
                $fail = false;
                throw new \dml_write_exception('Once');
            }
        }]);
        [$request, $attempt] = $this->started($recorder);

        $recorder->end_last_attempt($attempt, $this->answered(), $request, $this->succeeded());

        $this->assertSame(attempt_state::SUCCEEDED, $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt)->state);
        $this->assertSame(request_state::SUCCEEDED, $this->seen(usage_recorder::REQUEST_TABLE, $request)->state);
        $this->assertSame(1, usage_recorder::get_failure_count());
        $this->assertDebuggingCalledCount(1);
    }

    public function test_a_commit_whose_answer_was_lost_is_not_written_twice(): void {
        global $DB;
        $this->priced();
        $recorder = $this->recorder(['commit' => function (\moodle_transaction $transaction, \Closure $commit): void {
            $commit();
            // Committed, and then the answer never arrives. The time moves on before the
            // endings are written again, so a second write would show.
            $this->clock += 100;
            throw new \dml_write_exception('The connection went away during COMMIT');
        }]);
        [$request, $attempt] = $this->started($recorder);
        $ended = $this->clock;

        $recorder->end_last_attempt($attempt, $this->answered(), $request, $this->succeeded());

        $row = $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt);
        $this->assertSame(attempt_state::SUCCEEDED, $row->state);
        $this->assertSame($ended, (int) $row->timeended, 'Ended once, when it ended.');
        $this->assertSame($ended, (int) $this->seen(usage_recorder::REQUEST_TABLE, $request)->timeended);
        $this->assertSame(1, $DB->count_records(usage_recorder::ATTEMPT_TABLE));
        $this->assertSame(1, usage_recorder::get_failure_count());
        $this->assertDebuggingCalledCount(1);
        $this->assertFalse($DB->is_transaction_started());
    }

    public function test_a_failed_commit_leaves_no_transaction_behind_and_is_written_again(): void {
        global $DB;
        $this->priced();
        $recorder = $this->recorder(['commit' => function (\moodle_transaction $transaction, \Closure $commit): void {
            // What core leaves when COMMIT itself fails: the transaction disposed of and
            // still on the stack, where everything written later would join it.
            $transaction->dispose();
            throw new \dml_write_exception('COMMIT failed');
        }]);
        [$request, $attempt] = $this->started($recorder);

        $recorder->end_last_attempt($attempt, $this->answered(), $request, $this->succeeded());

        $this->assertFalse($DB->is_transaction_started(), 'The stack is cleared.');
        $this->assertSame(attempt_state::SUCCEEDED, $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt)->state);
        $this->assertSame(request_state::SUCCEEDED, $this->seen(usage_recorder::REQUEST_TABLE, $request)->state);
        $this->assertTrue($this->lock_is_free_elsewhere());
        $this->assertDebuggingCalledCount(1);
        // And what is written next, as core writes its own record, is committed as usual.
        $DB->set_field(usage_recorder::REQUEST_TABLE, 'placement', 'after', ['id' => $request]);
        $this->assertSame('after', $this->seen(usage_recorder::REQUEST_TABLE, $request)->placement);
    }

    public function test_inside_a_callers_transaction_nothing_is_committed_for_it(): void {
        global $DB;
        $this->priced();
        $recorder = $this->recorder();
        [$request, $attempt] = $this->started($recorder);
        $transaction = $DB->start_delegated_transaction();

        $recorder->end_last_attempt($attempt, $this->answered(), $request, $this->succeeded());

        // Written into the caller's transaction and left there: only the caller commits.
        $this->assertTrue($DB->is_transaction_started());
        $this->assertSame(attempt_state::SUCCEEDED, $DB->get_field(usage_recorder::ATTEMPT_TABLE, 'state', ['id' => $attempt]));
        $this->assertSame(attempt_state::STARTED, $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt)->state);
        $transaction->allow_commit();
        $this->assertSame(attempt_state::SUCCEEDED, $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt)->state);
        $this->assertSame(request_state::SUCCEEDED, $this->seen(usage_recorder::REQUEST_TABLE, $request)->state);
    }

    public function test_pricing_others_endings_cannot_undo_this_one(): void {
        $this->priced();
        $recorder = $this->recorder(['deferred' => function (): void {
            throw new \dml_read_exception('The unpriced endings could not be read');
        }]);
        [$request, $attempt] = $this->started($recorder);

        $recorder->end_last_attempt($attempt, $this->answered(), $request, $this->succeeded());

        $this->assertSame(attempt_state::SUCCEEDED, $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt)->state);
        $this->assertEqualsWithDelta(1.0, (float) $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt)->cost, 0.000001);
        $this->assertSame(request_state::SUCCEEDED, $this->seen(usage_recorder::REQUEST_TABLE, $request)->state);
        $this->assertSame(1, usage_recorder::get_failure_count());
        $this->assertDebuggingCalledCount(1);
    }

    public function test_rows_forgotten_meanwhile_are_not_made_again(): void {
        global $DB;
        $recorder = $this->recorder(['request' => function (): void {
            throw new \dml_write_exception('And the rescue has to leave them gone too');
        }]);
        [$request, $attempt] = $this->started($recorder);
        $DB->delete_records(usage_recorder::ATTEMPT_TABLE, ['id' => $attempt]);
        $DB->delete_records(usage_recorder::REQUEST_TABLE, ['id' => $request]);

        $recorder->end_last_attempt($attempt, $this->answered(), $request, $this->succeeded());

        $this->assertSame(0, $DB->count_records(usage_recorder::ATTEMPT_TABLE));
        $this->assertSame(0, $DB->count_records(usage_recorder::REQUEST_TABLE));
        $this->assertDebuggingCalledCount(2);
    }

    public function test_without_the_lock_the_usage_is_kept_for_the_next_holder(): void {
        $this->priced();
        $recorder = $this->recorder();
        [$request, $attempt] = $this->started($recorder);
        global $DB;
        $original = $DB;
        try {
            $DB = $this->elsewhere();
            $held = usage_recorder::lock_factory()->get_lock(usage_recorder::LOCK, 0);
        } finally {
            $DB = $original;
        }
        $this->assertNotFalse($held);

        try {
            $recorder->end_last_attempt($attempt, $this->answered(), $request, $this->succeeded());
        } finally {
            $held->release();
        }

        $row = $this->seen(usage_recorder::ATTEMPT_TABLE, $attempt);
        $this->assertSame(attempt_state::SUCCEEDED, $row->state);
        $this->assertSame(1000000, (int) $row->prompttokens);
        $this->assertNull($row->cost, 'Not priced beside a lock somebody else holds.');
        $this->assertSame(1, (int) $row->unpriced);
        $this->assertSame(request_state::SUCCEEDED, $this->seen(usage_recorder::REQUEST_TABLE, $request)->state);
        $this->assertSame(1, usage_recorder::get_deferred_count());
        $this->assertDebuggingCalledCount(1);
    }

    public function test_during_the_call_the_start_is_on_record_and_nothing_is_held(): void {
        global $DB;
        $this->add('to seven', 7);
        $during = [];
        $delegator = new class ($DB, function (int $targetid) use (&$during, $DB): void {
            $request = $DB->get_field(usage_recorder::REQUEST_TABLE, 'MAX(id)', []);
            $attempts = $this->elsewhere()->get_records(
                usage_recorder::ATTEMPT_TABLE,
                ['requestid' => $request],
                'seq ASC',
                'seq, state',
            );
            $during[$targetid] = [
                'request' => $this->seen(usage_recorder::REQUEST_TABLE, (int) $request)->state ?? null,
                'attempts' => array_map(static fn($row) => $row->state, array_values($attempts)),
                'transaction' => $DB->is_transaction_started(),
                'lock free' => $this->lock_is_free_elsewhere(),
            ];
        }) extends \local_airouter\delegator {
            /**
             * Constructor.
             *
             * @param \moodle_database $db The database.
             * @param \Closure $before What to run before each call.
             */
            public function __construct(
                \moodle_database $db,
                /** @var \Closure What to run before each call. */
                private readonly \Closure $before,
            ) {
                parent::__construct($db);
            }

            #[\Override]
            public function delegate(
                \core_ai\provider $target,
                \core_ai\aiactions\base $action,
            ): \core_ai\aiactions\responses\response_base {
                ($this->before)((int) $target->id);

                return parent::delegate($target, $action);
            }
        };

        $response = $this->route(
            instances: [
                $this->target(7, \aiprovider_mock\provider::FAILURE, ['errorcode' => 500]),
                $this->target(8, \aiprovider_mock\provider::SUCCESS),
            ],
            config: ['defaulttarget' => 8],
            delegator: $delegator,
        );

        $this->assertTrue($response->get_success());
        // Before each call, another process already sees the request and the attempt
        // being made, and the one before it closed; and while the target is asked the
        // router holds neither a transaction nor the record lock.
        $this->assertSame([
            7 => ['request' => request_state::OPEN, 'attempts' => [attempt_state::STARTED],
                'transaction' => false, 'lock free' => true],
            8 => ['request' => request_state::OPEN, 'attempts' => [attempt_state::FAILED, attempt_state::STARTED],
                'transaction' => false, 'lock free' => true],
        ], $during);
        $request = $DB->get_field(usage_recorder::REQUEST_TABLE, 'MAX(id)', []);
        $this->assertSame(request_state::SUCCEEDED, $this->seen(usage_recorder::REQUEST_TABLE, (int) $request)->state);
        $this->assertSame(0, usage_recorder::get_failure_count());
    }
}
