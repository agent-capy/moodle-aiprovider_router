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
use core_ai\manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');
require_once(__DIR__ . '/fixtures/fixture_text_provider.php');

/**
 * What routing does to the allowance a delegate is on.
 *
 * A provider can be given a number of requests per window, and Moodle counts one
 * each time it asks whether a request is allowed. Putting a router in front of that
 * could go wrong in two directions: the allowance could stop being counted, or one
 * request could cost two.
 *
 * Neither is a thing a fixture can be asked about directly. Core keys the counter on
 * the component a provider belongs to, and a provider defined in a test belongs to
 * none, so a fixture cannot be rate limited at all. The claim is therefore assembled
 * from two halves that can each be observed: that the real limiter spends exactly one
 * unit per check, and that one attempt through the router produces exactly one check.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(adapter_provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(single_router_dispatch::class)]
final class rate_limit_test extends \advanced_testcase {
    /** @var manager The manager a placement would be given. */
    protected manager $manager;

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        provider::get_instance_ids(true);
        \aiprovider_mock\provider::$ratechecks = [];
        $this->manager = \core\di::get(manager::class);
    }

    /**
     * The action a placement would bring.
     *
     * @return generate_text The action.
     */
    protected function action(): generate_text {
        return new generate_text(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'Hello',
        );
    }

    /**
     * An ordinary provider instance for the router to delegate to.
     *
     * @param array $config Scenario settings for the mock.
     * @return \core_ai\provider The instance.
     */
    protected function add_target(array $config = []): \core_ai\provider {
        return $this->manager->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: 'Routed',
            enabled: true,
            config: $config + ['scenario' => \aiprovider_mock\provider::SUCCESS, 'content' => 'Answered'],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
    }

    /**
     * A rule sending everything to one target.
     *
     * @param int $targetid Where it delegates.
     */
    protected function add_rule(int $targetid): void {
        global $DB;

        $rule = new rule();
        $rule->set('name', 'Everything');
        $rule->set('targetid', $targetid);
        (new rule_repository($DB))->save($rule);
    }

    public function test_a_provider_with_an_allowance_really_runs_out(): void {
        // The first half, against core's own limiter. This fixture belongs to this
        // plugin, so it has a component and can be counted, which is what lets the
        // mechanism be shown to be alive rather than assumed.
        $limited = new fixture_text_provider(
            enabled: true,
            name: 'Limited',
            config: json_encode(['enableglobalratelimit' => true, 'globalratelimit' => 2]),
        );
        $action = $this->action();

        $this->assertTrue($limited->is_request_allowed($action));
        $this->assertTrue($limited->is_request_allowed($action));
        $this->assertIsArray($limited->is_request_allowed($action));
    }

    public function test_the_router_puts_no_allowance_of_its_own_in_the_way(): void {
        // The router is not where a site sets a limit: it does not call the service,
        // and a limit here would silently halve whatever the delegate was allowed.
        $adapter = adapter_provider::create();
        $action = $this->action();

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($adapter->is_request_allowed($action), "request {$i}");
        }
    }

    public function test_one_attempt_costs_the_delegate_one_check(): void {
        // The second half. Core asks the delegate once inside the attempt it runs, so
        // one routed request spends one unit of the delegate's allowance.
        $target = $this->add_target();
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);

        $this->manager->process_action($this->action());

        $this->assertSame([(int) $target->id], \aiprovider_mock\provider::$ratechecks);
    }

    public function test_a_delegate_out_of_allowance_is_an_ordinary_failure(): void {
        global $DB;

        // And nobody else answers it. A request refused for spending is one the site
        // decided about, so passing it to the next provider would undo the decision.
        $this->manager->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: 'Ahead',
            enabled: true,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS, 'content' => 'Answered by the first provider'],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        $target = $this->add_target(['scenario' => \aiprovider_mock\provider::RATELIMIT]);
        $this->add_rule((int) $target->id);
        managed_policy::set_managed_actions([generate_text::class]);

        $response = $this->manager->process_action($this->action());

        $this->assertFalse($response->get_success());
        $this->assertNull($response->get_response_data()['generatedcontent']);
        $this->assertSame(1, $DB->count_records('ai_action_register'));
    }
}
