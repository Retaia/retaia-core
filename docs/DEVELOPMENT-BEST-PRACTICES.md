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

Selon le scope :

- jobs/capabilities: `specs/definitions/JOB-TYPES.md`, `specs/definitions/CAPABILITIES.md`, `specs/definitions/PROCESSING-PROFILES.md`
- sidecars: `specs/definitions/SIDECAR-RULES.md`
- sécurité et accès: `specs/policies/AUTHZ-MATRIX.md`
- verrous et concurrence: `specs/policies/LOCK-LIFECYCLE.md`, `specs/policies/LOCKING-MATRIX.md`

## Principes d’implémentation

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
- Respecter strictement `openapi/v1.yaml` et `specs/api/API-CONTRACTS.md`.
- Appliquer `Idempotency-Key` sur les endpoints critiques définis par la spec.
- Pour les features `v1.1+`, vérifier le feature flag et renvoyer un refus explicite si inactif.
- Garantir la stabilité des codes d’erreur contractuels (ex: `STATE_CONFLICT`, `IDEMPOTENCY_CONFLICT`).

## Concurrence, verrous, retries

- Claim de job atomique uniquement.
- Lease TTL obligatoire sur claim, heartbeat pour jobs longs.
- Aucun processing sur asset `MOVE_QUEUED`.
- Purge refusée si un job est encore claimé.
- Reprocess refusé si lock move actif.
- Écrire les flows en pensant crash/retry/reprise idempotente.

## Données et filesystem

- Aucun write agent direct dans les dossiers source ou `.derived`.
- Les dérivés passent par l’API upload dédiée.
- Les sidecars sont associés par règles déterministes, pas heuristiques locales.
- Tout comportement ambigu doit rester explicite (ex: sidecar non matché).

## Persistance locale

- Utiliser Doctrine ORM pour les entités applicatives (pas de persistance JSON ad hoc).
- En dev, la base de référence est PostgreSQL.
- Garder les tests unitaires et Behat indépendants de PostgreSQL avec des doubles en mémoire.

## Test strategy minimale (à appliquer à chaque PR)

- Transitions autorisées/interdites de state machine.
- Concurrence claim + expiry TTL + heartbeat.
- Ownership patch par `job_type` (pas d’écrasement cross-domaine).
- Idempotence endpoints critiques (même clé/même body vs clé réutilisée/body différent).
- Batch move: éligibilité, locks, collision naming, tolérance partielle aux erreurs.
- Purge: uniquement depuis `REJECTED`, suppression originaux + sidecars + dérivés.
- Authz: scope/acteur/état vérifiés selon matrice normative.

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
