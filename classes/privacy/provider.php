<?php
// This file is part of the aiprovider_router plugin for Moodle.
//
// SPDX-License-Identifier: GPL-3.0-or-later

namespace aiprovider_router\privacy;

/**
 * Privacy provider for aiprovider_router.
 *
 * 骨格段階では個人データを保持しないため null_provider。
 * WP4（モニタログ）・WP5（BYOKキー）の実装時に metadata/request プロバイダへ差し替える。
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider {
    #[\Override]
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
