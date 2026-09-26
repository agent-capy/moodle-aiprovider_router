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

namespace local_airouter;

use local_airouter\exception\declined_request;
use local_airouter\record\attempt_ending;
use local_airouter\record\attempt_state;
use local_airouter\record\request_ending;
use local_airouter\record\request_state;
use local_airouter\record\usage;
use local_airouter\record\usage_recorder;
use core_ai\aiactions\responses\response_base;

/**
 * Shared delegation logic for every action the router handles.
 *
 * The router does not call an AI service itself. It resolves candidate targets, runs
 * the action against them in order and passes the winning response through. Returning
 * the delegated response data from query_ai_api() keeps core's own flow intact, so the
 * model, finish reason and token counts reported by the target survive.
 *
 * @package    local_airouter
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

    /** @var string Reason recorded when a rule fitted in every respect but its budget. */
    public const REASON_BUDGET_SPENT = 'budget_exhausted';

    /** @var string Reason recorded when a registered key could not be decrypted. */
    public const REASON_KEY_UNREADABLE = 'byok_decrypt_failed';

    /** @var string Reason recorded when the provider refused the key it was given. */
    public const REASON_KEY_REJECTED = 'byok_key_rejected';

    /** @var string Reason recorded when every instance the payer has a key for failed. */
    public const REASON_NO_KEY_LEFT = 'byok_no_key_left';

    /** @var string Reason recorded when core's rate limiter turned the request away. */
    public const REASON_RATE_LIMITED = 'rate_limited';

    /**
     * @var string[] Reasons that mean the site decided, rather than something breaking.
     *
     * These are the failures that must not be retried by anybody else: a spending limit
     * that has been reached, a site with nowhere configured to send the request, and a
     * key somebody brought so that the request would be charged to them. A second
     * attempt on the site's own key would undo each of those rather than recover from
     * it. Everything not listed is a target that did not work, which is exactly what
     * core's fallback is for, and those keep returning an ordinary failed response.
     *
     * REASON_DECLINED is deliberately absent. "No rule claimed this request" is how the
     * router says the request was not its business, which is the whole point of running
     * it alongside other providers, and core carrying on is then correct. A budget that
     * has run out reads as the same absence of a match and is not the same thing at
     * all, which is why the resolver reports it separately.
     */
    protected const FINAL_REASONS = [
        self::REASON_BUDGET_SPENT,
        self::REASON_NO_TARGET,
        self::REASON_KEY_UNREADABLE,
        self::REASON_KEY_REJECTED,
        self::REASON_NO_KEY_LEFT,
        self::REASON_RATE_LIMITED,
    ];

    /**
     * @var string[] Reasons that are final only when somebody other than the site pays.
     *
     * A target that answers with nothing is ordinarily a target that did not work, and
     * core trying the next provider is the right thing. It stops being the right thing
     * the moment the request was being charged to somebody's own key: the next provider
     * answers on the site's key, so the request the person asked to pay for is paid for
     * by the site instead, quietly and with nothing in the reports to say so. The
     * failure is the same; who it happens to is what decides.
     */
    protected const FINAL_WHEN_BROUGHT = [
        self::REASON_EMPTY,
    ];

    /** @var string Config names whose values are secrets, whatever the provider calls them. */
    protected const SECRET_FIELDS = '/key|secret|token|password/i';

    /** @var int How much of a thrown message is worth writing down. */
    protected const MESSAGE_LIMIT = 500;

    /** @var string[] Finish reasons that mean the token budget ran out. */
    protected const TRUNCATED = ['length', 'max_tokens', 'model_length'];

    /** @var string|null Reason code for the last failure, for the monitor. */
    protected ?string $reason = null;

    /** @var int|null Status code of the last failure, for the monitor. */
    protected ?int $failurecode = null;

    /** @var string|null Language string chosen for the last failure. */
    protected ?string $failurestring = null;

    /** @var string Who was paying for the request the last failure belongs to. */
    protected string $keysource = rule::KEYSOURCE_SITE;

    /** @var bool Whether the router was ever reached, or core turned the request away first. */
    protected bool $routed = false;

    /** @var int|null The request row being written, until it is closed. */
    protected ?int $requestid = null;

    /** @var target_resolver|null The resolver that chose for this request, once one has. */
    protected ?target_resolver $resolver = null;

    /** @var int How many targets have been asked for this request so far. */
    protected int $attemptcount = 0;


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

    /**
     * Run the request, including the part of it core does before the router is asked.
     *
     * core_ai\process_base::process() checks the provider's rate limit and returns a
     * failure without ever calling query_ai_api(), which is where everything this
     * plugin does lives. The router built from the site's settings carries no rate
     * limit of its own, so today that failure does not happen. It is still handled,
     * because a failure nothing here saw would leave a request that was never
     * recorded as having ended.
     *
     * The limit is not asked about again here. Core's limiter counts the request as it
     * allows it, so asking twice would spend two of the allowance for one request. The
     * answer is read afterwards instead: a failure that arrives without query_ai_api()
     * having run can only have come from the limiter.
     *
     * @return response_base The result of the action.
     */
    #[\Override]
    public function process(): response_base {
        $this->routed = false;
        $this->attemptcount = 0;
        // Opened before anything is decided, including core's rate limit: a request
        // that was turned away at the door is still a request somebody made.
        $this->resolver = $this->get_resolver();
        $this->requestid = $this->get_recorder()->begin_request(
            $this->resolver->get_evaluated_context($this->action),
        );
        $response = parent::process();
        if ($this->routed || $response->get_success()) {
            return $response;
        }

        // Recorded before it is raised, as every other refusal here is, so that what a
        // site refused is in the reports whether or not the refusal was made final.
        $this->fail((int) ($response->get_errorcode() ?: 429), 'ratelimited', self::REASON_RATE_LIMITED);
        $this->end_request(request_state::DECLINED);
        $this->finalise([]);

        // Not made final, so core's own answer is returned exactly as before.
        return $response;
    }

    #[\Override]
    protected function query_ai_api(): array {
        $this->routed = true;
        if (!delegator::is_available()) {
            $outcome = $this->fail(503, 'delegationunavailable', self::REASON_UNAVAILABLE);
            $this->end_request(request_state::DECLINED);

            return $outcome;
        }

        $resolver = $this->resolver ??= $this->get_resolver();
        $candidates = $resolver->get_candidates($this->action);
        $this->keysource = $resolver->get_keysource();
        if (!$candidates) {
            // Four quite different things leave nothing to delegate to, and an
            // administrator reading the monitor needs to tell them apart: a key that is
            // registered and cannot be read, a budget that has been spent, the site
            // doing what it was told, and a site waiting to be fixed. Only the third of
            // those may be picked up by another provider; the rest are answered already,
            // whether by somebody's money running out or by their key being unusable.
            if ($resolver->get_unreadable_key() !== null) {
                $outcome = $this->fail(
                    500,
                    'byokdecryptfailed:' . $resolver->get_keysource(),
                    self::REASON_KEY_UNREADABLE,
                );
            } else if ($resolver->was_budget_spent()) {
                $outcome = $this->fail(503, 'budgetexhausted', self::REASON_BUDGET_SPENT);
            } else if ($resolver->was_declined()) {
                $outcome = $this->fail(503, 'norulematched', self::REASON_DECLINED);
            } else {
                $outcome = $this->fail(503, 'nodefaulttarget', self::REASON_NO_TARGET);
            }
            $this->end_request(request_state::DECLINED);

            return $this->finalise($outcome);
        }

        $delegator = $this->get_delegator();
        $last = null;
        $threw = false;
        $attempts = 0;
        foreach ($candidates as $candidate) {
            $attempts++;
            $this->attemptcount = $attempts;
            $target = $candidate->target;
            // On record before the call is made, so that a call this process does not
            // survive is on record as started rather than as never having happened.
            $attemptid = $this->get_recorder()->begin_attempt(
                $this->requestid,
                $attempts,
                $target,
                self::component_of($target),
                $candidate->keysource,
                $candidate->get_keyid(),
                $candidate->get_wallet(),
            );
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
                // What it used, if anything, is unknown: not nothing.
                $this->end_attempt(
                    $attemptid,
                    new attempt_ending(attempt_state::THREW, usage::unknown(), null, null, self::component_of($target)),
                );
                continue;
            }

            if (!$response->get_success()) {
                // A failure can still have been charged for, and some targets say so.
                $failed = $response->get_response_data();
                $ending = new attempt_ending(
                    attempt_state::FAILED,
                    $this->usage_of($failed, false),
                    self::modelled($failed['model'] ?? null),
                    (int) $response->get_errorcode() ?: null,
                    self::component_of($target),
                );
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
                    $this->end_last_attempt($attemptid, $ending, request_state::FAILED);

                    return $this->finalise($outcome);
                }
                $this->end_attempt($attemptid, $ending);
                $last = $response;
                continue;
            }

            $this->keysource = $candidate->keysource;

            $data = $response->get_response_data();
            if ($this->has_content($data)) {
                $this->end_last_attempt(
                    $attemptid,
                    new attempt_ending(
                        attempt_state::SUCCEEDED,
                        $this->usage_of($data, true),
                        self::modelled($data['model'] ?? null),
                        null,
                        self::component_of($target),
                    ),
                    request_state::SUCCEEDED,
                    (int) $target->id,
                );

                return ['success' => true] + $data;
            }

            // Nothing to show. Whether the token budget ran out or the model said
            // nothing, this call was made and charged for, and that is what the attempt
            // records; which of the two it was is the request's reason.
            $ending = new attempt_ending(
                attempt_state::EMPTY,
                $this->usage_of($data, false),
                self::modelled($data['model'] ?? null),
                null,
                self::component_of($target),
            );

            // A success carrying no content must never reach the placement: the user
            // would be shown an empty result as though it had worked.
            if (!$this->is_truncated($data)) {
                // Nothing to show and no reason given, so another target is tried.
                // What this one charged for is not undone by that, and it was charged
                // to this target and to whatever key paid for it -- not to whichever
                // one happens to answer next. So it gets a row of its own, marked as
                // not being a request: the person asked once.
                $this->end_attempt($attemptid, $ending);
                $last = $response;

                continue;
            }

            // The token budget ran out. Deliberately not a fallback: another target
            // would burn its budget the same way, and shortening the input is something
            // the user can act on.
            $outcome = $this->fail(502, 'emptyresponse', self::REASON_EMPTY);
            // The target still charged for the thinking it did, so the tokens are
            // recorded even though the user got nothing readable.
            $this->end_last_attempt($attemptid, $ending, request_state::FAILED);

            return $this->finalise($outcome);
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
        $this->end_request(request_state::FAILED);

        return $this->finalise($outcome);
    }

    /**
     * Close the request row, once.
     *
     * @param string $state One of request_state::TERMINAL.
     * @param int|null $answeredby The target whose answer was used, for a success.
     */
    protected function end_request(string $state, ?int $answeredby = null): void {
        if ($this->requestid === null) {
            return;
        }
        $ending = $this->request_ending($state, $answeredby);
        $this->get_recorder()->end_request(
            $this->requestid,
            $ending->state,
            $ending->reason,
            $ending->errorcode,
            $ending->answeredby,
            $ending->rule,
            $ending->keysource,
            $ending->attempts,
        );
        $this->requestid = null;
    }

    /**
     * Close an attempt the request is moving on from.
     *
     * Written on its own, before the next target is asked, so that what this one used
     * is on record whatever happens to the next call.
     *
     * @param int|null $attemptid The attempt, or null when it could not be recorded.
     * @param attempt_ending $ending How it ended.
     */
    protected function end_attempt(?int $attemptid, attempt_ending $ending): void {
        $this->get_recorder()->end_attempt(
            $attemptid,
            $ending->state,
            $ending->usage,
            $ending->model,
            $ending->errorcode,
            $ending->component,
        );
    }

    /**
     * Close the last attempt and the request with it, once.
     *
     * After the target has answered nothing more is going to be asked, so the two
     * endings are made durable together (usage_recorder::end_last_attempt()).
     *
     * @param int|null $attemptid The last attempt, or null when it could not be recorded.
     * @param attempt_ending $ending How it ended.
     * @param string $state How the request ended, one of request_state::TERMINAL.
     * @param int|null $answeredby The target whose answer was used, for a success.
     */
    protected function end_last_attempt(?int $attemptid, attempt_ending $ending, string $state, ?int $answeredby = null): void {
        if ($this->requestid === null) {
            $this->end_attempt($attemptid, $ending);

            return;
        }
        $this->get_recorder()->end_last_attempt(
            $attemptid,
            $ending,
            $this->requestid,
            $this->request_ending($state, $answeredby),
        );
        $this->requestid = null;
    }

    /**
     * How the request ended, as the record keeps it.
     *
     * @param string $state One of request_state::TERMINAL.
     * @param int|null $answeredby The target whose answer was used, for a success.
     * @return request_ending The ending.
     */
    protected function request_ending(string $state, ?int $answeredby): request_ending {
        $resolver = $this->resolver ??= $this->get_resolver();
        $succeeded = $state === request_state::SUCCEEDED;

        return new request_ending(
            $state,
            $succeeded ? null : $this->reason,
            $succeeded ? null : $this->failurecode,
            $answeredby,
            $resolver->get_matched_rule(),
            $resolver->get_keysource(),
            $this->attemptcount,
        );
    }

    /**
     * What a target reported using, as the record keeps it.
     *
     * @param array $data The response data.
     * @param bool $success Whether the response counted as an answer, for image counting.
     * @return usage The usage.
     */
    protected function usage_of(array $data, bool $success): usage {
        return new usage(
            self::counted($data['prompttokens'] ?? null),
            self::counted($data['completiontokens'] ?? null),
            $this->get_image_count($success),
        );
    }

    /**
     * Let a decision the site made be the end of the matter.
     *
     * core_ai\manager::process_action() walks the provider order and stops at the first
     * success. It has no way of being told that a failure is final, so a request the
     * router declined is offered to the next provider, which answers it on the site's
     * own key. The rule, the budget and the choice of who pays are all bypassed that
     * way, and nothing in the monitor says it happened.
     *
     * Throwing stops that, because neither the loop nor call_action_provider() catches
     * anything. The usage entry is already written by the time this runs -- every
     * caller records before it returns -- and only the reasons that mean the site
     * decided are treated this way. The router is only ever run for an action the site
     * placed under it, by a dispatcher with no second candidate, which turns the throw
     * back into an ordinary failed response, so the placement shows the failure and
     * core writes its own row in ai_action_register.
     *
     * @param array $outcome The failure payload built by fail().
     * @return array The same payload, when the failure is not a final one.
     */
    protected function finalise(array $outcome): array {
        $final = in_array($this->reason, self::FINAL_REASONS, true)
            || ($this->keysource !== rule::KEYSOURCE_SITE
                && in_array($this->reason, self::FINAL_WHEN_BROUGHT, true));
        if (!$final) {
            return $outcome;
        }

        throw new declined_request(
            (string) $this->reason,
            (string) $this->failurestring,
            (int) ($this->failurecode ?? 503),
        );
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
     * The record this processor writes as it goes.
     *
     * @return usage_recorder The recorder.
     */
    protected function get_recorder(): usage_recorder {
        global $DB;

        return new usage_recorder($DB);
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
            'local_airouter: delegation target ' . (int) $target->id . ' threw '
                . get_class($e) . ': ' . self::redact_for($e->getMessage(), $target),
            DEBUG_NORMAL,
        );
    }

    /**
     * @var int How long a model name may be before it is not one.
     *
     * The column it goes in. A name longer than this is not shortened to fit: two
     * models whose names differ only past this point would become one, and the rate
     * table matches on the name.
     */
    protected const MODEL_LENGTH = 100;

    /**
     * The model a target said answered, as a name this site is willing to believe.
     *
     * The name comes from outside and nothing checks it. One that will not fit the
     * column used to take the whole row down with it: the insert failed, the failure
     * was swallowed so that recording a request can never break the request, and the
     * result was a request that happened and left no trace at all -- no tokens, no
     * cost, nothing for a budget to count.
     *
     * A name that cannot be believed is recorded as no name, which the reports
     * already understand, and which the rate table reads as "whatever rate covers
     * this provider". Saying "some model of theirs" is true; saying a shortened name
     * would be saying something false about which one.
     *
     * @param mixed $value Whatever the target reported.
     * @return string|null The name, or null where there is not a usable one.
     */
    protected static function modelled(mixed $value): ?string {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $name = trim((string) $value);

        return $name !== '' && \core_text::strlen($name) <= self::MODEL_LENGTH ? $name : null;
    }

    /**
     * A usage figure a target reported, as a number this site is willing to believe.
     *
     * The count comes from outside and nothing checks it. A negative count is the one
     * that matters: cost is the count times a rate, so a negative count is a negative
     * cost, and a negative cost does not merely look odd in a report -- it subtracts
     * from what has been spent, which is what a budget weighs. A target that returned
     * -1,000,000 tokens would hand back a budget somebody had already used up.
     *
     * A count that cannot be believed is recorded as no count at all, which the
     * reports already understand: it means the same as a provider that said nothing.
     *
     * @param mixed $value Whatever the target reported.
     * @return int|null The count, or null where there is not a usable one.
     */
    protected static function counted(mixed $value): ?int {
        if (!is_numeric($value)) {
            return null;
        }
        $count = (int) $value;

        return $count >= 0 ? $count : null;
    }

    /**
     * Take the target's own secrets back out of a message before it is written down.
     *
     * What a delegate throws is written by somebody else, and an HTTP client that
     * puts the request in the exception message puts the key in it too. Debug output
     * reaches the screen and the server log, so the message is not ours to pass on
     * unread. The secrets are known exactly -- they are the ones about to be used --
     * so this replaces those values rather than guessing at what a secret looks like.
     *
     * @param string $message What was thrown.
     * @param \core_ai\provider $target The instance it was thrown by.
     * @return string The message, with the target's secrets removed and its length capped.
     */
    public static function redact_for(string $message, \core_ai\provider $target): string {
        global $DB;

        // The field a brought key was put in is known outright, so it is taken from
        // where it was recorded rather than recognised by its name. Every provider in
        // Moodle happens to call its key something this pattern matches, but nothing
        // makes them: the name belongs to whoever wrote the provider, and a key the
        // pattern missed would be somebody's own key going into the site's debug log.
        $named = (new target_settings($DB))->get_key_field((int) $target->id);

        foreach ($target->config as $name => $value) {
            $secret = (string) $name === $named || preg_match(self::SECRET_FIELDS, (string) $name);
            if (!is_string($value) || $value === '' || !$secret) {
                continue;
            }
            $message = str_replace($value, '[redacted]', $message);
        }

        // A provider that returns its whole response body in an exception is not
        // telling an administrator anything useful past the first few lines.
        return \core_text::substr($message, 0, self::MESSAGE_LIMIT);
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
        $this->failurestring = 'error:' . $stringid;

        return [
            'success' => false,
            'errorcode' => $errorcode,
            'error' => $reason,
            'errormessage' => get_string($this->failurestring, 'local_airouter'),
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
