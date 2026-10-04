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

namespace local_airouter\setup;

use local_airouter\budget_notifier;
use local_airouter\eligibility_policy;
use local_airouter\key;
use local_airouter\managed_policy;
use local_airouter\price_book;
use local_airouter\provider;
use local_airouter\record\ledger;
use local_airouter\record\usage_recorder;
use local_airouter\retention_policy;
use local_airouter\rule;
use local_airouter\rule_repository;
use local_airouter\target_resolver;

/**
 * Where the site stands, step by step, for what an administrator means to do with it.
 *
 * There is no single list every site has to finish. A site that routes by rule alone
 * needs no default target, one that counts budgets in requests needs no rates, and a
 * course key needs nothing from the policy on who may bring a personal one. So the
 * steps are grouped by purpose, and a step a purpose does not need says so rather
 * than waiting to be done.
 *
 * Every step reads settings. Nothing is changed, and no provider is asked anything.
 * What the settings cannot answer - whether a particular person sees a button,
 * whether a provider answers - is a step to check, never a step that is done.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup_status {
    /** @var string Route requests and keep a record of them. */
    public const PURPOSE_ROUTE = 'route';

    /** @var string Keep spending within budgets. */
    public const PURPOSE_BUDGET = 'budget';

    /** @var string Keep some requests on a model the site runs itself. */
    public const PURPOSE_LOCAL = 'local';

    /** @var string Let people pay with a key of their own. */
    public const PURPOSE_BYOK_USER = 'byokuser';

    /** @var string Let a course pay with a key of its own. */
    public const PURPOSE_BYOK_COURSE = 'byokcourse';

    /**
     * @var string The action an instance-wide problem is described against. The reasons that
     *             do not depend on the action never use its name, so any action will do.
     */
    private const ANY_ACTION = \core_ai\aiactions\generate_text::class;

    /** @var route_inspection[]|null Every action the router declares, inspected. */
    protected ?array $inspections = null;

    /** @var rule[]|null The rules in force. */
    protected ?array $rules = null;

    /** @var array|null The conditions of the rules in force. */
    protected ?array $conditions = null;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int $now The time to judge things at.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var int The time to judge things at. */
        protected readonly int $now,
    ) {
    }

    /**
     * The purposes a site can be set up for, in the order they are offered.
     *
     * @return string[] The purposes.
     */
    public static function purposes(): array {
        return [
            self::PURPOSE_ROUTE,
            self::PURPOSE_BUDGET,
            self::PURPOSE_LOCAL,
            self::PURPOSE_BYOK_USER,
            self::PURPOSE_BYOK_COURSE,
        ];
    }

    /**
     * The steps every purpose shares.
     *
     * @return setup_step[] The steps, in order.
     */
    public function common_steps(): array {
        return [
            $this->providers_step(),
            $this->placements_step(),
            $this->routed_step(),
        ];
    }

    /**
     * The steps one purpose needs on top of the common ones.
     *
     * @param string $purpose One of the PURPOSE_ constants.
     * @return setup_step[] The steps, in order.
     */
    public function purpose_steps(string $purpose): array {
        return match ($purpose) {
            self::PURPOSE_BUDGET => [
                $this->budget_rules_step(),
                $this->rates_step(),
                $this->history_step(),
                $this->after_limit_step(),
                $this->notify_step(),
            ],
            self::PURPOSE_LOCAL => [
                $this->rules_step(),
                $this->failure_step(),
                $this->outside_step(),
            ],
            self::PURPOSE_BYOK_USER => [
                $this->eligibility_step(),
                $this->keyfield_step(),
                $this->brought_rules_step(rule::KEYSOURCE_USER),
                $this->registered_step(key::SCOPE_USER),
                $this->backup_step(),
            ],
            self::PURPOSE_BYOK_COURSE => [
                $this->eligibility_not_needed_step(),
                $this->keyfield_step(),
                $this->coursekey_step(),
                $this->brought_rules_step(rule::KEYSOURCE_COURSE),
                $this->registered_step(key::SCOPE_COURSE),
                $this->backup_step(),
            ],
            default => [
                $this->targets_step(),
                $this->verify_step(),
            ],
        };
    }

    /**
     * Whether any provider instance can carry anything the router declares.
     *
     * @return setup_step The step.
     */
    protected function providers_step(): setup_step {
        $resolver = target_resolver::for_site_with_instances();
        $names = target_resolver::get_delegation_targets();
        $history = (new target_history($this->db))->get_last($this->now);
        $lines = [];
        $usable = 0;
        foreach ($names as $id => $name) {
            $problems = [];
            foreach (managed_policy::declared_actions() as $action) {
                $problems[$action] = $resolver->get_target_problem($id, $action);
            }
            $carries = array_keys(array_filter($problems, static fn(?string $problem): bool => $problem === null));
            $instanceproblem = $resolver->get_instance_problem($id);
            if ($carries !== []) {
                $usable++;
            }
            $state = $instanceproblem !== null
                ? s(target_resolver::describe_problem($instanceproblem, self::ANY_ACTION))
                : ($carries === []
                    ? get_string('setup:providers:noaction', 'local_airouter')
                    : get_string('setup:providers:carries', 'local_airouter', implode(', ', array_map(
                        static fn(string $action): string => managed_policy::label_for($action),
                        $carries,
                    ))));
            $lines[] = \html_writer::tag('strong', s($name)) . ': ' . $state . ' '
                . target_history::describe($history[$id] ?? null);
        }
        if ($names === []) {
            $lines[] = get_string('defaulttarget:none', 'local_airouter');
        }

        return new setup_step(
            'providers',
            $usable > 0 ? setup_step::DONE : setup_step::TODO,
            $lines,
            new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']),
            get_string('aiproviders', 'core_ai'),
        );
    }

    /**
     * Whether a placement offers any action, which is where people find the buttons.
     *
     * @return setup_step The step.
     */
    protected function placements_step(): setup_step {
        $manager = \core\di::get(\core_ai\manager::class);
        $enabled = \core_plugin_manager::instance()->get_enabled_plugins('aiplacement') ?: [];
        $lines = [];
        $offering = 0;
        foreach ($enabled as $name) {
            $component = 'aiplacement_' . $name;
            $actions = array_filter(
                managed_policy::declared_actions(),
                static fn(string $action): bool => $manager->is_action_enabled($component, $action),
            );
            if ($actions !== []) {
                $offering++;
            }
            $lines[] = \html_writer::tag('strong', get_string('pluginname', $component)) . ': '
                . ($actions === []
                    ? get_string('setup:placements:noaction', 'local_airouter')
                    : implode(', ', array_map(static fn(string $a): string => managed_policy::label_for($a), $actions)));
        }
        if ($enabled === []) {
            $lines[] = get_string('setup:placements:none', 'local_airouter');
        }
        // Who sees a button is decided per person and per course. A site setting can
        // only say that the feature is on.
        $lines[] = get_string('setup:placements:permissions', 'local_airouter');

        return new setup_step(
            'placements',
            $offering > 0 ? setup_step::DONE : setup_step::TODO,
            $lines,
            new \moodle_url('/admin/settings.php', ['section' => 'aiplacement']),
            get_string('aiplacements', 'core_ai'),
        );
    }

    /**
     * Which actions go through the router, and what each is offered.
     *
     * @return setup_step The step.
     */
    protected function routed_step(): setup_step {
        $lines = [];
        $routed = 0;
        $stuck = 0;
        foreach ($this->get_inspections() as $inspection) {
            if ($inspection->applied === route_inspection::APPLIED_ROUTED) {
                $routed++;
                if ($inspection->state === route_inspection::STATE_ACTION_NEEDED) {
                    $stuck++;
                }
                $lines[] = \html_writer::tag('strong', s(managed_policy::label_for($inspection->actionclass)))
                    . ' - ' . route_formatter::state_label($inspection) . ': '
                    . implode(' ', route_formatter::explain($inspection));
            } else {
                $lines[] = \html_writer::tag('strong', s(managed_policy::label_for($inspection->actionclass)))
                    . ' - ' . route_formatter::applied($inspection) . ' ' . route_formatter::core_route($inspection);
            }
        }
        foreach (managed_policy::unsupported_actions() as $action) {
            $stuck++;
            $lines[] = get_string('managed:noprocessor', 'local_airouter', managed_policy::basename_for($action));
        }

        return new setup_step(
            'routed',
            $routed > 0 && $stuck === 0 && managed_policy::is_switched_on() ? setup_step::DONE : setup_step::TODO,
            $lines,
            new \moodle_url('/local/airouter/managed.php'),
            get_string('managed:heading', 'local_airouter'),
        );
    }

    /**
     * Where routed requests go: the default target and the rules.
     *
     * @return setup_step The step.
     */
    protected function targets_step(): setup_step {
        $routed = $this->routed_inspections();
        $stuck = array_filter(
            $routed,
            static fn(route_inspection $i): bool => $i->state === route_inspection::STATE_ACTION_NEEDED,
        );
        $first = reset($routed) ?: null;
        $lines = [];
        if ($first !== null) {
            $lines[] = $first->defaulttargetid === null
                ? get_string('setup:targets:nodefault', 'local_airouter')
                : get_string('setup:targets:default', 'local_airouter', s($first->defaulttargetname
                    ?? get_string('setup:missingtarget', 'local_airouter', $first->defaulttargetid)));
            $lines[] = get_string($first->declines ? 'setup:unmatched:decline' : 'setup:targets:delegate', 'local_airouter');
        }
        $lines[] = get_string('setup:targets:rules', 'local_airouter', count($this->get_rules()));

        return new setup_step(
            'targets',
            $routed === [] || $stuck !== [] ? setup_step::TODO : setup_step::DONE,
            $lines,
            new \moodle_url('/admin/settings.php', ['section' => 'local_airouter_policy']),
            get_string('policy:heading', 'local_airouter'),
        );
    }

    /**
     * How to see a request go through, and whether any has.
     *
     * @return setup_step The step.
     */
    protected function verify_step(): setup_step {
        $since = $this->now - target_history::DAYS * DAYSECS;
        $count = $this->db->count_records_select(usage_recorder::REQUEST_TABLE, 'timestarted >= :since', ['since' => $since]);

        return new setup_step(
            'verify',
            $count > 0 ? setup_step::DONE : setup_step::CHECK,
            [
                get_string('setup:verify:test', 'local_airouter'),
                $count > 0
                    ? get_string('setup:verify:recorded', 'local_airouter', (object) [
                        'count' => $count,
                        'days' => target_history::DAYS,
                    ])
                    : get_string('setup:verify:none', 'local_airouter', target_history::DAYS),
                get_string('setup:verify:manual', 'local_airouter'),
            ],
            new \moodle_url('/local/airouter/ruletest.php'),
            get_string('ruletest:heading', 'local_airouter'),
        );
    }

    /**
     * The rules that limit spending.
     *
     * @return setup_step The step.
     */
    protected function budget_rules_step(): setup_step {
        $budgets = $this->budget_conditions();
        $lines = [];
        foreach ($budgets as $id => $budget) {
            $lines[] = \html_writer::tag('strong', s($this->get_rules()[$id]->get('name'))) . ': '
                . get_string('setup:budget:metric:' . ($budget['metric'] ?? ledger::METRIC_COST), 'local_airouter');
        }
        if ($budgets === []) {
            $lines[] = get_string('setup:budget:none', 'local_airouter');
        }

        return new setup_step(
            'budgetrules',
            $budgets === [] ? setup_step::TODO : setup_step::DONE,
            $lines,
            new \moodle_url('/local/airouter/rules.php'),
            get_string('rules:heading', 'local_airouter'),
        );
    }

    /**
     * Whether every budget in money has the rates it is measured against.
     *
     * @return setup_step The step.
     */
    protected function rates_step(): setup_step {
        $book = new price_book($this->db);
        $money = array_filter(
            $this->budget_conditions(),
            static fn(array $budget): bool => ($budget['metric'] ?? ledger::METRIC_COST) === ledger::METRIC_COST,
        );
        if ($money === []) {
            return new setup_step(
                'rates',
                setup_step::NOT_NEEDED,
                [get_string('setup:rates:notneeded', 'local_airouter')],
                new \moodle_url('/local/airouter/rates.php'),
                get_string('rates:heading', 'local_airouter'),
            );
        }
        $missing = [];
        foreach ($money as $budget) {
            $component = (string) ($budget['provider'] ?? '');
            if ($component !== '' && $book->currency_of($component) === null) {
                $missing[$component] = get_string_manager()->string_exists('pluginname', $component)
                    ? get_string('pluginname', $component)
                    : $component;
            }
        }

        return new setup_step(
            'rates',
            $missing === [] ? setup_step::DONE : setup_step::TODO,
            [$missing === []
                ? get_string('setup:rates:ok', 'local_airouter')
                : get_string('setup:rates:missing', 'local_airouter', s(implode(', ', $missing)))],
            new \moodle_url('/local/airouter/rates.php'),
            get_string('rates:heading', 'local_airouter'),
        );
    }

    /**
     * Whether the record reaches back as far as the budgets look.
     *
     * @return setup_step The step.
     */
    protected function history_step(): setup_step {
        $shortfall = (new retention_policy($this->db))->shortfall();

        return new setup_step(
            'history',
            $shortfall === null ? setup_step::DONE : setup_step::TODO,
            [$shortfall === null
                ? get_string('setup:history:ok', 'local_airouter')
                : get_string('setup:history:short', 'local_airouter')],
            new \moodle_url('/local/airouter/usage.php'),
            get_string('usage:settings', 'local_airouter'),
        );
    }

    /**
     * Where a request goes once a budget turns it away.
     *
     * @return setup_step The step.
     */
    protected function after_limit_step(): setup_step {
        $lines = [];
        foreach ($this->routed_inspections() as $inspection) {
            if (!$inspection->has_budget_rule()) {
                continue;
            }
            $lines[] = \html_writer::tag('strong', s(managed_policy::label_for($inspection->actionclass))) . ': '
                . ($inspection->has_default_route()
                    ? get_string('setup:budget', 'local_airouter', s((string) $inspection->defaulttargetname))
                    : get_string('setup:afterlimit:refused', 'local_airouter'));
        }
        if ($lines === []) {
            $lines[] = get_string('setup:afterlimit:none', 'local_airouter');
        }

        // Where it goes is the site's decision, not a fault, so this is a step to look at.
        return new setup_step(
            'afterlimit',
            setup_step::CHECK,
            $lines,
            new \moodle_url('/admin/settings.php', ['section' => 'local_airouter_policy']),
            get_string('policy:heading', 'local_airouter'),
        );
    }

    /**
     * Whether people are told when a budget is reached.
     *
     * @return setup_step The step.
     */
    protected function notify_step(): setup_step {
        $on = budget_notifier::is_enabled();

        return new setup_step(
            'notify',
            $on ? setup_step::DONE : setup_step::CHECK,
            [get_string($on ? 'setup:notify:on' : 'setup:notify:off', 'local_airouter')],
            new \moodle_url('/local/airouter/usage.php'),
            get_string('usage:heading', 'local_airouter'),
        );
    }

    /**
     * The rules that choose which requests go where.
     *
     * @return setup_step The step.
     */
    protected function rules_step(): setup_step {
        $names = target_resolver::get_delegation_targets();
        $lines = [];
        foreach ($this->get_rules() as $rule) {
            $targetid = (int) $rule->get('targetid');
            $lines[] = \html_writer::tag('strong', s($rule->get('name'))) . ' - '
                . s($names[$targetid] ?? get_string('setup:missingtarget', 'local_airouter', $targetid));
        }
        if ($lines === []) {
            $lines[] = get_string('setup:rules:none', 'local_airouter');
        }

        return new setup_step(
            'rules',
            $this->get_rules() === [] ? setup_step::TODO : setup_step::CHECK,
            $lines,
            new \moodle_url('/local/airouter/rules.php'),
            get_string('rules:heading', 'local_airouter'),
        );
    }

    /**
     * Where a request goes when the target a rule chose fails.
     *
     * @return setup_step The step.
     */
    protected function failure_step(): setup_step {
        $lines = [];
        $leaks = false;
        foreach ($this->routed_inspections() as $inspection) {
            $sitepaid = array_filter($inspection->usable_rules(), static fn(route_rule $r): bool => !$r->brought);
            if ($sitepaid === []) {
                continue;
            }
            $leaks = $leaks || $inspection->has_default_route();
            $lines[] = \html_writer::tag('strong', s(managed_policy::label_for($inspection->actionclass))) . ': '
                . ($inspection->has_default_route()
                    ? get_string('setup:fallback:default', 'local_airouter', s((string) $inspection->defaulttargetname))
                    : get_string('setup:failure:stays', 'local_airouter'));
        }
        if ($lines === []) {
            $lines[] = get_string('setup:failure:none', 'local_airouter');
        }

        return new setup_step(
            'failure',
            $leaks ? setup_step::CHECK : setup_step::DONE,
            $lines,
            new \moodle_url('/admin/settings.php', ['section' => 'local_airouter_policy']),
            get_string('policy:heading', 'local_airouter')
        );
    }

    /**
     * What happens to requests the router does not see.
     *
     * @return setup_step The step.
     */
    protected function outside_step(): setup_step {
        $lines = [];
        foreach ($this->get_inspections() as $inspection) {
            if ($inspection->applied === route_inspection::APPLIED_ROUTED) {
                $lines[] = \html_writer::tag('strong', s(managed_policy::label_for($inspection->actionclass))) . ': '
                    . get_string('setup:wouldtake', 'local_airouter', route_formatter::core_route($inspection));
            } else {
                $lines[] = \html_writer::tag('strong', s(managed_policy::label_for($inspection->actionclass))) . ': '
                    . route_formatter::applied($inspection) . ' ' . route_formatter::core_route($inspection);
            }
        }

        return new setup_step(
            'outside',
            setup_step::CHECK,
            $lines,
            new \moodle_url('/local/airouter/managed.php'),
            get_string('managed:heading', 'local_airouter')
        );
    }

    /**
     * Who may bring a personal key.
     *
     * @return setup_step The step.
     */
    protected function eligibility_step(): setup_step {
        $policy = new eligibility_policy();
        $nobody = $policy->get_access() === eligibility_policy::ACCESS_NOBODY;

        return new setup_step(
            'eligibility',
            $nobody ? setup_step::TODO : setup_step::DONE,
            [get_string($nobody ? 'setup:eligibility:nobody' : 'setup:eligibility:set', 'local_airouter')],
            new \moodle_url('/local/airouter/byok.php'),
            get_string('byok:heading', 'local_airouter'),
        );
    }

    /**
     * The policy on personal keys, which a course key does not need.
     *
     * @return setup_step The step.
     */
    protected function eligibility_not_needed_step(): setup_step {
        return new setup_step(
            'eligibility',
            setup_step::NOT_NEEDED,
            [get_string('setup:eligibility:course', 'local_airouter')],
        );
    }

    /**
     * Which providers can take a brought key, and whether their own key field is filled.
     *
     * @return setup_step The step.
     */
    protected function keyfield_step(): setup_step {
        $lines = [];
        $ready = 0;
        foreach ((new byok_readiness($this->db, $this->now))->rows() as $row) {
            if (byok_readiness::is_ready($row)) {
                $ready++;
            }
            $lines[] = \html_writer::tag('strong', s($row->name)) . ': ' . byok_readiness::describe($row);
        }

        return new setup_step(
            'keyfield',
            $ready > 0 ? setup_step::DONE : setup_step::TODO,
            $lines,
            new \moodle_url('/local/airouter/byok.php'),
            get_string('byok:heading', 'local_airouter'),
        );
    }

    /**
     * Who registers a course key.
     *
     * @return setup_step The step.
     */
    protected function coursekey_step(): setup_step {
        return new setup_step(
            'coursekey',
            setup_step::CHECK,
            [get_string('setup:coursekey', 'local_airouter')],
            new \moodle_url('/admin/roles/manage.php'),
            get_string('defineroles', 'core_role'),
        );
    }

    /**
     * The rules that charge a brought key.
     *
     * @param string $keysource Who pays.
     * @return setup_step The step.
     */
    protected function brought_rules_step(string $keysource): setup_step {
        $usable = [];
        foreach ($this->routed_inspections() as $inspection) {
            foreach ($inspection->usable_rules() as $rule) {
                if ($rule->keysource === $keysource) {
                    $usable[$rule->id] = $rule->name;
                }
            }
        }

        return new setup_step(
            'broughtrules',
            $usable === [] ? setup_step::TODO : setup_step::DONE,
            [$usable === []
                ? get_string('setup:broughtrules:none', 'local_airouter')
                : get_string('setup:broughtrules:some', 'local_airouter', s(implode(', ', $usable))),
             get_string('setup:fallback:brought', 'local_airouter'),
             get_string('setup:broughtrules:next', 'local_airouter')],
            new \moodle_url('/local/airouter/rules.php'),
            get_string('rules:heading', 'local_airouter'),
        );
    }

    /**
     * How many keys have been registered, which says nothing about whether they work.
     *
     * @param string $scope One of the key scopes.
     * @return setup_step The step.
     */
    protected function registered_step(string $scope): setup_step {
        $count = $this->db->count_records(key::TABLE, ['scope' => $scope]);

        return new setup_step(
            'registered',
            setup_step::CHECK,
            [get_string('setup:registered:' . $scope, 'local_airouter', $count),
             get_string('setup:registered:note', 'local_airouter')],
        );
    }

    /**
     * The file brought keys are encrypted with.
     *
     * @return setup_step The step.
     */
    protected function backup_step(): setup_step {
        return new setup_step('backup', setup_step::CHECK, [get_string('setup:backup', 'local_airouter')]);
    }

    /**
     * Every action the router declares, inspected once.
     *
     * @return route_inspection[] Keyed by action class name.
     */
    protected function get_inspections(): array {
        if ($this->inspections === null) {
            $inspector = new route_inspector($this->db, $this->now);
            $this->inspections = [];
            foreach (managed_policy::declared_actions() as $action) {
                $this->inspections[$action] = $inspector->inspect($action);
            }
        }

        return $this->inspections;
    }

    /**
     * The inspections of the actions that go through the router.
     *
     * @return route_inspection[] Keyed by action class name.
     */
    protected function routed_inspections(): array {
        return array_filter(
            $this->get_inspections(),
            static fn(route_inspection $i): bool => $i->applied === route_inspection::APPLIED_ROUTED,
        );
    }

    /**
     * The rules in force now.
     *
     * @return rule[] Keyed by id.
     */
    protected function get_rules(): array {
        return $this->rules ??= (new rule_repository($this->db))->get_active($this->now);
    }

    /**
     * The budget conditions of the rules in force.
     *
     * @return array[] Keyed by rule id.
     */
    protected function budget_conditions(): array {
        $this->conditions ??= (new rule_repository($this->db))->get_conditions_for(array_keys($this->get_rules()));
        $budgets = [];
        foreach ($this->conditions as $id => $conditions) {
            if (isset($conditions['budget']) && is_array($conditions['budget'])) {
                $budgets[$id] = $conditions['budget'];
            }
        }

        return $budgets;
    }
}
