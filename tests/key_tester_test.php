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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/mock/abstract_processor.php');
require_once(__DIR__ . '/fixtures/mock/process_generate_text.php');

/**
 * Tests for asking a provider whether it accepts a key.
 *
 * What matters is the difference between a refusal and everything else. Being told the
 * key is wrong is something its owner can act on; being unable to reach the provider is
 * not, and reporting the second as the first would send people looking for a problem with
 * a key that is perfectly good.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(key_tester::class)]
final class key_tester_test extends \advanced_testcase {
    /** @var key_repository The key store. */
    protected key_repository $keys;

    /** @var key_tester The tester under test. */
    protected key_tester $tester;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->keys = new key_repository($DB);
        $this->tester = new key_tester($DB);
        (new target_settings($DB))->set_key_field(3, 'apikey');
    }

    /**
     * A provider that behaves as the given scenario says.
     *
     * @param string $scenario One of the mock provider's scenario constants.
     * @param array $settings Extra settings for the scenario.
     * @return \aiprovider_mock\provider The instance.
     */
    protected function target(string $scenario, array $settings = []): \aiprovider_mock\provider {
        return new \aiprovider_mock\provider(
            enabled: true,
            name: 'Mock',
            config: json_encode(['scenario' => $scenario, 'apikey' => 'site'] + $settings),
            id: 3,
        );
    }

    /**
     * Run the test against a target behaving as given.
     *
     * @param \aiprovider_mock\provider $target The instance.
     * @return string The verification result.
     */
    protected function ask(\aiprovider_mock\provider $target): string {
        $key = $this->keys->save(key::SCOPE_USER, 7, 3, 'the-users-own-key');

        return $this->tester->test($target, $key, (int) \context_system::instance()->id, 7);
    }

    public function test_a_provider_that_answers_has_accepted_the_key(): void {
        $this->assertSame(key::VERIFY_OK, $this->ask($this->target(\aiprovider_mock\provider::SUCCESS)));
    }

    public function test_a_provider_that_says_unauthorised_has_refused_the_key(): void {
        $result = $this->ask($this->target(\aiprovider_mock\provider::FAILURE, ['errorcode' => 401]));

        $this->assertSame(key::VERIFY_REJECTED, $result);
    }

    public function test_a_provider_that_says_forbidden_has_refused_the_key(): void {
        $result = $this->ask($this->target(\aiprovider_mock\provider::FAILURE, ['errorcode' => 403]));

        $this->assertSame(key::VERIFY_REJECTED, $result);
    }

    public function test_any_other_failure_says_nothing_about_the_key(): void {
        $result = $this->ask($this->target(\aiprovider_mock\provider::FAILURE, ['errorcode' => 500]));

        // The provider is having a bad day. Telling its owner their key is wrong would
        // send them looking for a problem that is not there.
        $this->assertSame(key::VERIFY_FAILED, $result);
    }

    public function test_a_provider_that_cannot_be_reached_says_nothing_about_the_key(): void {
        $result = $this->ask($this->target(\aiprovider_mock\provider::EXCEPTION));

        $this->assertSame(key::VERIFY_FAILED, $result);
        $this->assertDebuggingCalled();
    }

    public function test_an_empty_answer_is_still_an_accepted_key(): void {
        // Only whether the key was accepted is being asked. What came back is thrown away.
        $this->assertSame(key::VERIFY_OK, $this->ask($this->target(\aiprovider_mock\provider::EMPTY_CONTENT)));
    }

    public function test_the_result_is_recorded_against_the_key(): void {
        $key = $this->keys->save(key::SCOPE_USER, 7, 3, 'the-users-own-key');
        $this->assertSame(0, (int) $key->get('timeverified'));

        $this->tester->test(
            $this->target(\aiprovider_mock\provider::FAILURE, ['errorcode' => 401]),
            $key,
            (int) \context_system::instance()->id,
            7,
        );

        $stored = $this->keys->get((int) $key->get('id'));
        $this->assertSame(key::VERIFY_REJECTED, $stored->get('verifystatus'));
        $this->assertGreaterThan(0, (int) $stored->get('timeverified'));
    }

    public function test_a_provider_with_nowhere_to_put_the_key_is_not_asked(): void {
        global $DB;
        (new target_settings($DB))->forget(3);

        $result = $this->ask($this->target(\aiprovider_mock\provider::SUCCESS));

        // Asking would have used the site's own key and reported that as a success.
        $this->assertSame(key::VERIFY_FAILED, $result);
    }
}
