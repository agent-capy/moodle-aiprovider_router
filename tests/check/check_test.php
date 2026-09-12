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

namespace aiprovider_router\check;

use aiprovider_router\fixture_text_provider;
use aiprovider_router\order_inspector;
use aiprovider_router\provider;
use core\check\result;
use core_ai\manager;
use core_ai\provider as ai_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/fixture_action.php');
require_once(__DIR__ . '/../fixtures/fixture_text_provider.php');
require_once(__DIR__ . '/../fixtures/fixture_other_provider.php');
require_once(__DIR__ . '/../fixtures/fixture_unconfigured_provider.php');

/**
 * Tests for the status checks the router reports on the site status report.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(routerlisted::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(routerfirst::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(staleentries::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(actionconflict::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(singleinstance::class)]
final class check_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        provider::get_instance_ids(true);
    }

    /**
     * Every check the plugin reports.
     *
     * @return string[] The check class names.
     */
    protected static function all_checks(): array {
        return [
            routerlisted::class,
            routerfirst::class,
            staleentries::class,
            actionconflict::class,
            singleinstance::class,
        ];
    }

    /**
     * Build an inspector over a made up site.
     *
     * @param ai_provider[] $instances Instances keyed by id.
     * @param string $order The provider_order value to store.
     * @return order_inspector The inspector.
     */
    protected function inspector(array $instances, string $order): order_inspector {
        set_config('provider_order', $order, 'core_ai');
        $manager = $this->createStub(manager::class);
        $manager->method('get_provider_instances')->willReturn($instances);

        return new order_inspector($manager);
    }

    /**
     * A router instance.
     *
     * @param int $id The instance id.
     * @param string $mode One of the provider MODE_ constants.
     * @return provider The router.
     */
    protected function router(int $id, string $mode = provider::MODE_FULL): provider {
        return new provider(
            enabled: true,
            name: "Router {$id}",
            config: json_encode(['mode' => $mode, 'defaulttarget' => 99]),
            id: $id,
        );
    }

    /**
     * A configured provider handling the same action as the router.
     *
     * @param int $id The instance id.
     * @return ai_provider The instance.
     */
    protected function other(int $id): ai_provider {
        return new fixture_text_provider(enabled: true, name: "Provider {$id}", config: '{}', id: $id);
    }

    public function test_every_check_stands_down_until_a_router_exists(): void {
        $inspector = $this->inspector([9 => $this->other(9)], ',9');

        foreach (self::all_checks() as $class) {
            $check = new $class($inspector);
            $this->assertSame(result::NA, $check->get_result()->get_status(), $class);
        }
    }

    public function test_a_router_at_the_front_passes_every_check(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',5,9');

        foreach (self::all_checks() as $class) {
            $check = new $class($inspector);
            $this->assertSame(result::OK, $check->get_result()->get_status(), $class);
        }
    }

    public function test_a_router_missing_from_the_order_is_an_error(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9');

        $this->assertSame(result::ERROR, (new routerlisted($inspector))->get_result()->get_status());
    }

    public function test_a_router_that_is_not_first_is_an_error_in_router_only_mode(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,5');

        $this->assertSame(result::ERROR, (new routerfirst($inspector))->get_result()->get_status());
    }

    public function test_a_router_that_is_not_first_is_only_noted_in_coexist_mode(): void {
        // An administrator running the router alongside other providers may have put one in
        // front deliberately, so reporting it as broken would be wrong.
        $instances = [5 => $this->router(5, provider::MODE_COEXIST), 9 => $this->other(9)];
        $inspector = $this->inspector($instances, ',9,5');

        $this->assertSame(result::INFO, (new routerfirst($inspector))->get_result()->get_status());
    }

    public function test_the_position_is_spelled_out_when_the_router_is_not_first(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,5');

        $this->assertStringContainsString('2', (new routerfirst($inspector))->get_result()->get_details());
    }

    public function test_leftover_entries_are_a_warning(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',5,9,77');
        $result = (new staleentries($inspector))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('77', $result->get_details());
    }

    public function test_a_provider_answering_first_is_a_warning_that_names_it(): void {
        $inspector = $this->inspector([5 => $this->router(5), 9 => $this->other(9)], ',9,5');
        $result = (new actionconflict($inspector))->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('Provider 9', $result->get_details());
    }

    public function test_a_second_router_is_an_error_that_says_which_one_to_delete(): void {
        $inspector = $this->inspector([4 => $this->router(4), 7 => $this->router(7)], ',4,7');
        $result = (new singleinstance($inspector))->get_result();

        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('Router 7', $result->get_details());
        $this->assertStringContainsString('Router 4', $result->get_details());
    }

    public function test_every_check_offers_somewhere_to_go_and_has_a_name(): void {
        $inspector = $this->inspector([5 => $this->router(5)], ',5');

        foreach (self::all_checks() as $class) {
            $check = new $class($inspector);
            $this->assertNotEmpty($check->get_name(), $class);
            $this->assertNotNull($check->get_action_link(), $class);
        }
    }
}
