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

use core_ai\aiactions\generate_text;

/**
 * Tests for routing an action that core does not define.
 *
 * The whole point of routing against actions rather than against the four core
 * ships is that a new one should need nothing here. These pin the parts that
 * would quietly stop being true.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(action_factory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(process_transcript_audio::class)]
final class extra_action_test extends \advanced_testcase {
    /** @var string The action defined outside core. */
    protected const TRANSCRIBE = 'local_aimedia\\aiactions\\transcript_audio';

    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_an_action_outside_core_is_carried_only_where_it_exists(): void {
        $actions = provider::get_action_list();

        // Core's four are always there.
        $this->assertContains(generate_text::class, $actions);
        $this->assertContains(\core_ai\aiactions\generate_image::class, $actions);
        $this->assertContains(\core_ai\aiactions\summarise_text::class, $actions);
        $this->assertContains(\core_ai\aiactions\explain_text::class, $actions);

        // Naming an action that is not installed makes the provider settings
        // screen fatal, because it asks each action for its own name, so the
        // offer follows the class, either way round.
        if (class_exists(self::TRANSCRIBE)) {
            $this->assertContains(self::TRANSCRIBE, $actions);
        } else {
            $this->assertNotContains(self::TRANSCRIBE, $actions);
        }
    }

    public function test_the_router_has_a_processor_for_every_action_it_offers(): void {
        // Core resolves the processor as process_<basename> in the provider's own
        // namespace, so an action offered without one is a fatal on first use.
        foreach (provider::get_action_list() as $class) {
            $processor = __NAMESPACE__ . '\\process_' . $class::get_basename();
            $this->assertTrue(class_exists($processor), "Missing processor for {$class}");
        }
    }

    public function test_the_rule_tester_can_build_every_action_it_lists(): void {
        // The tester offers whatever the router carries, and building an action
        // it cannot construct would take the screen down rather than the request.
        $contextid = \context_system::instance()->id;

        foreach (provider::get_action_list() as $class) {
            $action = action_factory::make($class, $contextid, 2, 'A prompt to route on');
            $this->assertInstanceOf($class, $action);
        }
    }

    public function test_an_empty_transcript_is_a_failed_target(): void {
        // A model that heard nothing still says so, so nothing back means the
        // target failed and the next candidate should be tried, exactly as for
        // an empty generation.
        if (!class_exists(self::TRANSCRIBE)) {
            $this->markTestSkipped('local_aimedia is not installed');
        }

        $processor = new \ReflectionClass(process_transcript_audio::class);
        $method = $processor->getMethod('get_content_key');
        $method->setAccessible(true);

        $this->assertSame(
            'transcript',
            $method->invoke($processor->newInstanceWithoutConstructor()),
        );
    }
}
