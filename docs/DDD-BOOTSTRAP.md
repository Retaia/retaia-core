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

## Sixième use case migré

- `POST /api/v1/auth/clients/device/cancel`
  - Application: `Application/AuthClient/CancelDeviceFlowHandler`
  - Infrastructure adapter: `Infrastructure/Auth/AuthClientGateway`
  - Controller: `Controller/Api/AuthController` (mapping HTTP conservé)

## Septième use case migré

- `POST /device`
  - Application: `Application/AuthClient/ApproveDeviceFlowHandler`
  - Infrastructure adapter: `Infrastructure/Auth/AuthClientGateway`
  - Controller: `Controller/DeviceController` (mapping HTTP conservé)

## Huitième lot (cleanup)

- `Controller/Api/AuthController` n'injecte plus `AuthClientService`
  - tous les use cases `auth/clients` sont désormais pilotés via handlers applicatifs dédiés

## Neuvième use case migré

- `POST /device` (orchestration complète approval + 2FA)
  - Application: `Application/AuthClient/CompleteDeviceApprovalHandler`
  - Ports: `Application/AuthClient/Port/DeviceApprovalSecondFactorGateway`
  - Infrastructure adapters: `Infrastructure/Auth/DeviceApprovalSecondFactorGateway` + `Infrastructure/Auth/AuthClientGateway`
  - Controller: `Controller/DeviceController` (mapping HTTP conservé)

## Règles de migration progressive

- conserver le contrat HTTP et les codes d'erreur existants
- migrer un use case à la fois
- ajouter des tests unitaires Domain/Application à chaque extraction
- garder les controllers minces (validation/mapping uniquement)
