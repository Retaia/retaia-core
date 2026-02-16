# Best Practices de Développement (Repo applicatif)

> Statut : **non normatif**.
> Source de vérité : `specs/` (submodule `retaia-docs`).
> En cas de conflit, `specs/` prime toujours.

## Objectif

Ce document sert de guide pratique pour implémenter vite et proprement dans ce repository, sans casser les invariants Retaia.

Il ne définit **aucune** règle produit nouvelle.

## Lecture minimale avant de coder

- `specs/change-management/CODE-QUALITY.md`
- `specs/state-machine/STATE-MACHINE.md`
- `specs/workflows/WORKFLOWS.md`
- `specs/workflows/AGENT-PROTOCOL.md`
- `specs/api/API-CONTRACTS.md`
- `specs/tests/TEST-PLAN.md`
- `specs/CONTRIBUTING.md`

Selon le scope :

- jobs/capabilities: `specs/definitions/JOB-TYPES.md`, `specs/definitions/CAPABILITIES.md`, `specs/definitions/PROCESSING-PROFILES.md`
- sidecars: `specs/definitions/SIDECAR-RULES.md`
- sécurité et accès: `specs/policies/AUTHZ-MATRIX.md`
- gouvernance features: `specs/policies/FEATURE-RESOLUTION-ENGINE.md`, `specs/change-management/FEATURE-FLAG-LIFECYCLE.md`, `specs/change-management/FEATURE-FLAG-KILLSWITCH-REGISTRY.md`
- verrous et concurrence: `specs/policies/LOCK-LIFECYCLE.md`, `specs/policies/LOCKING-MATRIX.md`

## Principes d’implémentation

- Suivre les best practices Symfony dans tous les cas (composants natifs avant implémentation custom).
- Ne jamais modifier manuellement des fichiers générés (Symfony/Composer/console).
- Le hook local `pre-commit` doit interdire les commits directs sur `master`.
- Garder le serveur comme source de vérité. L’agent exécute, ne décide pas.
- Ne jamais automatiser `KEEP/REJECT`.
- Utiliser l’UUID comme identité. Le path est mutable.
- Refuser explicitement toute transition d’état non autorisée.
- Ne jamais faire d’action destructive implicite (move, purge, delete).
- Préférer des changements petits, testables, réversibles.

## Patterns recommandés côté code

- Centraliser les transitions de state machine dans un composant dédié.
- Encadrer les mutations critiques avec validations d’état + acteur + scope.
- Isoler la logique lock/TTL/heartbeat dans une couche testable.
- Garder les jobs atomiques et idempotents, avec ownership clair des patchs par domaine.
- Exiger une trace d’audit pour toute action filesystem.
- Utiliser des logs structurés et inclure `asset_uuid`, `job_id`, `agent_id` quand applicable.

## API et contrats

