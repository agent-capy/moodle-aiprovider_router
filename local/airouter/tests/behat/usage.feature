@ai @local @local_airouter
Feature: Seeing what the router handled
  In order to know where my site's AI requests went and what they cost
  As an administrator
  I need a page that answers what Moodle's own record cannot

  Background:
    Given I log in as "admin"

  Scenario: A site that has recorded nothing says so rather than showing empty charts
    When I visit "/local/airouter/usage.php"
    Then I should see "AI Router usage"
    And I should see "Nothing was recorded in this period."
    And I should see "Why requests failed"
    And I should see "No request failed in this period."

  Scenario: The dashboard answers what the site spent before anything else
    When I visit "/local/airouter/usage.php"
    Then I should see "Paid for with"
    And the field "Paid for with" matches value "The site's own key"

  Scenario: The report by person says its costs are estimates before showing any
    When I visit "/local/airouter/userusage.php"
    Then I should see "AI Router usage by person"
    And I should see "Every cost here is worked out from the rates entered for this site"
    And I should see "Who has registered a key"

  Scenario: The report by person is reached from the usage page
    Given I visit "/local/airouter/usage.php"
    When I click on "AI Router usage by person" "button"
    Then I should see "Who used the AI, how much of it, and what it cost"

  Scenario: How long detail is kept can be changed
    Given I visit "/local/airouter/usage.php"
    When I set the field "Days of detail to keep" to "30"
    And I click on "Save changes" "button"
    Then I should see "Saved."
    And the field "Days of detail to keep" matches value "30"

  Scenario: Keeping detail forever is offered as a number rather than a switch
    Given I visit "/local/airouter/usage.php"
    When I set the field "Days of detail to keep" to "0"
    And I click on "Save changes" "button"
    Then I should see "Saved."
    And the field "Days of detail to keep" matches value "0"

  Scenario: Summaries cannot be kept for less time than the detail behind them
    Given I visit "/local/airouter/usage.php"
    When I set the following fields to these values:
      | Days of detail to keep   | 90 |
      | Days of summaries to keep | 30 |
    And I click on "Save changes" "button"
    Then I should see "Keep summaries for at least as long as the detailed records"

  Scenario: A negative retention is refused
    Given I visit "/local/airouter/usage.php"
    When I set the field "Days of detail to keep" to "-1"
    And I click on "Save changes" "button"
    Then I should see "Enter zero or more days."
