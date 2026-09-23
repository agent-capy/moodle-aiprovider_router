@ai @local @local_airouter
Feature: Registering a key of my own
  In order to have my AI requests charged to me rather than to the site
  As somebody the site allows to bring a key
  I need a page of my own that works whether or not I am in a course

  Background:
    Given the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | mode |
      | aiprovider_router | Test router | 1       | full |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
    And the following "courses" exist:
      | fullname     | shortname |
      | Test course  | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following config values are set as admin:
      | byokaccess | everybody | local_airouter |

  # The page is reached outside any course, so nothing sets a context for it on the
  # way in. Asking for the heading before setting one produced a developer warning on
  # every visit, which is the sort of thing a screenshot shows and a unit test does not.
  Scenario: My own key page opens outside any course
    Given I log in as "teacher1"
    When I visit "/local/airouter/keys.php"
    Then I should see "My AI keys"
    And I should see "No key is registered yet."

  Scenario: A site that allows nobody to bring one says so rather than failing
    Given the following config values are set as admin:
      | byokaccess | nobody | local_airouter |
    And I log in as "teacher1"
    When I visit "/local/airouter/keys.php"
    Then I should see "My AI keys"
    And I should see "This site does not currently allow you to bring your own key."

  Scenario: A key is registered and shown by its last characters only
    Given the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | apikey |
      | aiprovider_openai | Test OpenAI | 1       | abc123 |
    And the following "local_airouter > target settings" exist:
      | target      | keyfield |
      | Test OpenAI | apikey   |
    And I log in as "teacher1"
    And I visit "/local/airouter/keys.php"
    When I set the following fields to these values:
      | Provider | Test OpenAI      |
      | Key      | sk-test-key-abcd |
    And I click on "Save key" "button"
    Then I should see "The key has been saved."
    And I should see "abcd"
    And I should not see "sk-test-key-abcd"
    And I should see "Replace"

  Scenario: Replacing a key asks whether it is for the same account, and does not choose for me
    Given the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | apikey |
      | aiprovider_openai | Test OpenAI | 1       | abc123 |
    And the following "local_airouter > target settings" exist:
      | target      | keyfield |
      | Test OpenAI | apikey   |
    And the following "local_airouter > keys" exist:
      | user     | target      | secret            |
      | teacher1 | Test OpenAI | sk-first-key-aaaa |
    And I log in as "teacher1"
    And I visit "/local/airouter/keys.php"
    When I click on "Replace" "link"
    Then I should see "Replace the key for Test OpenAI"
    And I should see "Is the new key for the same account as the current one?"
    When I set the field "Key" to "sk-second-key-bbbb"
    And I click on "Save key" "button"
    Then I should see "Choose one."
    When I set the field "Key" to "sk-second-key-bbbb"
    And I set the field "No, another account" to "1"
    And I click on "Save key" "button"
    Then I should see "The key has been replaced."
    And I should see "bbbb"
    And I should not see "aaaa"

  Scenario: The same key registered again after being removed is recognised and asked nothing
    Given the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | apikey |
      | aiprovider_openai | Test OpenAI | 1       | abc123 |
    And the following "local_airouter > target settings" exist:
      | target      | keyfield |
      | Test OpenAI | apikey   |
    And the following "local_airouter > keys" exist:
      | user     | target      | secret            |
      | teacher1 | Test OpenAI | sk-first-key-aaaa |
    And I log in as "teacher1"
    And I visit "/local/airouter/keys.php"
    When I click on "Delete" "link"
    And I click on "Continue" "button"
    Then I should see "The key has been removed."
    When I set the following fields to these values:
      | Provider | Test OpenAI       |
      | Key      | sk-first-key-aaaa |
    And I click on "Save key" "button"
    Then I should see "The key has been saved."
    And I should see "aaaa"

  Scenario: Another key registered after one was removed is asked whether it is the same account
    Given the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | apikey |
      | aiprovider_openai | Test OpenAI | 1       | abc123 |
    And the following "local_airouter > target settings" exist:
      | target      | keyfield |
      | Test OpenAI | apikey   |
    And the following "local_airouter > keys" exist:
      | user     | target      | secret            |
      | teacher1 | Test OpenAI | sk-first-key-aaaa |
    And I log in as "teacher1"
    And I visit "/local/airouter/keys.php"
    And I click on "Delete" "link"
    And I click on "Continue" "button"
    When I set the following fields to these values:
      | Provider | Test OpenAI        |
      | Key      | sk-second-key-bbbb |
    And I click on "Save key" "button"
    Then I should see "Is this key for an account you had here before?"
    And I should see "aaaa"
    When I set the field "Key" to "sk-second-key-bbbb"
    And I set the field "No, another account" to "1"
    And I click on "Save key" "button"
    Then I should see "The key has been saved."
    And I should see "bbbb"
