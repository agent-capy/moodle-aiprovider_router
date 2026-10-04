@ai @local @local_airouter
Feature: Choosing which actions are routed through the AI Router
  In order to know what a change does before I make it
  As an administrator
  I need each action to say where its requests go now, and where they would go the other way

  Background:
    Given the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | apikey |
      | aiprovider_openai | Test OpenAI | 1       | abc123 |
    And I log in as "admin"

  Scenario: A new site routes nothing, and the routing policy page says so
    When I visit "/admin/settings.php?section=local_airouter_policy"
    Then I should see "Actions routed through the AI Router now"
    And I should see "None. Requests go to AI providers in the site order"

  Scenario: Routing an action with nowhere to go asks first, and then says what stopping would do
    Given I visit "/local/airouter/managed.php"
    And I should see "Not routed through the AI Router: Moodle handles it as usual."
    And I should see "it is offered to Test OpenAI first"
    When I set the field "Generate text" to "1"
    And I press "Save changes"
    Then I should see "Save anyway?"
    And I press "Continue"
    And I should see "Requests for Generate text are being refused"
    And I should see "If it stopped being routed through the AI Router"

  Scenario: An action with a usable default delegation target is said to have a target, and no more
    Given I visit "/admin/settings.php?section=local_airouter_policy"
    And I set the field "Default delegation target" to "Test OpenAI"
    And I press "Save changes"
    When I visit "/local/airouter/managed.php"
    And I set the field "Generate text" to "1"
    And I press "Save changes"
    Then I should see "Saved which actions are routed through the AI Router."
    And I should see "A target is available"
    And I should not see "Save anyway?"
    And I visit "/admin/settings.php?section=local_airouter_policy"
    And I should see "Switching routing off hands Generate text back to Moodle"
