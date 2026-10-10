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
 * Which model a provider instance uses for an action, and which others it could use.
 *
 * The owner of a brought key pays for what they ask, so they are entitled to know which
 * model their key is used with, and, where the site allows it, to ask for another. Both
 * are answered the way the provider itself answers them on its own settings page:
 *
 * - a provider that names its models in classes (core's providers since 5.0, and the
 *   providers built the same way) offers those models, and a model it does not list
 *   can be typed in, which is what its settings page calls a custom model;
 * - a provider that does not, Azure OpenAI for one, whose deployments are named by the
 *   site rather than chosen from a list, cannot be asked for another model at all.
 *
 * The model of an action is read from the instance's action settings, where every one of
 * these providers keeps it. Nothing is guessed: an action whose settings name no model
 * is reported as such, and offers no choice.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model_catalog {
    /** @var int What core's providers call a model for text. */
    public const TYPE_TEXT = 1;

    /** @var int What core's providers call a model for images. */
    public const TYPE_IMAGE = 2;

    /** @var int What the providers that transcribe call a model for audio. */
    public const TYPE_AUDIO = 3;

    /** @var string The name some providers give the entry that means a name is typed. */
    protected const CUSTOM = 'custom';

    /** @var string What a model's name may be made of when somebody types it. */
    protected const NAME_PATTERN = '~^[A-Za-z0-9][A-Za-z0-9._:/@+\-]{0,99}$~';

    /** @var array[] The models each provider lists, keyed by component, once read. */
    protected array $lists = [];

    /**
     * The model the site configured for an action at this instance.
     *
     * @param ai_provider $target The provider instance.
     * @param string $actionclass The action class.
     * @return string|null The model, or null when the settings name none.
     */
    public function get_site_model(ai_provider $target, string $actionclass): ?string {
        $model = $this->get_settings($target, $actionclass)['model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * Whether another model can be asked for in this action at this instance.
     *
     * Says what the provider allows, not what the site does: whether owners may choose
     * at all is the administrator's decision, recorded in the target settings.
     *
     * @param ai_provider $target The provider instance.
     * @param string $actionclass The action class.
     * @return bool True when the provider lists its models and the action names one.
     */
    public function can_choose(ai_provider $target, string $actionclass): bool {
        return $this->get_site_model($target, $actionclass) !== null && $this->get_models($target) !== null;
    }

    /**
     * The models the provider lists that suit this action.
     *
     * A model suits the action when it is of a kind the site's own model is of, or, where
     * the site's model is one the provider does not list, of the kind the action needs.
     * A provider whose models say nothing about their kind has all of them offered.
     *
     * @param ai_provider $target The provider instance.
     * @param string $actionclass The action class.
     * @return string[] Display names keyed by model name; empty when nothing can be chosen.
     */
    public function get_options(ai_provider $target, string $actionclass): array {
        if (!$this->can_choose($target, $actionclass)) {
            return [];
        }
        $models = $this->get_models($target);
        $site = $this->get_site_model($target, $actionclass);
        $wanted = $models[$site]['types'] ?? [];
        if ($wanted === []) {
            $wanted = self::types_for($actionclass);
        }

        $options = [];
        foreach ($models as $name => $model) {
            if ($model['types'] === [] || array_intersect($model['types'], $wanted) !== []) {
                $options[$name] = $model['label'];
            }
        }

        return $options;
    }

    /**
     * A model name as somebody typed it, or null when it cannot be one.
     *
     * Names are letters, digits and the punctuation providers use in them (dots, colons,
     * slashes, at signs, pluses, hyphens and underscores), at most as long as the router
     * records a model name. Anything else is refused rather than sent.
     *
     * @param string $model What was typed.
     * @return string|null The name, or null.
     */
    public static function clean(string $model): ?string {
        $model = trim($model);

        return preg_match(self::NAME_PATTERN, $model) ? $model : null;
    }

    /**
     * A copy of the instance that asks for another model in one action.
     *
     * The settings the site tuned for its own model go: a temperature or a token limit
     * chosen for one model can be refused by another, and the provider's own defaults
     * for the model asked for are the safer guess. Where the administrator saved
     * settings for the model asked for, which the providers' settings pages keep while
     * switching between models, those are used instead. Everything else the site
     * configured, the system instruction among it, stays.
     *
     * @param ai_provider $target The provider instance, already carrying the key.
     * @param string $actionclass The action class.
     * @param string $model The model asked for.
     * @return ai_provider The copy, or the instance itself when nothing changes.
     */
    public function apply(ai_provider $target, string $actionclass, string $model): ai_provider {
        $actionclass = ltrim($actionclass, '\\');
        $site = $this->get_site_model($target, $actionclass);
        if ($site === null || $model === '' || $model === $site) {
            return $target;
        }

        $settings = $this->get_settings($target, $actionclass);
        foreach ($this->get_setting_names($target, $site) as $name) {
            unset($settings[$name]);
        }
        unset($settings['modelextraparams']);
        $saved = $settings['modelsettings'][$model] ?? null;
        if (is_array($saved)) {
            foreach ($saved as $name => $value) {
                $settings[$name] = $value;
            }
        }
        $settings['model'] = $model;

        $actionconfig = $target->actionconfig;
        $actionconfig[$actionclass]['settings'] = $settings;

        return $target->with(actionconfig: $actionconfig);
    }

    /**
     * The actions a brought key could be used for at this instance.
     *
     * Only what the router answers and the instance has enabled: a key is never used
     * for an action left to core, which calls the instance with the site's own key.
     *
     * @param ai_provider $target The provider instance.
     * @return string[] Action classes, in the order the provider lists them.
     */
    public function get_actions(ai_provider $target): array {
        $managed = managed_policy::managed_actions();
        $actions = [];
        foreach ($target::get_action_list() as $actionclass) {
            $actionclass = ltrim($actionclass, '\\');
            $enabled = !empty($target->actionconfig[$actionclass]['enabled']);
            if ($enabled && in_array($actionclass, $managed, true)) {
                $actions[] = $actionclass;
            }
        }

        return $actions;
    }

    /**
     * The kinds of model an action needs.
     *
     * @param string $actionclass The action class.
     * @return int[] Model types.
     */
    public static function types_for(string $actionclass): array {
        return match (managed_policy::basename_for($actionclass)) {
            'generate_image', 'describe_image' => [self::TYPE_IMAGE],
            'transcript_audio' => [self::TYPE_AUDIO],
            default => [self::TYPE_TEXT],
        };
    }

    /**
     * The settings of one action at an instance.
     *
     * @param ai_provider $target The provider instance.
     * @param string $actionclass The action class.
     * @return array The settings, empty when there are none.
     */
    protected function get_settings(ai_provider $target, string $actionclass): array {
        $settings = $target->actionconfig[ltrim($actionclass, '\\')]['settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * The models a provider lists, read from its helper the way its settings page does.
     *
     * Two shapes are known: core's, where the helper returns model classes, and the one
     * where it returns the models themselves. A provider with neither, or whose list
     * cannot be read, offers no list, and so no choice.
     *
     * @param ai_provider $target The provider instance.
     * @return array[]|null Keyed by model name: label and types. Null when there is no list.
     */
    public function get_models(ai_provider $target): ?array {
        $component = \core\component::get_component_from_classname($target::class) ?? strtok($target::class, '\\');
        if (array_key_exists($component, $this->lists)) {
            return $this->lists[$component];
        }

        $helper = $component . '\\helper';
        $models = null;
        try {
            $items = match (true) {
                !class_exists($helper) => null,
                method_exists($helper, 'get_model_classes') => $helper::get_model_classes(),
                method_exists($helper, 'get_models') => $helper::get_models(),
                default => null,
            };
            if (is_array($items)) {
                $models = [];
                foreach ($items as $item) {
                    $model = is_string($item) ? new $item() : $item;
                    if (!is_object($model) || !method_exists($model, 'get_model_name')) {
                        continue;
                    }
                    $name = (string) $model->get_model_name();
                    if ($name === self::CUSTOM) {
                        // Not a model: the entry a provider's settings page uses for a
                        // model whose name is typed in, which here is the typed name.
                        continue;
                    }
                    $models[$name] = [
                        'label' => method_exists($model, 'get_model_display_name')
                            ? (string) $model->get_model_display_name()
                            : $name,
                        'types' => method_exists($model, 'model_type')
                            ? array_values(array_map('intval', (array) $model->model_type()))
                            : [],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // A provider whose list cannot be read is treated as one that has none. The
            // site's model is still used; only the choice is withheld.
            debugging('local_airouter: the models of ' . $component . ' could not be read: ' . get_class($e), DEBUG_DEVELOPER);
            $models = null;
        }

        return $this->lists[$component] = $models;
    }

    /**
     * The names of the settings a provider keeps for one of its models.
     *
     * @param ai_provider $target The provider instance.
     * @param string $model The model.
     * @return string[] Setting names; empty for a model the provider does not list.
     */
    protected function get_setting_names(ai_provider $target, string $model): array {
        $component = \core\component::get_component_from_classname($target::class) ?? strtok($target::class, '\\');
        $helper = $component . '\\helper';
        try {
            $class = class_exists($helper) && method_exists($helper, 'get_model_class')
                ? $helper::get_model_class($model)
                : null;
            if (is_object($class) && method_exists($class, 'get_model_settings')) {
                return array_map('strval', array_keys((array) $class->get_model_settings()));
            }
        } catch (\Throwable $e) {
            debugging('local_airouter: the settings of ' . $model . ' could not be read: ' . get_class($e), DEBUG_DEVELOPER);
        }

        return [];
    }
}
