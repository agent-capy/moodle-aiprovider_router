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

use core_ai\aiactions\generate_text;
use core_ai\manager;
use core_ai\provider as ai_provider;
use local_airouter\eligibility_policy;
use local_airouter\rule;
use local_airouter\rule_repository;
use local_airouter\target_resolver;
use local_airouter\target_settings;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/mock/provider.php');

/**
 * Tests for the reasons the router gives for passing over a rule whose conditions held.
 *
 * The rule tester shows these. They are recorded by the router as it passes the rule,
 * so these hold that each way of not honouring a brought key rule is named, and named
 * as either a site setting or the payer's own state.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(target_resolver::class)]
final class skip_reason_test extends \advanced_testcase {
    /** @var ai_provider The target the rules name. */
    protected ai_provider $target;

    #[\Override]
    public function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->target = \core\di::get(manager::class)->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: 'Own key',
            enabled: true,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS, 'apikey' => 'sk-site'],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        (new target_settings($DB))->set_key_field((int) $this->target->id, 'apikey');
    }

    /**
     * A rule paid for by somebody other than the site, with no conditions.
     *
     * @param string $keysource Who pays.
     * @return int The rule id.
     */
    protected function rule(string $keysource): int {
        global $DB;

        $rule = new rule();
        $rule->set('name', 'Brought');
        $rule->set('targetid', (int) $this->target->id);
        $rule->set('keysource', $keysource);

        return (int) (new rule_repository($DB))->save($rule)->get('id');
    }

    /**
     * Why the router passed the rule over for one person's request outside any course.
     *
     * @param int $ruleid The rule.
     * @param int $userid The person.
     * @return string|null The reason.
     */
    protected function reason(int $ruleid, int $userid): ?string {
        $resolver = target_resolver::for_site();
        $resolver->get_candidates(new generate_text(
            contextid: \context_system::instance()->id,
            userid: $userid,
            prompttext: 'Hello',
        ));

        return $resolver->get_skip_reason($ruleid);
    }

    public function test_a_person_the_policy_does_not_admit_is_a_site_setting(): void {
        $ruleid = $this->rule(rule::KEYSOURCE_USER);

        $reason = $this->reason($ruleid, (int) $this->getDataGenerator()->create_user()->id);

        $this->assertSame(target_resolver::SKIP_NOTELIGIBLE, $reason);
    }

    public function test_a_person_without_a_key_is_their_own_state(): void {
        (new eligibility_policy())->save(eligibility_policy::ACCESS_EVERYBODY, []);
        $ruleid = $this->rule(rule::KEYSOURCE_USER);

        $reason = $this->reason($ruleid, (int) $this->getDataGenerator()->create_user()->id);

        $this->assertSame(target_resolver::SKIP_NOKEY, $reason);
        $this->assertStringContainsString(
            get_string('ruletest:reason:nokey', 'local_airouter'),
            target_resolver::describe_problem($reason, generate_text::class),
        );
    }

    public function test_a_course_key_cannot_pay_outside_a_course(): void {
        $ruleid = $this->rule(rule::KEYSOURCE_COURSE);

        $this->assertSame(target_resolver::SKIP_NOCOURSE, $this->reason($ruleid, (int) get_admin()->id));
    }

    public function test_a_provider_that_takes_no_brought_keys_is_named(): void {
        global $DB;

        (new target_settings($DB))->set_mode((int) $this->target->id, target_settings::MODE_DISALLOWED);
        $ruleid = $this->rule(rule::KEYSOURCE_USER);

        $this->assertSame(target_resolver::PROBLEM_BYOKDISALLOWED, $this->reason($ruleid, (int) get_admin()->id));
    }

    public function test_a_rule_that_was_used_has_no_reason(): void {
        (new eligibility_policy())->save(eligibility_policy::ACCESS_EVERYBODY, []);
        $ruleid = $this->rule(rule::KEYSOURCE_USER);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->get_plugin_generator('local_airouter')->create_key([
            'userid' => (int) $user->id,
            'targetid' => (int) $this->target->id,
            'secret' => 'sk-own',
        ]);

        $this->assertNull($this->reason($ruleid, (int) $user->id));
    }

    public function test_every_reason_has_words(): void {
        $reasons = [
            target_resolver::PROBLEM_MISSING, target_resolver::PROBLEM_ROUTER, target_resolver::PROBLEM_DISABLED,
            target_resolver::PROBLEM_UNCONFIGURED, target_resolver::PROBLEM_NOACTION, target_resolver::PROBLEM_ACTIONOFF,
            target_resolver::PROBLEM_BYOKONLY, target_resolver::PROBLEM_BYOKDISALLOWED, target_resolver::SKIP_NOCOURSE,
            target_resolver::SKIP_NOUSER, target_resolver::SKIP_NOTELIGIBLE, target_resolver::SKIP_NOKEY,
            target_resolver::SKIP_NOFIELD, target_resolver::SKIP_SPENT,
        ];
        foreach ($reasons as $reason) {
            $this->assertTrue(
                get_string_manager()->string_exists('ruletest:reason:' . $reason, 'local_airouter'),
                $reason,
            );
        }
    }
}
