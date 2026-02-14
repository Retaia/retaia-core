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

## Dixième lot (ports)

- séparation des ports applicatifs `auth-client`:
  - `Application/AuthClient/Port/AuthClientGateway` (token/admin)
  - `Application/AuthClient/Port/DeviceFlowGateway` (device flow)
- objectif: réduire le couplage des handlers applicatifs à un sous-ensemble de capacités

## Onzième lot (adapters infra)

- séparation de l'adapter infra `auth-client` en deux classes:
  - `Infrastructure/Auth/AuthClientAdminGateway` pour `AuthClientGateway`
  - `Infrastructure/Auth/DeviceFlowGateway` pour `DeviceFlowGateway`
- objectif: aligner 1 adapter infra par port applicatif principal

## Douzième lot (state store)

- extraction de la persistance cache `auth-client` dans `Auth/AuthClientStateStore`
- `Auth/AuthClientService` conserve l'orchestration métier et délègue les accès état:
  - registry clients techniques
  - tokens actifs
  - device flows

## Treizième lot (device flow service)

- extraction de la logique `device flow` dans `Auth/AuthClientDeviceFlowService`
- `Auth/AuthClientService` devient façade de compatibilité pour:
  - `startDeviceFlow`
  - `pollDeviceFlow`
  - `cancelDeviceFlow`
  - `approveDeviceFlow`

## Quatorzième lot (admin/token service)

- extraction de la logique admin/token dans `Auth/AuthClientAdminService`:
  - `mintToken`, `hasClient`, `clientKind`, `revokeToken`, `rotateSecret`
  - `isMcpDisabledByAppPolicy`
- `Auth/AuthClientService` reste façade de compatibilité et délègue à:
  - `AuthClientAdminService`
  - `AuthClientDeviceFlowService`

## Quinzième lot (facade retirée)

- suppression de `Auth/AuthClientService` (façade devenue redondante)
- les adapters infra ciblent désormais directement:
  - `AuthClientAdminService`
  - `AuthClientDeviceFlowService`

## Seizième lot (policy service)

- extraction de la policy applicative `MCP gate` dans `Auth/AuthClientPolicyService`
- les gateways `AuthClientAdminGateway` et `DeviceFlowGateway` dépendent de ce service policy dédié

## Dix-septième lot (provisioning service)

- extraction du provisioning de clients techniques dans `Auth/AuthClientProvisioningService`
- `Auth/AuthClientDeviceFlowService` délègue désormais:
  - validation des `client_kind` provisionnables
  - création du `client_id` / `secret_key`
  - persistance dans la registry

## Dix-neuvième lot (password reset handlers)

- extraction des use cases `lost-password` en couche Application:
  - `RequestPasswordResetHandler`
  - `ResetPasswordHandler`
- ajout des ports:
  - `Application/Auth/Port/PasswordResetGateway`
  - `Application/Auth/Port/PasswordPolicyGateway`
- adapters infra:
  - `Infrastructure/User/PasswordResetGateway`
  - `Infrastructure/User/PasswordPolicyGateway`

## Règles de migration progressive

- conserver le contrat HTTP et les codes d'erreur existants
- migrer un use case à la fois
- ajouter des tests unitaires Domain/Application à chaque extraction
- garder les controllers minces (validation/mapping uniquement)
