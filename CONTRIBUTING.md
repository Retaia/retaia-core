# Contributing (retaia-core)

## Source de vérité
Le contrat API et les normes runtime viennent de `retaia-docs`.
Avant tout changement contractuel: modifier/valider `retaia-docs` puis implémenter ici.

## Workflow Git
- Branche depuis `master` (préfixe recommandé: `codex/`).
- Commits atomiques, PR atomiques.
- Rebase sur `master` avant merge.
- Aucun merge commit de synchronisation dans la PR.

## Exigences de PR
- Décrire clairement l'impact contrat/runtime.
- Ajouter/mettre à jour les tests d'intégration et non-régression.
- Conserver l'alignement avec la spec OpenAPI v1.
- Respecter Bearer-only, authz matrix, error model, feature governance.

## Règles d'implémentation
- `feature_flags`, `app_feature_enabled`, `user_feature_enabled` suivent les règles normatives.
- Les features `CORE_V1_GLOBAL` ne sont jamais désactivables par l'utilisateur.
- Aucun secret/token en clair dans logs, traces, dumps.
- Approche préférée : DDD (Domain-Driven Design).
- Validation recommandée par défaut : TDD (tests unitaires/intégration) et BDD (scénarios métier).
