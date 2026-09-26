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
 * Tests for how the pages this plugin adds are set up.
 *
 * They are in the administration tree now, and a page named there is set up as that
 * page, which is where its navigation and its breadcrumb come from. What these cover
 * is mostly the other branch: a page asked for without naming one, which falls back
 * to the trail these screens carried while no tree would have them. It was once
 * plain text, which is a trail that leads nowhere, and an administrator who opened
 * the rules could not return to the settings they came from.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(admin_page::class)]
final class admin_page_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
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
            new \moodle_url('/local/airouter/rules.php'),
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

    public function test_a_page_named_in_the_tree_is_set_up_as_that_page(): void {
        global $PAGE;

        // The screens used to hang below the AI provider list, because that was the
        // only thing above them that existed. Naming the tree page instead is what
        // gives them the settings navigation every other administration page has.
        $this->setAdminUser();
        $url = new \moodle_url('/local/airouter/rules.php');

        admin_page::setup($PAGE, $url, 'Routing rules', [], 'local_airouter_rules');

        $this->assertSame('admin', $PAGE->pagelayout);
        $this->assertSame($url->out(), $PAGE->url->out());
        $this->assertArrayHasKey(
            get_string('pluginname', 'local_airouter'),
            $this->trail($PAGE),
        );
    }

    public function test_a_page_outside_the_tree_still_has_a_trail(): void {
        // A missing step would be worse than one that leads nowhere.
        $trail = $this->trail($this->build());

        $this->assertArrayHasKey(get_string('pluginname', 'local_airouter'), $trail);
        $this->assertNull($trail[get_string('pluginname', 'local_airouter')]);
    }

    public function test_a_page_below_another_names_the_one_above_it(): void {
        $listurl = new \moodle_url('/local/airouter/rules.php');

        $trail = $this->trail($this->build(['Routing rules list' => $listurl]));

        $this->assertSame('/local/airouter/rules.php', $trail['Routing rules list']);
    }
}
