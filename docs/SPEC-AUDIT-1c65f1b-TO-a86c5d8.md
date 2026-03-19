# Audit Spec

Base de comparaison: `specs@1c65f1b8956241ab1c658cb1a436c026d3e99e61`  
Spec actuelle auditée: `specs@a86c5d84101b1120baf108eda5f6a1cca6ef8fef`  
Etat du code audité: branche `codex/spec-runtime-alignment-1c65f1b`

## Résumé

Depuis `1c65f1b`, la spec v1 a bougé sur 4 axes majeurs:
- auth interactive et technique durcie (`/auth/refresh`, WebAuthn, séparation AGENT/MCP)
- concurrence optimiste asset (`revision_etag`, `If-Match`, `412`, `428`)
- signature des requêtes agent (`X-Retaia-*`, OpenPGP)
- contrat agent/app policy et évolutions documentaires associées

Le runtime a déjà absorbé une partie importante de ce delta sur la branche courante:
- `/auth/refresh` et WebAuthn v1 sont implémentés et testés
- réponses login alignées avec `AuthLoginSuccess`
- endpoints techniques v1 `/auth/clients/*` restent AGENT-only
- `revision_etag` et `If-Match` sont implémentés sur patch/reopen/reprocess/purge/derived upload
- `agents/register`, jobs et uploads derived exigent maintenant les headers `X-Retaia-*`
- `agents/register` valide les nouveaux champs requis par la spec v1

## À faire en priorité

### P1. Implémenter les endpoints MCP v1 absents du runtime

La spec v1 expose maintenant:
- `/auth/mcp/register`
- `/auth/mcp/challenge`
- `/auth/mcp/token`

Référence spec:
- `specs/api/openapi/v1.yaml:1001`
- `specs/api/openapi/v1.yaml:1045`
- `specs/api/openapi/v1.yaml:1085`

Constat runtime:
- aucune route `/api/v1/auth/mcp/*` n’existe aujourd’hui

Travail attendu:
- implémenter les 3 endpoints
- créer le stockage challenge/réponse/replay
- brancher la policy `features.ai` au bon niveau MCP
- ajouter tests unitaires + fonctionnels + contrat OpenAPI

### P1. Remplacer la validation "présence de headers" par une vraie validation cryptographique

Etat actuel:
- `src/Api/Service/SignedAgentRequestValidator.php` vérifie seulement présence des headers, format date, cohérence `agent_id` / `openpgp_fingerprint`
- aucune vérification OpenPGP réelle
- aucun contrôle de skew temporel
- aucune protection contre rejeu de nonce
- aucun binding fort entre signature, bearer token technique et ressource ciblée

Travail attendu:
- définir le payload signé canonique
- vérifier la signature OpenPGP
- stocker et rejeter les nonces rejoués
- imposer une fenêtre de validité sur `X-Retaia-Signature-Timestamp`
- lier `X-Retaia-Agent-Id` et la clé/fingerprint au principal technique authentifié

### P1. Étendre le tooling de conformité OpenAPI

Constat:
- `scripts/check-openapi-routes.php` ne vérifie que les préfixes `assets/jobs/agents/auth/app`
- le message "coverage OK" ne révèle pas l’absence actuelle des routes MCP
- le script ne contrôle pas les exigences de headers ni les préconditions `If-Match`

Travail attendu:
- inclure explicitement les routes MCP v1 dans le contrôle
- ajouter un contrôle minimal sur les paramètres requis de la spec (`If-Match`, `X-Retaia-*`)
- ajouter un test de non-régression quand une route spec v1 manque en runtime

## À faire ensuite

### P2. Renforcer les tests négatifs autour des nouvelles contraintes v1

Les bases sont là, mais il manque encore des cas de test ciblés:
- `428 PRECONDITION_REQUIRED` sur patch/reopen/reprocess/purge/derived upload
- `412 PRECONDITION_FAILED` sur ETag obsolète
- `401` sur headers `X-Retaia-*` absents/invalides
- mismatch `agent_id` / `openpgp_fingerprint`
- replay/timestamp expired une fois la vraie validation signée implémentée

### P2. Aligner les docs internes du repo sur le nouvel état de la spec v1

Les specs ont beaucoup bougé côté textes et politiques:
- `api/API-CONTRACTS.md`
- `GOLDEN-RULES.md`
- `policies/GPG-OPENPGP-STANDARD.md`
- `policies/CLIENT-HARDENING.md`
- `tests/TEST-PLAN.md`

Travail attendu:
- vérifier que `docs/BOOTSTRAP-TECHNIQUE.md` et les docs runtime locales reflètent bien le modèle MCP/OpenPGP courant
- documenter la différence entre AGENT secret-key flow et MCP challenge/signature flow
- documenter la sémantique de `revision_etag` côté clients

### P2. Compléter la couverture contrat pour les nouvelles obligations d’interface

Le contrat runtime devrait vérifier explicitement:
- présence de `revision_etag` dans les payloads asset
- `ETag` sur réponses de mutation asset
- champs requis de `agents/register`
- erreurs `PRECONDITION_REQUIRED` / `PRECONDITION_FAILED`
- présence et comportement des endpoints MCP v1 dès qu’ils sont implémentés

## Déjà absorbé sur la branche courante

- `/auth/refresh`
- WebAuthn register/authenticate
- alignement `AuthLoginSuccess`
- AGENT-only sur `/auth/clients/token` et `/auth/clients/device/start`
- `revision_etag` + `If-Match` sur endpoints asset/derived/purge
- validation minimale des headers `X-Retaia-*`
- payload `agents/register` aligné avec la spec v1 courante

## Validation actuelle

Les validations suivantes passent sur la branche courante:
- `composer check:openapi`
- `composer check:openapi-docs-coherence`
- `composer check:contracts`
- `composer test`

Conclusion: le plus gros écart restant entre la spec v1 et le runtime n’est plus l’auth interactive ni la concurrence asset. Le point bloquant principal est maintenant le bloc MCP v1 et la vraie sécurité cryptographique des requêtes agent signées.
