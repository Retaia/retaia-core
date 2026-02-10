# Runbook Ops Auth

> Statut : non normatif.  
> Ce document décrit les actions opérationnelles, pas le comportement produit.

## Objectif

Fournir une procédure rapide pour diagnostiquer et traiter les incidents liés à l’authentification.

## Codes API courants

- `UNAUTHORIZED` (`401`)  
  Cause typique : session absente/invalide ou credentials incorrects.

- `EMAIL_NOT_VERIFIED` (`403`)  
  Cause typique : utilisateur non vérifié.

- `TOO_MANY_ATTEMPTS` (`429`)  
  Cause typique : throttling login ou verify-email/request.

- `INVALID_TOKEN` (`400`)  
  Cause typique : token reset/email invalide, expiré, ou altéré.

- `VALIDATION_FAILED` (`422`)  
  Cause typique : payload incomplet ou mot de passe non conforme à la policy.

## Vérifications rapides

1. Vérifier la santé API: `GET /api/v1/health`.
2. Vérifier les logs auth structurés:
   - `auth.login.failed`
   - `auth.login.throttled`
   - `auth.password_reset.*`
   - `auth.email_verification.*`
3. Vérifier la configuration effective:
   - `app.password_policy.*`
   - `app.password_reset_ttl_seconds`
   - `app.email_verification_ttl_seconds`
4. Vérifier l’état utilisateur en base:
   - `app_user.email_verified`
   - unicité `app_user.email`

## Procédures standard

### Utilisateur bloqué non vérifié

1. Demander une vérification email (`/verify-email/request`).
2. En cas d’urgence support interne, exécuter la vérification admin forcée (endpoint admin).
3. Vérifier un login réussi ensuite.

### Trop de `429` sur login

1. Confirmer le pattern d’échec (IP/email hashés dans logs).
2. Vérifier si tentative abusive ou erreur client.
3. Ajuster la config de limiter uniquement via PR si nécessaire.

### `INVALID_TOKEN` fréquent

1. Confirmer expiration TTL côté config.
2. Vérifier absence d’altération token côté client (transport/encodage).
3. Contrôler que l’environnement non-prod renvoie les tokens attendus pour tests.

## Escalade

- Incident persistant > 30 min: ouvrir incident interne avec:
  - horodatage UTC
  - endpoint touché
  - code de réponse
  - extrait de log structuré (sans secret)
- Si changement de comportement requis: update specs d’abord, puis implémentation.
