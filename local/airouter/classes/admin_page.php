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
 * Page setup shared by the administration screens this plugin adds.
 *
 * These pages are in the administration tree, under Site administration > AI. They
 * were not, for as long as the router was an aiprovider plugin: Moodle does not read
 * an aiprovider plugin's settings.php and offers no hook for extending the tree, so
 * every screen had to be reached from the provider's own settings form and carry a
 * breadcrumb it had built itself. Registering them in settings.php replaces all of
 * that with the navigation Moodle gives any other administration page.
 *
 * A screen reached from another screen rather than from the tree is set up as its
 * parent and then says where it is, which is what core's own nested pages do.
 *
 * The way back to the provider instance is kept while the instance still decides
 * anything: it is where the settings of such a site actually live.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_page {
    /**
     * Prepare one of this plugin's administration pages.
     *
     * @param \moodle_page $page The page being built.
     * @param \moodle_url $url Where this page lives.
     * @param string $heading Its title and heading.
     * @param array $trail Any pages between the section and this one, url keyed by label.
     * @param string $section The administration tree page this screen belongs to.
     */
    public static function setup(
        \moodle_page $page,
        \moodle_url $url,
        string $heading,
        array $trail = [],
        string $section = '',
    ): void {
        if ($section !== '') {
            global $CFG;

            // A page script gets this only when something else has already pulled it
            // in, which is not something to rely on: it was loaded here by the tests
            // and not by the pages, so the pages failed and the tests did not.
            require_once($CFG->libdir . '/adminlib.php');

            // Every screen checks moodle/site:config before it gets here, and that is
            // the same condition settings.php registers under, so a page that reaches
            // this line with a section is a page the tree knows. Asking the tree first
            // would mean building it, and building it renders settings that expect a
            // page context this page has not set yet.
            \admin_externalpage_setup($section, '', [], $url);
            $page->set_title($heading);
            $page->set_heading($heading);

            // A screen of its own is already the last step. One reached from another
            // says the steps between, and then itself.
            foreach ($trail as $label => $link) {
                $page->navbar->add($label, $link);
            }
            if ($trail !== []) {
                $page->navbar->add($heading, $url);
            }

            return;
        }

        // Asked for without a tree page: the screen is one that has not been given
        // one. It still checks the capability for itself, so it can be shown with the
        // trail these screens carried while no tree would have them.
        $page->set_context(\context_system::instance());
        $page->set_url($url);
        $page->set_pagelayout('admin');
        $page->set_title($heading);
        $page->set_heading($heading);

        self::add_router($page, $url);

        foreach ($trail as $label => $link) {
            $page->navbar->add($label, $link);
        }
        $page->navbar->add($heading, $url);
    }


    /**
     * A button saying, in words, how to get back to the router's settings.
     *
     * The breadcrumb leads there too, but a breadcrumb is a thin thing to rest a
     * whole way out on, and these pages have no other one: they are not in the
     * administration tree, so there is no settings navigation down the side either.
     * Somebody who followed a link here should be able to see how to leave.
     *
     * @param \moodle_url $here The page being shown, to come back to afterwards.
     * @return string HTML.
     */
    public static function back_button(\moodle_url $here): string {
        global $OUTPUT;

        $settings = self::get_settings_url($here);
        if ($settings === null) {
            // Nothing configured yet, so the useful destination is the list where
            // an instance is created.
            return \html_writer::div(
                $OUTPUT->single_button(
                    new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']),
                    get_string('backtoproviders', 'local_airouter'),
                    'get',
                ),
                'mb-3',
            );
        }

        return \html_writer::div(
            $OUTPUT->single_button($settings, get_string('backtosettings', 'local_airouter'), 'get'),
            'mb-3',
        );
    }

    /**
     * Core's settings form for the router instance, told where to come back to.
     *
     * @param \moodle_url $here The page to return to.
     * @return \moodle_url|null The form, or null when no instance exists yet.
     */
    protected static function get_settings_url(\moodle_url $here): ?\moodle_url {
        $router = (new order_inspector())->get_primary_router();
        if ($router === null || empty($router->id)) {
            return null;
        }

        return new \moodle_url('/ai/configure.php', [
            'id' => (int) $router->id,
            // Core's own parameter, honoured on both save and cancel.
            'returnurl' => $here->out_as_local_url(false),
        ]);
    }

    /**
     * The step in the trail that is the router instance itself.
     *
     * A site that has not created one yet still reaches these pages from the status
     * report, so there is a step here either way; it is only a link when there is
     * something for it to lead to.
     *
     * @param \moodle_page $page The page being built.
     * @param \moodle_url $url Where the administrator is, to come back to.
     */
    protected static function add_router(\moodle_page $page, \moodle_url $url): void {
        $settings = self::get_settings_url($url);
        if ($settings === null) {
            $page->navbar->add(get_string('pluginname', 'local_airouter'));

            return;
        }

        $router = (new order_inspector())->get_primary_router();
        $page->navbar->add(format_string($router->name), $settings);
    }
}
