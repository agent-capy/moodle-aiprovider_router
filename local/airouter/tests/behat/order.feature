@ai @local @local_airouter
Feature: Keeping the AI Router first in the provider order
  In order for the AI Router to be asked at all
  As an administrator
  I need to see where the router sits in the provider order and be able to move it

  Background:
    Given the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | apikey |
      | aiprovider_openai | Test OpenAI | 1       | abc123 |
    And the following "core_ai > ai providers" exist:
      | provider          | name        | enabled | mode |
      | aiprovider_router | Test router | 1       | full |
    And I log in as "admin"

  Scenario: The page says the router is not reached and offers to move it
    When I visit "/local/airouter/order.php"
    Then I should see "AI Router position in the provider order"
    And I should see "The AI Router is not the first provider Moodle tries"
    And I should see "Test OpenAI"
    And I should see "Move the AI Router to the front"

  Scenario: The change is shown before it is made
    Given I visit "/local/airouter/order.php"
    When I click on "Move the AI Router to the front" "button"
    Then I should see "Move the AI Router to the front?"
    And I should see "Now"
    And I should see "After the change"
    And I should see "This AI Router: Test router"

  Scenario: Moving the router to the front satisfies every check
    Given I visit "/local/airouter/order.php"
    And I click on "Move the AI Router to the front" "button"
    When I click on "Apply the change" "button"
    Then I should see "The provider order has been updated."
    And I should see "The AI Router is the first provider Moodle tries."
    And I should see "The provider order needs no changes."

  Scenario: Backing out of the confirmation changes nothing
    Given I visit "/local/airouter/order.php"
    And I click on "Move the AI Router to the front" "button"
    When I click on "Cancel" "button"
    Then I should see "The AI Router is not the first provider Moodle tries"
    And I should see "Move the AI Router to the front"

  Scenario: Entries left behind by deleted instances are reported and can be removed
    Given the following config values are set as admin:
      | provider_order | ,1,4321,2 | core_ai |
    When I visit "/local/airouter/order.php"
    Then I should see "Instance 4321, which no longer exists"
    And I should see "Leftover entries in the provider order"
    When I click on "Remove the leftover entries" "button"
    And I click on "Apply the change" "button"
    Then I should see "The provider order has been updated."
    And I should not see "Instance 4321, which no longer exists"

  Scenario: A second router instance is reported, saying which one to keep
    Given the following "core_ai > ai providers" exist:
      | provider          | name          | enabled | mode    |
      | aiprovider_router | Second router | 0       | coexist |
    When I visit "/local/airouter/order.php"
    Then I should see "Number of AI Router instances"
    And I should see "This site has 2 AI Router instances."
    And I should see "Keep: Test router"
    And I should see "Delete: Second router"
