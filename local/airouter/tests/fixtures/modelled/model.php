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
 * One model of the fixture provider.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class model {
    /** @var int A model for text. */
    public const TYPE_TEXT = 1;

    /** @var int A model for images. */
    public const TYPE_IMAGE = 2;

    /**
     * Constructor.
     *
     * @param string $name The model name.
     * @param string $label The display name.
     * @param int[] $types The kinds of model it is.
     * @param array $settings The settings it has, with their defaults.
     */
    public function __construct(
        /** @var string The model name. */
        protected readonly string $name,
        /** @var string The display name. */
        protected readonly string $label,
        /** @var int[] The kinds of model it is. */
        protected readonly array $types,
        /** @var array The settings it has. */
        protected readonly array $settings,
    ) {
    }

    /**
     * The model name.
     *
     * @return string The name.
     */
    public function get_model_name(): string {
        return $this->name;
    }

    /**
     * The display name.
     *
     * @return string The label.
     */
    public function get_model_display_name(): string {
        return $this->label;
    }

    /**
     * The kinds of model it is.
     *
     * @return int[] The types.
     */
    public function model_type(): array {
        return $this->types;
    }

    /**
     * The settings it has.
     *
     * @return array The settings, keyed by name.
     */
    public function get_model_settings(): array {
        return $this->settings;
    }
}
