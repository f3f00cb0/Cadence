# Plan d'amélioration — Furan

> App de mobilité Saint-Étienne (STAS + Vélivert) — https://furan.run/
> Issu de la review du 2026-06-08. Classé par rapport gain/effort.
> Légende sévérité : 🔴 majeur · 🟠 moyen · 🟡 mineur · ✅ fait

## Lot 1 — Quick wins (à faire en premier)

- [ ] 🔴 **Mémoïser `ActiveServicesResolver`** — cache mémoire `[$ymd => string[]]`.
  Un `batch-departures` (20 zones × ~4 quais) génère ~160 résolutions, chacune
  faisant un `calendars->findAll()` + requête `calendar_dates`. Le set est
  identique pour toute la requête. *Fichier : `src/Service/Gtfs/ActiveServicesResolver.php:22`. Effort : ~10 min.*

- [ ] 🟠 **Dédupliquer le `.env`** — les defaults de la recette Flex collés sous
  les valeurs custom les écrasent (dotenv = dernier gagne) : `APP_SECRET` finit
  vide, `DATABASE_URL` = placeholder `!ChangeMe!@127.0.0.1/app`. *Fichier : `.env`. Effort : ~5 min.*

- [ ] 🟡 **Brancher les tests dans la CI** — pas de `phpunit.xml` ni de
  `phpunit/phpunit` → le step PHPUnit est toujours sauté, le gate « test » ne
  fait que migrer. Lancer au minimum les tests standalone
  (`php tests/Service/Gtfs/StopAreaBuilderTest.php`), idéalement ajouter phpunit + config.
  *Fichiers : `.github/workflows/deploy.yml`, `composer.json`. Effort : ~30 min.*

## Lot 2 — Robustesse / correctness

- [ ] 🟠 **Import GTFS atomique** — `importFromDirectory` TRUNCATE hors transaction
  globale : un échec à mi-import (ex. cron du lundi 4h17) laisse les tables vides
  → site sans horaires jusqu'à relance manuelle. Importer dans des tables temp
  puis swap, ou envelopper l'ensemble. *Fichier : `src/Service/Gtfs/GtfsImporter.php:51`.*

- [ ] 🟠 **`withRealtimeDelay` perd le décalage de jour** — le DTO ne stocke que
  `scheduledTime` en `"H:i"` ; pour un trip à 25:30 sur le service de la veille,
  `serviceDay->setTime()` retombe 24h trop tôt → `minutesUntil` clampé à 0 (ETA
  faux sur passages de nuit retardés). Porter le `dayOffset` ou stocker le
  `DateTimeImmutable` complet. *Fichier : `src/Dto/DepartureDto.php:86`.*

- [ ] 🟡 **`findNearby` : convergence des méridiens** — `$rough` appliqué tel quel
  en lat et lon ; à 45° une station proche du bord E/O peut être écartée avant le
  filtre haversine. Diviser le delta de longitude par `cos(lat)`.
  *Fichier : `src/Repository/Gtfs/StopAreaRepository.php:52`.*

## Lot 3 — Performance (chemin chaud)

- [ ] 🟠 **`GtfsImporter` : insert ligne par ligne** → multi-row `VALUES` par
  chunks (le pattern existe déjà dans `TripStopUpdateRepository::replaceAll`) ou
  `COPY`. Gain 10–50× sur les `stop_times`. *Fichier : `src/Service/Gtfs/GtfsImporter.php:185`.*

- [ ] 🟡 **`batch-departures` reste en N×M requêtes** même après le Lot 1 — viser
  un seul `stop_id IN (...)` regroupé en PHP. *Fichiers : `AreaDepartureAggregator.php`, `DepartureFinder.php`.*

- [ ] 🟡 **Cache HTTP sur les endpoints JSON** — `Cache-Control: s-maxage=15-30`
  pour jouer avec Traefik/CDN. *Fichiers : `src/Controller/Api/*`.*

## Lot 4 — Finitions / hygiène

- [x] ✅ **Naming → Furan** — marque visible unifiée (titres, manifest PWA,
  en-têtes JS, README, description composer). *Identifiants d'infra laissés
  intacts volontairement* (DB locale `mobilite`, images GHCR `cadence`, canal
  monolog `mobilite`, clés localStorage `mobilite.*`) : les renommer casserait
  le déploiement live / le volume dev / effacerait les favoris des utilisateurs.
  → décision à prendre séparément (cf. Lot 5).
