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

namespace aiprovider_router\eligibility;

/**
 * Holds one of the named roles somewhere in the course tree.
 *
 * The usual answer to "who may bring their own key" is "the people who teach here", and
 * that is not a capability a site can be asked to invent: it is the roles it already
 * uses. A role held on a category counts, because holding it there is how a site says
 * somebody teaches every course under it.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teaching extends base {
    /** @var string[] Archetypes whose roles are ticked when the policy is first opened. */
    public const DEFAULT_ARCHETYPES = ['editingteacher', 'teacher', 'manager'];

    #[\Override]
    public function is_met(int $userid): bool {
        global $DB;

        $roles = array_filter(array_map('intval', $this->config['roles'] ?? []));
        if (!$roles || $userid <= 0) {
            // An unanswered policy refuses rather than admits. The other direction would
            // hand the site's AI to everybody the moment somebody saved an empty form.
            return false;
        }

        [$insql, $params] = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'role');
        [$levelsql, $levelparams] = $DB->get_in_or_equal(
            [CONTEXT_COURSECAT, CONTEXT_COURSE, CONTEXT_MODULE],
            SQL_PARAMS_NAMED,
            'level',
        );
        $params['userid'] = $userid;

        return $DB->record_exists_sql(
            'SELECT 1
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = :userid AND ra.roleid ' . $insql . ' AND ctx.contextlevel ' . $levelsql,
            $params + $levelparams,
        );
    }

    #[\Override]
    public static function add_to_form(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'autocomplete',
            'eligibilityroles',
            self::get_label(),
            self::get_role_options(),
            ['multiple' => true],
        );
        $mform->addHelpButton('eligibilityroles', 'eligibility:teaching', 'aiprovider_router');
    }

    #[\Override]
    public static function get_form_elements(): array {
        return ['eligibilityroles'];
    }

    #[\Override]
    public static function read_from_form(\stdClass $data): ?array {
        $roles = array_filter(array_map('intval', (array) ($data->eligibilityroles ?? [])));

        return $roles ? ['roles' => array_values($roles)] : null;
    }

    #[\Override]
    public static function to_form_data(array $config): array {
        return ['eligibilityroles' => array_map('intval', $config['roles'] ?? [])];
    }

    #[\Override]
    public function get_description(): string {
        $names = [];
        $known = self::get_role_options();
        foreach ($this->config['roles'] ?? [] as $roleid) {
            $names[] = $known[(int) $roleid] ?? get_string('eligibility:role:gone', 'aiprovider_router', $roleid);
        }

        return get_string('eligibility:teaching:described', 'aiprovider_router', implode(', ', $names));
    }

    /**
     * The roles the policy may name, ready for a form.
     *
     * @return string[] Role names keyed by id.
     */
    public static function get_role_options(): array {
        $options = [];
        foreach (role_fix_names(get_all_roles(), \context_system::instance(), ROLENAME_ORIGINAL) as $role) {
            $options[(int) $role->id] = $role->localname;
        }

        return $options;
    }

    /**
     * The roles a site would expect to be ticked before anybody has chosen.
     *
     * @return int[] Role ids.
     */
    public static function get_default_roles(): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(self::DEFAULT_ARCHETYPES, SQL_PARAMS_NAMED);

        return array_map('intval', array_keys(
            $DB->get_records_select('role', 'archetype ' . $insql, $params, '', 'id'),
        ));
    }
}
