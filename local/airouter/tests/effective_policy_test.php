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

/**
 * The settings a request is actually carried out under.
 *
 * Reading the settings at the start of a request is only half of it. The object that
 * ends up doing the work has to be built from what was read, and the reading has to
 * mean what Moodle means by a setting, including one a site has fixed in config.php.
 * Either half missing produces the same thing from outside: an administrator changes
 * something, the screen agrees with them, and requests carry on as before.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(request_policy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(routing_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(adapter_provider::class)]
final class effective_policy_test extends \advanced_testcase {
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
     * A provider instance the router can delegate to.
     *
     * @param string $name Its name, which is also what it answers with.
     * @return \core_ai\provider The instance.
     */
    protected function add_target(string $name): \core_ai\provider {
        return $this->manager->create_provider_instance(
            classname: \aiprovider_mock\provider::class,
            name: $name,
            enabled: true,
            config: ['scenario' => \aiprovider_mock\provider::SUCCESS, 'content' => $name],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
    }

    /**
     * Change a setting the way another process would leave it.
     *
     * Not set_config(): that updates this process's caches too, which is the very
     * thing these tests are about not being able to rely on.
     *
     * @param string $name The setting name.
     * @param string|int $value Its new value.
     */
    protected function elsewhere(string $name, string|int $value): void {
        global $DB;

        $existing = $DB->get_record('config_plugins', ['plugin' => 'local_airouter', 'name' => $name]);
        if ($existing) {
            $DB->set_field('config_plugins', 'value', (string) $value, ['id' => $existing->id]);

            return;
        }
        $DB->insert_record('config_plugins', (object) [
            'plugin' => 'local_airouter',
            'name' => $name,
            'value' => (string) $value,
        ]);
    }

    /**
     * Ask for some text, the way a placement asks.
     *
     * @param manager|null $manager The manager to ask, when it is not the usual one.
     * @return \core_ai\aiactions\responses\response_base What the site produced.
     */
    protected function ask(?manager $manager = null): \core_ai\aiactions\responses\response_base {
        return ($manager ?? $this->manager)->process_action(new generate_text(
            contextid: \context_system::instance()->id,
            userid: get_admin()->id,
            prompttext: 'Hello',
        ));
    }

    /**
     * What came back, or null when the request was refused.
     *
     * @param \core_ai\aiactions\responses\response_base $response The response.
     * @return string|null The content.
     */
    protected function answer(\core_ai\aiactions\responses\response_base $response): ?string {
        return $response->get_response_data()['generatedcontent'];
    }

    /**
     * A site routing everything to its default target, with no provider instance.
     *
     * @return \core_ai\provider[] The two targets, A first.
     */
    protected function routing_site(): array {
        $first = $this->add_target('Target A');
        $second = $this->add_target('Target B');
        set_config('defaulttarget', (int) $first->id, 'local_airouter');
        set_config('nomatch', provider::NOMATCH_DELEGATE, 'local_airouter');
        set_config(managed_policy::SWITCH, 1, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);

        return [$first, $second];
    }

    public function test_a_new_default_target_is_used_by_the_next_request(): void {
        [, $second] = $this->routing_site();

        $this->assertSame('Target A', $this->answer($this->ask()));

        $this->elsewhere('defaulttarget', (int) $second->id);

        $this->assertSame('Target B', $this->answer($this->ask()));
    }

    public function test_a_new_refusal_policy_is_used_by_the_next_request(): void {
        $this->routing_site();

        $this->assertSame('Target A', $this->answer($this->ask()));

        $this->elsewhere('nomatch', provider::NOMATCH_DECLINE);

        $response = $this->ask();
        $this->assertFalse($response->get_success());
        $this->assertNull($this->answer($response));
    }

    public function test_a_change_arriving_mid_request_waits_for_the_next_one(): void {
        [, $second] = $this->routing_site();
        $id = (int) $second->id;

        // A manager that lets something happen between reading the settings and
        // acting on them, which is the window a long request leaves open.
        $manager = new class ($GLOBALS['DB'], function () use ($id): void {
            $GLOBALS['DB']->set_field(
                'config_plugins',
                'value',
                (string) $id,
                ['plugin' => 'local_airouter', 'name' => 'defaulttarget'],
            );
        }) extends routing_manager {
            /**
             * Constructor.
             *
             * @param \moodle_database $db The database.
             * @param callable $interrupt What happens after the settings are read.
             */
            public function __construct(
                \moodle_database $db,
                /** @var callable What happens after the settings are read. */
                private $interrupt,
            ) {
                parent::__construct($db);
            }

            #[\Override]
            protected function router_for_dispatch(
                string $actionclass,
                ?request_policy $policy = null,
                ?array $instances = null,
            ): ?\core_ai\provider {
                ($this->interrupt)();

                return parent::router_for_dispatch($actionclass, $policy, $instances);
            }
        };

        $this->assertSame('Target A', $this->answer($this->ask($manager)));
    }

    public function test_a_setting_fixed_in_config_php_turns_the_router_on(): void {
        global $CFG;

        // A site can put a setting beyond the reach of the settings screen. What it
        // says then is what the site means, and routing has to obey it.
        $ahead = $this->add_target('Answered by the first provider');
        unset($ahead);
        set_config('nomatch', provider::NOMATCH_DECLINE, 'local_airouter');
        managed_policy::set_managed_actions([generate_text::class]);
        $this->elsewhere(managed_policy::SWITCH, 0);
        set_config(managed_policy::SWITCH, 0, 'local_airouter');

        $CFG->forced_plugin_settings['local_airouter'][managed_policy::SWITCH] = 1;

        try {
            $response = $this->ask();

            $this->assertFalse($response->get_success());
            $this->assertNull($this->answer($response));
        } finally {
            unset($CFG->forced_plugin_settings['local_airouter']);
        }
    }

    public function test_a_managed_list_fixed_in_config_php_is_obeyed(): void {
        global $CFG;

        $this->add_target('Answered by the first provider');
        set_config('nomatch', provider::NOMATCH_DECLINE, 'local_airouter');
        set_config(managed_policy::SWITCH, 1, 'local_airouter');
        managed_policy::set_managed_actions([]);

        $CFG->forced_plugin_settings['local_airouter'][managed_policy::SETTING] = generate_text::class;

        try {
            $response = $this->ask();

            $this->assertFalse($response->get_success());
            $this->assertNull($this->answer($response));
        } finally {
            unset($CFG->forced_plugin_settings['local_airouter']);
        }
    }

    /**
     * Values a site might fix the switch to, and what each one means.
     *
     * @return array<string, array{0: mixed, 1: bool}> The forced value and the answer.
     */
    public static function forced_switches(): array {
        return [
            // Core drops these when it is asked for a whole plugin, so the setting
            // counts as never having been made, and an absent switch means on.
            'null' => [null, true],
            'an array' => [[], true],
            // Everything else is a value, and reads as what it says.
            'zero' => [0, false],
            'false' => [false, false],
            'one' => [1, true],
            'the string one' => ['1', true],
        ];
    }

    /**
     * The screen and the request must read a fixed switch the same way.
     *
     * Core applies config.php differently depending on how it is asked. Given a setting
     * name it returns the forced value cast to a string, so a null arrives as an empty
     * string and reads as off; given only the plugin it drops the null, and the setting
     * counts as absent, which this plugin reads as on. One of those was on each side of
     * the same question, so a site could be told routing was off while every request
     * went on being routed.
     *
     * @param mixed $forced What config.php fixes the switch to.
     * @param bool $expected Whether the site routes.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forced_switches')]
    public function test_a_fixed_switch_means_the_same_to_the_screen_and_the_request(
        mixed $forced,
        bool $expected,
    ): void {
        global $CFG, $DB;

        set_config(managed_policy::SWITCH, 1, 'local_airouter');
        $CFG->forced_plugin_settings['local_airouter'][managed_policy::SWITCH] = $forced;

        try {
            $this->assertSame($expected, managed_policy::is_switched_on());
            $this->assertSame(
                managed_policy::is_switched_on(),
                request_policy::start($DB)->is_switched_on(),
                'The switch a status check reports must be the switch a request is routed by.',
            );
        } finally {
            unset($CFG->forced_plugin_settings['local_airouter']);
        }
    }

    /**
     * The same, for the list of actions the router answers.
     *
     * Nothing forced makes these two disagree today, because an empty list and no list
     * both mean nothing is managed. It is here because the setting is read on both
     * sides, and that is the whole of what went wrong with the switch.
     */
    public function test_a_fixed_managed_list_means_the_same_to_the_screen_and_the_request(): void {
        global $CFG, $DB;

        managed_policy::set_managed_actions([generate_text::class]);
        $CFG->forced_plugin_settings['local_airouter'][managed_policy::SETTING] = null;

        try {
            $this->assertSame(
                managed_policy::managed_actions(),
                request_policy::start($DB)->managed_actions(),
                'The actions a screen lists as managed must be the actions a request is held to.',
            );
        } finally {
            unset($CFG->forced_plugin_settings['local_airouter']);
        }
    }
}
