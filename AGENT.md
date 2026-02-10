# AGENT.md

Ce repository fait partie du projet **Retaia**.

Ce document s’adresse :

* aux développeurs humains
* aux assistants IA
* aux agents de génération de code

Il définit les **règles impératives** à respecter avant toute modification.

---

## Règle n°1 — Les specs sont la loi

Ce repository contient un submodule `specs/` (repository **retaia-docs**).

Le contenu de `specs/` est :

* **normatif**
* **cross‑project**
* **source de vérité unique**

Avant toute modification de code, tu DOIS :

* lire les documents pertinents dans `specs/`
* lire `specs/change-management/CODE-QUALITY.md` et appliquer les règles.
* comprendre les contraintes qu’ils imposent
* implémenter strictement en respectant ces règles

Si le code et les specs sont en conflit, **les specs ont raison**.

---

## Interdiction absolue

Il est strictement interdit de :

* modifier des fichiers dans `specs/`
* committer des changements dans le submodule `specs/`
* contourner une règle définie dans `specs/`
* corriger une spec "localement" dans ce repo

Toute modification des specs DOIT être faite dans le repository **retaia-docs**.

---

## Si une spec est manquante, ambiguë ou incorrecte

Si une règle :

* est absente
* est ambiguë
* semble incorrecte

Alors la procédure est la suivante :

1. **Ne pas coder de comportement nouveau**
2. Identifier précisément :

    * le fichier concerné dans `retaia-docs`
    * la section exacte
    * le problème constaté
3. Proposer une modification explicite des specs (texte clair, impact identifié)
4. Attendre la mise à jour des specs
5. Implémenter le code en conformité avec la nouvelle version

Coder avant la mise à jour des specs est interdit.

---

## Docs locales (non normatives)

Ce repository peut contenir un dossier `docs/`.

Le contenu de `docs/` est **non normatif**.

Guide local recommandé :

* `docs/DEVELOPMENT-BEST-PRACTICES.md` (guide d’implémentation et de qualité, sans autorité normative)
* `docs/BOOTSTRAP-TECHNIQUE.md` (état du socle technique local et commandes de test)
* `docs/GITHUB-WORKFLOWS.md` (pipeline CI GitHub Actions et exécution des tests)
* `docs/DOCKER-DEVELOPMENT.md` (environnement de développement docker-compose)

Autorisé dans `docs/` :

* documentation de stack (frameworks, versions, tooling)
* instructions de développement (setup, run, debug)
* runbooks opérationnels
* conventions locales d’implémentation

Interdit dans `docs/` :

* définir ou modifier un comportement produit
* définir des états, transitions ou workflows
* définir des contrats API
* définir des "job types" ou capabilities

Toute règle de comportement DOIT vivre dans `retaia-docs`.

---

## Commits et branches

* Les commits DOIVENT respecter **Conventional Commits**.
* La branche principale est `master`.
* Aucun push direct sur `master` n’est autorisé.
* Tout changement passe par une Pull Request.
* Préférer une branche dédiée par feature (ex: `codex/<feature>`).

---

## Conventions techniques locales

* Les best practices Symfony DOIVENT être respectées dans tous les cas (security, DI, routing, persistence, tests).
* Les fichiers générés DOIVENT rester non modifiés manuellement.
* Interdiction de modifier manuellement : `vendor/`, `var/cache/`, `config/reference.php`, fichiers auto-générés par recipes/console.
* Si un fichier généré change pendant un run, ne pas le committer sauf demande explicite et justifiée.
* Persistance applicative utilisateurs : Doctrine ORM.
* Base de données de référence (dev/prod) : PostgreSQL.
* Les tests unitaires et Behat DOIVENT rester rapides et isolés via des doubles en mémoire.
* Les noms de classes `Entity` et `Repository` DOIVENT rester agnostiques d’implémentation.
* Ne pas inclure `Doctrine` dans les noms de classes métier (ex: préférer `UserRepository` à `DoctrineUserRepository`).
* Les endpoints critiques DOIVENT appliquer `Idempotency-Key` selon `specs/api/API-CONTRACTS.md`.
* Les workflows move/purge DOIVENT utiliser des verrous persistés (table `asset_operation_lock`) conformément à `specs/policies/LOCKING-MATRIX.md`.
* Le polling filesystem DOIT ignorer les symlinks et refuser les chemins non sûrs.
* Les messages API DOIVENT rester localisables (`en`, `fr`) avec fallback `en` sans impacter les codes métier.
* Toute PR doit vérifier que les migrations nécessaires sont présentes et exécutables.

---

## Responsabilité

Tout changement mergé dans ce repository est **de la responsabilité de l’humain** qui le valide.

L’IA est un outil d’assistance.
Elle n’est jamais responsable d’un bug, d’une régression ou d’une violation des specs.

---

## Résumé

* `specs/` = vérité
* `docs/` = aide locale
* En cas de doute → **mettre à jour les specs avant de coder**

Toute contribution qui ne respecte pas ces règles peut être refusée, même si le code fonctionne.
