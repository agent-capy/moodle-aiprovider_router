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

use core\hook\di_configuration;
use core_ai\hook\after_ai_provider_form_hook;
use core_course\hook\before_course_deleted;

/**
 * Callbacks for the core hooks this plugin listens to.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_listener {
    /**
     * Put this plugin's manager in front of the AI subsystem.
     *
     * Every entry point core has for starting an AI request asks the container for a
     * manager, so a definition registered here is what decides which requests the
     * router is allowed to answer. Being first in the provider order is not the same
     * thing: an order says which provider is preferred, and a provider above the router
     * answers before any rule, budget or key of somebody's has been looked at.
     *
     * The definition is registered whether or not the site manages anything. Whether an
     * action is managed can change between one request and the next, and the container
     * is built once, so the question is asked when the request arrives instead. With
     * nothing managed the class behaves exactly as core's own manager does.
     *
     * The definition is a closure, so nothing is built while the container is being
     * assembled and the manager cannot end up depending on itself.
     *
     * @param di_configuration $hook The hook being handled.
     */
    public static function configure_di(di_configuration $hook): void {
        $hook->add_definition(
            id: \core_ai\manager::class,
            definition: static function (\moodle_database $db): \core_ai\manager {
                return new routing_manager($db);
            },
        );
    }

    /**
     * Add the router settings to the provider instance form.
     *
     * @param after_ai_provider_form_hook $hook The hook being handled.
     */
    public static function set_form_definition_for_local_airouter(
        after_ai_provider_form_hook $hook,
    ): void {
        if ($hook->plugin !== provider::INSTANCE_PLUGIN) {
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
            get_string('mode', 'local_airouter'),
            [
                provider::MODE_FULL => get_string('mode:full', 'local_airouter'),
                provider::MODE_COEXIST => get_string('mode:coexist', 'local_airouter'),
            ],
        );
        $mform->setDefault('mode', provider::MODE_FULL);
        $mform->addHelpButton('mode', 'mode', 'local_airouter');

        // What happens to a request no rule claimed is the path most sites travel most
        // of the time, so it is a stated choice rather than something to be inferred
        // from the mode. The default still follows the mode, which is why the two
        // options name the mode they suit.
        $mform->addElement(
            'select',
            'nomatch',
            get_string('nomatch', 'local_airouter'),
            [
                provider::NOMATCH_DELEGATE => get_string('nomatch:delegate', 'local_airouter'),
                provider::NOMATCH_DECLINE => get_string('nomatch:decline', 'local_airouter'),
            ],
        );
        $mform->setDefault('nomatch', provider::NOMATCH_DELEGATE);
        $mform->addHelpButton('nomatch', 'nomatch', 'local_airouter');

        // Whether a refusal is allowed to be the last word. Core has no way of being
        // told that a failure is final, so saying so means throwing, and throwing is
        // visible to the person who made the request. That is a trade worth stating
        // rather than burying, so it is a setting with the consequence in its help.
        $mform->addElement(
            'advcheckbox',
            'strictdecline',
            get_string('strictdecline', 'local_airouter'),
            get_string('strictdecline:label', 'local_airouter'),
        );
        $mform->setDefault('strictdecline', 1);
        $mform->addHelpButton('strictdecline', 'strictdecline', 'local_airouter');

        $mform->addElement(
            'static',
            'managedlink',
            get_string('managed:heading', 'local_airouter'),
            \html_writer::link(
                new \moodle_url('/local/airouter/managed.php'),
                get_string('managed:manage', 'local_airouter'),
            ),
        );

        // The rules are a separate page because core never reads an aiprovider plugin's
        // settings.php, so there is no admin tree entry to reach them from. This form is
        // where an administrator configuring the router already is.
        $mform->addElement(
            'static',
            'ruleslink',
            get_string('rules:heading', 'local_airouter'),
            \html_writer::link(
                new \moodle_url('/local/airouter/rules.php'),
                get_string('rules:manage', 'local_airouter'),
            ),
        );

        $mform->addElement(
            'static',
            'rateslink',
            get_string('rates:heading', 'local_airouter'),
            \html_writer::link(
                new \moodle_url('/local/airouter/rates.php'),
                get_string('rates:manage', 'local_airouter'),
            ),
        );

        $mform->addElement(
            'static',
            'usagelink',
            get_string('usage:heading', 'local_airouter'),
            \html_writer::link(
                new \moodle_url('/local/airouter/usage.php'),
                get_string('usage:manage', 'local_airouter'),
            ),
        );

        $mform->addElement(
            'static',
            'byoklink',
            get_string('byok:heading', 'local_airouter'),
            \html_writer::link(
                new \moodle_url('/local/airouter/byok.php'),
                get_string('byok:manage', 'local_airouter'),
            ),
        );

        $targets = self::get_target_options();
        if (!$targets) {
            $mform->addElement(
                'static',
                'notargets',
                get_string('defaulttarget', 'local_airouter'),
                get_string('defaulttarget:none', 'local_airouter'),
            );

            return;
        }

        $mform->addElement(
            'select',
            'defaulttarget',
            get_string('defaulttarget', 'local_airouter'),
            ['' => get_string('choosedots')] + $targets,
        );
        $mform->setType('defaulttarget', PARAM_INT);
        $mform->addHelpButton('defaulttarget', 'defaulttarget', 'local_airouter');
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

        return ['name' => get_string('error:onlyoneinstance', 'local_airouter')];
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
            new \moodle_url('/local/airouter/order.php'),
            get_string('order:heading', 'local_airouter'),
        );

        $messages = [];
        if (count($inspector->get_routers()) > 1) {
            $messages[] = self::build_duplicate_notice($inspector);
        }
        if (!$inspector->is_router_first()) {
            $key = $router->get_mode() === provider::MODE_COEXIST
                ? 'order:notice:notfirst:coexist'
                : 'order:notice:notfirst:full';
            $messages[] = get_string($key, 'local_airouter');
        }

        if ($messages) {
            $mform->addElement('html', \html_writer::div(
                implode('', array_map(fn($m) => \html_writer::tag('p', $m), $messages))
                    . \html_writer::tag('p', $link),
                'alert alert-warning',
            ));

            return;
        }

        $mform->addElement('static', 'orderlink', get_string('order:heading', 'local_airouter'), $link);
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
                'local_airouter',
                ['id' => $id, 'name' => s($instance->name)],
            );
        }

        return get_string('warning:duplicateinstances', 'local_airouter', count($rows))
            . \html_writer::alist($rows)
            . \html_writer::link(
                new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']),
                get_string('check:singleinstance:manage', 'local_airouter'),
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

    /**
     * Remove the key a course paid with when the course goes.
     *
     * What a course used the AI for is history and stays: a removed course does not
     * unspend the money. Its key is not history. It is a secret this site can still
     * decrypt, for an account somebody is still paying for, and once the course is gone
     * there is nothing left that could ever use it and no screen that could reach it to
     * take it away -- the key screen is a page in a course. Moodle's privacy tools
     * cannot find it either, because they find a course key through the course context,
     * which is deleted with everything else.
     *
     * Done on the hook that fires before the deletion rather than on the event that
     * follows it, because the event arrives once the course, and the context the key is
     * reached through, have already gone.
     *
     * @param before_course_deleted $hook The hook being handled.
     */
    public static function delete_keys_for_deleted_course(before_course_deleted $hook): void {
        global $DB;

        (new key_repository($DB))->delete_for_course((int) $hook->course->id);
    }
}
