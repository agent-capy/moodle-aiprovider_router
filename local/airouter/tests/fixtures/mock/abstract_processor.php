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

namespace aiprovider_mock;

/**
 * Produces the response the chosen scenario calls for.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_processor extends \core_ai\process_base {
    /**
     * The content key this action answers with, if it has one.
     *
     * @return string|null The key, or null when the action returns no text.
     */
    abstract protected function get_content_key(): ?string;

    #[\Override]
    protected function query_ai_api(): array {
        /** @var provider $mock */
        $mock = $this->provider;

        if ($delay = (int) $mock->get_setting('delay', 0)) {
            // Milliseconds, so that a slow target can be represented without a slow suite.
            usleep($delay * 1000);
        }

        switch ($mock->get_scenario()) {
            case provider::EXCEPTION:
                // What an unreachable endpoint really does. Guzzle's ConnectException
                // extends TransferException rather than RequestException, so the catch
                // in core's own providers does not cover it and it escapes query_ai_api().
                throw new \RuntimeException((string) $mock->get_setting('message', 'Connection refused'));

            case provider::FAILURE:
                return [
                    'success' => false,
                    'errorcode' => (int) $mock->get_setting('errorcode', 500),
                    'error' => (string) $mock->get_setting('error', 'serverfailure'),
                    'errormessage' => (string) $mock->get_setting('errormessage', 'Upstream failed'),
                ];

            case provider::MALFORMED:
                return ['success' => true];

            case provider::EMPTY_CONTENT:
                return $this->body('', 'stop');

            case provider::TRUNCATED:
                return $this->body('', (string) $mock->get_setting('finishreason', 'length'));

            default:
                return $this->body((string) $mock->get_setting('content', 'Mock answer'), 'stop');
        }
    }

    /**
     * A successful response body shaped the way the core providers shape theirs.
     *
     * @param string $content The generated content.
     * @param string $finishreason Why the generation stopped.
     * @return array The response data.
     */
    protected function body(string $content, string $finishreason): array {
        $data = [
            'success' => true,
            'model' => (string) $this->provider->get_setting('model', 'mock-1'),
            'finishreason' => $finishreason,
        ];
        // A target that does not say what it used, which some do not. Left out rather
        // than set to null, because that is what a provider that omits them produces.
        if ($this->provider->get_setting('reportusage', true)) {
            $data['prompttokens'] = (int) $this->provider->get_setting('prompttokens', 11);
            $data['completiontokens'] = (int) $this->provider->get_setting('completiontokens', 22);
        }
        if ($key = $this->get_content_key()) {
            $data[$key] = $content;
        }

        return $data;
    }
}
