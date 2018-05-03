Feature: checking in and checking out

  Scenario: Successful check-in
    Given the building "Phantasialand" was registered
    When "Bob" checks into "Phantasialand"
    Then "Bob" should have been checked into "Phantasialand"

  Scenario: Double check-in leads to a check-in anomaly being detected
    Given the building "Phantasialand" was registered
    And "Bob" checked into "Phantasialand"
    When "Bob" checks into "Phantasialand"
    Then "Bob" should have been checked into "Phantasialand"
    And a check-in anomaly for "Bob" was detected in "Phantasialand"
