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

/**
 * Page setup shared by the administration screens this plugin adds.
 *
 * None of these pages is in the administration tree, because core never reads an
 * aiprovider plugin's settings.php. They are reached from the router's own settings
 * form and from the site status report. That leaves the breadcrumb as the only way
 * back, so it has to be a real one: naming the plugin as plain text, which is what
 * these pages did at first, is a trail that leads nowhere.
 *
 * The instance name links to core's own form for it, carrying returnurl, so that
 * saving or cancelling there comes back to the page the administrator started from.
 *
 * @package    aiprovider_router
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
     * @param array $trail Any pages between the router and this one, url keyed by label.
     */
    public static function setup(
        \moodle_page $page,
        \moodle_url $url,
        string $heading,
        array $trail = [],
    ): void {
        $page->set_context(\context_system::instance());
        $page->set_url($url);
        $page->set_pagelayout('admin');
        $page->set_title($heading);
        $page->set_heading($heading);

        $page->navbar->add(
            get_string('aiproviders', 'core_ai'),
            new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']),
        );
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
                    get_string('backtoproviders', 'aiprovider_router'),
                    'get',
                ),
                'mb-3',
            );
        }

        return \html_writer::div(
            $OUTPUT->single_button($settings, get_string('backtosettings', 'aiprovider_router'), 'get'),
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
            $page->navbar->add(get_string('pluginname', 'aiprovider_router'));

            return;
        }

        $router = (new order_inspector())->get_primary_router();
        $page->navbar->add(format_string($router->name), $settings);
    }
}
