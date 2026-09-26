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

use local_airouter\exception\declined_request;
use core_ai\aiactions\generate_text;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fixture_router.php');

/**
 * Tests for what the processor is willing to believe and willing to write down.
 *
 * Both are about the same thing: everything a delegation target returns was
 * written by somebody else.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(abstract_processor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(declined_request::class)]
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

    public function test_the_field_a_key_was_put_in_is_redacted_whatever_it_is_called(): void {
        global $DB;

        // Every provider Moodle ships happens to call its key something a pattern of
        // likely names would catch, but nothing obliges one to. The name belongs to
        // whoever wrote the provider, and the value here is somebody's own key, put
        // there by this plugin, which therefore knows exactly where it is.
        $target = \core\di::get(\core_ai\manager::class)->create_provider_instance(
            classname: '\aiprovider_openai\provider',
            name: 'Target two',
            config: ['apikey' => 'sk-site-key', 'orgid' => 'org-visible'],
        );
        (new target_settings($DB))->set_key_field((int) $target->id, 'orgid');
        $carrying = $target->with(config: ['orgid' => 'brought-key-1234'] + $target->config);

        $redacted = abstract_processor::redact_for(
            'refused the orgid brought-key-1234 supplied',
            $carrying,
        );

        $this->assertStringNotContainsString('brought-key-1234', $redacted);
        $this->assertStringContainsString('[redacted]', $redacted);
    }

    public function test_a_message_long_enough_to_be_a_response_body_is_cut(): void {
        $redacted = abstract_processor::redact_for(str_repeat('x', 4000), $this->target());

        $this->assertLessThanOrEqual(500, \core_text::strlen($redacted));
    }

    /**
     * A processor over a router instance, with nothing else replaced.
     *
     * @param array $config The router instance configuration.
     * @return process_generate_text The processor.
     */
    protected function processor(array $config = []): process_generate_text {
        return new process_generate_text(
            new \local_airouter\fixture_router(
                enabled: true,
                name: 'Router',
                config: json_encode($config + ['defaulttarget' => 7]),
            ),
            new generate_text(
                contextid: \context_system::instance()->id,
                userid: get_admin()->id,
                prompttext: 'hello',
            ),
        );
    }

    /**
     * Turn a failure payload into whatever the processor decides it really is.
     *
     * @param process_generate_text $processor The processor.
     * @param int $errorcode The status code.
     * @param string $stringid The language string, without its error: prefix.
     * @param string $reason The reason code.
     * @return array The payload, where one comes back.
     */
    protected function conclude(
        process_generate_text $processor,
        int $errorcode,
        string $stringid,
        string $reason,
    ): array {
        $outcome = (new \ReflectionMethod($processor, 'fail'))
            ->invoke($processor, $errorcode, $stringid, $reason);

        return (new \ReflectionMethod($processor, 'finalise'))->invoke($processor, $outcome);
    }

    /**
     * Every reason the router records, and whether it is the end of the matter.
     *
     * The distinction is the whole of the design. A spending limit that has been
     * reached, and a key somebody brought so the request would be charged to them, must
     * not be retried by the next provider on the site's own key. A target that merely
     * broke is exactly what core's fallback is for. And "no rule claimed this" is the
     * router saying the request was not its business, which in a site running the
     * router alongside other providers is precisely an invitation to try the next one.
     *
     * @return array<string, array{string, string, bool}> Reason, language string, and
     *                                                    whether the request stops there.
     */
    public static function reasons(): array {
        return [
            'a rule fitted but its budget was spent' =>
                [abstract_processor::REASON_BUDGET_SPENT, 'budgetexhausted', true],
            'nowhere to send it' => [abstract_processor::REASON_NO_TARGET, 'nodefaulttarget', true],
            'a brought key that cannot be read' =>
                [abstract_processor::REASON_KEY_UNREADABLE, 'byokdecryptfailed:user', true],
            'a brought key the provider refused' =>
                [abstract_processor::REASON_KEY_REJECTED, 'byokkeyrejected:user', true],
            'nothing left that the payer holds a key for' =>
                [abstract_processor::REASON_NO_KEY_LEFT, 'byoknokeyleft:user', true],
            'core no longer offers the delegation point' =>
                [abstract_processor::REASON_UNAVAILABLE, 'delegationunavailable', false],
            'the target answered with nothing' => [abstract_processor::REASON_EMPTY, 'emptyresponse', false],
            'the target threw' => [abstract_processor::REASON_TARGET_THREW, 'alltargetsfailed', false],
            'every target failed' => [abstract_processor::REASON_ALL_FAILED, 'alltargetsfailed', false],
            // Not final on purpose: this is how the router says the request was never
            // its business, and the next provider taking it is the point.
            'no rule claimed it' => [abstract_processor::REASON_DECLINED, 'norulematched', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reasons')]
    public function test_only_a_decision_the_site_made_ends_the_request(
        string $reason,
        string $stringid,
        bool $final,
    ): void {
        $processor = $this->processor();

        if (!$final) {
            $outcome = $this->conclude($processor, 503, $stringid, $reason);

            $this->assertFalse($outcome['success']);
            $this->assertSame($reason, $outcome['error']);

            return;
        }

        try {
            $this->conclude($processor, 503, $stringid, $reason);
            $this->fail("Expected {$reason} to stop the request.");
        } catch (declined_request $e) {
            $this->assertSame($reason, $e->get_reason());
            $this->assertSame(get_string('error:' . $stringid, 'local_airouter'), $e->getMessage());
        }
    }

    public function test_the_status_the_failure_carried_survives_being_thrown(): void {
        // A refusal by the provider that holds somebody's key is a 401 and should still
        // read as one wherever it is caught.
        try {
            $this->conclude(
                $this->processor(),
                401,
                'byokkeyrejected:user',
                abstract_processor::REASON_KEY_REJECTED,
            );
            $this->fail('Expected a refused key to stop the request.');
        } catch (declined_request $e) {
            $this->assertSame(401, $e->get_statuscode());
        }
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
