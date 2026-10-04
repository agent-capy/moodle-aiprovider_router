@ai @local @local_airouter
Feature: Seeing where the site stands before setting the AI Router up
  In order to know what to do next, and what my purpose does not need
  As an administrator
  I need a setup page, a rule tester that starts before the rules, and a keys page that says where each provider stands

  Background:
    Given the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | apikey |
      | aiprovider_openai | Test OpenAI | 1       | abc123 |
    And I log in as "admin"

  Scenario: The setup page says what to do first and reads nothing as done that is not
    When I visit "/local/airouter/setup.php"
    Then I should see "Setup status"
    And I should see "Actions routed through the AI Router"
    And I should see "To do"
    And I should see "Seeing a request go through"
    And I should see "To check"
    And I should see "Status checks"

  Scenario: A course key does not wait on the policy for personal keys
    When I visit "/local/airouter/setup.php?purpose=byokcourse"
    Then I should see "For this purpose: A course pays with its own key"
    And I should see "Not needed for this purpose"
    And I should see "Not needed for course keys"

  Scenario: The rule tester says when a request would not reach the rules at all
    When I visit "/local/airouter/ruletest.php"
    And I set the field "Prompt" to "Please summarise this"
    And I click on "Test" "button"
    Then I should see "Before the rules"
    And I should see "Not routed through the AI Router: Moodle handles it as usual."
    And I should see "The rules below are shown for reference"
    And I should see "Choose a person and a placement to see whether they would be offered the button."

  Scenario: The keys page says where each provider stands before anything is set
    When I visit "/local/airouter/byok.php"
    Then I should see "Where each provider stands"
    And I should see "Personal keys: nobody may bring one yet."
    And I should see "A course key does not depend on the policy below."
    And I should see "Cannot take a brought key yet: nobody has said where one goes."
