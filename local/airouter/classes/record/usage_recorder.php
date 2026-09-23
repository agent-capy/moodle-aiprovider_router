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
use local_airouter\price_book;
use local_airouter\rule;

/**
 * Writes what happens to a request as it happens: one row for the request, one row
 * for every provider asked on its behalf.
 *
 * Four moments, in order: the request is opened; a target is asked; that target
 * comes back, or does not; the request is closed. Each is written when it happens
 * rather than assembled at the end, so that a process that dies half way leaves
 * the truth up to that point, and a target that charged for a failure leaves the
 * charge.
 *
 * Nothing here may stop the request. A record is a tool for running a site, not an
 * obstacle on the path of every AI request, so a failure to write is reported to
 * the developer log, counted, and otherwise swallowed. A method whose write failed
 * returns null, and the later methods accept null and do nothing, so the caller
 * does not have to know.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_recorder {
    /** @var string The request table. */
    public const REQUEST_TABLE = 'local_airouter_request';

    /** @var string The attempt table. */
    public const ATTEMPT_TABLE = 'local_airouter_attempt';

    /**
     * @var string Plugin setting counting writes that failed.
     *
     * A count and nothing else: what it could not write, it could not write. It is
     * there so that a status check can say the history has holes, rather than the
     * holes being found by somebody comparing a bill with a report.
     */
    public const FAILURES_SETTING = 'recordfailures';

    /** @var string The setting counting endings written without the lock, whose cost is still to be worked out. */
    public const DEFERRED_SETTING = 'unpricedendings';

    /**
     * @var string The lock under which an attempt is attached to its request.
     *
     * Held for an existence check and an insert, and by a privacy deletion for its
     * deletes, so that the two cannot interleave: a request that has been forgotten
     * while in flight does not get a child a moment later that nothing can find.
     */
    public const LOCK = 'record';

    /** @var int How long to wait for that lock before giving the attempt up as unrecorded. */
    public const LOCK_TIMEOUT = 5;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to write to.
     * @param price_book|null $prices Where rates are looked up, or null for the site's.
     * @param \Closure|null $clock Returns the current time. Tests set this; nothing else does.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var price_book|null The rates. */
        protected ?price_book $prices = null,
        /** @var \Closure|null The clock. */
        protected readonly ?\Closure $clock = null,
    ) {
        $this->prices ??= new price_book($db);
    }

    /**
     * Open a request.
     *
     * Written before anything is decided about it: before the rate limit, before the
     * rules. What is known at this point is who asked, where, and for what.
     *
     * @param evaluation_context $context What the rules will be told about the request.
     * @return int|null The request id, or null when it could not be written.
     */
    public function begin_request(evaluation_context $context): ?int {
        try {
            return $this->db->insert_record(self::REQUEST_TABLE, (object) [
                'correlation' => \core\uuid::generate(),
                'userid' => $context->get_userid(),
                'contextid' => $context->get_contextid(),
                'courseid' => $context->get_courseid(),
                'actionname' => $context->get_action_name(),
                'placement' => $context->get_placement(),
                'keysource' => rule::KEYSOURCE_SITE,
                'state' => request_state::OPEN,
                'timestarted' => $this->now(),
            ]);
        } catch (\Throwable $e) {
            $this->note_failure('open a request', $e);

            return null;
        }
    }

    /**
     * Record that a target is being asked.
     *
     * Written before the call, so that a call the process does not survive is on
     * record as started, and can be swept up as lost rather than never having happened.
     *
     * @param int|null $requestid The request, or null when it could not be recorded.
     * @param int $seq Which attempt of the request this is, from one.
     * @param \core_ai\provider $target The instance being asked.
     * @param string $component The target's component, which is what it is priced by.
     * @param string $keysource Whose key it is being asked with.
     * @param int|null $keyid Which brought key, when one is used.
     * @param int $walletid The wallet that key charges, or zero for the site's own key.
     * @return int|null The attempt id, or null when it could not be written.
     */
    public function begin_attempt(
        ?int $requestid,
        int $seq,
        \core_ai\provider $target,
        string $component,
        string $keysource,
        ?int $keyid,
        int $walletid = 0,
    ): ?int {
        if ($requestid === null) {
            return null;
        }
        try {
            $lock = self::lock_factory()->get_lock(self::LOCK, self::LOCK_TIMEOUT);
            if (!$lock) {
                throw new \RuntimeException('the record lock was not obtained in ' . self::LOCK_TIMEOUT . ' seconds');
            }
            try {
                // The request may have been forgotten since it was opened: a deletion
                // request reaches a request in flight like any other. An attempt is
                // personal only through its request, so one made after the request has
                // gone would be personal data nothing could find again. It is not
                // recorded, and that is not a failure.
                if (!$this->db->record_exists(self::REQUEST_TABLE, ['id' => $requestid])) {
                    return null;
                }

                return $this->db->insert_record(self::ATTEMPT_TABLE, (object) [
                    'requestid' => $requestid,
                    'seq' => $seq,
                    'targetid' => (int) $target->id,
                    'targetname' => $target->name,
                    'targetprovider' => $component,
                    'keysource' => $keysource,
                    'keyid' => $keyid,
                    'walletid' => $walletid,
                    'state' => attempt_state::STARTED,
                    'timestarted' => $this->now(),
                ]);
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            $this->note_failure('record an attempt', $e);

            return null;
        }
    }

    /**
     * Record how a target came back, and what it used.
     *
     * The cost is worked out now, at the rate in force now, and kept. What the target
     * said it used is kept as it said it, including when it said nothing: a null count
     * is a count nobody gave, and is not turned into zero.
     *
     * An attempt ends once. The update applies only to a row still started, in one
     * statement, so that the same ending sent twice -- by a retried process, say --
     * leaves the first ending's cost, currency, usage and time exactly as they were,
     * whatever the rates or the date have become since. A row that has gone is left
     * gone: an update recreates nothing.
     *
     * The rate is looked up and the ending written under the record lock, which a
     * correction of a provider's currency holds for the whole of its run. So an
     * ending is either written before the correction, and worked out again by it, or
     * after, at the corrected rate; it cannot be priced before and written after,
     * which would put the old currency back on a row the correction never saw.
     *
     * @param int|null $attemptid The attempt, or null when it could not be recorded.
     * @param string $state One of attempt_state::TERMINAL.
     * @param usage $usage What the target reported using.
     * @param string|null $model The model the target said answered, already checked.
     * @param int|null $errorcode The error code, for a failure.
     * @param string $component The target's component, which is what it is priced by.
     */
    public function end_attempt(
        ?int $attemptid,
        string $state,
        usage $usage,
        ?string $model,
        ?int $errorcode,
        string $component,
    ): void {
        if ($attemptid === null) {
            return;
        }
        $ending = new attempt_ending($state, $usage, $model, $errorcode, $component);
        $lock = null;
        try {
            $lock = $this->take_lock('take the record lock before closing an attempt');
            $this->write_attempt_ending($attemptid, $ending, $lock !== null);
            if ($lock === null) {
                self::note_deferred();
            } else {
                // Holding the lock, this ending prices whatever was written without it.
                $this->price_deferred();
            }
        } catch (\Throwable $e) {
            $this->note_failure('close an attempt', $e);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Close the last attempt of a request and the request itself, in one commit.
     *
     * The same two writes end_attempt() and end_request() make, made in one short
     * transaction after the target has answered, so that the database makes one write
     * durable instead of two. Nothing else about them changes: the rate is looked up
     * and both rows written under the record lock, and the lock is let go only once the
     * commit has happened, because the lock is what keeps a currency correction and a
     * privacy deletion apart from an ending, and a transaction keeps nobody out. Rows
     * that have already ended, or have gone, are left as they are.
     *
     * Only the last attempt is closed this way. An attempt the request moves on from is
     * closed on its own before the next target is asked, so that what it used is on
     * record whatever happens to the next call.
     *
     * Two cases are written the way they always have been, one write after the other.
     * One is a caller that has a transaction of its own open: only the caller can make
     * anything durable then, and a rollback in here would take the caller's work with
     * it. The other is when only one of the two rows exists to be written.
     *
     * When the two writes cannot be committed together, what was written is rolled back
     * and each is made again on its own, still under the lock, exactly as the separate
     * methods make them. An attempt's ending must not be lost because its request's
     * could not be written, and the endings are conditional, so writing one again after
     * a commit whose answer was lost changes nothing that did happen. Pricing endings
     * left without a price by others is done afterwards and apart, so that its failure
     * cannot take this ending with it.
     *
     * @param int|null $attemptid The last attempt, or null when it could not be recorded.
     * @param attempt_ending $attempt How the attempt ended.
     * @param int|null $requestid The request, or null when it could not be recorded.
     * @param request_ending $request How the request ended.
     */
    public function end_last_attempt(?int $attemptid, attempt_ending $attempt, ?int $requestid, request_ending $request): void {
        if ($attemptid === null || $requestid === null || $this->db->is_transaction_started()) {
            $this->end_attempt(
                $attemptid,
                $attempt->state,
                $attempt->usage,
                $attempt->model,
                $attempt->errorcode,
                $attempt->component,
            );
            $this->end_request(
                $requestid,
                $request->state,
                $request->reason,
                $request->errorcode,
                $request->answeredby,
                $request->rule,
                $request->keysource,
                $request->attempts,
            );

            return;
        }

        $lock = null;
        try {
            $lock = $this->take_lock('take the record lock before closing the last attempt');
            if (!$this->write_both($attemptid, $attempt, $requestid, $request, $lock !== null)) {
                $this->write_again($attemptid, $attempt, $requestid, $request, $lock !== null);
            } else if ($lock === null) {
                self::note_deferred();
            }
            if ($lock !== null) {
                try {
                    $this->price_deferred();
                } catch (\Throwable $e) {
                    $this->note_failure('price the endings left without a price', $e);
                }
            }
        } finally {
            $lock?->release();
        }
    }

    /**
     * Write both endings in one transaction of this recorder's own, and commit it.
     *
     * @param int $attemptid The attempt.
     * @param attempt_ending $attempt How it ended.
     * @param int $requestid The request.
     * @param request_ending $request How it ended.
     * @param bool $locked Whether the record lock is held, which is what allows a price.
     * @return bool True when both were committed; false when the transaction was undone.
     */
    protected function write_both(
        int $attemptid,
        attempt_ending $attempt,
        int $requestid,
        request_ending $request,
        bool $locked,
    ): bool {
        $transaction = $this->db->start_delegated_transaction();
        try {
            $this->write_attempt_ending($attemptid, $attempt, $locked);
            $this->write_request_ending($requestid, $request);
            $this->commit($transaction);

            return true;
        } catch (\Throwable $e) {
            $this->undo($transaction, $e);
            $this->note_failure('close the last attempt and its request together', $e);

            return false;
        }
    }

    /**
     * Make the transaction's work durable.
     *
     * @param \moodle_transaction $transaction The transaction this recorder opened.
     */
    protected function commit(\moodle_transaction $transaction): void {
        $transaction->allow_commit();
    }

    /**
     * Undo a transaction this recorder opened, and leave no transaction behind.
     *
     * Core's rollback throws the exception it is given once it has rolled back, which
     * is caught here: the caller already has it. A commit or a rollback that itself
     * failed leaves the transaction on core's stack, where everything written later in
     * the request -- core's own record of the action included -- would join it and be
     * thrown away at the end. The transaction on the stack is the one opened here and no
     * other, because this is never done inside a caller's transaction, so clearing the
     * stack clears nothing of anybody else's.
     *
     * @param \moodle_transaction $transaction The transaction.
     * @param \Throwable $e What went wrong.
     */
    protected function undo(\moodle_transaction $transaction, \Throwable $e): void {
        try {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
        } catch (\Throwable $thrown) {
            unset($thrown);
        }
        if ($this->db->is_transaction_started()) {
            $this->db->force_transaction_rollback();
        }
    }

    /**
     * Write the two endings again, one at a time, after they could not be committed together.
     *
     * @param int $attemptid The attempt.
     * @param attempt_ending $attempt How it ended.
     * @param int $requestid The request.
     * @param request_ending $request How it ended.
     * @param bool $locked Whether the record lock is still held.
     */
    protected function write_again(
        int $attemptid,
        attempt_ending $attempt,
        int $requestid,
        request_ending $request,
        bool $locked,
    ): void {
        try {
            $this->write_attempt_ending($attemptid, $attempt, $locked);
            if (!$locked) {
                self::note_deferred();
            }
        } catch (\Throwable $e) {
            $this->note_failure('close an attempt', $e);
        }
        try {
            $this->write_request_ending($requestid, $request);
        } catch (\Throwable $e) {
            $this->note_failure('close a request', $e);
        }
    }

    /**
     * Take the record lock, or say that it could not be taken.
     *
     * An ending that cannot take it is written all the same, since an ending that is
     * lost is worse than a wait: but written without a price. The lock is held by
     * something that may be pricing this provider's record again, and a price worked
     * out beside it could be in a currency the rates no longer say. The usage is kept
     * and the pricing left to whoever holds the lock next. Counted as a gap, so that
     * the status check says so.
     *
     * @param string $what What the lock was wanted for, for the failure count.
     * @return \core\lock\lock|null The lock, or null when it was not obtained in time.
     */
    protected function take_lock(string $what): ?\core\lock\lock {
        $lock = self::lock_factory()->get_lock(self::LOCK, self::LOCK_TIMEOUT) ?: null;
        if ($lock === null) {
            $this->note_failure(
                $what,
                new \RuntimeException('the record lock was not obtained in ' . self::LOCK_TIMEOUT . ' seconds'),
            );
        }

        return $lock;
    }

    /**
     * Write how an attempt ended, if it has not ended already.
     *
     * Priced only when the record lock is held; otherwise written without a price, for
     * whoever holds the lock next.
     *
     * @param int $attemptid The attempt.
     * @param attempt_ending $ending How it ended.
     * @param bool $locked Whether the record lock is held.
     */
    protected function write_attempt_ending(int $attemptid, attempt_ending $ending, bool $locked): void {
        $now = $this->now();
        $usage = $ending->usage;
        $price = $locked ? $this->prices->find($ending->component, $ending->model, $now) : null;
        $this->db->execute(
            'UPDATE {' . self::ATTEMPT_TABLE . '}
                SET state = :state, errorcode = :errorcode, model = :model,
                    prompttokens = :prompttokens, completiontokens = :completiontokens,
                    images = :images, usageknown = :usageknown,
                    cost = :cost, currency = :currency, unpriced = :unpriced, timeended = :timeended
              WHERE id = :id AND state = :started',
            [
                'state' => $ending->state,
                'errorcode' => $ending->errorcode,
                'model' => $ending->model,
                'prompttokens' => $usage->prompttokens,
                'completiontokens' => $usage->completiontokens,
                'images' => $usage->images,
                'usageknown' => (int) $usage->is_known(),
                'cost' => $price?->cost($usage->prompttokens, $usage->completiontokens, $usage->images),
                // The currency of the rate, which is the one the provider bills in.
                'currency' => $price?->get('currency'),
                'unpriced' => $locked ? 0 : 1,
                'timeended' => $now,
                'id' => $attemptid,
                'started' => attempt_state::STARTED,
            ],
        );
    }

    /**
     * Price the endings that were written without the lock.
     *
     * To be called with the record lock held: that is the whole point. Each is priced
     * at the rate in force when it ended, as a correction would price it, and stops
     * being unpriced whether or not a rate covered it -- an ending no rate covers is
     * simply unpriced the ordinary way, as one written under the lock would be.
     *
     * The count of deferred endings is kept in a setting so that this costs one
     * small read on the ordinary path and a scan only when there is something to
     * find. The count is approximate, since the endings that raise it hold no lock;
     * a pass that ignores it is taken by the summariser, so nothing waits forever.
     *
     * @param bool $always Whether to look even when the count says there is nothing.
     * @return int How many endings were priced.
     */
    public function price_deferred(bool $always = false): int {
        if (!$always && self::get_deferred_count() === 0) {
            return 0;
        }
        $count = 0;
        $rows = $this->db->get_recordset_select(
            self::ATTEMPT_TABLE,
            'unpriced = 1 AND state <> :started',
            ['started' => attempt_state::STARTED],
            'id ASC',
            'id, targetprovider, model, prompttokens, completiontokens, images, timestarted, timeended',
        );
        foreach ($rows as $row) {
            $price = $this->prices->find(
                (string) $row->targetprovider,
                $row->model,
                (int) ($row->timeended ?? $row->timestarted),
            );
            $this->db->execute(
                'UPDATE {' . self::ATTEMPT_TABLE . '}
                    SET cost = :cost, currency = :currency, unpriced = 0
                  WHERE id = :id AND unpriced = 1',
                [
                    'cost' => $price?->cost(
                        $row->prompttokens === null ? null : (int) $row->prompttokens,
                        $row->completiontokens === null ? null : (int) $row->completiontokens,
                        (int) $row->images,
                    ),
                    'currency' => $price?->get('currency'),
                    'id' => $row->id,
                ],
            );
            $count++;
        }
        $rows->close();
        set_config(self::DEFERRED_SETTING, max(0, self::get_deferred_count() - $count), 'local_airouter');

        return $count;
    }

    /**
     * How many endings have been written without a price and not yet priced, roughly.
     *
     * @return int The count.
     */
    public static function get_deferred_count(): int {
        global $DB;

        $value = $DB->get_field('config_plugins', 'value', ['plugin' => 'local_airouter', 'name' => self::DEFERRED_SETTING]);

        return $value === false || $value === null ? 0 : max(0, (int) $value);
    }

    /**
     * Count one more ending written without a price.
     */
    protected static function note_deferred(): void {
        try {
            set_config(self::DEFERRED_SETTING, self::get_deferred_count() + 1, 'local_airouter');
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Close a request.
     *
     * @param int|null $requestid The request, or null when it could not be recorded.
     * @param string $state One of request_state::TERMINAL.
     * @param string|null $reason Why it did not succeed, for anything but success.
     * @param int|null $errorcode The error code, for anything but success.
     * @param int|null $answeredby The target whose answer was used, for a success.
     * @param rule|null $rule The rule that claimed the request, if one did.
     * @param string $keysource Whose key the request was to be paid for with.
     * @param int $attempts How many targets the caller asked. Taken from the caller
     *                      rather than counted from the attempt table, so that a
     *                      request can be closed even when its attempts could not be
     *                      written; the count then says what the rows do not.
     */
    public function end_request(
        ?int $requestid,
        string $state,
        ?string $reason,
        ?int $errorcode,
        ?int $answeredby,
        ?rule $rule,
        string $keysource,
        int $attempts,
    ): void {
        if ($requestid === null) {
            return;
        }
        $ending = new request_ending($state, $reason, $errorcode, $answeredby, $rule, $keysource, $attempts);
        try {
            $this->write_request_ending($requestid, $ending);
        } catch (\Throwable $e) {
            $this->note_failure('close a request', $e);
        }
    }

    /**
     * Write how a request ended, if it has not ended already.
     *
     * Closed once, for the same reason an attempt ends once: the day a request is
     * counted in is the day it was closed, and a retried closing must not move it to
     * another.
     *
     * @param int $requestid The request.
     * @param request_ending $ending How it ended.
     */
    protected function write_request_ending(int $requestid, request_ending $ending): void {
        $this->db->execute(
            'UPDATE {' . self::REQUEST_TABLE . '}
                SET state = :state, reason = :reason, errorcode = :errorcode,
                    answeredby = :answeredby, ruleid = :ruleid, rulename = :rulename,
                    keysource = :keysource, attempts = :attempts, timeended = :timeended
              WHERE id = :id AND state = :open',
            [
                'state' => $ending->state,
                'reason' => $ending->reason,
                'errorcode' => $ending->errorcode,
                'answeredby' => $ending->answeredby,
                'ruleid' => $ending->rule === null ? null : (int) $ending->rule->get('id'),
                'rulename' => $ending->rule === null ? null : $ending->rule->get('name'),
                'keysource' => $ending->keysource,
                'attempts' => $ending->attempts,
                'timeended' => $this->now(),
                'id' => $requestid,
                'open' => request_state::OPEN,
            ],
        );
    }

    /**
     * The lock factory the recorder and the privacy deletion share.
     *
     * @return \core\lock\lock_factory The factory.
     */
    public static function lock_factory(): \core\lock\lock_factory {
        return \core\lock\lock_config::get_lock_factory('local_airouter');
    }

    /**
     * How many writes have failed since the count was last reset.
     *
     * @return int The count.
     */
    public static function get_failure_count(): int {
        return (int) get_config('local_airouter', self::FAILURES_SETTING);
    }

    /**
     * The current time.
     *
     * @return int The time.
     */
    protected function now(): int {
        return $this->clock === null ? time() : ($this->clock)();
    }

    /**
     * Say that a write failed, without letting it matter to the request.
     *
     * The count is best effort: if the database is what failed, counting into it
     * fails too, and that is reported the same way and then let go.
     *
     * @param string $what What was being written.
     * @param \Throwable $e What went wrong.
     */
    protected function note_failure(string $what, \Throwable $e): void {
        debugging(
            'local_airouter: could not ' . $what . ': ' . get_class($e) . ': ' . $e->getMessage(),
            DEBUG_NORMAL,
        );
        try {
            set_config(self::FAILURES_SETTING, self::get_failure_count() + 1, 'local_airouter');
        } catch (\Throwable $again) {
            unset($again);
        }
    }
}
