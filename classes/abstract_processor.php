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

    /** @var string Reason recorded when the router turned the request down on purpose. */
    public const REASON_DECLINED = 'no_rule_matched';

    /** @var string Reason recorded when a registered key could not be decrypted. */
    public const REASON_KEY_UNREADABLE = 'byok_decrypt_failed';

    /** @var string Reason recorded when the provider refused the key it was given. */
    public const REASON_KEY_REJECTED = 'byok_key_rejected';

    /** @var string Reason recorded when every instance the payer has a key for failed. */
    public const REASON_NO_KEY_LEFT = 'byok_no_key_left';

    /** @var string[] Finish reasons that mean the token budget ran out. */
    protected const TRUNCATED = ['length', 'max_tokens', 'model_length'];

    /** @var string|null Reason code for the last failure, for the monitor. */
    protected ?string $reason = null;

    /** @var int|null Status code of the last failure, for the monitor. */
    protected ?int $failurecode = null;

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

        $resolver = $this->get_resolver();
        $candidates = $resolver->get_candidates($this->action);
        if (!$candidates) {
            // Three quite different things leave nothing to delegate to, and an
            // administrator reading the monitor needs to tell them apart: the site doing
            // what it was told, a site waiting to be fixed, and a key that is registered
            // and cannot be read. The last of those must not be quietly retried
            // elsewhere: somebody asked to pay for this request themselves.
            if ($resolver->get_unreadable_key() !== null) {
                $outcome = $this->fail(
                    500,
                    'byokdecryptfailed:' . $resolver->get_keysource(),
                    self::REASON_KEY_UNREADABLE,
                );
            } else if ($resolver->was_declined()) {
                $outcome = $this->fail(503, 'norulematched', self::REASON_DECLINED);
            } else {
                $outcome = $this->fail(503, 'nodefaulttarget', self::REASON_NO_TARGET);
            }
            $this->record_usage($resolver, null, null, 0);

            return $outcome;
        }

        $delegator = $this->get_delegator();
        $last = null;
        $threw = false;
        $attempts = 0;
        foreach ($candidates as $candidate) {
            $attempts++;
            $target = $candidate->target;
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
                if ($candidate->is_byok() && $this->was_key_refused($response)) {
                    // The key reached the provider and the provider would not have it.
                    // Nobody else's key is going to change that, and the person who
                    // brought it is the only one who can put it right, so they are told
                    // rather than moved quietly onto somebody else's money.
                    $outcome = $this->fail(
                        (int) $response->get_errorcode(),
                        'byokkeyrejected:' . $candidate->keysource,
                        self::REASON_KEY_REJECTED,
                    );
                    $this->record_usage($resolver, $candidate, null, $attempts);

                    return $outcome;
                }
                $last = $response;
                continue;
            }

            $data = $response->get_response_data();
            if ($this->has_content($data)) {
                $this->record_usage($resolver, $candidate, $data, $attempts, true);

                return ['success' => true] + $data;
            }

            // A success carrying no content must never reach the placement: the user
            // would be shown an empty result as though it had worked.
            if ($this->is_truncated($data)) {
                // Deliberately not a fallback. Another target would burn its budget the
                // same way, and shortening the input is something the user can act on.
                $outcome = $this->fail(502, 'emptyresponse', self::REASON_EMPTY);
                // The target still charged for the thinking it did, so the tokens are
                // recorded even though the user got nothing readable.
                $this->record_usage($resolver, $candidate, $data, $attempts);

                return $outcome;
            }
            $last = $response;
        }

        // Pass the target's status code through so that a 429 stays a 429.
        $code = $last === null ? 502 : ($last->get_errorcode() ?: 502);
        $keysource = $resolver->get_keysource();
        if ($keysource !== rule::KEYSOURCE_SITE) {
            // Everything the payer holds a key for has been tried. Worth its own reason:
            // it says the fallback chain was short because of who was paying, not that
            // the site's providers are all down.
            $outcome = $this->fail($code, 'byoknokeyleft:' . $keysource, self::REASON_NO_KEY_LEFT);
        } else {
            $reason = ($last === null && $threw) ? self::REASON_TARGET_THREW : self::REASON_ALL_FAILED;
            $outcome = $this->fail($code, 'alltargetsfailed', $reason);
        }
        $this->record_usage($resolver, null, null, $attempts);

        return $outcome;
    }

    /**
     * Whether a failure was the provider refusing the key rather than anything else.
     *
     * @param response_base $response What the target returned.
     * @return bool True when the key itself was refused.
     */
    protected function was_key_refused(response_base $response): bool {
        return in_array((int) $response->get_errorcode(), key_tester::REJECTED, true);
    }

    /**
     * Hand what happened to the monitor.
     *
     * Failures and refusals are recorded as well as successes. How often the router
     * turns requests down is a number a site owner needs, and it has to be countable
     * apart from targets breaking, which means something quite different.
     *
     * @param target_resolver $resolver The resolver that chose, holding the matched rule.
     * @param candidate|null $candidate The candidate that answered, if one did.
     * @param array|null $data The response data from that target.
     * @param int $attempts How many targets were tried.
     * @param bool $success Whether the user got an answer.
     */
    protected function record_usage(
        target_resolver $resolver,
        ?candidate $candidate,
        ?array $data,
        int $attempts,
        bool $success = false,
    ): void {
        $context = $resolver->get_evaluated_context($this->action);
        $rule = $resolver->get_matched_rule();
        $target = $candidate?->target;
        // A request that never reached a target can still have been somebody's to pay
        // for, and a key that could not be read is exactly the case worth finding again.
        $keyid = $candidate?->get_keyid() ?? $resolver->get_unreadable_key()?->get('id');

        $entry = (object) [
            'timecreated' => time(),
            'userid' => $context->get_userid(),
            'contextid' => $context->get_contextid(),
            'courseid' => $context->get_courseid(),
            'actionname' => $context->get_action_name(),
            'placement' => $context->get_placement(),
            'ruleid' => $rule === null ? null : (int) $rule->get('id'),
            'rulename' => $rule === null ? null : $rule->get('name'),
            'targetid' => $target === null ? null : (int) $target->id,
            'targetname' => $target === null ? null : $target->name,
            'targetprovider' => $target === null ? null : self::component_of($target),
            'model' => $data['model'] ?? null,
            'success' => (int) $success,
            'errorcode' => $success ? null : $this->failurecode,
            'reason' => $success ? null : $this->reason,
            'attempts' => $attempts,
            'keysource' => $resolver->get_keysource(),
            'keyid' => $keyid === null ? null : (int) $keyid,
            'prompttokens' => isset($data['prompttokens']) ? (int) $data['prompttokens'] : null,
            'completiontokens' => isset($data['completiontokens']) ? (int) $data['completiontokens'] : null,
        ];

        $this->get_logger()->record($entry, $this->get_image_count($success));
    }

    /**
     * Which plugin an instance belongs to, which is what rates are looked up by.
     *
     * Core's own get_name() resolves the component from the class and returns null when
     * it cannot, which happens for a provider class that is not an installed plugin. The
     * first segment of the namespace is the component for every AI provider, because
     * that is how core itself decides which plugin a provider class belongs to.
     *
     * @param \core_ai\provider $target The instance.
     * @return string The component name.
     */
    protected static function component_of(\core_ai\provider $target): string {
        return \core\component::get_component_from_classname($target::class)
            ?? strtok($target::class, '\\');
    }

    /**
     * How many images this request produced, for actions that are costed per image.
     *
     * Image responses carry no token counts at all, so there is nothing else to cost
     * them by. Actions that deal in text return zero and are costed on their tokens.
     *
     * @param bool $success Whether the request succeeded.
     * @return int The number of images.
     */
    protected function get_image_count(bool $success): int {
        unset($success);

        return 0;
    }

    /**
     * The monitor this processor reports to.
     *
     * @return usage_logger The logger.
     */
    protected function get_logger(): usage_logger {
        global $DB;

        return new usage_logger($DB);
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
        $this->failurecode = $errorcode;

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
