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

use core_ai\provider as ai_provider;

/**
 * Puts somebody's own key into a copy of the provider instance it is for.
 *
 * The copy is made with the provider's own with() method, which core uses for the same
 * purpose when it saves an edited instance. Nothing is written: what comes back is an
 * instance that exists for the length of one request and carries one different setting.
 *
 * Building the instance by hand instead would mean knowing the order of a constructor
 * this plugin does not own, in every release it supports, for every provider plugin
 * anybody might install.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_injector {
    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to read.
     * @param target_settings|null $settings Where the key field for each target is recorded.
     * @param key_repository|null $keys Where the keys are held.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var target_settings|null The target settings. */
        protected ?target_settings $settings = null,
        /** @var key_repository|null The key store. */
        protected ?key_repository $keys = null,
    ) {
        $this->settings ??= new target_settings($db);
        $this->keys ??= new key_repository($db);
    }

    /**
     * The instance to use when a request is to be paid for by a particular subject.
     *
     * @param ai_provider $target The instance the rule named.
     * @param string $scope One of the key scopes.
     * @param int $scopeid The user or course paying.
     * @param string|null $actionclass The action being asked for, so that the model the
     *                                 owner chose for it is used; null for the site's.
     * @return key_injection What happened, and the instance to use if there is one.
     */
    public function for_subject(
        ai_provider $target,
        string $scope,
        int $scopeid,
        ?string $actionclass = null,
    ): key_injection {
        if ($scopeid <= 0) {
            // A course key asked for outside any course, or a user key with no user.
            // Nothing is wrong; there is simply nobody here to pay.
            return new key_injection(key_status::ABSENT);
        }

        $key = $this->keys->find($scope, $scopeid, (int) $target->id);
        if ($key === null) {
            return new key_injection(key_status::ABSENT);
        }

        return $this->inject($target, $key, $actionclass);
    }

    /**
     * The instance carrying a particular key.
     *
     * @param ai_provider $target The instance the key is for.
     * @param key $key The key.
     * @param string|null $actionclass The action being asked for, so that the model the
     *                                 owner chose for it is used; null for the site's.
     * @return key_injection What happened, and the instance to use if there is one.
     */
    public function inject(ai_provider $target, key $key, ?string $actionclass = null): key_injection {
        // Asked here rather than only where a key is registered. A site can forbid
        // brought keys at a provider long after somebody registered one for it, and a
        // policy that is only enforced at the moment of registration is not a policy:
        // the keys already stored would go on being used indefinitely.
        if (!$this->settings->supports_byok((int) $target->id)) {
            $field = $this->settings->get_key_field((int) $target->id);
            if ($field !== null && $field !== target_settings::NO_KEY) {
                return new key_injection(key_status::DISALLOWED, key: $key);
            }

            // Either nobody has said where this provider's key goes, or somebody has said
            // it takes none. Both mean the key cannot be applied, and putting it nowhere
            // would send the request charged to the site instead.
            return new key_injection(key_status::NO_FIELD, key: $key);
        }

        $field = $this->settings->get_key_field((int) $target->id);

        $secret = $this->keys->reveal($key);
        if ($secret === null) {
            return new key_injection(key_status::UNREADABLE, key: $key);
        }

        $carrying = $target->with(config: [$field => $secret] + $target->config);

        return new key_injection(key_status::OK, $this->with_chosen_model($carrying, $key, $actionclass), $key);
    }

    /**
     * The instance asking for the model the key's owner chose, where they may choose.
     *
     * The choice is obeyed only while the site allows choosing at this target and the
     * provider can be asked for another model in this action. A choice made while that
     * held is kept when it stops holding, and simply not used, the way a key is kept
     * when the site stops allowing keys: the owner set it and the owner can remove it.
     *
     * @param ai_provider $target The instance, already carrying the key.
     * @param key $key The key.
     * @param string|null $actionclass The action being asked for.
     * @return ai_provider The instance to use.
     */
    protected function with_chosen_model(ai_provider $target, key $key, ?string $actionclass): ai_provider {
        if ($actionclass === null || !$this->settings->allows_model_choice((int) $target->id)) {
            return $target;
        }
        $model = $key->get_chosen_model($actionclass);
        $catalog = new model_catalog();
        if ($model === null || model_catalog::clean($model) === null || !$catalog->can_choose($target, $actionclass)) {
            return $target;
        }

        return $catalog->apply($target, $actionclass, $model);
    }
}
