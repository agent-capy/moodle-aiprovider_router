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

use core_ai\aiactions\generate_text;
use core_ai\provider as ai_provider;

/**
 * Finds out whether a key is any good by asking the provider.
 *
 * One real request with the shortest prompt there is. The alternative considered was a
 * provider's own lightweight endpoint, a models list or similar, which was rejected for
 * the same reason the key field is not assumed: it would mean holding knowledge about
 * each provider inside the router, and a provider nobody here has heard of would then
 * have no way to be tested at all.
 *
 * The only thing being asked is whether the key is accepted. What comes back is thrown
 * away, so an empty answer, a refusal to answer and a long answer are all the same here.
 *
 * Never run on its own. Somebody presses a button, having been told that their provider
 * may charge them a small amount for it.
 *
 * @package    aiprovider_router
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_tester {
    /** @var string The shortest thing worth asking for. */
    protected const PROMPT = 'Hi';

    /** @var int[] Status codes that mean the key itself was refused. */
    public const REJECTED = [401, 403];

    /**
     * Constructor.
     *
     * @param \moodle_database $db The database to work on.
     * @param key_injector|null $injector How the key is put into the instance.
     * @param key_repository|null $keys Where the result is recorded.
     */
    public function __construct(
        /** @var \moodle_database The database. */
        protected readonly \moodle_database $db,
        /** @var key_injector|null The injector. */
        protected ?key_injector $injector = null,
        /** @var key_repository|null The key store. */
        protected ?key_repository $keys = null,
    ) {
        $this->injector ??= new key_injector($db);
        $this->keys ??= new key_repository($db);
    }

    /**
     * Ask the provider whether it accepts this key, and record what it said.
     *
     * @param ai_provider $target The instance the key is for.
     * @param key $key The key to test.
     * @param int $contextid The context the test is being run from.
     * @param int $userid The person running it.
     * @return string One of the key verification results.
     */
    public function test(ai_provider $target, key $key, int $contextid, int $userid): string {
        $injection = $this->injector->inject($target, $key);
        if (!$injection->is_usable()) {
            $this->keys->record_verification($key, key::VERIFY_FAILED);

            return key::VERIFY_FAILED;
        }

        $status = $this->ask($injection->target, $contextid, $userid);
        $this->keys->record_verification($key, $status);

        return $status;
    }

    /**
     * Put one request to the instance and judge the answer.
     *
     * @param ai_provider $target The instance carrying the key.
     * @param int $contextid The context the test is being run from.
     * @param int $userid The person running it.
     * @return string One of the key verification results.
     */
    protected function ask(ai_provider $target, int $contextid, int $userid): string {
        if (!in_array(generate_text::class, $target::get_action_list(), true)) {
            // Nothing here can be asked of this provider, which says nothing about the
            // key. Better to report that the test reached no conclusion.
            return key::VERIFY_FAILED;
        }

        try {
            $response = $this->get_delegator()->delegate(
                $target,
                new generate_text(contextid: $contextid, userid: $userid, prompttext: self::PROMPT),
            );
        } catch (\Throwable $e) {
            // Unreachable, timed out, or a provider that throws where others return. None
            // of that is evidence about the key.
            // The key being tested is in this target's config, and an HTTP client that
            // puts the request in the exception message puts the key in it too.
            debugging(
                'aiprovider_router: key test threw ' . get_class($e) . ': '
                    . abstract_processor::redact_for($e->getMessage(), $target),
                DEBUG_NORMAL,
            );

            return key::VERIFY_FAILED;
        }

        if ($response->get_success()) {
            return key::VERIFY_OK;
        }

        return in_array((int) $response->get_errorcode(), self::REJECTED, true)
            ? key::VERIFY_REJECTED
            : key::VERIFY_FAILED;
    }

    /**
     * The delegator the test request goes through.
     *
     * @return delegator The delegator.
     */
    protected function get_delegator(): delegator {
        return new delegator($this->db);
    }
}