- [ ] 🟡 **README obsolète** — dit `/` = carte, `/board` = dashboard ; en réalité
  `/` sert le board, `/board` redirige 301, carte sur `/map`.
- [ ] 🟡 **`board-mockup.html` (32 Ko)** traîne à la racine → ranger/supprimer.
- [ ] 🟡 **VelivertFetcher** — upsert only (stations retirées du flux jamais
  purgées) ; pas de transaction d'ensemble. *Fichier : `src/Service/Velivert/VelivertFetcher.php`.*

## Lot 5 — Distribuabilité (avant lancement public)

> Issu du test personas. L'app est OK pour une beta locale ; ces points
> construisent la « couche de confiance » attendue en conditions réelles.

- [x] ✅ **Erreurs réseau visibles** — les fetchers lèvent désormais, les
  sections affichent un état « Connexion impossible · Réessayer » distinct du
  « aucune donnée », les favoris restent visibles hors-ligne, et le bandeau
  ne ment plus (« TEMPS RÉEL » seulement si une section a vraiment rafraîchi).
  *Fichiers : `public/js/board.js`, `public/css/board.css`.*
- [x] ✅ **Service worker** — `public/sw.js` : app-shell network-first (le board
  charge hors-ligne), API GET network-first avec fallback dernière réponse cachée
  (derniers départs connus), assets/fonts/Leaflet en stale-while-revalidate.
  Enregistré **en prod uniquement** via `base.html.twig`. *Limite connue :
  `batch-departures` est en POST → non cachable par la Cache API (les noms
  d'arrêts proches restent dispo via le GET `/api/areas/nearby`).*
- [x] ✅ **Auto-héberger les fonts** — Fraunces / Inter / JetBrains Mono (variables,
  sous-ensembles latin + latin-ext, italiques inclus) téléchargées dans
  `public/fonts/` (~489 Ko, 10 woff2) + `public/css/fonts.css` chargé par
  `base.html.twig`. **Zéro requête vers Google** → RGPD réglé, plus de dépendance
  CDN pour le texte. *Au passage : l'ancienne URL Fraunces des templates était
  malformée (3 axes / 2 valeurs) → Google la rejetait, Fraunces tombait en
  fallback serif. Corrigé.*
- [ ] 🟡 **Auto-héberger Leaflet** (reste sur unpkg) — moins critique (chargé sur
  la seule page carte, avec SRI), mais à faire pour zéro CDN tiers.
  *Fichiers : `templates/map/index.html.twig`.*
- [x] ✅ **Retirer Leaflet de la page board (`/`)** — sorti de `base.html.twig`,
  déplacé dans le seul template `map` (CSS + JS avant `app.js`). Le board ne
  charge plus ~150 Ko de Leaflet inutile sur le chemin le plus chaud.
- [x] ✅ **Page mentions légales / vie privée** — `/mentions-legales`
  (`LegalController` + `templates/legal/index.html.twig`), liée depuis les footers
  board + carte. Couvre éditeur (Mathieu Mont, particulier non commercial),
  hébergeur (Hetzner Online GmbH), RGPD (géoloc non stockée, localStorage,
  Nominatim, journaux), sources open data + licences. Aucun placeholder restant.
  Au passage, le label statique trompeur « TEMPS RÉEL » du footer board a été retiré.
- [ ] 🟡 **Décider du sort des identifiants d'infra** (DB/GHCR/monolog/localStorage)
  — soit on assume `Furan` partout avec migrations (rename image GHCR + reconfig
  Dokploy, migration localStorage `mobilite.* → furan.*`), soit on garde tel quel.
- [ ] 🟡 **Audit accessibilité** (axe-core / Lighthouse) — vérifier piège de focus
  dans les sheets et contraste AA des badges de ligne sur thème sombre.
- [ ] 🟡 **Report d'erreurs (Sentry)** — sans ça, impossible de savoir qu'un user
  tombe sur des écrans vides en prod.
- [ ] 🟡 **i18n (EN)** — tout est en français codé en dur ; plafonne l'audience
  (touristes). Optionnel selon l'ambition.
</content>
