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

use core_ai\aiactions\generate_text;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture_promptless_action.php');

/**
 * Tests for what a rule is allowed to know about a request.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(evaluation_context::class)]
final class evaluation_context_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A context over a generate_text action raised somewhere.
     *
     * @param \context $context Where the action was raised.
     * @param int $userid Who raised it.
     * @param string $prompt What they asked.
     * @return evaluation_context The context under test.
     */
    protected function context(\context $context, int $userid = 0, string $prompt = 'Hello'): evaluation_context {
        return new evaluation_context(
            new generate_text(contextid: $context->id, userid: $userid, prompttext: $prompt),
        );
    }

    public function test_a_course_context_resolves_to_its_own_course(): void {
        $course = $this->getDataGenerator()->create_course();

        $context = $this->context(\context_course::instance($course->id));

        $this->assertSame((int) $course->id, $context->get_courseid());
    }

    public function test_an_activity_resolves_to_the_course_it_is_in(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $context = $this->context(\context_module::instance($page->cmid));

        $this->assertSame((int) $course->id, $context->get_courseid());
    }

    public function test_a_request_from_outside_any_course_has_no_course(): void {
        $context = $this->context(\context_system::instance());

        // Not an error. Site wide placements raise requests from here, and every course
        // condition simply fails to be met rather than matching something arbitrary.
        $this->assertNull($context->get_courseid());
        $this->assertSame([], $context->get_categoryids());
    }

    public function test_a_context_that_no_longer_exists_is_not_fatal(): void {
        $course = $this->getDataGenerator()->create_course();
        $contextid = \context_course::instance($course->id)->id;
        delete_course($course, false);

        $context = new evaluation_context(
            new generate_text(contextid: $contextid, userid: 0, prompttext: 'Hello'),
        );

        $this->assertNull($context->get_context());
        $this->assertNull($context->get_courseid());
    }

    public function test_the_categories_of_a_course_include_its_ancestors(): void {
        $parent = $this->getDataGenerator()->create_category();
        $child = $this->getDataGenerator()->create_category(['parent' => $parent->id]);
        $course = $this->getDataGenerator()->create_course(['category' => $child->id]);

        $categories = $this->context(\context_course::instance($course->id))->get_categoryids();

        // Nearest first, and the ancestors behind it. A rule naming the parent category
        // therefore covers this course, which is why the form offers no subcategory
        // switch: there is nothing to switch off.
        $this->assertSame([(int) $child->id, (int) $parent->id], $categories);
    }

    public function test_a_request_raised_in_a_category_finds_that_category(): void {
        $parent = $this->getDataGenerator()->create_category();
        $child = $this->getDataGenerator()->create_category(['parent' => $parent->id]);

        $categories = $this->context(\context_coursecat::instance($child->id))->get_categoryids();

        $this->assertSame([(int) $child->id, (int) $parent->id], $categories);
    }

    public function test_roles_include_those_inherited_from_further_up(): void {
        global $CFG, $DB;
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $user = $this->getDataGenerator()->create_user();
        $managerid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $studentid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        role_assign($managerid, $user->id, \context_coursecat::instance($category->id)->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $roles = $this->context(\context_course::instance($course->id), (int) $user->id)->get_roleids();

        sort($roles);
        // The authenticated user role is here too, because Moodle gives it to every
        // logged in account without writing an assignment for it. The point of this
        // test is the manager role, which is held two levels above the course.
        $expected = [$managerid, $studentid, (int) $CFG->defaultuserroleid];
        sort($expected);
        $this->assertSame($expected, $roles);
    }

    public function test_a_request_without_a_user_has_no_roles(): void {
        $context = $this->context(\context_system::instance(), 0);

        $this->assertSame([], $context->get_roleids());
    }

    public function test_the_prompt_is_sized_in_tokens(): void {
        $context = new evaluation_context(
            new generate_text(contextid: \context_system::instance()->id, userid: 0, prompttext: 'abcdefghijklmnop'),
            new token_estimator(cjkratio: 1.0, otherratio: 4.0),
        );

        $this->assertSame(4, $context->get_estimated_tokens());
    }

    public function test_an_action_that_carries_no_prompt_is_not_a_warning(): void {
        // Core hands back the property of that name directly, so asking an action for
        // something it does not have would raise a PHP warning. Actions from other
        // plugins need not carry a prompt or a user.
        $context = new evaluation_context(new fixture_promptless_action(contextid: 1));

        $this->assertSame('', $context->get_prompt());
        $this->assertSame(0, $context->get_userid());
        $this->assertSame(0, $context->get_estimated_tokens());
    }

    public function test_a_placement_given_by_the_caller_is_used_as_is(): void {
        // This is what the rule tester does: it asks what would happen for a placement
        // rather than being called from one.
        $context = new evaluation_context(
            new generate_text(contextid: \context_system::instance()->id, userid: 0, prompttext: 'Hello'),
            placement: 'aiplacement_courseassist',
        );

        $this->assertSame('aiplacement_courseassist', $context->get_placement());
    }

    public function test_a_request_that_came_from_no_placement_says_so(): void {
        // PHPUnit is not a placement, so there is nothing in the call stack to find.
        // Every placement condition is then not met, rather than met by guesswork.
        $context = $this->context(\context_system::instance());

        $this->assertNull($context->get_placement());
    }

    public function test_the_role_moodle_gives_everybody_counts_as_a_role(): void {
        global $CFG;

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $context = $this->context(\context_course::instance((int) $course->id), (int) $user->id);

        // Moodle gives every logged in account the authenticated user role without ever
        // writing a role assignment for it. The role condition offers it in its list --
        // it is a role, and "anybody with an account" is an ordinary thing to mean --
        // so a condition that could be chosen and never satisfied was a trap.
        $this->assertContains((int) $CFG->defaultuserroleid, $context->get_roleids());
    }

    public function test_a_role_somebody_was_actually_given_still_counts(): void {
        global $DB;

        // The other half: adding the special roles must not lose the ordinary ones.
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $editing = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);

        $context = $this->context(\context_course::instance((int) $course->id), (int) $teacher->id);

        $this->assertContains($editing, $context->get_roleids());
    }

    public function test_the_action_class_is_reported_without_a_leading_separator(): void {
        $context = $this->context(\context_system::instance());

        $this->assertSame(generate_text::class, $context->get_action_class());
        $this->assertStringStartsNotWith('\\', $context->get_action_class());
    }
}
