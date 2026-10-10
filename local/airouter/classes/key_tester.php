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
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_tester {
    /** @var string The shortest thing worth asking for. */
    protected const PROMPT = 'Hi';

    /** @var int[] Status codes that mean the key itself was refused. */
    public const REJECTED = [401, 403];

    /** @var string The model answered. */
    public const MODEL_OK = 'ok';

    /** @var string The provider refused the key. */
    public const MODEL_REJECTED = 'rejected';

    /** @var string Something else went wrong; the error says what. */
    public const MODEL_FAILED = 'failed';

    /** @var string The action is not one a test asks. */
    public const MODEL_SKIPPED = 'skipped';

    /** @var string[] The actions a model test asks: those that take a line of text. */
    protected const TESTABLE = ['generate_text', 'summarise_text', 'explain_text'];

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
                'local_airouter: key test threw ' . get_class($e) . ': '
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
     * Try the key with the model it would be used with, action by action.
     *
     * Asks each action the key could be used for, with the model the owner chose for it
     * or the site's where they chose none, so that a model that does not work is found
     * by its owner rather than by the next person who asks. Only the actions that take
     * a line of text are asked: generating an image costs far more than a test should,
     * and the media actions need a recording or a picture to ask anything at all.
     *
     * Nothing is recorded against the key: what a test costs is the owner's, at their
     * own provider, and it is not counted towards their limit.
     *
     * @param ai_provider $target The instance the key is for.
     * @param key $key The key.
     * @param int $contextid The context the test is made in.
     * @param int $userid The user making it.
     * @return array[] Keyed by action class: status (one of the MODEL_ constants), the
     *                 model asked for, the model that answered, the provider's status
     *                 code when it gave one, and what failed.
     */
    public function test_models(ai_provider $target, key $key, int $contextid, int $userid): array {
        $catalog = new model_catalog();
        $results = [];
        foreach ($catalog->get_actions($target) as $actionclass) {
            $injection = $this->injector->inject($target, $key, $actionclass);
            $asked = $injection->is_usable() ? $catalog->get_site_model($injection->target, $actionclass) : null;
            $result = ['status' => self::MODEL_SKIPPED, 'model' => $asked, 'answered' => null, 'code' => 0, 'error' => ''];
            if (!in_array(managed_policy::basename_for($actionclass), self::TESTABLE, true)) {
                $results[$actionclass] = $result;
                continue;
            }
            if (!$injection->is_usable()) {
                $results[$actionclass] = ['status' => self::MODEL_FAILED, 'error' => $injection->status] + $result;
                continue;
            }
            $results[$actionclass] = $this->ask_with_model($injection->target, $actionclass, $contextid, $userid) + $result;
        }

        return $results;
    }

    /**
     * Ask one action once, and say what came back.
     *
     * @param ai_provider $target The instance, carrying the key and the model.
     * @param string $actionclass The action class.
     * @param int $contextid The context.
     * @param int $userid The user.
     * @return array The status, the model that answered and what failed.
     */
    protected function ask_with_model(ai_provider $target, string $actionclass, int $contextid, int $userid): array {
        try {
            $response = $this->get_delegator()->delegate(
                $target,
                new $actionclass(contextid: $contextid, userid: $userid, prompttext: self::PROMPT),
            );
        } catch (\Throwable $e) {
            // The provider's message is the owner's to read, since it is about their own
            // key and their own choice of model, but the key is in this target's config
            // and must not be in what is shown.
            return [
                'status' => self::MODEL_FAILED,
                'error' => abstract_processor::redact_for(get_class($e) . ': ' . $e->getMessage(), $target),
            ];
        }
        if ($response->get_success()) {
            $model = $response->get_response_data()['model'] ?? null;

            return ['status' => self::MODEL_OK, 'answered' => is_string($model) && $model !== '' ? $model : null];
        }

        $message = trim((string) $response->get_errormessage());
        if ($message === '' && method_exists($response, 'get_error')) {
            $message = (string) $response->get_error();
        }
        $code = (int) $response->get_errorcode();

        return [
            'status' => in_array($code, self::REJECTED, true) ? self::MODEL_REJECTED : self::MODEL_FAILED,
            'code' => $code,
            'error' => abstract_processor::redact_for($message, $target),
        ];
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
