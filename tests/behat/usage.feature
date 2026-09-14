@ai @aiprovider @aiprovider_router
Feature: Seeing what the router handled
  In order to know where my site's AI requests went and what they cost
  As an administrator
  I need a page that answers what Moodle's own record cannot

  Background:
    Given the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | mode |
      | aiprovider_router | Test router | 1       | full |
    And I log in as "admin"

  Scenario: A site that has recorded nothing says so rather than showing empty charts
    When I visit "/ai/provider/router/usage.php"
    Then I should see "AI Router usage"
    And I should see "Nothing was recorded in this period."
    And I should see "Why requests failed"
    And I should see "No request failed in this period."

  Scenario: The dashboard answers what the site spent before anything else
    When I visit "/ai/provider/router/usage.php"
    Then I should see "Paid for with"
    And the field "Paid for with" matches value "The site's own key"

  Scenario: How long detail is kept can be changed
    Given I visit "/ai/provider/router/usage.php"
    When I set the field "Days of detail to keep" to "30"
    And I click on "Save changes" "button"
    Then I should see "Saved."
    And the field "Days of detail to keep" matches value "30"

  Scenario: Keeping detail forever is offered as a number rather than a switch
    Given I visit "/ai/provider/router/usage.php"
    When I set the field "Days of detail to keep" to "0"
    And I click on "Save changes" "button"
    Then I should see "Saved."
    And the field "Days of detail to keep" matches value "0"

  Scenario: A negative retention is refused
    Given I visit "/ai/provider/router/usage.php"
    When I set the field "Days of detail to keep" to "-1"
    And I click on "Save changes" "button"
    Then I should see "Enter zero or more days."
