<?php
// This file is part of the aiprovider_router plugin for Moodle.
//
// SPDX-License-Identifier: GPL-3.0-or-later

namespace aiprovider_router;

/**
 * AI Router provider.
 *
 * 骨格実装。宣言するアクションは Moodle 5.0〜5.2 共通のコア4種で、
 * 「完全ルータモード」の前提（全アクションを宣言し、実処理は委譲先へ振る）に合わせている。
 * 実際の委譲エンジンとルール評価は WP2/WP3 で実装する。
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider extends \core_ai\provider {
    #[\Override]
    public static function get_action_list(): array {
        return [
            \core_ai\aiactions\generate_text::class,
            \core_ai\aiactions\generate_image::class,
            \core_ai\aiactions\summarise_text::class,
            \core_ai\aiactions\explain_text::class,
        ];
    }

    #[\Override]
    public function is_provider_configured(): bool {
        // 骨格段階ではルーティング規則が未実装のため常に未設定を返す。
        // WP2 で「委譲先が1件以上構成されているか」の判定に置き換える。
        return false;
    }
}
