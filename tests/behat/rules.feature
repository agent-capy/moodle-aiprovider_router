@ai @aiprovider @aiprovider_router
Feature: Routing requests by rule
  In order to send different requests to different AI providers
  As an administrator
  I need to write rules, put them in order and see which one would claim a request

  Background:
    Given the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | apikey |
      | aiprovider_openai | Test OpenAI | 1       | abc123 |
    And the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | mode |
      | aiprovider_router | Test router | 1       | full |
    And the following "courses" exist:
      | fullname    | shortname |
      | Biology 101 | BIO101    |
    And I log in as "admin"

  Scenario: A site with no rules says what happens instead
    When I visit "/ai/provider/router/rules.php"
    Then I should see "AI Router rules"
    And I should see "No rules yet"

  Scenario: A rule is created and appears in the list
    Given I visit "/ai/provider/router/rules.php"
    When I click on "Add a rule" "button"
    And I set the following fields to these values:
      | Rule name     | Long prompts |
      | Delegate to   | Test OpenAI  |
    And I set the field "promptlengthcharacters" to "2000"
    And I click on "Save changes" "button"
    Then I should see "Rule \"Long prompts\" has been saved."
    And I should see "Long prompts"
    And I should see "at least 2000 characters"
    And I should see "Test OpenAI"

  Scenario: A rule with no conditions is marked as taking everything
    Given I visit "/ai/provider/router/rule.php"
    When I set the following fields to these values:
      | Rule name   | Everything  |
      | Delegate to | Test OpenAI |
    And I click on "Save changes" "button"
    Then I should see "No conditions, so this rule takes every request that reaches it."

  Scenario: A rule below one that takes everything is reported as unreachable
    Given I visit "/ai/provider/router/rule.php"
    And I set the following fields to these values:
      | Rule name   | Everything  |
      | Delegate to | Test OpenAI |
    And I click on "Save changes" "button"
    And I visit "/ai/provider/router/rule.php"
    And I set the following fields to these values:
      | Rule name   | Never runs  |
      | Delegate to | Test OpenAI |
    And I click on "Save changes" "button"
    When I visit "/ai/provider/router/rules.php"
    Then I should see "Never reached: a rule above this one takes every request."

  Scenario: A rule paid for with a brought key says so, and warns when there is nowhere to put one
    Given I visit "/ai/provider/router/rule.php"
    When I set the following fields to these values:
      | Rule name     | Teachers pay for their own          |
      | Delegate to   | Test OpenAI                         |
      | Paid for with | A key the person asking has brought |
    And I click on "Save changes" "button"
    Then I should see "Paid for with: A key the person asking has brought"
    And I should see "nobody has said which field of this provider's configuration a key goes in"

  Scenario: A rule cannot be saved without a delegation target
    Given I visit "/ai/provider/router/rule.php"
    When I set the field "Rule name" to "No target"
    And I click on "Save changes" "button"
    Then I should see "Choose the provider instance this rule delegates to."

  Scenario: The rule tester says which rule would claim a request
    Given I visit "/ai/provider/router/rule.php"
    And I set the following fields to these values:
      | Rule name   | Everything  |
      | Delegate to | Test OpenAI |
    And I click on "Save changes" "button"
    When I visit "/ai/provider/router/ruletest.php"
    And I set the field "Prompt" to "Please summarise this"
    And I click on "Test" "button"
    Then I should see "The rule \"Everything\" would claim this request, and it would go to Test OpenAI."
    And I should see "21 characters. This is what prompt length conditions compare against."
    And I should see "An estimate, shown as a guide when choosing a threshold"

  Scenario: The rule tester says when nothing would claim a request
    Given I visit "/ai/provider/router/ruletest.php"
    When I set the field "Prompt" to "Please summarise this"
    And I click on "Test" "button"
    Then I should see "No rule would claim this request."
