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
 * Tests for the user tours shipped with the plugin.
 *
 * They are shipped as files for an administrator to import, not installed by the
 * plugin. What can break without anybody noticing is that a tour stops importing, a
 * step names a language string that has gone, or a step points at an element the
 * page no longer has; each of those is held here.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class tours_test extends \advanced_testcase {
    /**
     * The tour files.
     *
     * @return array<string, array{string}> The file paths.
     */
    public static function tours(): array {
        $tours = [];
        foreach (glob(__DIR__ . '/../doc/tours/*.json') as $path) {
            $tours[basename($path)] = [$path];
        }

        return $tours;
    }

    /**
     * Every place a step can point at, from the files that render them.
     *
     * @return string The source of the pages and classes the tours visit.
     */
    protected static function page_sources(): string {
        $base = __DIR__ . '/..';

        return file_get_contents("{$base}/setup.php") . file_get_contents("{$base}/settings.php")
            . file_get_contents("{$base}/classes/setup/route_formatter.php");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tours')]
    public function test_a_tour_imports_and_every_string_it_names_exists(string $path): void {
        $this->resetAfterTest();
        $json = file_get_contents($path);
        $data = json_decode($json);

        $this->assertNotNull($data, 'The file is JSON.');
        $this->assertNotEmpty($data->steps);

        $texts = [$data->name, $data->description];
        foreach ($data->steps as $step) {
            $texts[] = $step->title;
            $texts[] = $step->content;
        }
        foreach ($texts as $text) {
            [$identifier, $component] = explode(',', $text);
            $this->assertSame('local_airouter', $component);
            $this->assertTrue(get_string_manager()->string_exists($identifier, $component), $identifier);
        }

        $tour = \tool_usertours\manager::import_tour_from_json($json);
        $this->assertCount(count($data->steps), $tour->get_steps());
        $this->assertSame(
            get_string(explode(',', $data->name)[0], 'local_airouter'),
            \tool_usertours\helper::get_string_from_input($tour->get_name()),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tours')]
    public function test_every_step_points_at_something_the_page_has(string $path): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/adminlib.php');

        // The default target is offered once there is something to choose.
        \core\di::get(\core_ai\manager::class)->create_provider_instance(
            classname: '\aiprovider_openai\provider',
            name: 'Somewhere to send requests',
            config: ['apikey' => 'sk-test'],
        );
        $data = json_decode(file_get_contents($path));
        $sources = self::page_sources();
        /** @var \admin_settingpage $policy */
        $policy = admin_get_root(true, true)->locate('local_airouter_policy');
        $settings = array_map(
            static fn(\admin_setting $setting): string => $setting->name,
            array_values((array) $policy->settings),
        );

        foreach ($data->steps as $step) {
            if ((string) $step->targettype !== '0') {
                continue;
            }
            $selector = (string) $step->targetvalue;
            if (str_starts_with($selector, '#admin-')) {
                // A setting on the policy page, which Moodle renders with the id admin-<name>.
                $this->assertContains(substr($selector, 7), $settings, $selector);
            } else {
                $this->assertStringContainsString(ltrim($selector, '#.'), $sources, $selector);
            }
        }
    }
}
