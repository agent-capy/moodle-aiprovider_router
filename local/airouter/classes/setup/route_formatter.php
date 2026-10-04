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

use local_airouter\target_resolver;

/**
 * Puts what route_inspector found into words.
 *
 * Every sentence says what the settings allow and nothing more. In particular none
 * of them says that a request will be answered: the words for the best case are "a
 * target is available", and the explanation goes on to say what is not checked.
 *
 * What comes back is HTML. Provider and rule names are entered by administrators and
 * are escaped here, before they go into a sentence.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class route_formatter {
    /**
     * Whether requests for the action go through the router, in words.
     *
     * @param route_inspection $inspection The inspection.
     * @return string The sentence.
     */
    public static function applied(route_inspection $inspection): string {
        return get_string('setup:applied:' . $inspection->applied, 'local_airouter');
    }

    /**
     * The short name of what the settings offer.
     *
     * @param route_inspection $inspection The inspection.
     * @return string The label.
     */
    public static function state_label(route_inspection $inspection): string {
        return get_string('setup:state:' . $inspection->state, 'local_airouter');
    }

    /**
     * What the settings offer, sentence by sentence.
     *
     * @param route_inspection $inspection The inspection.
     * @return string[] Sentences, as HTML.
     */
    public static function explain(route_inspection $inspection): array {
        $default = self::default_name($inspection);
        $sentences = [];

        $sentences[] = match ($inspection->state) {
            route_inspection::STATE_READY =>
                get_string('setup:state:ready_desc', 'local_airouter', $default),
            route_inspection::STATE_POLICY_REFUSED =>
                get_string('setup:state:policyrefused_desc', 'local_airouter', $default),
            route_inspection::STATE_CONDITIONAL => $inspection->served_only_by_brought_keys()
                ? get_string('setup:state:conditional_brought', 'local_airouter')
                : get_string('setup:state:conditional_desc', 'local_airouter'),
            default => get_string('setup:state:actionneeded_desc', 'local_airouter'),
        };

        // Where a request no rule claims goes, where the state has not said already.
        if (
            $inspection->state === route_inspection::STATE_CONDITIONAL
            || $inspection->state === route_inspection::STATE_ACTION_NEEDED
        ) {
            $sentences[] = self::unmatched($inspection);
        }

        // Where a request goes when the target a rule chose fails.
        $usable = $inspection->usable_rules();
        $sitepaid = array_filter($usable, static fn(route_rule $rule): bool => !$rule->brought);
        if ($sitepaid !== []) {
            if ($inspection->has_default_route()) {
                $sentences[] = get_string('setup:fallback:default', 'local_airouter', $default);
            } else if ($inspection->declines) {
                $sentences[] = get_string('setup:fallback:decline', 'local_airouter');
            }
        }
        if (count($sitepaid) !== count($usable)) {
            $sentences[] = get_string('setup:fallback:brought', 'local_airouter');
        }

        // A budget limits a rule, not the action: what it turns away is unclaimed.
        if ($inspection->has_budget_rule() && $inspection->has_default_route()) {
            $sentences[] = get_string('setup:budget', 'local_airouter', $default);
        }

        $broken = $inspection->broken_rules();
        if ($broken !== []) {
            $sentences[] = get_string('setup:brokenrules', 'local_airouter', implode(', ', array_map(
                static fn(route_rule $rule): string => get_string('setup:rulecannot', 'local_airouter', (object) [
                    'rule' => s($rule->name),
                    'target' => self::target_name($rule->targetid, $rule->targetname),
                    'reason' => s(target_resolver::describe_problem((string) $rule->problem, $inspection->actionclass)),
                ]),
                $broken,
            )));
        }

        return $sentences;
    }

    /**
     * What happens to the action if Moodle handles it itself.
     *
     * @param route_inspection $inspection The inspection.
     * @return string The sentence, as HTML.
     */
    public static function core_route(route_inspection $inspection): string {
        return $inspection->corefirst === null
            ? get_string('setup:core:none', 'local_airouter')
            : get_string('setup:core:first', 'local_airouter', s($inspection->corefirst));
    }

    /**
     * Everything worth saying about one action on a screen that changes the policy.
     *
     * For an action routed through the router: what it gets there, and what changes if
     * it stops being routed. For one that is not: what Moodle does with it now, and
     * what it would get through the router.
     *
     * @param route_inspection $inspection The inspection.
     * @return string The explanation, as HTML.
     */
    public static function describe(route_inspection $inspection): string {
        $routed = $inspection->applied === route_inspection::APPLIED_ROUTED;
        $summary = self::state_label($inspection) . ': ' . implode(' ', self::explain($inspection));

        $lines = [self::applied($inspection)];
        if ($routed) {
            $lines[] = $summary;
            $lines[] = get_string('setup:wouldtake', 'local_airouter', self::core_route($inspection));
        } else {
            if ($inspection->applied !== route_inspection::APPLIED_NO_PROCESSOR) {
                $lines[] = self::core_route($inspection);
            }
            $lines[] = get_string('setup:ifrouted', 'local_airouter', $summary);
        }

        return \html_writer::alist($lines, ['class' => 'local-airouter-route list-unstyled mb-0']);
    }

    /**
     * Where a request no rule claims goes, when that is somewhere other than a target.
     *
     * @param route_inspection $inspection The inspection.
     * @return string The sentence, as HTML.
     */
    protected static function unmatched(route_inspection $inspection): string {
        if ($inspection->declines) {
            return get_string('setup:unmatched:decline', 'local_airouter');
        }
        if ($inspection->defaulttargetid === null) {
            return get_string('setup:unmatched:none', 'local_airouter');
        }
        if ($inspection->defaultproblem !== null) {
            return get_string('setup:unmatched:broken', 'local_airouter', (object) [
                'target' => self::default_name($inspection),
                'reason' => s(target_resolver::describe_problem($inspection->defaultproblem, $inspection->actionclass)),
            ]);
        }

        return get_string('setup:state:ready_desc', 'local_airouter', self::default_name($inspection));
    }

    /**
     * The default target's name, escaped, or a description of it when it is gone.
     *
     * @param route_inspection $inspection The inspection.
     * @return string The name, as HTML.
     */
    protected static function default_name(route_inspection $inspection): string {
        return $inspection->defaulttargetid === null
            ? ''
            : self::target_name($inspection->defaulttargetid, $inspection->defaulttargetname);
    }

    /**
     * A target's name, escaped, or a description of it when it is gone.
     *
     * @param int $id The instance id.
     * @param string|null $name Its name, or null when it no longer exists.
     * @return string The name, as HTML.
     */
    protected static function target_name(int $id, ?string $name): string {
        return $name === null ? get_string('setup:missingtarget', 'local_airouter', $id) : s($name);
    }
}
