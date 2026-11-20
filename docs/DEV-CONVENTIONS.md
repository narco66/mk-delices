# Conventions de développement — MK Délices

## Général
- Langue : code et identifiants en anglais ; commentaires, messages d'erreur, interface et documentation en **français**.
- Montants : **entiers XOF** partout (`*_xof`, `bigint`) ; jamais de flottant ; jamais de montant accepté du navigateur.
- Dates : stockage `timestamptz` UTC ; affichage `Africa/Dakar` (`lib/format.ts`, `Settings::timezone()`).
- Identifiants : UUID pour toutes les entités métier ; référence commande `MKD-XXXX-XXXX` non prédictible.
- Aucune donnée commerciale inventée présentée comme réelle : données fictives marquées `is_demo` et signalées dans l'interface.
- Secrets uniquement dans `.env` (ignoré par Git) ; `.env.example` sans valeur sensible.

## Backend (PHP / Laravel)
- PSR-12 via **Laravel Pint** (`vendor/bin/pint`, vérifié en CI par `pint --test`).
- Contrôleurs légers : validation (FormRequest ou `validate`), appel d'un service `App\Domain\…`, ressource de réponse.
- Erreurs métier : `App\Domain\Shared\DomainException` (code stable, message français, statut HTTP) → format `{error:{code,message,details},request_id}`.
- Toute opération critique dans `DB::transaction` avec `lockForUpdate()` ; aucune modification de statut hors `OrderStateMachine`.
- Toute mutation sensible journalisée (`OrderJournal`, `AuditLogger`) ; les journaux n'acceptent ni UPDATE ni DELETE.
- Autorisation **côté serveur** sur chaque route (`permission:` middleware) **et** dans les services (`$user->can()`).
- Mode strict Eloquent hors production : charger explicitement les relations (`with()`), pas de chargement paresseux.
- Migrations : additives en production (expand/contract), pas de `migrate:fresh` hors base de test.
- Tests : PHPUnit sur PostgreSQL (`mk_delices_test`), un test par règle de gestion ou scénario de recette, nommés en anglais, assertions sur codes d'erreur stables.

## Frontend (TypeScript / React)
- TypeScript `strict`, pas de `any` (règle ESLint en erreur), imports de type explicites.
- ESLint (`npm run lint`) + Prettier (`npm run format`, tri des classes Tailwind).
- Composants fonctionnels ; données serveur via **TanStack Query** (pas d'état global dupliqué) ; état dérivé plutôt que synchronisé par `useEffect`.
- Formulaires : React Hook Form + Zod ; erreurs serveur 422 rattachées aux champs (`ApiError.fieldErrors`).
- Chaque écran gère chargement (`Skeleton`/`Spinner`), vide (`EmptyState`), erreur (`ErrorState` avec réessai).
- Accessibilité : `Field` (label + `aria-describedby`), cibles ≥ 44 px, statuts = texte + couleur (`StatusPill`), modales Radix, `prefers-reduced-motion`.
- Les gardes de routes ne sont qu'un confort : l'API reste la seule source d'autorisation.
- Jetons de design dans `src/index.css` (`@theme`) ; ne pas coder de couleurs en dur hors exceptions documentées (bouton WhatsApp).

## Messages d'erreur (règles du 20/11/2026)
- **Format unique de l'API** : `{ error: { code, message, details }, request_id }`, avec en-tête `X-Request-Id`. Le `code` est stable, en anglais, et sert au programme et aux tests. Le `message` est en français, destiné à l'utilisateur.
- **Message utile** : dire ce qui se passe et quoi faire (« Ce créneau est complet : choisissez-en un autre »), sans jargon ni faute rejetée sur l'utilisateur.
- **Erreur métier** : `DomainException` (`unprocessable`, `conflict`, `forbidden`) avec un code et un message français. `abort(4xx, 'message français')` est réservé aux cas simples. Ne jamais renvoyer le message d'une exception du framework ou d'une bibliothèque : `bootstrap/app.php` le remplace par un message maîtrisé selon le statut.
- **Erreur inattendue (5xx)** : message générique avec la **référence** (`request_id`), détail uniquement dans les journaux. `APP_DEBUG=false` hors poste de développement.
- **Validation** : messages dans `lang/fr/validation.php`. Tout nouveau champ affiché à l'utilisateur reçoit un nom lisible dans `attributes`, ou dans `attributes()` de la FormRequest.
- **Frontend** : toujours `errorMessage(error)` (Toast, `Notice`) ou `ErrorState` (écran, avec « Réessayer » et la référence). Erreurs de champ via `ApiError.fieldErrors`. Ne jamais afficher `error.message` d'une erreur JavaScript ou du navigateur.
- **Cas réseau** gérés par `lib/api.ts` : hors ligne, délai dépassé (30 s, 120 s pour un envoi de fichier), réponse non JSON (502/503/504 de nginx, 413), session expirée (401 : déconnexion et toast), CSRF expiré (419 : nouvelle tentative automatique), limitation (429 : délai d'attente affiché).
- **Erreurs de rendu** : `RouteError` par route, `AppErrorBoundary` au-dessus des fournisseurs ; erreur consignée dans la console, écran sans détail technique avec « Recharger la page ».

## Git (à appliquer dès l'initialisation du dépôt)
- Branches `main` (production), `develop`, `feature/<id-exigence>-<sujet>`.
- Messages de commit : `<type>(<module>): <résumé>` — ex. `feat(payments): rapprochement planifié`.
- CI obligatoire avant fusion : `.github/workflows/ci.yml`.
