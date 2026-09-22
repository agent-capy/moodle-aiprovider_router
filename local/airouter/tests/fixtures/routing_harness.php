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

use local_airouter\exception\declined_request;
use core_ai\aiactions\generate_text;
use core_ai\provider as ai_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/mock/provider.php');
require_once(__DIR__ . '/mock/abstract_processor.php');
require_once(__DIR__ . '/mock/process_generate_text.php');

/**
 * Routes a request through the real rules, the real resolver and the real delegation
 * chain, against mock targets that behave as told.
 *
 * Shared by the tests that care what a request leaves behind. A history assembled
 * from anything less than the real chain would not be the history a real request
 * produces.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait routing_harness {
    /** @var int The user every routed request is made by, which is the site administrator. */
    protected static int $requester = 2;

    /**
     * A target that behaves as the given scenario says.
     *
     * @param int $id The instance id.
     * @param string $scenario One of the mock provider's scenario constants.
     * @param array $settings Extra settings for the scenario.
     * @return \aiprovider_mock\provider The target.
     */
    protected function target(int $id, string $scenario, array $settings = []): \aiprovider_mock\provider {
        return new \aiprovider_mock\provider(
            enabled: true,
            name: "Mock {$id}",
            config: json_encode(['scenario' => $scenario] + $settings),
            id: $id,
        );
    }

    /**
     * Save a rule.
     *
     * @param string $name The rule name.
     * @param int $targetid Where it delegates.
     * @param string $keysource Whose key pays.
     * @return rule The saved rule.
     */
    protected function add(string $name, int $targetid, string $keysource = rule::KEYSOURCE_SITE): rule {
        global $DB;

        $rule = new rule();
        $rule->set('name', $name);
        $rule->set('targetid', $targetid);
        $rule->set('keysource', $keysource);

        return (new rule_repository($DB))->save($rule);
    }

    /**
     * Register a key for the person the routed requests are made by.
     *
     * @param int $targetid The instance the key is for.
     * @param string $secret The key.
     * @return key The stored key.
     */
    protected function bring_key(int $targetid, string $secret = 'their-own-key'): key {
        global $DB;

        set_config(eligibility_policy::ACCESS_SETTING, eligibility_policy::ACCESS_EVERYBODY, 'local_airouter');
        eligibility_policy::purge();
        (new target_settings($DB))->set_key_field($targetid, 'apikey');

        return (new key_repository($DB))->save(key::SCOPE_USER, self::$requester, $targetid, $secret);
    }

    /**
     * A rate for a model of the mock provider.
     *
     * @param string $model The model, or empty for any model.
     * @param float $promptrate Per million prompt tokens.
     * @param float $completionrate Per million completion tokens.
     */
    protected function rate(string $model, float $promptrate, float $completionrate = 0.0): void {
        $rate = new price();
        $rate->set('provider', 'aiprovider_mock');
        $rate->set('model', $model);
        $rate->set('promptrate', $promptrate);
        $rate->set('completionrate', $completionrate);
        $rate->create();
    }

    /**
     * Route a request through the rules and the real delegation chain.
     *
     * @param ai_provider[] $instances The provider instances the site has.
     * @param array $config The router instance configuration.
     * @param \context|null $context Where the request is raised.
     * @return object The response the router produced.
     */
    protected function route(array $instances, array $config = ['defaulttarget' => 7], ?\context $context = null): object {
        global $DB;

        $router = new \aiprovider_router\provider(enabled: true, name: 'Router', config: json_encode($config), id: 1);
        $action = new generate_text(
            contextid: ($context ?? \context_system::instance())->id,
            userid: self::$requester,
            prompttext: 'Hello',
        );

        $resolver = new class ($router, $instances) extends target_resolver {
            /**
             * Constructor.
             *
             * @param provider $router The router instance.
             * @param ai_provider[] $testinstances The instances the site has.
             */
            public function __construct(
                provider $router,
                /** @var ai_provider[] The injected instances. */
                public array $testinstances,
            ) {
                parent::__construct($router);
            }

            #[\Override]
            protected function get_instances(): array {
                return $this->testinstances;
            }
        };

        $processor = new class ($router, $action, $resolver, new delegator($DB)) extends process_generate_text {
            /**
             * Constructor.
             *
             * @param provider $provider The router instance.
             * @param generate_text $action The action being processed.
             * @param target_resolver $testresolver The resolver to use.
             * @param delegator $testdelegator The delegator to use.
             */
            public function __construct(
                provider $provider,
                generate_text $action,
                /** @var target_resolver The injected resolver. */
                public target_resolver $testresolver,
                /** @var delegator The injected delegator. */
                public delegator $testdelegator,
            ) {
                parent::__construct($provider, $action);
            }

            #[\Override]
            protected function get_resolver(): target_resolver {
                return $this->testresolver;
            }

            #[\Override]
            protected function get_delegator(): delegator {
                return $this->testdelegator;
            }
        };

        return $processor->process();
    }

    /**
     * Route a request the router is expected to refuse for good.
     *
     * @param ai_provider[] $instances The provider instances the site has.
     * @param array $config The router instance configuration.
     * @return declined_request What the router threw.
     */
    protected function refused(array $instances, array $config = ['defaulttarget' => 7]): declined_request {
        try {
            $this->route($instances, $config);
        } catch (declined_request $e) {
            return $e;
        }

        $this->fail('Expected the router to stop the request rather than pass it on.');
    }
}
