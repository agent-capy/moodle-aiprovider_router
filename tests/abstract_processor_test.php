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

/**
 * Tests for what the processor is willing to believe and willing to write down.
 *
 * Both are about the same thing: everything a delegation target returns was
 * written by somebody else.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(abstract_processor::class)]
final class abstract_processor_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A provider instance holding secrets under the names providers actually use.
     *
     * @return \core_ai\provider The instance.
     */
    protected function target(): \core_ai\provider {
        return \core\di::get(\core_ai\manager::class)->create_provider_instance(
            classname: '\aiprovider_openai\provider',
            name: 'Target one',
            config: ['apikey' => 'sk-secret-value-9876', 'orgid' => 'org-visible'],
        );
    }

    public function test_a_targets_own_secret_does_not_reach_the_log(): void {
        $target = $this->target();
        $message = 'POST https://api.example.invalid/v1 failed: bad key sk-secret-value-9876 supplied';

        $redacted = abstract_processor::redact_for($message, $target);

        $this->assertStringNotContainsString('sk-secret-value-9876', $redacted);
        $this->assertStringContainsString('[redacted]', $redacted);
        // What is left still says what went wrong and where it was sent.
        $this->assertStringContainsString('api.example.invalid', $redacted);
    }

    public function test_a_setting_that_is_not_a_secret_is_left_alone(): void {
        $redacted = abstract_processor::redact_for('the org-visible account was refused', $this->target());

        $this->assertStringContainsString('org-visible', $redacted);
    }

    public function test_a_message_long_enough_to_be_a_response_body_is_cut(): void {
        $redacted = abstract_processor::redact_for(str_repeat('x', 4000), $this->target());

        $this->assertLessThanOrEqual(500, \core_text::strlen($redacted));
    }

    /**
     * Usage figures a target might report, and what this site will believe.
     *
     * @return array<string, array{mixed, int|null}> The reported value and the recorded one.
     */
    public static function counts(): array {
        return [
            'an ordinary count' => [1500, 1500],
            'a count as text' => ['1500', 1500],
            'nothing reported' => [null, null],
            'not a number at all' => ['plenty', null],
            // The one that matters: cost is the count times a rate, so a negative
            // count is a negative cost, and a negative cost gives back budget that
            // has already been spent.
            'a negative count' => [-1000000, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('counts')]
    public function test_only_a_believable_usage_figure_is_recorded(mixed $reported, ?int $recorded): void {
        $method = new \ReflectionMethod(abstract_processor::class, 'counted');
        $method->setAccessible(true);

        $this->assertSame($recorded, $method->invoke(null, $reported));
    }
}
