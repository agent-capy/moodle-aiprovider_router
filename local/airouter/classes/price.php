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
 * One rate an administrator has entered for a provider and model.
 *
 * Rates belong to a provider and a model rather than to an instance, because two
 * instances of the same provider are charged the same. A rate with an empty model
 * covers anything from that provider which has no rate of its own.
 *
 * Each rate carries the time it took effect. A request is costed with the rate in
 * force when it happened, and the result is kept, so that editing a rate today does
 * not rewrite what last month cost.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class price extends \core\persistent {
    /** @var string The table holding rates. */
    public const TABLE = 'local_airouter_price';

    /** @var int Rates are entered per this many tokens, the way providers publish them. */
    public const TOKEN_UNIT = 1000000;

    #[\Override]
    protected static function define_properties(): array {
        return [
            'provider' => [
                'type' => PARAM_COMPONENT,
            ],
            'model' => [
                'type' => PARAM_TEXT,
                'default' => '',
            ],
            'promptrate' => [
                'type' => PARAM_FLOAT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'completionrate' => [
                'type' => PARAM_FLOAT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'imagerate' => [
                'type' => PARAM_FLOAT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'timefrom' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
        ];
    }

    /**
     * A rate has to say something about what it costs.
     *
     * @param mixed $value The submitted prompt rate.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_promptrate($value): true|\core\lang_string {
        if ($value !== null && (float) $value < 0) {
            return new \core\lang_string('price:error:negative', 'local_airouter');
        }

        return true;
    }

    /**
     * Completion rates are subject to the same check as prompt rates.
     *
     * @param mixed $value The submitted completion rate.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_completionrate($value): true|\core\lang_string {
        return $this->validate_promptrate($value);
    }

    /**
     * Image rates are subject to the same check as prompt rates.
     *
     * @param mixed $value The submitted image rate.
     * @return true|\core\lang_string True when valid, otherwise the error to show.
     */
    protected function validate_imagerate($value): true|\core\lang_string {
        return $this->validate_promptrate($value);
    }

    /**
     * What this rate says a request of that size costs.
     *
     * Returns null rather than zero when nothing here covers the request. Zero would
     * read as "this was free", which is a different and much more comforting claim.
     *
     * @param int|null $prompttokens Tokens in the prompt, or null for an action with none.
     * @param int|null $completiontokens Tokens generated, or null for an action with none.
     * @param int $images Images produced.
     * @return float|null The cost, or null when this rate cannot cost the request.
     */
    public function cost(?int $prompttokens, ?int $completiontokens, int $images = 0): ?float {
        $total = null;

        foreach ([['promptrate', $prompttokens], ['completionrate', $completiontokens]] as [$field, $tokens]) {
            $rate = $this->get($field);
            if ($rate === null || $tokens === null) {
                continue;
            }
            $total = ($total ?? 0.0) + ((float) $rate * $tokens / self::TOKEN_UNIT);
        }

        // Image actions carry no token counts at all, so they are costed per image.
        if ($images > 0 && $this->get('imagerate') !== null) {
            $total = ($total ?? 0.0) + ((float) $this->get('imagerate') * $images);
        }

        return $total;
    }
}
