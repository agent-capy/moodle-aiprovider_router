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

use core_ai\aiactions\generate_image;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/mock/provider.php');
require_once(__DIR__ . '/fixtures/modelled/provider.php');
require_once(__DIR__ . '/fixtures/modelled/helper.php');
require_once(__DIR__ . '/fixtures/modelled/model.php');

/**
 * Tests for reading which model an instance uses, and which others it could use.
 *
 * The models are read the way the provider's own settings page reads them, so the tests
 * use a provider that lists its models in each of the two shapes the router knows, one
 * of them core's own OpenAI provider, and one that lists nothing.
 *
 * @package    local_airouter
 * @copyright  2026 UDAGAWA Mitsuru
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(model_catalog::class)]
final class model_catalog_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The fixture provider, with these settings for its actions.
     *
     * @param array $actionconfig Keyed by action class.
     * @return \aiprovider_modelled\provider The instance.
     */
    protected function modelled(array $actionconfig): \aiprovider_modelled\provider {
        return new \aiprovider_modelled\provider(
            enabled: true,
            name: 'Modelled',
            config: json_encode(['apikey' => 'site']),
            actionconfig: json_encode($actionconfig),
            id: 4,
        );
    }

    /**
     * Settings for one action that names a model.
     *
     * @param string $model The model.
     * @param array $extra Any other settings.
     * @return array The action's configuration.
     */
    protected static function action(string $model, array $extra = []): array {
        return ['enabled' => true, 'settings' => ['model' => $model] + $extra];
    }

    public function test_the_site_model_and_the_choices_come_from_the_provider(): void {
        $target = $this->modelled([
            generate_text::class => self::action('alpha'),
            generate_image::class => self::action('pictor'),
        ]);
        $catalog = new model_catalog();

        $this->assertSame('alpha', $catalog->get_site_model($target, generate_text::class));
        $this->assertTrue($catalog->can_choose($target, generate_text::class));
        // Text models for a text action; the entry a settings page uses for a typed
        // name is not a model, and is not offered as one.
        $this->assertSame(['alpha' => 'Alpha', 'beta' => 'Beta'], $catalog->get_options($target, generate_text::class));
        $this->assertSame(['pictor' => 'Pictor'], $catalog->get_options($target, generate_image::class));
    }

    public function test_a_site_model_the_provider_does_not_list_offers_the_kind_the_action_needs(): void {
        $target = $this->modelled([generate_text::class => self::action('a-model-typed-in')]);

        $options = (new model_catalog())->get_options($target, generate_text::class);

        $this->assertSame(['alpha', 'beta'], array_keys($options));
    }

    public function test_a_provider_that_lists_no_models_offers_no_choice(): void {
        $target = new \aiprovider_mock\provider(
            enabled: true,
            name: 'Mock',
            config: json_encode(['apikey' => 'site']),
            actionconfig: json_encode([generate_text::class => self::action('whatever')]),
            id: 3,
        );
        $catalog = new model_catalog();

        // Its model is still said; only the choice is withheld.
        $this->assertSame('whatever', $catalog->get_site_model($target, generate_text::class));
        $this->assertFalse($catalog->can_choose($target, generate_text::class));
        $this->assertSame([], $catalog->get_options($target, generate_text::class));
    }

    public function test_an_action_whose_settings_name_no_model_offers_no_choice(): void {
        // What Azure OpenAI looks like: the deployment decides, not a model name.
        $target = $this->modelled([generate_text::class => ['enabled' => true, 'settings' => ['deployment' => 'mine']]]);
        $catalog = new model_catalog();

        $this->assertNull($catalog->get_site_model($target, generate_text::class));
        $this->assertFalse($catalog->can_choose($target, generate_text::class));
    }

    public function test_core_openai_offers_its_models_by_kind(): void {
        if (!class_exists(\aiprovider_openai\provider::class)) {
            $this->markTestSkipped('Core OpenAI provider is not installed.');
        }
        $target = new \aiprovider_openai\provider(
            enabled: true,
            name: 'OpenAI',
            config: json_encode(['apikey' => 'site']),
            actionconfig: json_encode([
                generate_text::class => self::action('gpt-4o'),
                generate_image::class => self::action('dall-e-3'),
            ]),
            id: 5,
        );
        $catalog = new model_catalog();

        $text = $catalog->get_options($target, generate_text::class);
        $image = $catalog->get_options($target, generate_image::class);

        $this->assertArrayHasKey('gpt-4o', $text);
        $this->assertArrayNotHasKey('dall-e-3', $text);
        $this->assertArrayHasKey('dall-e-3', $image);
        $this->assertArrayNotHasKey('gpt-4o', $image);
    }

    public function test_another_model_drops_what_was_tuned_for_the_site_model(): void {
        $target = $this->modelled([
            generate_text::class => self::action('alpha', [
                'systeminstruction' => 'Be brief.',
                'temperature' => 0.2,
                'top_k' => 5,
                'modelextraparams' => '{"seed": 1}',
            ]),
        ]);

        $asked = (new model_catalog())->apply($target, generate_text::class, 'beta');

        $settings = $asked->actionconfig[generate_text::class]['settings'];
        $this->assertSame('beta', $settings['model']);
        // What the site said about its own model goes; what it said about the action stays.
        $this->assertArrayNotHasKey('temperature', $settings);
        $this->assertArrayNotHasKey('top_k', $settings);
        $this->assertArrayNotHasKey('modelextraparams', $settings);
        $this->assertSame('Be brief.', $settings['systeminstruction']);
        // A copy: the instance the site configured is left as it was.
        $this->assertSame('alpha', $target->actionconfig[generate_text::class]['settings']['model']);
        $this->assertSame((int) $target->id, (int) $asked->id);
    }

    public function test_settings_saved_for_the_model_asked_for_are_used(): void {
        // Where core keeps them: beside the action's settings, not inside them
        // (ai/configure_actions.php, Moodle 5.1 on). Review R13-01: the first version
        // read them from inside, and this test built that wrong shape itself.
        $target = $this->modelled([
            generate_text::class => self::action('alpha', ['temperature' => 0.2]) + [
                'modelsettings' => ['beta' => ['temperature' => 0.9]],
            ],
        ]);

        $asked = (new model_catalog())->apply($target, generate_text::class, 'beta');

        $this->assertSame(0.9, $asked->actionconfig[generate_text::class]['settings']['temperature']);
    }

    /**
     * The body core's OpenAI provider would send for generating text with this instance.
     *
     * @param \core_ai\provider $target The instance.
     * @return array The decoded request body.
     */
    protected function openai_body(\core_ai\provider $target): array {
        $action = new generate_text(contextid: (int) \context_system::instance()->id, userid: 2, prompttext: 'Hi');
        $processor = new \aiprovider_openai\process_generate_text($target, $action);
        $request = (new \ReflectionMethod($processor, 'create_request_object'))->invoke($processor, 'u2');

        return json_decode((string) $request->getBody(), true);
    }

    public function test_core_openai_sends_the_chosen_models_settings_and_not_the_site_models(): void {
        if (!class_exists(\aiprovider_openai\process_generate_text::class)) {
            $this->markTestSkipped('Core OpenAI provider is not installed.');
        }
        $target = new \aiprovider_openai\provider(
            enabled: true,
            name: 'OpenAI',
            config: json_encode(['apikey' => 'site']),
            actionconfig: json_encode([
                generate_text::class => self::action('gpt-4o', [
                    'systeminstruction' => 'Be brief.',
                    'frequency_penalty' => 0.3,
                    'max_completion_tokens' => 99,
                ]) + ['modelsettings' => ['o1' => ['max_completion_tokens' => 17, 'top_p' => 0.8]]],
            ]),
            id: 5,
        );

        $body = $this->openai_body((new model_catalog())->apply($target, generate_text::class, 'o1'));

        // Asked the way the provider's own settings form asks, so that Moodle 5.0, whose
        // models only add form fields, loses the site model's settings as 5.3 does
        // (review R13-02), and what was saved for o1 reaches the request (R13-01).
        $this->assertSame('o1', $body['model']);
        $this->assertArrayNotHasKey('frequency_penalty', $body);
        $this->assertSame(17, $body['max_completion_tokens']);
        $this->assertSame(0.8, $body['top_p']);
        $this->assertStringContainsString('Be brief.', json_encode($body['messages']));
        // The site's instance is untouched.
        $this->assertSame(0.3, $target->actionconfig[generate_text::class]['settings']['frequency_penalty']);
    }

    public function test_a_provider_whose_models_only_add_form_fields_loses_the_site_models_settings(): void {
        if (!class_exists(\aiprovider_sakuraaiengine\provider::class)) {
            $this->markTestSkipped('The Sakura AI Engine provider is not installed.');
        }
        $target = new \aiprovider_sakuraaiengine\provider(
            enabled: true,
            name: 'Sakura',
            config: json_encode(['account_token' => 'site']),
            actionconfig: json_encode([
                generate_text::class => self::action('gpt-oss-120b', [
                    'systeminstruction' => 'Be brief.',
                    'endpoint' => 'https://api.example.invalid/v1/chat/completions',
                    'max_tokens' => 99,
                    'temperature' => 0.3,
                ]),
            ]),
            id: 6,
        );

        $asked = (new model_catalog())->apply($target, generate_text::class, 'llm-jp-3.1-8x13b-instruct4');

        $settings = $asked->actionconfig[generate_text::class]['settings'];
        $this->assertSame('llm-jp-3.1-8x13b-instruct4', $settings['model']);
        $this->assertArrayNotHasKey('max_tokens', $settings);
        $this->assertArrayNotHasKey('temperature', $settings);
        // What describes the action, not the model, stays.
        $this->assertSame('Be brief.', $settings['systeminstruction']);
        $this->assertSame('https://api.example.invalid/v1/chat/completions', $settings['endpoint']);
    }

    public function test_the_site_model_leaves_the_instance_as_it_is(): void {
        $target = $this->modelled([generate_text::class => self::action('alpha', ['temperature' => 0.2])]);

        $this->assertSame($target, (new model_catalog())->apply($target, generate_text::class, 'alpha'));
    }

    public function test_only_actions_the_router_answers_and_the_instance_enables_are_listed(): void {
        managed_policy::set_managed_actions([generate_text::class, summarise_text::class]);
        $target = $this->modelled([
            generate_text::class => self::action('alpha'),
            summarise_text::class => ['enabled' => false, 'settings' => ['model' => 'alpha']],
            generate_image::class => self::action('pictor'),
        ]);

        // Summarising is switched off at the instance, and images are left to core, which
        // calls the instance with the site's own key.
        $this->assertSame([generate_text::class], (new model_catalog())->get_actions($target));
    }

    /**
     * Names of models as somebody might type them.
     *
     * @return array[] The name, and whether it is accepted.
     */
    public static function names_provider(): array {
        return [
            'plain' => ['claude-opus-5', true],
            'a tag' => ['gpt-oss:120b', true],
            'a path' => ['preview/Qwen3-VL-30B-A3B-Instruct', true],
            'dotted' => ['us.anthropic.claude-sonnet-4-5-20250929-v1:0', true],
            'a space' => ['two words', false],
            'quotes' => ["x'; drop table", false],
            'empty' => ['', false],
            'too long' => [str_repeat('a', 101), false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('names_provider')]
    public function test_a_typed_name_is_sent_only_when_it_can_be_a_model_name(string $name, bool $accepted): void {
        $this->assertSame($accepted, model_catalog::clean($name) !== null);
    }
}
