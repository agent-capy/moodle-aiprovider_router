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


/**
 * Tests for the router's place in the site administration tree.
 *
 * Moodle does not read an aiprovider plugin's settings.php and offers no hook for
 * extending the administration tree, so for as long as the router was one, none of
 * its screens could be in the tree. That is why they are worth a test: what puts
 * them there is the plugin type, and nothing in the plugin would notice losing it.
 *
 * What registers the pages is settings.php, which is a script rather than a class,
 * so there is nothing here for a coverage attribute to name. The classes these
 * touch have tests of their own.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class settings_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/adminlib.php');
    }

    /**
     * The administration tree, built from scratch.
     *
     * @return \admin_root The tree.
     */
    protected function tree(): \admin_root {
        return admin_get_root(true, true);
    }

    public function test_the_router_has_its_own_place_under_ai(): void {
        $tree = $this->tree();
        $category = $tree->locate('local_airouter');

        $this->assertInstanceOf(\admin_category::class, $category);
        $this->assertSame(
            get_string('pluginname', 'local_airouter'),
            $category->visiblename,
        );
    }

    /**
     * Each screen that should be reachable from the tree.
     *
     * @return array<string, array{string}> The page names.
     */
    public static function tree_pages(): array {
        return [
            'routing policy' => ['local_airouter_policy'],
            'managed actions' => ['local_airouter_managed'],
            'rules' => ['local_airouter_rules'],
            'rates' => ['local_airouter_rates'],
            'brought keys' => ['local_airouter_byok'],
            'usage' => ['local_airouter_usage'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tree_pages')]
    public function test_every_screen_is_in_the_tree(string $name): void {
        $this->assertNotNull($this->tree()->locate($name), $name);
    }

    /**
     * The settings a page holds, by the name each one is stored under.
     *
     * @param \admin_settingpage $page The page.
     * @return string[] The setting names.
     */
    protected static function setting_names(\admin_settingpage $page): array {
        return array_map(
            static fn(\admin_setting $setting): string => $setting->name,
            array_values((array) $page->settings),
        );
    }

    public function test_the_policy_settings_are_the_ones_the_router_reads(): void {
        /** @var \admin_settingpage $page */
        $page = $this->tree()->locate('local_airouter_policy');
        $names = self::setting_names($page);

        // Anything offered here and not read would be a setting that does nothing.
        $this->assertContains(managed_policy::SWITCH, $names);
        $this->assertContains('nomatch', $names);
        // The operating mode is not offered: what it chose between was whether core
        // tries the next provider after a refusal, and for an action placed under
        // the router there is no next provider.
        $this->assertNotContains('mode', $names);
    }

    public function test_the_router_reads_what_the_settings_page_writes(): void {
        set_config('nomatch', provider::NOMATCH_DECLINE, 'local_airouter');
        set_config('defaulttarget', 42, 'local_airouter');

        $adapter = adapter_provider::create();

        $this->assertSame(provider::NOMATCH_DECLINE, $adapter->get_nomatch_behaviour());
        $this->assertSame(42, $adapter->get_default_target_id());
    }

    public function test_an_unconfigured_site_gets_the_same_defaults_as_before(): void {
        $adapter = adapter_provider::create();

        $this->assertSame(provider::NOMATCH_DELEGATE, $adapter->get_nomatch_behaviour());
        $this->assertNull($adapter->get_default_target_id());
    }

    public function test_a_site_that_has_never_seen_the_switch_keeps_routing(): void {
        // The switch was added after the plugin. A site that had been routing must
        // not stop because a setting it never saw is not there.
        //
        // Moodle writes a setting's default when the plugin is upgraded, so this is
        // not a state an installed site stays in for long. It is the state between
        // the code arriving and the upgrade running, and a request in that window
        // has to behave.
        unset_config(managed_policy::SWITCH, 'local_airouter');

        $this->assertFalse(get_config('local_airouter', managed_policy::SWITCH));
        $this->assertTrue(managed_policy::is_switched_on());
    }
}
