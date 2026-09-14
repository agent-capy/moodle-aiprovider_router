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
 * Belongs to one of the named cohorts.
 *
 * The most direct way for a site to answer this question: whoever is in the list may
 * bring a key. Sites already keep cohorts for the groups that cut across courses — a
 * department, a project, the people who have been briefed on paying for their own use —
 * and maintaining one is something an administrator can hand to somebody else, which a
 * role assignment or a profile field is not.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort extends base {
    #[\Override]
    public function is_met(int $userid): bool {
        global $DB;

        $cohorts = array_filter(array_map('intval', $this->config['cohorts'] ?? []));
        if (!$cohorts || $userid <= 0) {
            return false;
        }

        [$insql, $params] = $DB->get_in_or_equal($cohorts, SQL_PARAMS_NAMED, 'cohort');
        $params['userid'] = $userid;

        return $DB->record_exists_sql(
            'SELECT 1 FROM {cohort_members} WHERE userid = :userid AND cohortid ' . $insql,
            $params,
        );
    }

    #[\Override]
    public static function add_to_form(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'autocomplete',
            'eligibilitycohorts',
            self::get_label(),
            self::get_cohort_options(),
            ['multiple' => true],
        );
        $mform->addHelpButton('eligibilitycohorts', 'eligibility:cohort', 'aiprovider_router');
    }

    #[\Override]
    public static function get_form_elements(): array {
        return ['eligibilitycohorts'];
    }

    #[\Override]
    public static function read_from_form(\stdClass $data): ?array {
        $cohorts = array_filter(array_map('intval', (array) ($data->eligibilitycohorts ?? [])));

        return $cohorts ? ['cohorts' => array_values($cohorts)] : null;
    }

    #[\Override]
    public static function to_form_data(array $config): array {
        return ['eligibilitycohorts' => array_map('intval', $config['cohorts'] ?? [])];
    }

    #[\Override]
    public function get_description(): string {
        $names = [];
        $known = self::get_cohort_options();
        foreach ($this->config['cohorts'] ?? [] as $cohortid) {
            // A cohort can be deleted after the policy names it. Saying so beats showing
            // a number, and the condition simply stops matching on that entry.
            $names[] = $known[(int) $cohortid]
                ?? get_string('eligibility:cohort:gone', 'aiprovider_router', $cohortid);
        }

        return get_string('eligibility:cohort:described', 'aiprovider_router', implode(', ', $names));
    }

    /**
     * The cohorts a policy may name, ready for a form.
     *
     * Read directly rather than through cohort_get_all_cohorts(), which pages its results
     * and is built for a browsing screen. What is wanted here is the whole list, named so
     * that two cohorts with the same name in different categories can be told apart.
     *
     * @return string[] Cohort names keyed by id.
     */
    public static function get_cohort_options(): array {
        global $DB;

        $options = [];
        $records = $DB->get_records('cohort', null, 'name ASC', 'id, name, idnumber, contextid');
        foreach ($records as $record) {
            $name = format_string($record->name);
            $where = self::describe_context((int) $record->contextid);
            $options[(int) $record->id] = $where === '' ? $name : $name . ' (' . $where . ')';
        }

        return $options;
    }

    /**
     * Where a cohort lives, for telling two of the same name apart.
     *
     * @param int $contextid The cohort's context.
     * @return string The context name, or the empty string for the site itself.
     */
    protected static function describe_context(int $contextid): string {
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context || $context instanceof \context_system) {
            return '';
        }

        return $context->get_context_name(false);
    }
}
