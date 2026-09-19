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

namespace aiprovider_router\condition;

use aiprovider_router\evaluation_context;
use aiprovider_router\token_estimator;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;

/**
 * Tests for the conditions a rule is built from.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(set_base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(category::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(role::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(action::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(placement::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(promptlength::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(registry::class)]
final class condition_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * An evaluation context over a generate_text action.
     *
     * @param \context $context Where the action was raised.
     * @param int $userid Who raised it.
     * @param string $prompt What they asked.
     * @param string|null $placement The placement to report.
     * @return evaluation_context The context.
     */
    protected function context(
        \context $context,
        int $userid = 0,
        string $prompt = 'Hello',
        ?string $placement = null,
    ): evaluation_context {
        return new evaluation_context(
            new generate_text(contextid: $context->id, userid: $userid, prompttext: $prompt),
            new token_estimator(cjkratio: 1.0, otherratio: 4.0),
            $placement,
        );
    }

    public function test_a_course_condition_is_met_by_any_of_the_courses_listed(): void {
        $wanted = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $condition = new course(['courseids' => [(int) $wanted->id, 12345]]);

        $this->assertTrue($condition->is_met($this->context(\context_course::instance($wanted->id))));
        $this->assertFalse($condition->is_met($this->context(\context_course::instance($other->id))));
    }

    public function test_a_course_condition_is_not_met_outside_a_course(): void {
        $condition = new course(['courseids' => [1, 2, 3]]);

        $this->assertFalse($condition->is_met($this->context(\context_system::instance())));
    }

    public function test_a_category_condition_covers_the_courses_beneath_it(): void {
        $parent = $this->getDataGenerator()->create_category();
        $child = $this->getDataGenerator()->create_category(['parent' => $parent->id]);
        $course = $this->getDataGenerator()->create_course(['category' => $child->id]);
        $condition = new category(['categoryids' => [(int) $parent->id]]);

        $this->assertTrue($condition->is_met($this->context(\context_course::instance($course->id))));
    }

    public function test_a_role_condition_is_met_by_any_of_the_roles_listed(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $condition = new role(['roleids' => [
            (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']),
            (int) $DB->get_field('role', 'id', ['shortname' => 'manager']),
        ]]);

        $coursecontext = \context_course::instance($course->id);
        $this->assertTrue($condition->is_met($this->context($coursecontext, (int) $teacher->id)));
        $this->assertFalse($condition->is_met($this->context($coursecontext, (int) $student->id)));
    }

    public function test_an_action_condition_matches_the_action_being_routed(): void {
        $condition = new action(['actions' => [generate_text::class, summarise_text::class]]);

        $this->assertTrue($condition->is_met($this->context(\context_system::instance())));
    }

    public function test_an_action_condition_ignores_a_leading_separator(): void {
        // Core stores provider class names without one, and a name typed by hand or
        // written by an older version may carry it. Both spellings mean the same class.
        $condition = new action(['actions' => ['\\' . generate_text::class]]);

        $this->assertTrue($condition->is_met($this->context(\context_system::instance())));
    }

    public function test_an_action_condition_for_another_action_is_not_met(): void {
        $condition = new action(['actions' => [summarise_text::class]]);

        $this->assertFalse($condition->is_met($this->context(\context_system::instance())));
    }

    public function test_a_placement_condition_matches_the_placement_that_asked(): void {
        $condition = new placement(['placements' => ['aiplacement_courseassist']]);

        $this->assertTrue($condition->is_met(
            $this->context(\context_system::instance(), placement: 'aiplacement_courseassist'),
        ));
        $this->assertFalse($condition->is_met(
            $this->context(\context_system::instance(), placement: 'aiplacement_editor'),
        ));
    }

    public function test_a_placement_that_cannot_be_identified_meets_no_condition(): void {
        // Actions do not say which placement raised them on any supported version, so
        // this happens for real. Falling into the rule on a guess would be worse.
        $condition = new placement(['placements' => ['aiplacement_courseassist']]);

        $this->assertFalse($condition->is_met($this->context(\context_system::instance())));
    }

    public function test_a_prompt_length_condition_compares_characters(): void {
        $long = new promptlength(['operator' => promptlength::OPERATOR_GTE, 'characters' => 16]);
        $short = new promptlength(['operator' => promptlength::OPERATOR_LTE, 'characters' => 15]);
        $context = $this->context(\context_system::instance(), prompt: 'abcdefghijklmnop');

        $this->assertTrue($long->is_met($context));
        $this->assertFalse($short->is_met($context));
    }

    public function test_a_prompt_length_condition_counts_characters_not_bytes(): void {
        // Nine characters, but twenty seven bytes. strlen() would put this over any
        // threshold an administrator meant to set.
        $condition = new promptlength(['operator' => promptlength::OPERATOR_LTE, 'characters' => 10]);

        $this->assertTrue($condition->is_met(
            $this->context(\context_system::instance(), prompt: '日本語のプロンプト'),
        ));
    }

    public function test_a_prompt_length_condition_does_not_depend_on_the_token_ratios(): void {
        // This is the whole reason the condition counts characters. The same rule has to
        // mean the same thing whatever the site has the estimation ratios set to.
        set_config('tokenratiocjk', 0.25, 'aiprovider_router');
        $condition = new promptlength(['operator' => promptlength::OPERATOR_GTE, 'characters' => 10]);

        $this->assertFalse($condition->is_met(
            $this->context(\context_system::instance(), prompt: '日本語のプロンプト'),
        ));
    }

    public function test_a_prompt_length_condition_includes_its_threshold(): void {
        $condition = new promptlength(['operator' => promptlength::OPERATOR_GTE, 'characters' => 16]);

        $this->assertTrue($condition->is_met(
            $this->context(\context_system::instance(), prompt: 'abcdefghijklmnop'),
        ));
        $this->assertFalse($condition->is_met(
            $this->context(\context_system::instance(), prompt: 'abcdefghijkl'),
        ));
    }

    /**
     * Conditions that carry no usable configuration.
     *
     * @return array[] The condition under test, named for what is wrong with it.
     */
    public static function unfinished_conditions(): array {
        return [
            'course with nothing chosen' => [new course([])],
            'course with an empty list' => [new course(['courseids' => []])],
            'course with the wrong key' => [new course(['courses' => [1]])],
            'role with nothing chosen' => [new role([])],
            'action with nothing chosen' => [new action([])],
            'placement with nothing chosen' => [new placement([])],
            'prompt length with no threshold' => [new promptlength(['operator' => 'gte'])],
            'prompt length with no operator' => [new promptlength(['characters' => 10])],
            'prompt length with an unknown operator' => [
                new promptlength(['operator' => 'near', 'characters' => 10]),
            ],
            'prompt length still measured in tokens' => [
                new promptlength(['operator' => 'gte', 'tokens' => 10]),
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unfinished_conditions')]
    public function test_an_unfinished_condition_is_never_met(base $condition): void {
        // A condition present but empty is one the administrator has not finished, and
        // the same shape arrives from a damaged row. Reading it as "no restriction"
        // would turn either into a rule that matches every request on the site.
        $this->assertFalse($condition->is_met($this->context(\context_system::instance())));
    }

    public function test_the_registry_builds_every_type_it_offers(): void {
        foreach (registry::get_types() as $type) {
            $this->assertInstanceOf(base::class, registry::make($type, []));
        }
    }

    public function test_the_registry_does_not_build_a_type_it_does_not_know(): void {
        // A rule written on a newer version can carry one. The evaluator turns a null
        // into an unmet condition, so such a rule stops matching instead of matching
        // everything.
        $this->assertNull(registry::make('futurecondition', []));
        $this->assertNull(registry::make('registry', []));
        $this->assertNull(registry::make('base', []));
    }

    public function test_a_condition_knows_its_stored_type_name(): void {
        $this->assertSame('course', course::get_type());
        $this->assertSame('promptlength', promptlength::get_type());
    }
}
