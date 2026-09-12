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
     * Provider instances that the router could delegate to.
     *
     * @return array Instance id to display name.
     */
    protected static function get_target_options(): array {
        $options = [];
        foreach (\core\di::get(\core_ai\manager::class)->get_provider_instances() as $instance) {
            // A router delegating to a router would loop.
            if ($instance instanceof provider) {
                continue;
            }
            $options[(int) $instance->id] = $instance->name;
        }

        return $options;
    }
}
