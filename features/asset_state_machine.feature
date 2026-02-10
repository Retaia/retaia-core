Feature: Asset state machine transitions
  In order to enforce lifecycle invariants
  As the API
  I want forbidden transitions to be rejected

  Scenario: Allowed decision transition from DECISION_PENDING to DECIDED_KEEP
    Given an asset exists in state "DECISION_PENDING"
    When I apply decision action "KEEP"
    Then the asset state should be "DECIDED_KEEP"

  Scenario: Forbidden decision transition from PROCESSED to DECIDED_KEEP
    Given an asset exists in state "PROCESSED"
    When I apply decision action "KEEP"
    Then the decision transition should be rejected
