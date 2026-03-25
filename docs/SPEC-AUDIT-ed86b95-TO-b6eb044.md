# Audit spec/runtime/tests

Perimetre:
- spec precedente: `ed86b95dd1409b65f347e85dc78af46258b01c44`
- spec courante: `b6eb0447cf3c9d3bf3d4b9d2969ceda4cd38202a`
- branche de travail: `codex/specs-bump-audit-b6eb044`

## Resume executif

Le delta de spec est concentre sur un nouveau concept metier humain: `projects[]` sur les assets.

Ce changement n'est pas casseur au niveau des checks OpenAPI actuels parce que le champ est optionnel dans `AssetSummary`/`AssetDetail`. En revanche, le runtime n'implémente aujourd'hui ni l'exposition ni l'edition de `projects[]`, et la suite de tests ne couvre pas ce nouveau contrat.

## Validation executee

- `composer check:openapi` ✅
- `composer check:openapi-docs-coherence` ✅
- `composer check:contracts` ✅

Conclusion:
- conformite structurelle des specs/docs/contrat: OK
- conformite fonctionnelle runtime/tests vis-a-vis du nouveau contrat `projects[]`: NON OK

## Delta de spec

Sources:
- `specs/api/openapi/v1.yaml`
- `specs/api/API-CONTRACTS.md`
- `specs/tests/TEST-PLAN.md`
- `specs/ui/UI-GLOBAL-SPEC.md`
- `specs/ui/UI-WIREFRAMES-TEXTE.md`

Changements introduits entre `ed86b95` et `b6eb044`:
- ajout du schema `AssetProjectRef`
- ajout du champ optionnel `projects[]` dans les payloads asset exposes par l'API
- clarification normative: `projects[]` est un rattachement metier humain explicite
- clarification normative: `projects[]` doit rester distinct de `location_*` et de `fields`
- clarification normative: `projects[]` est editable via `PATCH /assets/{uuid}`
- clarification normative: `projects[]` n'est pas un concept de processing `AGENT` et ne doit pas venir des `facts`
- renforcement du test plan et des wireframes UI sur le bloc `Projects`

