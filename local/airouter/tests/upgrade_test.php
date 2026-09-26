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
use core_ai\aiactions\summarise_text;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/local/airouter/db/upgrade.php');

/**
 * The upgrade that retires the router as an AI provider instance.
 *
 * A site that created an AI Router provider instance had its settings there. The
 * companion plugin that made it one is gone, so the upgrade carries those settings over
 * and takes the instance away. The rows are written directly: the class they name no
 * longer exists, which is the situation the upgrade meets on a real site.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_local_airouter_upgrade')]
final class upgrade_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The step runs from the version before it, as it does on a site being upgraded.
        set_config('version', 2026092309, 'local_airouter');
    }

    /**
     * A provider instance row, as core stored it.
     *
     * @param string $class The provider class it names.
     * @param bool $enabled Whether it was switched on.
     * @param array $config Its configuration.
     * @param array $actionconfig Its action settings.
     * @return int The row id.
     */
    private function stored(string $class, bool $enabled, array $config = [], array $actionconfig = []): int {
        global $DB;

        return (int) $DB->insert_record('ai_providers', (object) [
            'name' => 'Stored ' . $class,
            'provider' => $class,
            'enabled' => (int) $enabled,
            'config' => json_encode($config),
            'actionconfig' => json_encode($actionconfig),
        ]);
    }

    /**
     * A stored router instance.
     *
     * @param bool $enabled Whether it was switched on.
     * @param array $config Its configuration.
     * @return int The row id.
     */
    private function router(bool $enabled, array $config): int {
        return $this->stored('aiprovider_router\\provider', $enabled, $config, [
            generate_text::class => ['enabled' => true],
            summarise_text::class => ['enabled' => false],
        ]);
    }

    public function test_a_stored_router_hands_its_settings_over_and_goes(): void {
        global $DB;
        $other = $this->stored('aiprovider_openai\\provider', true);
        $router = $this->router(true, ['defaulttarget' => $other, 'mode' => 'full', 'nomatch' => 'decline']);
        set_config('provider_order', ",{$router},{$other}", 'core_ai');
        set_config('managedactions', '', 'local_airouter');

        xmldb_local_airouter_upgrade(2026092309);

        $this->assertSame((string) $other, get_config('local_airouter', 'defaulttarget'));
        $this->assertSame('decline', get_config('local_airouter', 'nomatch'));
        // Its requests reached it through the provider order; now only the actions
        // placed under the router do, so its actions are placed there. The one it had
        // switched off is not.
        $this->assertSame(ltrim(generate_text::class, '\\'), get_config('local_airouter', 'managedactions'));
        $this->assertFalse($DB->record_exists('ai_providers', ['id' => $router]));
        $this->assertTrue($DB->record_exists('ai_providers', ['id' => $other]));
        $this->assertSame(",{$other}", get_config('core_ai', 'provider_order'));
        $this->assertSame('2026092600', get_config('local_airouter', 'version'));
    }

    public function test_a_router_without_a_default_target_leaves_none_behind(): void {
        // It routed by rule alone. A target left on the routing policy page decided
        // nothing while the instance existed, and must not start deciding now.
        set_config('defaulttarget', 5, 'local_airouter');
        $this->router(true, ['defaulttarget' => 0, 'nomatch' => 'decline']);

        xmldb_local_airouter_upgrade(2026092309);

        $this->assertFalse(get_config('local_airouter', 'defaulttarget'));
        $this->assertNull(adapter_provider::create()->get_default_target_id());
    }

    public function test_a_router_running_alongside_other_providers_declines_what_no_rule_claims(): void {
        // It said nothing about a request no rule claimed, and its operating mode
        // decided: alongside other providers, such a request was declined.
        $this->router(true, ['defaulttarget' => 7, 'mode' => 'coexist']);

        xmldb_local_airouter_upgrade(2026092309);

        $this->assertSame('decline', get_config('local_airouter', 'nomatch'));
    }

    public function test_a_router_that_was_switched_off_places_nothing_under_the_router(): void {
        // Nothing reached it, so nothing is placed under the router on its account.
        $this->router(false, ['defaulttarget' => 7]);
        set_config('managedactions', '', 'local_airouter');

        xmldb_local_airouter_upgrade(2026092309);

        $this->assertSame('', get_config('local_airouter', 'managedactions'));
        $this->assertSame('7', get_config('local_airouter', 'defaulttarget'));
    }

    public function test_the_actions_already_placed_under_the_router_stay(): void {
        set_config('managedactions', ltrim(summarise_text::class, '\\'), 'local_airouter');
        $this->router(true, ['defaulttarget' => 7]);

        xmldb_local_airouter_upgrade(2026092309);

        $managed = explode(',', get_config('local_airouter', 'managedactions'));
        sort($managed);
        $this->assertSame([ltrim(generate_text::class, '\\'), ltrim(summarise_text::class, '\\')], $managed);
    }

    public function test_of_two_stored_routers_the_one_that_decided_is_carried_over(): void {
        global $DB;
        $first = $this->router(true, ['defaulttarget' => 7]);
        $second = $this->router(true, ['defaulttarget' => 9]);

        xmldb_local_airouter_upgrade(2026092309);

        $this->assertLessThan($second, $first);
        $this->assertSame('7', get_config('local_airouter', 'defaulttarget'));
        $this->assertSame(0, $DB->count_records('ai_providers', ['provider' => 'aiprovider_router\\provider']));
    }

    public function test_a_site_without_a_stored_router_is_left_as_it_was(): void {
        set_config('defaulttarget', 5, 'local_airouter');
        set_config('nomatch', 'delegate', 'local_airouter');
        set_config('managedactions', ltrim(generate_text::class, '\\'), 'local_airouter');
        set_config('provider_order', ',3,4', 'core_ai');

        xmldb_local_airouter_upgrade(2026092309);

        $this->assertSame('5', get_config('local_airouter', 'defaulttarget'));
        $this->assertSame('delegate', get_config('local_airouter', 'nomatch'));
        $this->assertSame(ltrim(generate_text::class, '\\'), get_config('local_airouter', 'managedactions'));
        $this->assertSame(',3,4', get_config('core_ai', 'provider_order'));
    }
}
