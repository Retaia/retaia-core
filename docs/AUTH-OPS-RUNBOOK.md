# Runbook Ops Auth (Core local)

> Statut : non normatif.
> Procedure fonctionnelle globale : `retaia-docs/ops/AUTH-INCIDENT-RUNBOOK.md`.

## Objectif

Documenter les points de controle et details d'implementation propres a `retaia-core` pour les incidents auth.

## Verification locale rapide

1. verifier l'API locale:
   - `GET /api/v1/openapi`
2. verifier les logs auth structures emis par Core:
   - `auth.login.failure`
   - `auth.login.throttled`
   - `auth.password_reset.*`
   - `auth.email_verification.*`
3. verifier la configuration effective locale:
   - `app.password_policy.*`
   - `app.password_reset_ttl_seconds`
   - `app.email_verification_ttl_seconds`
4. verifier les donnees persistantes:
   - `app_user.email_verified`
   - unicite `app_user.email`

## Details d'implementation Core

- en environnement non `prod`, les endpoints de demande peuvent retourner des tokens de test pour faciliter la validation locale
- les logs auth ne doivent inclure ni mot de passe ni token brut
- toute verification email forcee par admin doit laisser une trace d'audit

## Regle

- source cross-project: [retaia-docs ops auth](https://github.com/Retaia/retaia-docs/blob/master/ops/AUTH-INCIDENT-RUNBOOK.md)
