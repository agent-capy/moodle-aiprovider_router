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

use core_ai\hook\after_ai_provider_form_hook;

/**
 * Builds the settings form for a router instance.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_listener {
    /**
     * Add the router settings to the provider instance form.
     *
     * @param after_ai_provider_form_hook $hook The hook being handled.
     */
    public static function set_form_definition_for_aiprovider_router(
        after_ai_provider_form_hook $hook,
    ): void {
        if ($hook->plugin !== 'aiprovider_router') {
            return;
        }

        $mform = $hook->mform;

        // Core has no hook that fires before an instance is created, so the only place
        // a second router can be refused is the form that creates it.
        $mform->addFormRule([self::class, 'validate_single_instance']);

        self::add_order_notice($mform);

        $mform->addElement(
            'select',
            'mode',
            get_string('mode', 'aiprovider_router'),
            [
                provider::MODE_FULL => get_string('mode:full', 'aiprovider_router'),
                provider::MODE_COEXIST => get_string('mode:coexist', 'aiprovider_router'),
            ],
        );
        $mform->setDefault('mode', provider::MODE_FULL);
        $mform->addHelpButton('mode', 'mode', 'aiprovider_router');

        // What happens to a request no rule claimed is the path most sites travel most
        // of the time, so it is a stated choice rather than something to be inferred
        // from the mode. The default still follows the mode, which is why the two
        // options name the mode they suit.
        $mform->addElement(
            'select',
            'nomatch',
            get_string('nomatch', 'aiprovider_router'),
            [
                provider::NOMATCH_DELEGATE => get_string('nomatch:delegate', 'aiprovider_router'),
                provider::NOMATCH_DECLINE => get_string('nomatch:decline', 'aiprovider_router'),
            ],
        );
        $mform->setDefault('nomatch', provider::NOMATCH_DELEGATE);
        $mform->addHelpButton('nomatch', 'nomatch', 'aiprovider_router');

        // The rules are a separate page because core never reads an aiprovider plugin's
        // settings.php, so there is no admin tree entry to reach them from. This form is
        // where an administrator configuring the router already is.
        $mform->addElement(
            'static',
            'ruleslink',
            get_string('rules:heading', 'aiprovider_router'),
            \html_writer::link(
                new \moodle_url('/ai/provider/router/rules.php'),
                get_string('rules:manage', 'aiprovider_router'),
            ),
        );

        $mform->addElement(
            'static',
            'rateslink',
            get_string('rates:heading', 'aiprovider_router'),
            \html_writer::link(
                new \moodle_url('/ai/provider/router/rates.php'),
                get_string('rates:manage', 'aiprovider_router'),
            ),
        );

        $mform->addElement(
            'static',
            'usagelink',
            get_string('usage:heading', 'aiprovider_router'),
            \html_writer::link(
                new \moodle_url('/ai/provider/router/usage.php'),
                get_string('usage:manage', 'aiprovider_router'),
            ),
        );

        $targets = self::get_target_options();
        if (!$targets) {
            $mform->addElement(
                'static',
                'notargets',
                get_string('defaulttarget', 'aiprovider_router'),
                get_string('defaulttarget:none', 'aiprovider_router'),
            );

            return;
        }

        $mform->addElement(
            'select',
            'defaulttarget',
            get_string('defaulttarget', 'aiprovider_router'),
            ['' => get_string('choosedots')] + $targets,
        );
        $mform->setType('defaulttarget', PARAM_INT);
        $mform->addHelpButton('defaulttarget', 'defaulttarget', 'aiprovider_router');
    }

    /**
     * Refuse a second router instance.
     *
     * Only creation can be caught here. An existing instance is being edited when the
     * form carries the hidden id that ai_provider_form adds in that case, and editing
     * must keep working even on a site that already has more than one router.
     *
     * @param array $values The submitted values.
     * @return array|bool Errors keyed by element name, or true when the form is fine.
     */
    public static function validate_single_instance(array $values): array|bool {
        if (!empty($values['id'])) {
            return true;
        }
        if (!provider::get_instance_ids()) {
            return true;
        }

        return ['name' => get_string('error:onlyoneinstance', 'aiprovider_router')];
    }


    /**
     * Tell the administrator where this instance sits in the site's provider order.
     *
     * The form saves one row of ai_providers, while the order is a setting of core_ai that
     * applies to the whole site, so nothing here changes it. Being told about the problem
     * on the screen where the router is configured saves an administrator from having to
     * suspect the provider order in the first place, and the link leads to the one page
     * where the change can be made.
     *
     * @param \MoodleQuickForm $mform The form being built.
     */
    protected static function add_order_notice(\MoodleQuickForm $mform): void {
        $inspector = new order_inspector();
        $router = $inspector->get_primary_router();
        if ($router === null) {
            // The instance is being created, so there is no position to report yet.
            return;
        }

        $link = \html_writer::link(
            new \moodle_url('/ai/provider/router/order.php'),
            get_string('order:heading', 'aiprovider_router'),
        );

        $messages = [];
        if (count($inspector->get_routers()) > 1) {
            $messages[] = self::build_duplicate_notice($inspector);
        }
        if (!$inspector->is_router_first()) {
            $key = $router->get_mode() === provider::MODE_COEXIST
                ? 'order:notice:notfirst:coexist'
                : 'order:notice:notfirst:full';
            $messages[] = get_string($key, 'aiprovider_router');
        }

        if ($messages) {
            $mform->addElement('html', \html_writer::div(
                implode('', array_map(fn($m) => \html_writer::tag('p', $m), $messages))
                    . \html_writer::tag('p', $link),
                'alert alert-warning',
            ));

            return;
        }

        $mform->addElement('static', 'orderlink', get_string('order:heading', 'aiprovider_router'), $link);
    }

    /**
     * The notice shown on every router instance when the site has more than one.
     *
     * The same list appears on all of them, marking which instance is kept and which are
     * to be removed, so that an administrator who happens to open the surviving one still
     * sees that something needs doing. Deleting is left to core's own delete button on the
     * provider list, which already handles the capability check and the delete hooks.
     *
     * @param order_inspector $inspector The inspector to read the site through.
     * @return string HTML.
     */
    protected static function build_duplicate_notice(order_inspector $inspector): string {
        $primaryid = (int) $inspector->get_primary_router()->id;
        $rows = [];
        foreach ($inspector->get_routers() as $id => $instance) {
            $rows[] = get_string(
                $id === $primaryid ? 'order:instance:keep' : 'order:instance:remove',
                'aiprovider_router',
                ['id' => $id, 'name' => s($instance->name)],
            );
        }

        return get_string('warning:duplicateinstances', 'aiprovider_router', count($rows))
            . \html_writer::alist($rows)
            . \html_writer::link(
                new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']),
                get_string('check:singleinstance:manage', 'aiprovider_router'),
            );
    }

    /**
     * Provider instances that the router could delegate to.
     *
     * @return array Instance id to display name.
     */
    protected static function get_target_options(): array {
        return target_resolver::get_delegation_targets();
    }
}
