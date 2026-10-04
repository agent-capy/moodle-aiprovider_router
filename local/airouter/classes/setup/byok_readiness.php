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

use local_airouter\eligibility_policy;
use local_airouter\key;
use local_airouter\rule;
use local_airouter\rule_repository;
use local_airouter\target_resolver;
use local_airouter\target_settings;

/**
 * What stands between each provider and a request paid for with a brought key.
 *
 * A personal key and a course key do not need the same things. The policy on who may
 * bring a key is about personal keys only; a course key is registered by whoever may
 * manage the course's key. Both need the provider to have said where a key goes, to
 * accept brought keys, and to have its own key field filled, since Moodle does not
 * count a provider with an empty key field as set up at all.
 *
 * The number of keys registered is shown as a guide. It is not readiness: a key can be
 * for the wrong course, belong to somebody the policy no longer admits, or have reached
 * its owner's limit.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok_readiness {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database.
     * @param int $now The time to judge rule windows at.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var int The time to judge rule windows at. */
        protected readonly int $now,
    ) {
    }

    /**
     * Whether personal keys can be brought at all under the site's policy.
     *
     * @return bool True when the policy admits somebody.
     */
    public function personal_keys_admitted(): bool {
        return (new eligibility_policy())->get_access() !== eligibility_policy::ACCESS_NOBODY;
    }

    /**
     * One row per provider instance.
     *
     * @return \stdClass[] Keyed by instance id: name, field (null when nobody has said,
     *                     target_settings::NO_KEY when it takes none), mode, problem, and
     *                     counts of rules and keys for each scope.
     */
    public function rows(): array {
        $settings = new target_settings($this->db);
        $resolver = target_resolver::for_site_with_instances();

        $rules = [];
        foreach ((new rule_repository($this->db))->get_active($this->now) as $rule) {
            if ($rule->is_byok()) {
                $rules[(int) $rule->get('targetid')][(string) $rule->get('keysource')][] = $rule;
            }
        }

        $keys = [];
        $sql = 'SELECT targetid, scope, COUNT(1) AS held FROM {' . key::TABLE . '} GROUP BY targetid, scope';
        foreach ($this->db->get_recordset_sql($sql) as $record) {
            $keys[(int) $record->targetid][(string) $record->scope] = (int) $record->held;
        }

        $rows = [];
        foreach (target_resolver::get_delegation_targets() as $id => $name) {
            $field = $settings->get_key_field($id);
            $rows[$id] = (object) [
                'name' => $name,
                'field' => $field,
                'mode' => $settings->get_mode($id),
                'problem' => $resolver->get_instance_problem($id),
                'userrules' => count($rules[$id][rule::KEYSOURCE_USER] ?? []),
                'courserules' => count($rules[$id][rule::KEYSOURCE_COURSE] ?? []),
                'userkeys' => $keys[$id][key::SCOPE_USER] ?? 0,
                'coursekeys' => $keys[$id][key::SCOPE_COURSE] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * Whether a provider can carry a brought key at all.
     *
     * @param \stdClass $row A row from rows().
     * @return bool True when nothing in its settings stops it.
     */
    public static function is_ready(\stdClass $row): bool {
        return $row->field !== null && $row->field !== target_settings::NO_KEY
            && $row->mode !== target_settings::MODE_DISALLOWED && $row->problem === null;
    }

    /**
     * Whether a provider can carry a brought key, in words.
     *
     * @param \stdClass $row A row from rows().
     * @return string The state.
     */
    public static function describe(\stdClass $row): string {
        if ($row->field === null) {
            return get_string('byokready:nofield', 'local_airouter');
        }
        if ($row->field === target_settings::NO_KEY) {
            return get_string('byokready:takesnokey', 'local_airouter');
        }
        if ($row->mode === target_settings::MODE_DISALLOWED) {
            return get_string('byokready:disallowed', 'local_airouter');
        }
        if ($row->problem === target_resolver::PROBLEM_UNCONFIGURED) {
            return get_string('byokready:siteempty', 'local_airouter');
        }
        if ($row->problem !== null) {
            return s(target_resolver::describe_problem($row->problem, \core_ai\aiactions\generate_text::class));
        }

        return get_string('byokready:ready', 'local_airouter');
    }
}
