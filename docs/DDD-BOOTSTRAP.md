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

## Dix-huitième lot (email verification handlers)

- extraction des use cases `verify-email` en couche Application:
  - `RequestEmailVerificationHandler`
  - `ConfirmEmailVerificationHandler`
  - `AdminConfirmEmailVerificationHandler`
- ajout du port `Application/Auth/Port/EmailVerificationGateway`
- adapter infra `Infrastructure/User/EmailVerificationGateway` branché sur `User/Service/EmailVerificationService`

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

## Vingtième lot (two-factor handlers)

- extraction des use cases `2fa` en couche Application:
  - `SetupTwoFactorHandler`
  - `EnableTwoFactorHandler`
  - `DisableTwoFactorHandler`
- ajout du port:
  - `Application/Auth/Port/TwoFactorGateway`
- adapter infra:
  - `Infrastructure/User/TwoFactorGateway`

## Vingt-et-unième lot (my features handlers)

- extraction des use cases `/auth/me/features` en couche Application:
  - `GetMyFeaturesHandler`
  - `PatchMyFeaturesHandler`
- ajout du port:
  - `Application/Auth/Port/FeatureGovernanceGateway`
- adapter infra:
  - `Infrastructure/Feature/FeatureGovernanceGateway`

## Vingt-deuxième lot (auth me handler)

- extraction du use case `/auth/me` en couche Application:
  - `GetAuthMeProfileHandler`
- objectif: centraliser la composition du payload profil auth dans un handler dédié

## Vingt-troisième lot (admin actor handler)

- extraction de la résolution d'acteur admin pour `verify-email/admin-confirm`:
  - `ResolveAdminActorHandler`
- ajout du port:
  - `Application/Auth/Port/AdminActorGateway`
- adapter infra:
  - `Infrastructure/Auth/AdminActorGateway`

## Vingt-quatrième lot (admin actor reuse)

- réutilisation de `ResolveAdminActorHandler` sur les endpoints admin clients:
  - `/auth/clients/{clientId}/revoke-token`
  - `/auth/clients/{clientId}/rotate-secret`
- objectif: unifier la résolution d'acteur admin dans le controller auth

## Vingt-cinquième lot (authenticated user handler)

- extraction de la résolution d'utilisateur authentifié en couche Application:
  - `ResolveAuthenticatedUserHandler`
- ajout du port:
  - `Application/Auth/Port/AuthenticatedUserGateway`
- adapter infra:
  - `Infrastructure/Auth/AuthenticatedUserGateway`
- réutilisation dans `AuthController` pour les endpoints nécessitant auth user

## Vingt-sixième lot (auth controller cleanup)

- suppression de la dépendance directe résiduelle à `User/Service/EmailVerificationService` dans `AuthController`
- `AuthController` passe entièrement par handlers applicatifs pour les use cases `verify-email`

## Vingt-septième lot (app features handlers)

- extraction des use cases `/app/features` en couche Application:
  - `GetAppFeaturesHandler`
  - `PatchAppFeaturesHandler`
- ajout du port:
  - `Application/AppPolicy/Port/AppFeatureGovernanceGateway`
- adapter infra:
  - `Infrastructure/AppPolicy/AppFeatureGovernanceGateway`
- `AppController` réutilise aussi:
  - `ResolveAuthenticatedUserHandler`
  - `ResolveAdminActorHandler`

## Vingt-huitième lot (agent register handler)

- extraction du use case `/agents/register` en couche Application:
  - `RegisterAgentHandler`
- réutilisation du domain service:
  - `Domain/AppPolicy/FeatureFlagsContractPolicy`
- `AgentController` réutilise:
  - `ResolveAuthenticatedUserHandler`
- objectif: isoler la policy de registration agent (contract-version + server policy payload) du contrôleur HTTP

## Vingt-neuvième lot (derived controller handlers)

- extraction des use cases `/assets/{uuid}/derived` en couche Application:
  - `InitDerivedUploadHandler`
  - `UploadDerivedPartHandler`
  - `CompleteDerivedUploadHandler`
  - `ListDerivedFilesHandler`
  - `GetDerivedByKindHandler`
- ajout du port:
  - `Application/Derived/Port/DerivedGateway`
- adapter infra:
  - `Infrastructure/Derived/DerivedGateway`
- extraction de la résolution d'acteur agent:
  - `ResolveAgentActorHandler`
  - `Application/Auth/Port/AgentActorGateway`
  - `Infrastructure/Auth/AgentActorGateway`

## Trentième lot (workflow controller handlers)

- extraction des use cases `/batches/*`, `/decisions/*`, `/assets/{uuid}/purge*` en couche Application:
  - `PreviewMovesHandler`
  - `ApplyMovesHandler`
  - `GetBatchReportHandler`
  - `PreviewDecisionsHandler`
  - `ApplyDecisionsHandler`
  - `PreviewPurgeHandler`
  - `PurgeAssetHandler`
- ajout du port:
  - `Application/Workflow/Port/WorkflowGateway`
- adapter infra:
  - `Infrastructure/Workflow/WorkflowGateway`
- `WorkflowController` réutilise:
  - `ResolveAgentActorHandler`
  - `ResolveAuthenticatedUserHandler`

## Règles de migration progressive

- conserver le contrat HTTP et les codes d'erreur existants
- migrer un use case à la fois
- ajouter des tests unitaires Domain/Application à chaque extraction
- garder les controllers minces (validation/mapping uniquement)