- Ne jamais dériver le comportement depuis “ce que fait le code aujourd’hui” si la spec dit autre chose.
- Source OpenAPI unique du repo : `specs/api/openapi/v1.yaml` (aucune copie locale autorisée).
- Si un apport de contrat est nécessaire, le notifier et mettre à jour la spec dans `retaia-docs` avant implémentation.
- Respecter strictement `specs/api/openapi/v1.yaml` et `specs/api/API-CONTRACTS.md`.
- Appliquer `Idempotency-Key` sur les endpoints critiques définis par la spec.
- Pour les features `v1.1+`, vérifier le feature flag et renvoyer un refus explicite si inactif.
- Les filtres `suggested_tags` / `suggested_tags_mode` doivent être refusés tant que `features.ai.suggested_tags_filters` est inactif.
- `job_type=suggest_tags` (v1.1+) doit être refusé sans `features.ai.suggest_tags` actif et sans scope dédié (`suggestions:write`).
- Garantir la stabilité des codes d’erreur contractuels (ex: `STATE_CONFLICT`, `IDEMPOTENCY_CONFLICT`).
- Ajouter systématiquement des headers de sécurité sur les réponses API (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`).
- En production HTTPS, forcer `Strict-Transport-Security` et cookies de session `secure`.

## Concurrence, verrous, retries

- Claim de job atomique uniquement.
- Lease TTL obligatoire sur claim, heartbeat pour jobs longs.
- Un lock token obsolète sur heartbeat/submit/fail doit renvoyer `409 STALE_LOCK_TOKEN` (pas un conflit générique).
- Un lock manquant/invalide sur heartbeat/submit/fail doit renvoyer `423 LOCK_REQUIRED` / `423 LOCK_INVALID`.
- Aucun processing sur asset `MOVE_QUEUED`.
- Purge refusée si un job est encore claimé.
- Reprocess refusé si lock move actif.
- Verrous d'opération persistés (`asset_move_lock`, `asset_purge_lock`) requis pour toute opération move/purge.
- Les locks actifs doivent bloquer les mutations humaines critiques (`decision`, `reprocess`, `reopen`, `purge`).
- Écrire les flows en pensant crash/retry/reprise idempotente.

## Données et filesystem

- Aucun write agent direct dans les dossiers source ou `.derived`.
- Les dérivés passent par l’API upload dédiée.
- Les sidecars sont associés par règles déterministes, pas heuristiques locales.
- Tout comportement ambigu doit rester explicite (ex: sidecar non matché).
- Le polling filesystem doit ignorer les symlinks et refuser les chemins non sûrs (`..`, null-byte, absolu inattendu).
- Le polling doit rester résilient aux races filesystem (rename/delete pendant scan) et aux erreurs de permission.
- Les collisions de noms en move outbox doivent rester déterministes et sans écrasement.
- Les retries de `apply-outbox` doivent être idempotents (pas de doublon `path_history`/audit si déjà appliqué) et tolérer les erreurs par asset sans bloquer tout le lot.
- Ne pas committer de contenu généré : `vendor/`, `var/cache/`, `config/reference.php` et fichiers auto-générés équivalents.

## Persistance locale

- Utiliser Doctrine ORM pour les entités applicatives (pas de persistance JSON ad hoc).
- En dev, la base de référence est PostgreSQL.
- Garder les tests unitaires et Behat indépendants de PostgreSQL avec des doubles en mémoire.
- Garder des noms de classes agnostiques: pas de préfixe/suffixe `Doctrine` dans les `Entity`/`Repository`.

## Test strategy minimale (à appliquer à chaque PR)

Règle SSOT (obligatoire pour l'équipe) :

- Les scénarios E2E et BDD sont dérivés des specs (`specs/`) et non du comportement observé dans le code.
- Il est interdit de "faire passer" un test en l'alignant sur une implémentation qui diverge de la spec.
- Si la logique implémentée semble correcte mais contredit la spec, proposer une mise à jour de spec (`retaia-docs`) et attendre validation.
- Toute évolution fonctionnelle doit être propagée dans le code et dans les tests existants pertinents (unitaires, fonctionnels/E2E, BDD legacy).

- Transitions autorisées/interdites de state machine.
- Concurrence claim + expiry TTL + heartbeat.
- Ownership patch par `job_type` (pas d’écrasement cross-domaine).
- Idempotence endpoints critiques (même clé/même body vs clé réutilisée/body différent).
- Batch move: éligibilité, locks, collision naming, tolérance partielle aux erreurs.
- Purge: uniquement depuis `REJECTED`, suppression originaux + sidecars + dérivés.
- Authz: scope/acteur/état vérifiés selon matrice normative.
- I18N API: messages localisés (`en`, `fr`) + fallback `en` pour locale non supportée.

## Checklist PR

- Objectif unique et clair.
- Pas de mélange feature + refactor + formatage massif.
- Specs impactées identifiées.
- Si changement de comportement: specs mises à jour dans `retaia-docs` avant code.
- Tests de non-régression ajoutés pour tout bug corrigé.
- Risques et rollback documentés.
- Commit messages en Conventional Commits.

## Usage de l’IA (assistant/agent)

- L’IA propose, l’humain valide.
- Aucun secret dans prompts, code, logs, commits.
- Pas de modification implicite d’API/state machine/workflows.
- Toute contribution IA reste soumise aux mêmes exigences de tests et review.

## En cas d’ambiguïté

- Ne pas implémenter de nouveau comportement.
- Ouvrir une proposition de modification dans `retaia-docs` avec section précise et impact.
- Attendre validation de la spec avant de coder.
