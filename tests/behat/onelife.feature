@mod @mod_onelife
Feature: Play a One Life run
  In order to revise a topic by myself
  As a student
  I need to choose a scope, play a run, and see my personal best afterwards

  # Everything is built with core's own generator steps rather than by clicking
  # through setup, so a failure here is a failure of this plugin rather than of
  # course or question bank administration.
  #
  # The question categories are anchored with "contextlevel: Course". That works on
  # every supported version: on 4.5 the category stays at the course context, and on
  # 5.0 and later core relocates it into a question bank module inside the same
  # course. Both are contexts this plugin searches, so auto-detection finds the bank
  # either way without the feature naming a qbank activity, which does not exist on
  # 4.5.
  #
  # Every question uses the one_of_four template, whose single correct answer is
  # "One". The pool is drawn at random, so all four questions carry the same text and
  # the same general feedback; that keeps the assertions deterministic whichever
  # question is served.
  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    # The bank and its topics come from a plugin step, not from core's "question
    # categories" generator. Core cannot express this pair across the supported range:
    # on 5.0+ a course-context request is relocated into a question bank module but the
    # child's parent is still validated against the course context, so the child is
    # always rejected; on 4.5 the "Activity module" alternative does not exist because
    # mod_qbank does not. Everything else, including the questions below, uses core.
    And a One Life topic bank "Biology bank" with topics "Cells,Genetics" exists in course "C1"
    And the following "questions" exist:
      | questioncategory | qtype       | template   | name | questiontext              | generalfeedback              |
      | Cells            | multichoice | one_of_four | Q1  | Which is the odd one out? | One is the odd one out here. |
      | Cells            | multichoice | one_of_four | Q2  | Which is the odd one out? | One is the odd one out here. |
      | Genetics         | multichoice | one_of_four | Q3  | Which is the odd one out? | One is the odd one out here. |
      | Genetics         | multichoice | one_of_four | Q4  | Which is the odd one out? | One is the odd one out here. |

  @javascript
  Scenario: A teacher adds a One Life activity and it saves
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I turn editing mode on
    When I add a "onelife" activity to course "Course 1" section "1" and I fill the form with:
      | One Life name | Revision sprint |
    Then I should see "Revision sprint"

  @javascript
  Scenario: A student sees the permitted modes and the topics
    Given the following "activities" exist:
      | activity | name            | course | idnumber |
      | onelife  | Revision sprint | C1     | onelife1 |
    When I am on the "Revision sprint" "onelife activity" page logged in as "student1"
    Then I should see "What would you like to practise?"
    And I should see "One topic"
    And I should see "Selected topics"
    And I should see "All topics"
    And I should see "Cells"
    And I should see "Genetics"
    And I should see "No records yet. Play a run and your best streak will appear here."

  @javascript
  Scenario: A student answers correctly and the streak increases
    Given the following "activities" exist:
      | activity | name            | course | idnumber |
      | onelife  | Revision sprint | C1     | onelife1 |
    And I am on the "Revision sprint" "onelife activity" page logged in as "student1"
    And I click on "All topics" "text"
    When I press "Start"
    Then I should see "Which is the odd one out?"
    # The streak is rendered as a numeral beside a short label, with the full
    # sentence kept for screen readers only, so it is asserted on the element
    # rather than with a bare "I should see".
    And I should see "0" in the ".onelife-play__streaknumber" "css_element"
    When I press "One"
    Then I should see "1" in the ".onelife-play__streaknumber" "css_element"
    When I press "One"
    Then I should see "2" in the ".onelife-play__streaknumber" "css_element"

  @javascript
  Scenario: A wrong answer ends the run and shows the correct answer and explanation
    Given the following "activities" exist:
      | activity | name            | course | idnumber |
      | onelife  | Revision sprint | C1     | onelife1 |
    And I am on the "Revision sprint" "onelife activity" page logged in as "student1"
    And I click on "All topics" "text"
    And I press "Start"
    And I press "One"
    When I press "Two"
    Then I should see "Run over"
    And I should see "1" in the ".onelife-summary__hero" "css_element"
    And I should see "The correct answer was"
    And I should see "One" in the ".onelife-summary__correctanswer" "css_element"
    And I should see "One is the odd one out here."

  @javascript
  Scenario: The personal record appears on the picker after a run
    Given the following "activities" exist:
      | activity | name            | course | idnumber |
      | onelife  | Revision sprint | C1     | onelife1 |
    And I am on the "Revision sprint" "onelife activity" page logged in as "student1"
    And I click on "All topics" "text"
    And I press "Start"
    And I press "One"
    And I press "Two"
    And I should see "Run over"
    When I follow "Back to the activity"
    Then I should see "Your personal records"
    And I should not see "No records yet. Play a run and your best streak will appear here."
    And I should see "All topics" in the ".onelife-records__table" "css_element"
    And I should see "1" in the ".onelife-records__best" "css_element"
