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

use core_ai\aiactions\responses\response_base;

/**
 * Shared delegation logic for every action the router handles.
 *
 * The router does not call an AI service itself. It resolves candidate targets, runs
 * the action against them in order and passes the winning response through. Returning
 * the delegated response data from query_ai_api() keeps core's own flow intact, so the
 * model, finish reason and token counts reported by the target survive, and the router's
 * own rate limiting still applies on the way in.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_processor extends \core_ai\process_base {
    /** @var string Reason recorded when no rule produced a target. */
    public const REASON_NO_TARGET = 'no_default_target';

    /** @var string Reason recorded when every candidate failed. */
    public const REASON_ALL_FAILED = 'all_targets_failed';

    /** @var string Reason recorded when the target spent its budget on reasoning. */
    public const REASON_EMPTY = 'empty_response_token_exhausted';

    /** @var string Reason recorded when core no longer exposes the delegation point. */
    public const REASON_UNAVAILABLE = 'delegation_unavailable';

    /** @var string Reason recorded when a target threw instead of returning a response. */
    public const REASON_TARGET_THREW = 'target_threw';

    /** @var string[] Finish reasons that mean the token budget ran out. */
    protected const TRUNCATED = ['length', 'max_tokens', 'model_length'];

    /** @var string|null Reason code for the last failure, for the monitor log in WP4. */
    protected ?string $reason = null;

    /**
     * The response field carrying the generated content, if the action has one.
     *
     * Actions that return content define this so that an empty generation can be told
     * apart from a successful one. Returning null skips the check.
     *
     * @return string|null The response data key, or null.
     */
    protected function get_content_key(): ?string {
        return null;
    }

    /**
     * Reason code for the last failure.
     *
     * @return string|null The reason code, or null if nothing has failed.
     */
    public function get_failure_reason(): ?string {
        return $this->reason;
    }

    #[\Override]
    protected function query_ai_api(): array {
        if (!delegator::is_available()) {
            return $this->fail(503, 'delegationunavailable', self::REASON_UNAVAILABLE);
        }

        $candidates = $this->get_resolver()->get_candidates($this->action);
        if (!$candidates) {
            return $this->fail(503, 'nodefaulttarget', self::REASON_NO_TARGET);
        }

        $delegator = $this->get_delegator();
        $last = null;
        $threw = false;
        foreach ($candidates as $target) {
            try {
                $response = $delegator->delegate($target, $this->action);
            } catch (\Throwable $e) {
                // A target that throws would otherwise end the whole request, taking the
                // remaining candidates with it. Core does not catch here, and the core
                // providers do not catch everything either: their own handling catches
                // Guzzle's RequestException, while an unreachable endpoint raises a
                // ConnectException, which extends TransferException instead. Containing
                // it is what makes the fallback chain mean anything.
                $threw = true;
                $this->report_target_failure($target, $e);
                continue;
            }

            if (!$response->get_success()) {
                $last = $response;
                continue;
            }

            $data = $response->get_response_data();
            if ($this->has_content($data)) {
                return ['success' => true] + $data;
            }

            // A success carrying no content must never reach the placement: the user
            // would be shown an empty result as though it had worked.
            if ($this->is_truncated($data)) {
                // Deliberately not a fallback. Another target would burn its budget the
                // same way, and shortening the input is something the user can act on.
                return $this->fail(502, 'emptyresponse', self::REASON_EMPTY);
            }
            $last = $response;
        }

        // Pass the target's status code through so that a 429 stays a 429.
        $code = $last === null ? 502 : ($last->get_errorcode() ?: 502);
        $reason = ($last === null && $threw) ? self::REASON_TARGET_THREW : self::REASON_ALL_FAILED;

        return $this->fail($code, 'alltargetsfailed', $reason);
    }

    /**
     * Record that a target threw, without letting anything about it reach the user.
     *
     * The message can name a host, a key or an endpoint, so it goes to the developer log
     * only. WP4 gives the administrator a readable history of this; until then this is
     * what a site owner has to work with when a target misbehaves.
     *
     * @param \core_ai\provider $target The target that threw.
     * @param \Throwable $e What it threw.
     */
    protected function report_target_failure(\core_ai\provider $target, \Throwable $e): void {
        debugging(
            'aiprovider_router: delegation target ' . (int) $target->id . ' threw '
                . get_class($e) . ': ' . $e->getMessage(),
            DEBUG_NORMAL,
        );
    }

    /**
     * Whether the target actually returned something to show the user.
     *
     * Actions with no content key are taken at their word, since there is nothing to
     * inspect.
     *
     * @param array $data The response data from the target.
     * @return bool True if there is content, or if the action has none to check.
     */
    protected function has_content(array $data): bool {
        $key = $this->get_content_key();

        return $key === null || trim((string) ($data[$key] ?? '')) !== '';
    }

    /**
     * Whether the generation was cut short because the token budget ran out.
     *
     * Reasoning models return HTTP 200 with usage counted and no content when the
     * budget is spent on thinking. Measured on ollama gpt-oss, Sakura AI Engine and
     * Claude Opus 5. Only the finish reason tells this apart from an empty answer for
     * some other cause, which is treated as a failed target and falls through instead.
     *
     * @param array $data The response data from the target.
     * @return bool True if the target ran out of tokens.
     */
    protected function is_truncated(array $data): bool {
        $finishreason = strtolower((string) ($data['finishreason'] ?? ''));

        return in_array($finishreason, self::TRUNCATED, true);
    }

    /**
     * Build a failure payload for core to turn into a response.
     *
     * The message reaches the end user through the placement, so it names no provider,
     * no rule and no instance id. The detail lives in the reason code instead.
     *
     * The 'error' key is a short error name. Moodle 5.2 requires one on any failure and
     * throws a coding_exception without it; 5.0 has no such field and ignores the key,
     * so sending it always is what works across the supported range. The reason code is
     * exactly the short stable name that field wants.
     *
     * @param int $errorcode An HTTP style status code.
     * @param string $stringid The language string for the user facing message.
     * @param string $reason The reason code recorded for the administrator.
     * @return array The failure payload.
     */
    protected function fail(int $errorcode, string $stringid, string $reason): array {
        $this->reason = $reason;

        return [
            'success' => false,
            'errorcode' => $errorcode,
            'error' => $reason,
            'errormessage' => get_string('error:' . $stringid, 'aiprovider_router'),
        ];
    }

    /**
     * The resolver used to pick candidate targets.
     *
     * @return target_resolver The resolver.
     */
    protected function get_resolver(): target_resolver {
        return new target_resolver($this->provider);
    }

    /**
     * The delegator used to run the action against a target.
     *
     * @return delegator The delegator.
     */
    protected function get_delegator(): delegator {
        global $DB;

        return new delegator($DB);
    }
}
