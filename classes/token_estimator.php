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
 * Estimates how many tokens a prompt is worth.
 *
 * Nothing routes on this. Rules compare character counts, so that a rule means the same
 * thing whatever language a prompt happens to be in; but tokens are the unit an
 * administrator thinks in when weighing cost and context windows, so the estimate is
 * shown beside the character count wherever a threshold is being chosen. Being wrong
 * here costs a misleading figure on a screen, never a request sent to the wrong
 * provider, and the monitor in WP4 reports what targets actually charged, which is what
 * these ratios can be calibrated against.
 *
 * The split is by character, not by document. A prompt is rarely all one language, and
 * counting each run against its own ratio is both closer to the truth and simpler than
 * classifying the prompt as a whole.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_estimator {
    /** @var float Characters per token for CJK text, before an administrator adjusts it. */
    public const DEFAULT_CJK_RATIO = 1.0;

    /** @var float Characters per token for everything else, before adjustment. */
    public const DEFAULT_OTHER_RATIO = 4.0;

    /**
     * Characters counted against the CJK ratio.
     *
     * Kana, ideographs, Hangul and the fullwidth forms. This is a ratio bucket rather
     * than a language detector, so the boundaries only have to be roughly right.
     */
    protected const CJK_PATTERN = '/[\x{3000}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}'
        . '\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}]/u';

    /**
     * Constructor.
     *
     * @param float|null $cjkratio Characters per token for CJK text, or null for the site setting.
     * @param float|null $otherratio Characters per token for other text, or null for the site setting.
     */
    public function __construct(
        /** @var float|null Characters per token for CJK text. */
        protected ?float $cjkratio = null,
        /** @var float|null Characters per token for other text. */
        protected ?float $otherratio = null,
    ) {
    }

    /**
     * The configured ratio for CJK text.
     *
     * @return float Characters per token.
     */
    public function get_cjk_ratio(): float {
        return $this->cjkratio ?? self::read_ratio('tokenratiocjk', self::DEFAULT_CJK_RATIO);
    }

    /**
     * The configured ratio for text that is not CJK.
     *
     * @return float Characters per token.
     */
    public function get_other_ratio(): float {
        return $this->otherratio ?? self::read_ratio('tokenratioother', self::DEFAULT_OTHER_RATIO);
    }

    /**
     * Estimate the tokens a piece of text is worth.
     *
     * @param string $text The prompt.
     * @return int The estimate, rounded up.
     */
    public function estimate(string $text): int {
        if (trim($text) === '') {
            return 0;
        }
        $cjk = $this->count_cjk($text);
        $other = max(0, \core_text::strlen($text) - $cjk);

        return (int) ceil($cjk / $this->get_cjk_ratio() + $other / $this->get_other_ratio());
    }

    /**
     * How many characters were counted against each ratio.
     *
     * Shown next to the estimate so that an administrator can see where a number came
     * from instead of being handed a token count on trust.
     *
     * @param string $text The prompt.
     * @return array{cjk: int, other: int, characters: int} The counts.
     */
    public function describe(string $text): array {
        $characters = \core_text::strlen($text);
        $cjk = $this->count_cjk($text);

        return [
            'cjk' => $cjk,
            'other' => max(0, $characters - $cjk),
            'characters' => $characters,
        ];
    }

    /**
     * Count the characters that fall in the CJK bucket.
     *
     * @param string $text The prompt.
     * @return int The number of characters.
     */
    protected function count_cjk(string $text): int {
        return (int) preg_match_all(self::CJK_PATTERN, $text);
    }

    /**
     * Read a ratio from the plugin configuration.
     *
     * A ratio of zero or less would divide by zero or produce a negative estimate, so a
     * setting that has been edited into nonsense falls back to the shipped value rather
     * than taking the request down with it.
     *
     * @param string $name The configuration name.
     * @param float $default The value shipped with the plugin.
     * @return float Characters per token.
     */
    protected static function read_ratio(string $name, float $default): float {
        $value = (float) get_config('aiprovider_router', $name);

        return $value > 0 ? $value : $default;
    }
}
