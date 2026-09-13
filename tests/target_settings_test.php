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

/**
 * Tests for working out where a brought key goes in a provider's configuration.
 *
 * The distinction that matters is between not having been asked and having been answered
 * with "this provider takes no key". One is a gap to be filled, the other is a settled
 * fact, and a plugin that confused them would either nag about ollama forever or silently
 * treat an unanswered provider as taking no key.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(target_settings::class)]
final class target_settings_test extends \advanced_testcase {
    /** @var target_settings The settings under test. */
    protected target_settings $settings;

    #[\Override]
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->settings = new target_settings($DB);
    }

    /**
     * A provider instance carrying the given configuration.
     *
     * @param array $config The instance configuration.
     * @return \aiprovider_mock\provider The instance.
     */
    protected function instance(array $config): \aiprovider_mock\provider {
        return new \aiprovider_mock\provider(
            enabled: true,
            name: 'Mock',
            config: json_encode($config),
            id: 1,
        );
    }

    /**
     * Configurations and the field that should be guessed from them.
     *
     * @return array The cases.
     */
    public static function guess_provider(): array {
        return [
            'Moodle\'s own providers' => [['apikey' => 'x', 'orgid' => 'y'], 'apikey'],
            'Sakura AI Engine' => [['account_token' => 'x', 'endpoint' => 'y'], 'account_token'],
            'A token by another name' => [['systemtoken' => 'x'], 'systemtoken'],
            'Nothing key shaped' => [['endpoint' => 'x', 'model' => 'y'], ''],
            'Nothing at all' => [[], ''],
            'A known name is preferred over a merely plausible one' => [
                ['refreshtoken' => 'x', 'apikey' => 'y'],
                'apikey',
            ],
            'Something only key shaped is still offered' => [['authtoken' => 'x'], 'authtoken'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guess_provider')]
    public function test_the_key_field_is_guessed_from_the_configuration(array $config, string $expected): void {
        $this->assertSame($expected, target_settings::guess($this->instance($config)));
    }

    public function test_an_unanswered_target_is_not_the_same_as_one_that_takes_no_key(): void {
        $this->assertNull($this->settings->get_key_field(1));
        $this->assertFalse($this->settings->supports_byok(1));

        $this->settings->set_key_field(1, target_settings::NO_KEY);

        // Answered now, and the answer is still that no key can be brought.
        $this->assertSame('', $this->settings->get_key_field(1));
        $this->assertFalse($this->settings->supports_byok(1));
    }

    public function test_an_answer_can_be_given_and_changed(): void {
        $this->settings->set_key_field(1, 'apikey');
        $this->assertSame('apikey', $this->settings->get_key_field(1));
        $this->assertTrue($this->settings->supports_byok(1));

        $this->settings->set_key_field(1, 'account_token');

        $this->assertSame('account_token', $this->settings->get_key_field(1));
        $this->assertCount(1, $this->settings->get_all());
    }

    public function test_answers_are_forgotten_with_the_instance_they_were_about(): void {
        $this->settings->set_key_field(1, 'apikey');
        $this->settings->set_key_field(2, 'apikey');

        $this->settings->forget(1);

        $this->assertNull($this->settings->get_key_field(1));
        $this->assertSame('apikey', $this->settings->get_key_field(2));
    }
}
