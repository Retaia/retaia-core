# Contributing (retaia-core)

## Source de vérité
Le contrat API et les normes runtime viennent de `retaia-docs`.
Avant tout changement contractuel: modifier/valider `retaia-docs` puis implémenter ici.

## Workflow Git
- Branche depuis `master` (préfixe recommandé: `codex/`).
- Commits atomiques, PR atomiques.
- Rebase sur `master` avant merge.
- Aucun merge commit de synchronisation dans la PR.
- Le hook `pre-commit` DOIT bloquer tout commit direct sur `master`.

## Exigences de PR
- Décrire clairement l'impact contrat/runtime.
- Ajouter/mettre à jour les tests d'intégration et non-régression.
- Conserver l'alignement avec la spec OpenAPI v1.
- Respecter Bearer-only, authz matrix, error model, feature governance.
- Les tests E2E et BDD DOIVENT être écrits depuis les specs (SSOT), jamais pour refléter le comportement actuel du code.
- Toute modification de logique DOIT inclure les changements dans le code applicatif et dans les tests existants impactés (unitaires, fonctionnels/E2E, BDD legacy).
- En cas d'incohérence logique constatée, ouvrir une proposition de changement dans `retaia-docs` au lieu d'adapter les tests à une implémentation divergente.

## Règles d'implémentation
- `feature_flags`, `app_feature_enabled`, `user_feature_enabled` suivent les règles normatives.
- Les features `CORE_V1_GLOBAL` ne sont jamais désactivables par l'utilisateur.
- Aucun secret/token en clair dans logs, traces, dumps.
- Approche préférée : DDD (Domain-Driven Design).
- Validation recommandée par défaut : TDD (tests unitaires/intégration) et BDD (scénarios métier).

## Licence des contributions
- Toute contribution est publiée sous `AGPL-3.0-or-later`.
- En soumettant une PR, vous acceptez que votre contribution soit distribuée sous cette licence.
