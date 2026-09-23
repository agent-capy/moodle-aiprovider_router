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

use core_ai\aiactions\base as action_base;
use local_airouter\record\ledger;

/**
 * Everything a rule is allowed to ask about one request.
 *
 * Each answer is worked out at most once. Rule evaluation runs once per AI request, and
 * an AI request only happens when a user asks for one, so nothing here is cached beyond
 * the request: a role that has just been taken away has to stop working immediately,
 * and a cache that made that untrue would be a worse problem than the query it saved.
 *
 * Where an answer cannot be established, this class says so rather than guessing. Every
 * condition treats "unknown" as not met, so a request whose course or placement cannot
 * be worked out falls out of the narrow rules rather than into them.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evaluation_context {
    /** @var bool Whether the context object has been looked up. */
    protected bool $contextloaded = false;

    /** @var \context|null The context the action was raised in. */
    protected ?\context $context = null;

    /** @var int|null The course, once resolved. */
    protected ?int $courseid = null;

    /** @var bool Whether the course has been resolved. */
    protected bool $courseresolved = false;

    /** @var int[]|null The categories the request belongs to, closest first. */
    protected ?array $categoryids = null;

    /** @var int[]|null The roles the user holds here. */
    protected ?array $roleids = null;

    /** @var int|null The length of the prompt in characters. */
    protected ?int $promptlength = null;

    /** @var int|null The estimated size of the prompt, for display only. */
    protected ?int $tokens = null;

    /** @var string|null The placement, once detected. */
    protected ?string $placement = null;

    /** @var bool Whether placement detection has run. */
    protected bool $placementresolved = false;

    /** @var ledger|null The ledger every limit on this request is weighed in, once made. */
    protected ?ledger $ledger = null;

    /**
     * Constructor.
     *
     * @param action_base $action The action being routed.
     * @param token_estimator|null $estimator The estimator to size the prompt with.
     * @param string|null $placement The placement, when the caller already knows it.
     *                               Detection is skipped in that case, which is what the
     *                               rule tester uses to try a placement out.
     */
    public function __construct(
        /** @var action_base The action being routed. */
        protected readonly action_base $action,
        /** @var token_estimator|null The estimator. */
        protected readonly ?token_estimator $estimator = null,
        ?string $placement = null,
    ) {
        if ($placement !== null) {
            $this->placement = $placement;
            $this->placementresolved = true;
        }
    }

    /**
     * The ledger every limit on this request is weighed in.
     *
     * One for the request, so that the budgets its rules carry and the limit on the key
     * it would use read the record under the same generation, and read it once.
     *
     * @return ledger The ledger.
     */
    public function get_ledger(): ledger {
        global $DB;

        return $this->ledger ??= ledger::for_one_request($DB);
    }

    /**
     * The action class being routed.
     *
     * @return string The fully qualified class name.
     */
    public function get_action_class(): string {
        return $this->action::class;
    }

    /**
     * The short name of the action, as core records it.
     *
     * The monitor stores the same value core does, so that the two logs can be lined
     * up against each other to work out how much of a site's AI traffic reaches the
     * router at all.
     *
     * @return string The action basename.
     */
    public function get_action_name(): string {
        return $this->action::get_basename();
    }

    /**
     * The user the request is being made for.
     *
     * @return int The user id, or zero when the action does not carry one.
     */
    public function get_userid(): int {
        return (int) $this->read('userid', 0);
    }

    /**
     * The context the action was raised in.
     *
     * @return int The context id, or zero when the action does not carry one.
     */
    public function get_contextid(): int {
        return (int) $this->read('contextid', 0);
    }

    /**
     * The context object, when it still exists.
     *
     * @return \context|null The context, or null when it is gone or was never given.
     */
    public function get_context(): ?\context {
        if (!$this->contextloaded) {
            $this->contextloaded = true;
            $contextid = $this->get_contextid();
            // A context id that no longer resolves is not worth an exception. The
            // request still has to be routed somewhere, and the conditions that depend
            // on a context will simply not be met.
            $this->context = $contextid > 0
                ? (\context::instance_by_id($contextid, IGNORE_MISSING) ?: null)
                : null;
        }

        return $this->context;
    }

    /**
     * The course the request belongs to.
     *
     * Resolved the way core resolves it. Moodle 5.3 added manager::resolve_courseid()
     * for the same job, and it does exactly this; doing the same thing here means the
     * monitor log and core's own log will not disagree about which course a request
     * belonged to once sites reach 5.3. The method it relies on exists in 5.0.
     *
     * @return int|null The course id, or null when the request did not come from a course.
     */
    public function get_courseid(): ?int {
        if (!$this->courseresolved) {
            $this->courseresolved = true;
            $context = $this->get_context();
            $coursecontext = $context?->get_course_context(false);
            $this->courseid = $coursecontext ? (int) $coursecontext->instanceid : null;
        }

        return $this->courseid;
    }

    /**
     * The categories the request belongs to, nearest first.
     *
     * Ancestors are included, so that choosing a category in a rule also covers the
     * courses in the categories beneath it. That is why the editing form has no
     * "include subcategories" option: there is nothing to switch off.
     *
     * @return int[] The category ids. Empty when the request is not under a category.
     */
    public function get_categoryids(): array {
        if ($this->categoryids === null) {
            $this->categoryids = $this->resolve_categoryids();
        }

        return $this->categoryids;
    }

    /**
     * The roles the user holds in this context, including those inherited from above.
     *
     * The special ones are included. Moodle gives every logged in account the
     * authenticated user role, and everybody on the front page the front page role,
     * without ever writing a role assignment for either. A rule condition offers those
     * roles in its list -- they are roles, and an administrator has every reason to
     * pick one -- so leaving them out here meant a condition that could be chosen on
     * the screen and could never be satisfied by anybody.
     *
     * @return int[] The role ids.
     */
    public function get_roleids(): array {
        if ($this->roleids === null) {
            $this->roleids = [];
            $context = $this->get_context();
            $userid = $this->get_userid();
            if ($context !== null && $userid > 0) {
                foreach (get_user_roles_with_special($context, $userid) as $assignment) {
                    $this->roleids[(int) $assignment->roleid] = (int) $assignment->roleid;
                }
                $this->roleids = array_values($this->roleids);
            }
        }

        return $this->roleids;
    }

    /**
     * The prompt the request carries.
     *
     * @return string The prompt, or an empty string for an action that has none.
     */
    public function get_prompt(): string {
        return (string) $this->read('prompttext', '');
    }

    /**
     * How long the prompt is.
     *
     * This is what prompt length conditions compare against: a count, not an estimate,
     * so that a rule written here means the same thing whatever language the prompt is
     * in and whichever target eventually handles it.
     *
     * @return int The number of characters.
     */
    public function get_prompt_length(): int {
        if ($this->promptlength === null) {
            $this->promptlength = \core_text::strlen($this->get_prompt());
        }

        return $this->promptlength;
    }

    /**
     * The estimated size of the prompt, for showing beside the character count.
     *
     * Nothing routes on this. Tokens are the unit an administrator thinks in, so the
     * estimate is worth showing where a threshold is being chosen, but it depends on the
     * ratios configured for the site and on the target's tokeniser, and a rule must not.
     *
     * @return int The estimate in tokens.
     */
    public function get_estimated_tokens(): int {
        if ($this->tokens === null) {
            $this->tokens = $this->get_estimator()->estimate($this->get_prompt());
        }

        return $this->tokens;
    }

    /**
     * The estimator sizing this request.
     *
     * @return token_estimator The estimator.
     */
    public function get_estimator(): token_estimator {
        return $this->estimator ?? new token_estimator();
    }

    /**
     * Which placement raised this request.
     *
     * Actions do not say. All four action classes are identical on Moodle 5.0 through
     * 5.3 in this respect, so the only thing left to read is the call stack. If core
     * ever puts the placement on the action, that field should be preferred and this
     * should become the fallback.
     *
     * @return string|null The placement component, or null when it could not be told.
     */
    public function get_placement(): ?string {
        if (!$this->placementresolved) {
            $this->placementresolved = true;
            $this->placement = self::detect_placement();
        }

        return $this->placement;
    }

    /**
     * Look for a placement in the call stack.
     *
     * @return string|null The placement component, or null when nothing identifiable was found.
     */
    protected static function detect_placement(): ?string {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            if (str_starts_with($class, 'aiplacement_')) {
                return strtok($class, '\\');
            }
            // Code outside a class, and code whose class has been aliased, is still in a
            // file belonging to the placement. The path picked up the public/ prefix in
            // Moodle 5.1, which is why only the tail of it is matched.
            $file = str_replace('\\', '/', $frame['file'] ?? '');
            if (preg_match('#/ai/placement/([a-z0-9_]+)/#', $file, $matches)) {
                return 'aiplacement_' . $matches[1];
            }
        }

        return null;
    }

    /**
     * Work out which categories the request sits under.
     *
     * @return int[] The category ids, nearest first.
     */
    protected function resolve_categoryids(): array {
        global $DB;

        $categoryid = 0;
        $courseid = $this->get_courseid();
        if ($courseid !== null && $courseid > 1) {
            $categoryid = (int) $DB->get_field('course', 'category', ['id' => $courseid], IGNORE_MISSING);
        } else {
            // A request raised in a category itself, rather than in one of its courses.
            foreach ($this->get_context()?->get_parent_contexts(true) ?? [] as $parent) {
                if ((int) $parent->contextlevel === CONTEXT_COURSECAT) {
                    $categoryid = (int) $parent->instanceid;
                    break;
                }
            }
        }
        if ($categoryid <= 0) {
            return [];
        }

        // The stored path is the whole ancestry, so one read covers every level.
        $path = (string) $DB->get_field('course_categories', 'path', ['id' => $categoryid], IGNORE_MISSING);
        $ancestry = array_values(array_filter(array_map('intval', explode('/', $path))));

        return $ancestry ? array_reverse($ancestry) : [$categoryid];
    }

    /**
     * Read a value from the action, if it has one.
     *
     * get_configuration() returns the property of that name directly, so asking for one
     * the action does not have raises a warning. Actions from other plugins need not
     * carry a prompt or a user, so every read is checked first.
     *
     * @param string $name The property name.
     * @param mixed $default What to return when the action does not have it.
     * @return mixed The value.
     */
    protected function read(string $name, mixed $default): mixed {
        if (!property_exists($this->action, $name)) {
            return $default;
        }

        return $this->action->get_configuration($name);
    }
}
