@ai @aiprovider @aiprovider_router
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
      | byokaccess | everybody | aiprovider_router |

  # The page is reached outside any course, so nothing sets a context for it on the
  # way in. Asking for the heading before setting one produced a developer warning on
  # every visit, which is the sort of thing a screenshot shows and a unit test does not.
  Scenario: My own key page opens outside any course
    Given I log in as "teacher1"
    When I visit "/ai/provider/router/keys.php"
    Then I should see "My AI keys"
    And I should see "No key is registered yet."

  Scenario: A site that allows nobody to bring one says so rather than failing
    Given the following config values are set as admin:
      | byokaccess | nobody | aiprovider_router |
    And I log in as "teacher1"
    When I visit "/ai/provider/router/keys.php"
    Then I should see "My AI keys"
    And I should see "This site does not currently allow you to bring your own key."
