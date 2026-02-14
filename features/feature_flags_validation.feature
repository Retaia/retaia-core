Feature: Feature flags payload validation
  In order to keep feature governance predictable
  As the platform
  I want invalid feature payloads to be rejected explicitly

  Scenario: Unknown app feature key is detected
    When I validate app feature payload with unknown key
    Then unknown feature keys should contain "features.unknown.flag"

  Scenario: Non-boolean app feature value is detected
    When I validate app feature payload with non-boolean value
    Then non-boolean feature keys should contain "features.ai"
