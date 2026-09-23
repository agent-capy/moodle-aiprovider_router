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
     * @return int|null The attempt id, or null when it could not be written.
     */
    public function begin_attempt(
        ?int $requestid,
        int $seq,
        \core_ai\provider $target,
        string $component,
        string $keysource,
        ?int $keyid,
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
        if (!in_array($state, attempt_state::TERMINAL, true)) {
            throw new \coding_exception('Not a state an attempt can end in: ' . $state);
        }
        try {
            $now = $this->now();
            $price = $this->prices->find($component, $model, $now);
            $this->db->execute(
                'UPDATE {' . self::ATTEMPT_TABLE . '}
                    SET state = :state, errorcode = :errorcode, model = :model,
                        prompttokens = :prompttokens, completiontokens = :completiontokens,
                        images = :images, usageknown = :usageknown,
                        cost = :cost, currency = :currency, timeended = :timeended
                  WHERE id = :id AND state = :started',
                [
                    'state' => $state,
                    'errorcode' => $errorcode,
                    'model' => $model,
                    'prompttokens' => $usage->prompttokens,
                    'completiontokens' => $usage->completiontokens,
                    'images' => $usage->images,
                    'usageknown' => (int) $usage->is_known(),
                    'cost' => $price?->cost($usage->prompttokens, $usage->completiontokens, $usage->images),
                    // The currency of the rate, which is the one the provider bills in.
                    'currency' => $price?->get('currency'),
                    'timeended' => $now,
                    'id' => $attemptid,
                    'started' => attempt_state::STARTED,
                ],
            );
        } catch (\Throwable $e) {
            $this->note_failure('close an attempt', $e);
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
        if (!in_array($state, request_state::TERMINAL, true)) {
            throw new \coding_exception('Not a state a request can end in: ' . $state);
        }
        try {
            // Closed once, for the same reason an attempt ends once: the day a request
            // is counted in is the day it was closed, and a retried closing must not
            // move it to another.
            $this->db->execute(
                'UPDATE {' . self::REQUEST_TABLE . '}
                    SET state = :state, reason = :reason, errorcode = :errorcode,
                        answeredby = :answeredby, ruleid = :ruleid, rulename = :rulename,
                        keysource = :keysource, attempts = :attempts, timeended = :timeended
                  WHERE id = :id AND state = :open',
                [
                    'state' => $state,
                    'reason' => $reason,
                    'errorcode' => $errorcode,
                    'answeredby' => $answeredby,
                    'ruleid' => $rule === null ? null : (int) $rule->get('id'),
                    'rulename' => $rule === null ? null : $rule->get('name'),
                    'keysource' => $keysource,
                    'attempts' => $attempts,
                    'timeended' => $this->now(),
                    'id' => $requestid,
                    'open' => request_state::OPEN,
                ],
            );
        } catch (\Throwable $e) {
            $this->note_failure('close a request', $e);
        }
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
