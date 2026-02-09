Feature: Basic user authentication and password reset
  In order to secure access to the API
  As a user
  I want to login and reset my password

  Scenario: Login succeeds with valid credentials
    Given a bootstrap user exists
    When I login with email "admin@retaia.local" and password "change-me"
    Then authentication should succeed

  Scenario: Lost password reset updates credentials
    Given a bootstrap user exists
    When I request a password reset for "admin@retaia.local"
    And I reset the password to "New-password1!" using the reset token
    Then I can login with email "admin@retaia.local" and password "New-password1!"
