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

use aiprovider_router\eligibility\cohort;
use aiprovider_router\eligibility\profilefield;
use aiprovider_router\eligibility\teaching;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Tests for who the site allows to bring their own key.
 *
 * Every way this can be wrong is a way of giving the site's AI budget away, so the cases
 * that matter most are the ones where nothing has been said: an unanswered policy, a
 * condition with nothing chosen in it, a type this version does not understand. All of
 * them have to refuse.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(eligibility_policy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(teaching::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(profilefield::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(cohort::class)]
final class eligibility_policy_test extends \advanced_testcase {
    /** @var eligibility_policy The policy under test. */
    protected eligibility_policy $policy;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->policy = new eligibility_policy();
    }

    /**
     * The id of a role by its short name.
     *
     * @param string $shortname The role short name.
     * @return int The role id.
     */
    protected function role(string $shortname): int {
        global $DB;

        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    public function test_a_site_that_has_said_nothing_allows_nobody(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame(eligibility_policy::ACCESS_NOBODY, $this->policy->get_access());
        $this->assertFalse($this->policy->is_eligible((int) $user->id));
    }

    public function test_a_site_can_allow_everybody(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->policy->save(eligibility_policy::ACCESS_EVERYBODY, []);

        $this->assertTrue($this->policy->is_eligible((int) $user->id));
    }

    public function test_the_guest_never_brings_a_key(): void {
        $this->policy->save(eligibility_policy::ACCESS_EVERYBODY, []);

        $this->assertFalse($this->policy->is_eligible((int) guest_user()->id));
        $this->assertFalse($this->policy->is_eligible(0));
    }

    public function test_conditions_chosen_with_no_conditions_named_allows_nobody(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, []);

        // The site asked for "only those matching the conditions" and named none. Reading
        // that as everybody would be the opposite of what was asked for.
        $this->assertFalse($this->policy->is_eligible((int) $user->id));
    }

    public function test_a_teacher_matches_and_a_student_does_not(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, [
            'teaching' => ['roles' => [$this->role('editingteacher')]],
        ]);

        $this->assertTrue($this->policy->is_eligible((int) $teacher->id));
        $this->assertFalse($this->policy->is_eligible((int) $student->id));
    }

    public function test_a_role_held_on_a_category_counts(): void {
        $category = $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();
        role_assign($this->role('editingteacher'), $user->id, \context_coursecat::instance($category->id)->id);

        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, [
            'teaching' => ['roles' => [$this->role('editingteacher')]],
        ]);

        // Holding the role there is how a site says somebody teaches everything under it.
        $this->assertTrue($this->policy->is_eligible((int) $user->id));
    }

    public function test_a_condition_with_nothing_chosen_in_it_allows_nobody(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, ['teaching' => ['roles' => []]]);

        $this->assertFalse($this->policy->is_eligible((int) $teacher->id));
    }

    public function test_a_profile_field_can_decide_it(): void {
        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'staffcategory',
            'name' => 'Staff category',
        ]);
        $staff = $this->getDataGenerator()->create_user(['profile_field_staffcategory' => 'Academic']);
        $other = $this->getDataGenerator()->create_user(['profile_field_staffcategory' => 'Visitor']);
        $this->assertNotEmpty($field);

        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, [
            'profilefield' => ['field' => 'staffcategory', 'values' => ['academic', 'research']],
        ]);

        // Typed by a person into a text field, so the comparison ignores case.
        $this->assertTrue($this->policy->is_eligible((int) $staff->id));
        $this->assertFalse($this->policy->is_eligible((int) $other->id));
    }

    /**
     * Somebody who teaches but is in no cohort, and a cohort they are not in.
     *
     * @return array The user and the cohort.
     */
    protected function teacher_outside_a_cohort(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $cohort = $this->getDataGenerator()->create_cohort();

        return [$teacher, $cohort];
    }

    public function test_any_one_condition_is_enough_by_default(): void {
        [$teacher, $cohort] = $this->teacher_outside_a_cohort();

        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, [
            'teaching' => ['roles' => [$this->role('editingteacher')]],
            'cohort' => ['cohorts' => [(int) $cohort->id]],
        ]);

        // Teachers, or anyone in the BYOK cohort: the ordinary shape of this policy, and
        // a site with one policy and no list behind it has nowhere else to express it.
        $this->assertSame(eligibility_policy::MATCH_ANY, $this->policy->get_match());
        $this->assertTrue($this->policy->is_eligible((int) $teacher->id));
    }

    public function test_a_site_can_require_every_condition_instead(): void {
        [$teacher, $cohort] = $this->teacher_outside_a_cohort();

        $this->policy->save(
            eligibility_policy::ACCESS_CONDITIONS,
            [
                'teaching' => ['roles' => [$this->role('editingteacher')]],
                'cohort' => ['cohorts' => [(int) $cohort->id]],
            ],
            eligibility_policy::MATCH_ALL,
        );

        // The same two conditions now mean "teachers who are also in that cohort".
        $this->assertFalse($this->policy->is_eligible((int) $teacher->id));

        cohort_add_member((int) $cohort->id, (int) $teacher->id);
        eligibility_policy::purge();

        $this->assertTrue($this->policy->is_eligible((int) $teacher->id));
    }

    public function test_a_cohort_member_matches_and_somebody_else_does_not(): void {
        $cohort = $this->getDataGenerator()->create_cohort();
        $member = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        cohort_add_member((int) $cohort->id, (int) $member->id);

        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, [
            'cohort' => ['cohorts' => [(int) $cohort->id]],
        ]);

        $this->assertTrue($this->policy->is_eligible((int) $member->id));
        $this->assertFalse($this->policy->is_eligible((int) $other->id));
    }

    public function test_a_cohort_condition_with_no_cohort_chosen_allows_nobody(): void {
        $cohort = $this->getDataGenerator()->create_cohort();
        $member = $this->getDataGenerator()->create_user();
        cohort_add_member((int) $cohort->id, (int) $member->id);

        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, ['cohort' => ['cohorts' => []]]);

        $this->assertFalse($this->policy->is_eligible((int) $member->id));
    }

    public function test_a_cohort_that_has_been_deleted_is_named_as_such(): void {
        $condition = new cohort(['cohorts' => [999999]]);

        $this->assertFalse($condition->is_met((int) $this->getDataGenerator()->create_user()->id));
        $this->assertStringContainsString('999999', $condition->get_description());
    }

    public function test_two_cohorts_of_the_same_name_can_be_told_apart(): void {
        $category = $this->getDataGenerator()->create_category(['name' => 'Science']);
        $this->getDataGenerator()->create_cohort(['name' => 'Pilot']);
        $this->getDataGenerator()->create_cohort([
            'name' => 'Pilot',
            'contextid' => \context_coursecat::instance($category->id)->id,
        ]);

        $options = cohort::get_cohort_options();

        $this->assertCount(2, $options);
        $this->assertContains('Pilot', $options);
        $this->assertContains('Pilot (Science)', $options);
    }

    public function test_an_unknown_match_setting_falls_back_to_any(): void {
        [$teacher, $cohort] = $this->teacher_outside_a_cohort();
        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, [
            'teaching' => ['roles' => [$this->role('editingteacher')]],
            'cohort' => ['cohorts' => [(int) $cohort->id]],
        ], eligibility_policy::MATCH_ALL);
        set_config(eligibility_policy::MATCH_SETTING, 'sometimes', 'aiprovider_router');
        eligibility_policy::purge();

        $this->assertSame(eligibility_policy::MATCH_ANY, $this->policy->get_match());
        $this->assertTrue($this->policy->is_eligible((int) $teacher->id));
    }

    public function test_an_unknown_match_cannot_be_saved(): void {
        $this->expectException(\coding_exception::class);

        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, [], 'sometimes');
    }

    public function test_an_answer_is_remembered_rather_than_worked_out_again(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, [
            'teaching' => ['roles' => [$this->role('editingteacher')]],
        ]);
        $this->assertFalse($this->policy->is_eligible((int) $user->id));

        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');

        // Still the remembered answer: a change in who holds which role is followed
        // within the cache's lifetime rather than at once.
        $this->assertFalse($this->policy->is_eligible((int) $user->id));
        $this->assertTrue($this->policy->evaluate((int) $user->id));

        eligibility_policy::purge();

        $this->assertTrue($this->policy->is_eligible((int) $user->id));
    }

    public function test_changing_the_policy_takes_effect_at_once(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->policy->save(eligibility_policy::ACCESS_EVERYBODY, []);
        $this->assertTrue($this->policy->is_eligible((int) $user->id));

        $this->policy->save(eligibility_policy::ACCESS_NOBODY, []);

        // An administrator who has just shut this off does not wait five minutes to see it.
        $this->assertFalse($this->policy->is_eligible((int) $user->id));
    }

    public function test_a_condition_this_version_does_not_understand_is_reported_and_ignored(): void {
        $user = $this->getDataGenerator()->create_user();
        set_config(eligibility_policy::ACCESS_SETTING, eligibility_policy::ACCESS_CONDITIONS, 'aiprovider_router');
        set_config(
            eligibility_policy::POLICY_SETTING,
            json_encode(['fromthefuture' => ['something' => 1]]),
            'aiprovider_router',
        );

        $this->assertSame(['fromthefuture'], $this->policy->get_unknown_conditions());
        $this->assertSame([], $this->policy->get_conditions());
        // Nothing left that this version understands, so nobody is admitted.
        $this->assertFalse($this->policy->is_eligible((int) $user->id));
    }

    public function test_an_unknown_access_setting_falls_back_to_refusing(): void {
        $user = $this->getDataGenerator()->create_user();
        set_config(eligibility_policy::ACCESS_SETTING, 'anythinggoes', 'aiprovider_router');

        $this->assertSame(eligibility_policy::ACCESS_NOBODY, $this->policy->get_access());
        $this->assertFalse($this->policy->is_eligible((int) $user->id));
    }

    public function test_the_policy_can_be_described_in_words(): void {
        $this->policy->save(eligibility_policy::ACCESS_CONDITIONS, [
            'teaching' => ['roles' => [$this->role('editingteacher')]],
        ]);

        $descriptions = $this->policy->get_descriptions();

        $this->assertCount(1, $descriptions);
        $this->assertStringContainsString('Teacher', $descriptions[0]);
    }
}
