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
 * Delegates the transcribe audio action to another provider.
 *
 * The action is not one of Moodle's: local_aiaudio defines it, because the AI
 * subsystem has nothing that takes audio in. The router treats it like any other
 * action — rules, budgets, brought keys and the usage history all apply — which
 * is the point of routing being written against actions rather than against the
 * four core happens to ship.
 *
 * ⚠ A transcription carries no token counts, so its cost cannot be worked out
 * from the rate table and shows as unknown. A budget counted in requests is the
 * measure that still works on it, which is also what a free allowance written in
 * requests per month wants.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_transcript_audio extends abstract_processor {
    #[\Override]
    protected function get_content_key(): ?string {
        // An empty transcript is a failed target, not a silent recording: a model
        // that heard nothing still says so. Falling through to the next candidate
        // is the same treatment an empty generation gets.
        return 'transcript';
    }
}
