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

namespace aiprovider_modelled;

/**
 * The models the fixture provider lists.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Every model, including the entry a settings page uses for a typed name.
     *
     * @return model[] The models.
     */
    public static function get_models(): array {
        return [
            new model('alpha', 'Alpha', [model::TYPE_TEXT], ['temperature' => 0.2, 'top_k' => 5]),
            new model('beta', 'Beta', [model::TYPE_TEXT], ['temperature' => 0.7]),
            new model('pictor', 'Pictor', [model::TYPE_IMAGE], []),
            new model('custom', 'Custom', [model::TYPE_TEXT], []),
        ];
    }

    /**
     * One model by name.
     *
     * @param string $name The model name.
     * @return model|null The model, or null.
     */
    public static function get_model_class(string $name): ?model {
        foreach (self::get_models() as $model) {
            if ($model->get_model_name() === $name) {
                return $model;
            }
        }

        return null;
    }
}
