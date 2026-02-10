Feature: Job claim locking
  In order to avoid concurrent processing conflicts
  As the scheduler
  I want only one agent to claim a pending job

  Scenario: Concurrent claim has a single winner
    Given a pending job exists with id "job-1"
    When agent "agent-a" claims job "job-1"
    And agent "agent-b" claims job "job-1"
    Then exactly one claim should succeed
