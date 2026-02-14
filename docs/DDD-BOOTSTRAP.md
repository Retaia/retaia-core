# DDD Bootstrap (non normatif)

## Objectif

Poser un premier découpage DDD sans changer le contrat API v1.

## Couches introduites

- `src/Domain/*`: règles métier pures, sans dépendance framework
- `src/Application/*`: orchestration de use cases
- `src/Controller/*`: adaptateurs HTTP (mapping request/response)

## Premier use case migré

- `GET /api/v1/app/policy`
  - Domain: `Domain/AppPolicy/FeatureFlagsContractPolicy`
  - Application: `Application/AppPolicy/GetAppPolicyHandler`
  - Controller: `Controller/Api/AppController` (mapping HTTP conservé)

## Deuxième use case migré

- `POST /api/v1/auth/clients/token`
  - Domain: `Domain/AuthClient/TechnicalClientTokenPolicy`
  - Application: `Application/AuthClient/MintClientTokenHandler`
  - Infrastructure adapter: `Infrastructure/Auth/AuthClientGateway`
  - Controller: `Controller/Api/AuthController` (mapping HTTP conservé)

## Troisième use case migré

- `POST /api/v1/auth/clients/{client_id}/revoke-token`
  - Domain: `Domain/AuthClient/TechnicalClientAdminPolicy`
  - Application: `Application/AuthClient/RevokeClientTokenHandler`
  - Infrastructure adapter: `Infrastructure/Auth/AuthClientGateway`
  - Controller: `Controller/Api/AuthController` (mapping HTTP conservé)
- `POST /api/v1/auth/clients/{client_id}/rotate-secret`
  - Application: `Application/AuthClient/RotateClientSecretHandler`
  - Infrastructure adapter: `Infrastructure/Auth/AuthClientGateway`
  - Controller: `Controller/Api/AuthController` (mapping HTTP conservé)

## Quatrième use case migré

- `POST /api/v1/auth/clients/device/start`
  - Domain: `Domain/AuthClient/TechnicalClientTokenPolicy` (règles actor/scope)
  - Application: `Application/AuthClient/StartDeviceFlowHandler`
  - Infrastructure adapter: `Infrastructure/Auth/AuthClientGateway`
  - Controller: `Controller/Api/AuthController` (mapping HTTP conservé)

## Cinquième use case migré

- `POST /api/v1/auth/clients/device/poll`
  - Application: `Application/AuthClient/PollDeviceFlowHandler`
  - Infrastructure adapter: `Infrastructure/Auth/AuthClientGateway`
  - Controller: `Controller/Api/AuthController` (mapping HTTP conservé)

## Règles de migration progressive

- conserver le contrat HTTP et les codes d'erreur existants
- migrer un use case à la fois
- ajouter des tests unitaires Domain/Application à chaque extraction
- garder les controllers minces (validation/mapping uniquement)
