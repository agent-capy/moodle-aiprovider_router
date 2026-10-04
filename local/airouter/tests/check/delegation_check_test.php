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

namespace local_airouter\check;

use core\check\result;
use core_ai\aiactions\generate_text;
use core_ai\manager;
use core_ai\provider as ai_provider;
use local_airouter\managed_policy;
use local_airouter\provider;
use local_airouter\rule;
use local_airouter\rule_repository;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/mock/provider.php');
require_once(__DIR__ . '/../fixtures/fixture_unconfigured_provider.php');

/**
 * Tests for the checks that say whether the router has somewhere to send a request.
 *
 * The delegation check took over the half of the boundary check that was being read
 * as "requests are answered". These hold the split: the boundary check says only
 * that requests reach the router, and the delegation check says whether the
 * settings name anything that could carry them, in words that stop short of a promise.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(delegation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ruletargets::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(managedboundary::class)]
final class delegation_check_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A provider instance that answers generate_text.
     *
     * @param string $name Its name.
     * @param bool $enabled Whether it is switched on.
     * @return ai_provider The instance.
     */
    protected function target(string $name, bool $enabled = true): ai_provider {
        return \core\di::get(manager::class)->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: $name,
            enabled: $enabled,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
    }

    /**
     * A rule delegating everything to one target.
     *
     * @param int $targetid Where it delegates.
     * @param string $name Its name.
     */
    protected function rule(int $targetid, string $name = 'Everything'): void {
        global $DB;

        $rule = new rule();
        $rule->set('name', $name);
        $rule->set('targetid', $targetid);
        (new rule_repository($DB))->save($rule);
    }

    public function test_nothing_routed_means_nothing_to_check(): void {
        $this->assertSame(result::NA, (new delegation())->get_result()->get_status());

        managed_policy::set_managed_actions([generate_text::class]);
        set_config(managed_policy::SWITCH, 0, 'local_airouter');

        $this->assertSame(result::NA, (new delegation())->get_result()->get_status());
    }

    public function test_a_routed_action_with_nowhere_to_go_is_an_error(): void {
        managed_policy::set_managed_actions([generate_text::class]);

        $result = (new delegation())->get_result();

        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString(generate_text::get_name(), $result->get_summary());
    }

    public function test_a_default_target_that_was_switched_off_is_a_warning_while_a_rule_still_works(): void {
        $off = $this->target('Switched off', false);
        $this->rule((int) $this->target('Local')->id);
        set_config('defaulttarget', $off->id, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);

        $result = (new delegation())->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        // The details name the target and the reason, and say what is not checked.
        $this->assertStringContainsString('Switched off', $result->get_details());
        $this->assertStringContainsString(
            get_string('ruletest:reason:disabled', 'local_airouter'),
            $result->get_details(),
        );
        $this->assertStringContainsString(get_string('setup:unchecked', 'local_airouter'), $result->get_details());
    }

    public function test_declining_everything_for_an_action_is_reported_not_raised(): void {
        set_config('defaulttarget', $this->target('Cloud')->id, 'local_airouter');
        set_config('nomatch', provider::NOMATCH_DECLINE, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);

        $this->assertSame(result::INFO, (new delegation())->get_result()->get_status());
    }

    public function test_a_target_available_is_ok_and_says_what_it_did_not_check(): void {
        set_config('defaulttarget', $this->target('Cloud')->id, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);

        $result = (new delegation())->get_result();

        $this->assertSame(result::OK, $result->get_status());
        $this->assertSame(get_string('check:delegation:ok', 'local_airouter'), $result->get_summary());
    }

    public function test_the_boundary_check_no_longer_answers_for_delegation(): void {
        // A request reaching the router is all it reports. Saying OK here while the
        // delegation check says ERROR is the point of having two checks.
        managed_policy::set_managed_actions([generate_text::class]);

        $this->assertSame(result::OK, (new managedboundary())->get_result()->get_status());
        $this->assertSame(result::ERROR, (new delegation())->get_result()->get_status());
    }

    public function test_a_rule_naming_a_switched_off_or_unset_provider_is_reported(): void {
        $this->rule((int) $this->target('Off', false)->id, 'Names a switched off provider');
        $unset = \core\di::get(manager::class)->create_provider_instance(
            classname: \local_airouter\fixture_unconfigured_provider::class,
            name: 'Empty key',
            enabled: true,
            config: [],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        $this->rule((int) $unset->id, 'Names an unset provider');
        $this->rule((int) $this->target('Fine')->id, 'Names a working provider');

        $result = (new ruletargets())->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertSame(get_string('check:ruletargets:found', 'local_airouter', 2), $result->get_summary());
        $this->assertStringContainsString('Names a switched off provider', $result->get_details());
        $this->assertStringContainsString('Names an unset provider', $result->get_details());
        $this->assertStringNotContainsString('Names a working provider', $result->get_details());
    }

    public function test_a_rule_name_is_escaped_in_the_details(): void {
        // A rule name cannot hold markup, which saving refuses, but it can hold the
        // characters that start it, and the details are HTML.
        $this->rule(987654, 'R&D "draft"');

        $details = (new ruletargets())->get_result()->get_details();

        $this->assertStringContainsString('R&amp;D &quot;draft&quot;', $details);
    }
}
