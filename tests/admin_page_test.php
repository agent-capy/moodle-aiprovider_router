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
 * Tests for the breadcrumb on the pages this plugin adds.
 *
 * None of them is in the administration tree, so the breadcrumb is the only way back.
 * It was once plain text, which is a trail that leads nowhere, and an administrator
 * who opened the rules could not return to the settings they came from.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(admin_page::class)]
final class admin_page_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        provider::get_instance_ids(true);
    }

    /**
     * The page this plugin would build, as a fresh one each time.
     *
     * @param array $trail Anything between the router and the page.
     * @return \moodle_page The page.
     */
    protected function build(array $trail = []): \moodle_page {
        $page = new \moodle_page();
        $page->set_course(get_site());
        admin_page::setup(
            $page,
            new \moodle_url('/ai/provider/router/rules.php'),
            'Routing rules',
            $trail,
        );

        return $page;
    }

    /**
     * Where each step of the breadcrumb leads, in order.
     *
     * @param \moodle_page $page The page.
     * @return array Link text to the URL it leads to, or null where it is not a link.
     */
    protected function trail(\moodle_page $page): array {
        $trail = [];
        foreach ($page->navbar->get_items() as $item) {
            $trail[(string) $item->text] = $item->action instanceof \moodle_url
                ? $item->action->out_as_local_url(false)
                : null;
        }

        return $trail;
    }

    /**
     * Create a router instance on the site.
     *
     * @param string $name What it is called.
     * @return provider The instance.
     */
    protected function add_router(string $name = 'Our router'): provider {
        /** @var provider $instance */
        $instance = \core\di::get(\core_ai\manager::class)->create_provider_instance(
            classname: provider::class,
            name: $name,
            enabled: true,
            config: ['defaulttarget' => 7],
            actionconfig: [generate_text::class => ['enabled' => true]],
        );
        provider::get_instance_ids(true);

        return $instance;
    }

    public function test_the_breadcrumb_leads_back_to_the_router_settings(): void {
        $router = $this->add_router();

        $trail = $this->trail($this->build());

        // The step naming the instance is a link to core's own form for it, so that
        // the page an administrator opened is not one they have to leave by the
        // browser's back button.
        $this->assertArrayHasKey('Our router', $trail);
        $this->assertStringContainsString('/ai/configure.php', (string) $trail['Our router']);
        $this->assertStringContainsString('id=' . $router->id, (string) $trail['Our router']);
    }

    public function test_the_settings_form_is_told_where_to_come_back_to(): void {
        $this->add_router();

        $trail = $this->trail($this->build());

        // Core's own parameter, so saving or cancelling there returns here.
        $this->assertStringContainsString(
            'returnurl=',
            (string) $trail['Our router'],
        );
        $this->assertStringContainsString(
            urlencode('/ai/provider/router/rules.php'),
            (string) $trail['Our router'],
        );
    }

    public function test_the_provider_list_is_always_one_step_up(): void {
        $this->add_router();

        $trail = $this->trail($this->build());
        $providers = get_string('aiproviders', 'core_ai');

        $this->assertArrayHasKey($providers, $trail);
        $this->assertStringContainsString('section=aiprovider', (string) $trail[$providers]);
    }

    public function test_a_site_with_no_router_yet_still_has_a_trail(): void {
        // These pages are reachable from the status report before any instance
        // exists, and a missing step would be worse than one that leads nowhere.
        $trail = $this->trail($this->build());

        $this->assertArrayHasKey(get_string('pluginname', 'aiprovider_router'), $trail);
        $this->assertNull($trail[get_string('pluginname', 'aiprovider_router')]);
    }

    public function test_a_page_below_another_names_the_one_above_it(): void {
        $this->add_router();
        $listurl = new \moodle_url('/ai/provider/router/rules.php');

        $trail = $this->trail($this->build(['Routing rules list' => $listurl]));

        $this->assertSame('/ai/provider/router/rules.php', $trail['Routing rules list']);
    }

    public function test_a_page_says_in_words_how_to_get_back(): void {
        // The breadcrumb leads there too, but it is a thin thing to rest the only
        // way out on, and these pages have no settings navigation down the side.
        $router = $this->add_router();
        $this->build();

        $html = admin_page::back_button(new \moodle_url('/ai/provider/router/rules.php'));

        $this->assertStringContainsString(
            get_string('backtosettings', 'aiprovider_router'),
            $html,
        );
        $this->assertStringContainsString('/ai/configure.php', $html);
        $this->assertStringContainsString((string) $router->id, $html);
    }

    public function test_the_button_brings_the_administrator_back_to_this_page(): void {
        $this->add_router();
        $this->build();

        $html = admin_page::back_button(new \moodle_url('/ai/provider/router/usage.php'));

        // A GET form, so the parameters are hidden inputs rather than a query string.
        $this->assertStringContainsString('returnurl', $html);
        $this->assertStringContainsString('/ai/provider/router/usage.php', $html);
    }

    public function test_a_site_with_no_router_is_sent_where_one_is_created(): void {
        $this->build();

        $html = admin_page::back_button(new \moodle_url('/ai/provider/router/rules.php'));

        $this->assertStringContainsString(
            get_string('backtoproviders', 'aiprovider_router'),
            $html,
        );
        // A GET form, so the parameters are hidden inputs rather than a query string.
        $this->assertStringContainsString('/admin/settings.php', $html);
        $this->assertStringContainsString('value="aiprovider"', $html);
    }
}