References spec:
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/api/openapi/v1.yaml:1244`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/api/openapi/v1.yaml:1244)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/api/openapi/v1.yaml:3577`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/api/openapi/v1.yaml:3577)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/api/openapi/v1.yaml:3665`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/api/openapi/v1.yaml:3665)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/api/API-CONTRACTS.md:975`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/api/API-CONTRACTS.md:975)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/api/API-CONTRACTS.md:1949`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/api/API-CONTRACTS.md:1949)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/api/API-CONTRACTS.md:2073`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/api/API-CONTRACTS.md:2073)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/tests/TEST-PLAN.md:234`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/tests/TEST-PLAN.md:234)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/tests/TEST-PLAN.md:259`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/tests/TEST-PLAN.md:259)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/tests/TEST-PLAN.md:517`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/tests/TEST-PLAN.md:517)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/ui/UI-GLOBAL-SPEC.md:122`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/ui/UI-GLOBAL-SPEC.md:122)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/specs/ui/UI-WIREFRAMES-TEXTE.md:84`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/specs/ui/UI-WIREFRAMES-TEXTE.md:84)

## Etat runtime actuel

### 1. Lecture asset: `projects[]` absent

Le detail asset retourne actuellement `summary`, `paths`, `processing`, `derived`, `transcript`, `decisions`, `audit`, mais jamais `projects`.

References runtime:
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetReadGateway.php:61`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetReadGateway.php:61)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetReadGateway.php:106`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetReadGateway.php:106)

Impact:
- `GET /assets/{uuid}` n'expose pas `projects[]`
- la liste `GET /assets` n'expose pas non plus `projects[]` dans `summary`
- le champ ne peut donc pas alimenter le bloc `Projects` attendu cote UI

### 2. Ecriture asset: `PATCH /assets/{uuid}` ne gere pas `projects[]`

La gateway de patch ne prend en charge que `tags`, `notes` et `fields` en bloc brut. Aucun traitement de `projects[]` n'existe.

References runtime:
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetPatchGateway.php:21`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetPatchGateway.php:21)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetPatchGateway.php:44`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetPatchGateway.php:44)

Impact:
- `PATCH /assets/{uuid}` ignore completement le nouveau contrat metier `projects[]`
- aujourd'hui, un client devrait tricher via `fields`, ce que la spec interdit explicitement

### 3. Aucun stockage/metier dedie pour `projects[]`

Le code asset s'appuie encore entierement sur `Asset::fields` pour les metadonnees libres et sur des mappers de lecture derives de `fields`. Aucun modele dedie ou normalisation n'existe pour `projects[]`.

Observation:
- aucune occurrence `projects`, `AssetProjectRef`, `project_id`, `project_name` dans `src/` ou `tests/`

Impact:
- pas de garantie d'unicite par `project_id`
- pas d'ordre stable metier
- pas de validation de forme (`project_id`, `project_name`, `created_at`, `description?`)
- pas de separation nette entre metadata humaines et facts/processing

## Etat tests actuel

### 4. Couverture de contrat/runtime insuffisante

La suite actuelle ne teste pas `projects[]` en lecture ni en ecriture.

Constat:
- aucune occurrence `projects[]` ou `project_id` dans les tests runtime
- les tests asset existants couvrent encore principalement `fields`, `captured_at` et les flux d'etat

References utiles:
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/tests/Functional/AssetStateMachineApiTest.php:137`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/tests/Functional/AssetStateMachineApiTest.php:137)
- [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/tests/Functional/AssetStateMachineApiTest.php:307`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/tests/Functional/AssetStateMachineApiTest.php:307)

Impact:
- un drift runtime/spec sur `projects[]` ne serait pas detecte par la suite actuelle
- le nouveau `TEST-PLAN` n'est pas implemente dans les tests applicatifs

## Changements a faire

Priorite P1:
- ajouter un mapping lecture `projects[]` dans [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetReadGateway.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetReadGateway.php)
- decider le stockage de reference pour `projects[]`
- exposer `projects[]` dans `AssetDetail` et, si voulu par la spec de list payload, dans `AssetSummary`

Priorite P1:
- ajouter le support `projects[]` dans [`/Users/fullfrontend/Jobs/A - Full Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetPatchGateway.php`](/Users/fullfrontend/Jobs/A%20-%20Full%20Front-End/retaia-workspace/retaia-core/src/Infrastructure/Asset/AssetPatchGateway.php)
- refuser les structures invalides
- normaliser l'unicite par `project_id`
- garantir un ordre stable et deterministe

Priorite P1:
- ajouter des tests fonctionnels sur:
  - lecture detail asset avec `projects[]`
  - patch asset avec `projects[]`
  - persistance de `description?`
  - unicite par `project_id`
  - separation entre `projects[]` et `fields`

Priorite P2:
- ajouter des tests de contrat OpenAPI pour verifier que `projects` existe dans les schemas exposes et reste optionnel/la forme des items est correcte

Priorite P2:
- clarifier si `projects[]` doit etre present aussi dans la liste assets (`summary`) ou uniquement dans le detail. La spec OpenAPI l'introduit a deux endroits, donc l'implementation devrait vraisemblablement l'exposer dans les deux payloads.

## Recommendation d'implementation

Approche minimale coherente avec l'etat actuel du code:
- stocker provisoirement `projects[]` sous une cle dediee stable dans `fields`, par exemple `fields['projects']`, mais ne jamais l'exposer comme contenu generique de `fields`
- ajouter un normaliseur applicatif dedie pour convertir ce stockage en `AssetProjectRef[]`
- interdire l'alimentation de `projects[]` via jobs/facts/processing
- reserver l'ecriture a `PATCH /assets/{uuid}` cote acteur humain, conformement a la spec

Approche plus propre a moyen terme:
- sortir `projects[]` de `fields` vers un stockage/metier dedie si des besoins d'indexation, de filtrage ou de gouvernance apparaissent

## Etat du repo

Modifications locales actuelles:
- `specs` bump vers `b6eb044`
- `contracts/openapi-v1.sha256` regenere
- audit ajoute dans `docs/SPEC-AUDIT-ed86b95-TO-b6eb044.md`
