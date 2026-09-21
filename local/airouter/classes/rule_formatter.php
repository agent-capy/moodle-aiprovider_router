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

use local_airouter\condition\registry;

/**
 * Turns a rule into the cells of the rule list.
 *
 * The list is where an administrator answers "why did this request go there", so every
 * cell says what it means rather than showing what is stored. A target id, a condition
 * type and a sort order are all meaningless on their own.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_formatter {
    /**
     * The rule's name, with anything worth knowing about it.
     *
     * @param rule $rule The rule.
     * @param bool $reachable Whether any request can still get this far down the list.
     * @return string HTML.
     */
    public static function name(rule $rule, bool $reachable): string {
        $output = \html_writer::tag('strong', s($rule->get('name')));
        if (!$rule->get('enabled')) {
            $output .= \html_writer::div(get_string('rules:disabled', 'local_airouter'), 'text-muted');
        }
        if (!$reachable && $rule->get('enabled')) {
            $output .= \html_writer::div(
                get_string('rules:unreachable', 'local_airouter'),
                'text-danger',
            );
        }
        $window = self::window($rule);
        if ($window !== '') {
            $output .= \html_writer::div($window, 'text-muted');
        }

        return $output;
    }

    /**
     * What the rule requires of a request.
     *
     * @param array[] $conditions Stored configuration keyed by condition type.
     * @return string HTML.
     */
    public static function conditions(array $conditions): string {
        if (!$conditions) {
            // Worth spelling out. A rule with nothing on it takes every request that
            // reaches it, which is useful at the bottom of the list and alarming at the top.
            return \html_writer::span(get_string('rules:noconditions', 'local_airouter'), 'text-warning');
        }

        $described = [];
        foreach ($conditions as $type => $config) {
            $condition = registry::make((string) $type, $config);
            $described[] = $condition === null
                ? get_string('rules:unknowncondition', 'local_airouter', s((string) $type))
                : $condition->get_description();
        }

        return \html_writer::alist($described);
    }

    /**
     * Where the rule sends a request, and whose key pays for it.
     *
     * @param rule $rule The rule.
     * @param string[] $targets Instance names keyed by id.
     * @param int[] $byokcapable Ids of the instances a brought key can actually go into.
     * @param int[] $byokonly Ids of the instances the site's own key may not be used at.
     * @return string HTML.
     */
    public static function target(
        rule $rule,
        array $targets,
        array $byokcapable = [],
        array $byokonly = [],
    ): string {
        $targetid = (int) $rule->get('targetid');
        if (isset($targets[$targetid])) {
            $output = s($targets[$targetid]);
        } else {
            // The instance has been deleted, or it is a router. Either way this rule
            // cannot be carried out and requests matching it fall through to the next.
            $output = \html_writer::span(
                get_string('rules:missingtarget', 'local_airouter', $targetid),
                'text-danger',
            );
        }

        return $output . self::keysource($rule, $byokcapable, $byokonly);
    }

    /**
     * Whose key pays for the requests this rule claims.
     *
     * Two cases are worth calling out, and neither looks wrong from the list. A rule
     * asking for a brought key at an instance nobody has said the key field of can
     * never be honoured; a rule paying with the site's key at an instance kept for
     * brought keys only can never be honoured either. Both are valid rules at working
     * instances whose only symptom is that they never seem to claim anything.
     *
     * @param rule $rule The rule.
     * @param int[] $byokcapable Ids of the instances a brought key can actually go into.
     * @param int[] $byokonly Ids of the instances the site's own key may not be used at.
     * @return string HTML, empty for an ordinary rule the site pays for.
     */
    protected static function keysource(rule $rule, array $byokcapable, array $byokonly = []): string {
        if (!$rule->is_byok()) {
            // Every rule was this before keys could be brought, so saying it on all of
            // them would bury the one rule an administrator is looking for.
            if (in_array((int) $rule->get('targetid'), array_map('intval', $byokonly), true)) {
                return \html_writer::div(
                    get_string('rules:byokonlytarget', 'local_airouter'),
                    'text-warning',
                );
            }

            return '';
        }

        $output = \html_writer::div(
            get_string(
                'rules:keysource',
                'local_airouter',
                get_string('keysource:' . $rule->get('keysource'), 'local_airouter'),
            ),
            'text-muted',
        );
        if (!in_array((int) $rule->get('targetid'), array_map('intval', $byokcapable), true)) {
            $output .= \html_writer::div(
                get_string('rules:byoknotsupported', 'local_airouter'),
                'text-warning',
            );
        }

        return $output;
    }

    /**
     * The things that can be done to the rule.
     *
     * @param \moodle_url $url The rule list page.
     * @param rule $rule The rule.
     * @param int $position Where the rule sits, counting from zero.
     * @param int $last The position of the last rule.
     * @return string HTML.
     */
    public static function actions(\moodle_url $url, rule $rule, int $position, int $last): string {
        global $OUTPUT;

        $id = (int) $rule->get('id');
        $links = [];

        $links[] = \html_writer::link(
            new \moodle_url('/local/airouter/rule.php', ['id' => $id]),
            $OUTPUT->pix_icon('t/edit', get_string('edit')),
        );
        $links[] = self::action_link($url, $id, 'duplicate', 't/copy', 'rules:duplicate');
        $links[] = $rule->get('enabled')
            ? self::action_link($url, $id, 'disable', 't/hide', 'rules:disable')
            : self::action_link($url, $id, 'enable', 't/show', 'rules:enable');
        $links[] = $position > 0
            ? self::action_link($url, $id, 'up', 't/up', 'rules:moveup')
            : '';
        $links[] = $position < $last
            ? self::action_link($url, $id, 'down', 't/down', 'rules:movedown')
            : '';
        $links[] = \html_writer::link(
            new \moodle_url($url, ['action' => 'delete', 'ruleid' => $id]),
            $OUTPUT->pix_icon('t/delete', get_string('delete')),
        );

        return implode(' ', array_filter($links));
    }

    /**
     * One icon link that changes something, carrying the session key.
     *
     * @param \moodle_url $url The rule list page.
     * @param int $id The rule id.
     * @param string $action What to do.
     * @param string $icon The core icon to show.
     * @param string $stringid The language string naming the action.
     * @return string HTML.
     */
    protected static function action_link(
        \moodle_url $url,
        int $id,
        string $action,
        string $icon,
        string $stringid,
    ): string {
        global $OUTPUT;

        return \html_writer::link(
            new \moodle_url($url, ['action' => $action, 'ruleid' => $id, 'sesskey' => sesskey()]),
            $OUTPUT->pix_icon($icon, get_string($stringid, 'local_airouter')),
        );
    }

    /**
     * The rule's active period, when it has one.
     *
     * @param rule $rule The rule.
     * @return string Plain text, already escaped. Empty when the rule always applies.
     */
    protected static function window(rule $rule): string {
        $start = (int) $rule->get('timestart');
        $end = (int) $rule->get('timeend');
        if (!$start && !$end) {
            return '';
        }
        $format = get_string('strftimedatetimeshort', 'langconfig');

        return get_string('rules:window', 'local_airouter', [
            'start' => $start ? userdate($start, $format) : get_string('rules:window:open', 'local_airouter'),
            'end' => $end ? userdate($end, $format) : get_string('rules:window:open', 'local_airouter'),
        ]);
    }
}
