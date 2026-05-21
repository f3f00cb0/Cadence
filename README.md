# Mobilité Stéphanoise

> Backend Symfony 7 + frontend Leaflet pour visualiser en temps réel le réseau
> STAS (bus/tram) et les stations Vélivert à Saint-Étienne.
>
> Source de données : open data Saint-Étienne Métropole (GTFS + GBFS).

## Stack

- **PHP 8.3** + **Symfony 7.2** (FrameworkBundle, Doctrine, Messenger, Scheduler, Twig)
- **PostgreSQL 16** (image `postgis/postgis` pour pouvoir basculer plus tard sur PostGIS si besoin)
- **Doctrine ORM 3** (attributes, no annotations)
- **Leaflet** + tile OSM en filtre dark pour le frontend
- Pas de framework JS — vanilla ES modules

## Boot du projet

```bash
# 1. Installer les dépendances
composer install

# 2. Démarrer les containers (PostgreSQL + PHP-FPM + nginx + worker scheduler)
docker compose up -d

# 3. Créer le schéma de la DB
docker compose exec php bin/console doctrine:database:create --if-not-exists
docker compose exec php bin/console make:migration
docker compose exec php bin/console doctrine:migrations:migrate -n

# 4. Importer le GTFS STAS (zip téléchargé depuis transport.data.gouv.fr)
#    NB: regroupe automatiquement les Stops en StopArea en fin d'import.
#    Skipper avec --no-group si besoin (et lancer app:gtfs:group-stops manuellement).
docker compose exec php bin/console app:gtfs:import

# 5. Premier fetch Vélivert (ensuite c'est le scheduler qui prend la main)
docker compose exec php bin/console app:velivert:refresh

# 6. (Optionnel) Régénérer les icônes PWA
docker compose exec php php bin/generate-board-icons.php

# 7. Test unitaire du builder de zones
docker compose exec php php tests/Service/Gtfs/StopAreaBuilderTest.php
```

Ouvrir http://localhost:8080

## Architecture

```
src/
├── Entity/Gtfs/         Stop · StopArea · Route · Trip · StopTime · Calendar · CalendarDate
├── Entity/Velivert/     Station (GBFS info + status mergés)
├── Service/Gtfs/        GtfsImporter · ActiveServicesResolver · DepartureFinder
│                        StopAreaBuilder · AreaDepartureAggregator
├── Service/Velivert/    VelivertFetcher (GBFS discovery → station_information + station_status)
├── Command/             app:gtfs:import · app:gtfs:group-stops · app:velivert:refresh
├── Controller/          / (carte) · /board (dashboard mobile) · /api/areas/* · /api/stops/* · /api/velivert/*
├── Message + Handler    RefreshVelivertMessage
└── Scheduler/           MainSchedule — rafraîchit Vélivert toutes les 60s
```

## API publique

Les endpoints v1 `/api/stops/*` sont conservés en alias dépréciés et retournent
des `StopArea`. Les clients neufs consomment `/api/areas/*`.

### v2 — Areas (groupes d'arrêts)

| Méthode | Endpoint | Description |
|---|---|---|
| GET | `/api/areas/search?q=…` | Recherche par nom de zone (« Hôtel de Ville » au lieu de N quais) |
| GET | `/api/areas/in-bbox?minLat=…&maxLat=…&minLon=…&maxLon=…` | Zones visibles dans une bounding box (carte) |
| GET | `/api/areas/nearby?lat=…&lon=…&limit=5&radius=2000` | Zones triées par distance (haversine) à un point |
| GET | `/api/areas/{id}/departures?window=60&limit=15` | Prochains passages mergés depuis tous les quais de la zone |
| POST | `/api/areas/batch-departures` | Body `{ "ids": ["…"], "window": 50, "limit": 4 }` — N zones en 1 appel |
| GET | `/api/velivert/stations` | Toutes les stations Vélivert + état temps réel |
| GET | `/api/velivert/nearby?lat=…&lon=…&limit=5` | Stations triées par distance |
| GET | `/api/stops/{id}/departures` | Passages par **quai** individuel (debug, détail) |

### v1 — alias deprecated

| Méthode | Endpoint | Devient |
|---|---|---|
| GET | `/api/stops/search` | `/api/areas/search` |
| GET | `/api/stops/in-bbox` | `/api/areas/in-bbox` |

## Mode board (dashboard mobile)

`/board` : écran one-handed que l'on ouvre à un arrêt de bus ou en marchant.
Pas de carte — données denses, sectionnées :

- **À proximité** : 3 `StopArea` les plus proches (géoloc HTML5), 4 départs / zone.
- **Favoris** : liste épinglée — `localStorage` clé `mobilite.favorites.v1`,
  versionnée pour migrations futures (max 20).
- **Vélivert à proximité** : 3 stations + barre de remplissage colorée.
- **Sheet "Détail arrêt"** : 20 prochains départs (fenêtre 90 min), filtre
  Tram/Bus, bouton ⭐ favori, accordéon « Quais de cette zone ».
- **Sheet réglages** : géoloc, masquage modal/Vélivert, reset favoris.
- **Pull-to-refresh** : CSS transform + touchstart/touchend (sans lib).
- **PWA** : `manifest.webmanifest` + `theme-color`. Icônes pures-PHP générées
  via `bin/generate-board-icons.php` (pas de service worker pour ce sprint).

Si la géoloc est refusée : input texte → géocode via Nominatim → fallback
coordonnées centre Saint-Étienne.

## Modèle GTFS

Format standard. Quelques notes spécifiques à cette implémentation :

- Les horaires sont stockés en **secondes depuis minuit** (`int`). Un horaire de
  `26:30:00` devient `95400`. On accepte les valeurs > 86400 pour gérer les trips
  qui débordent sur le lendemain (dernier bus, navettes de nuit).
- `DepartureFinder` interroge deux fenêtres : services du jour pour les passages
  à venir, **et services de la veille** pour rattraper les trips encore en cours
  après minuit.
- Pas de PostGIS pour l'instant — bounding box en `BETWEEN`. Si tu veux du
  géospatial sérieux (nearest neighbor, isochrones), on bascule sur `geometry`
  + index GIST.

## Vélivert (GBFS)

Le `VelivertFetcher` :
1. Récupère le `gbfs.json` (discovery) pour obtenir les URLs des feeds.
2. Fetch `station_information.json` (statique : nom, position, capacité).
3. Fetch `station_status.json` (live : vélos/places dispo, opérationnel).
4. Merge les deux et upsert dans `velivert_station`.

Le `MainSchedule` déclenche ce refresh **toutes les 60 secondes** via
Symfony Messenger + Scheduler. Le worker tourne dans le container `worker` du
compose.

## Prochaines étapes (roadmap)

- [ ] Intégration **GTFS-RT** (Protobuf) pour merger les retards/suppressions
      en temps réel par-dessus les horaires théoriques
- [ ] Endpoint `/api/stops/nearby?lat&lon&radius` avec PostGIS (`<->`)
- [ ] Système de favoris (localStorage côté front d'abord, ensuite users + auth)
- [ ] Endpoint `/api/render/epaper` qui génère un PNG 800×480 1-bit
      consommable par un ESP32 TRMNL-BYOS
- [ ] App iOS native (SwiftUI) qui consomme la même API
- [ ] Widget WidgetKit qui montre les prochains passages d'un arrêt favori

## Déploiement

Pipeline **GitHub Actions → GHCR → Dokploy** (VPS auto-hébergé).
Stratégie : on build l'image Docker dans Actions, on la pousse sur GHCR,
puis on déclenche un webhook Dokploy qui pull et redéploie le compose.

### 1. Prérequis côté GitHub (one-time)

1. **Settings → Actions → General → Workflow permissions** :
   cocher **"Read and write permissions"** (nécessaire pour pousser sur GHCR
   avec le `GITHUB_TOKEN` automatique).
2. **Settings → Secrets and variables → Actions** : créer
   - `DOKPLOY_WEBHOOK_URL` — URL fournie par Dokploy (Service → Webhooks).
   - `DOKPLOY_TOKEN` — token Bearer fourni par Dokploy à côté de la même URL.

### 2. Setup Dokploy (one-time)

1. **Project** → New → "Furan".
2. **Add service** → **Compose**.
3. **Provider** : connecter le repo GitHub via l'app GitHub Dokploy
   (ou GitHub PAT si on préfère). Branch `main`, **Compose Path** `docker-compose.prod.yml`.
4. **Environment** : copier le contenu de [`.env.prod.example`](.env.prod.example),
   remplacer les `changeme` par les vraies valeurs (notamment `APP_SECRET` :
   `openssl rand -hex 16`, et `POSTGRES_PASSWORD` : password fort aléatoire).
5. **Registry** : Settings → Registry → Add → `ghcr.io` avec username GitHub
   et PAT (scope `read:packages` suffit pour pull).
6. **Domains** : ajouter le domaine cible sur le service `nginx`, Let's Encrypt
   activé en auto. Dokploy gère Traefik en amont.
7. **Webhooks** : générer l'URL + token, les coller dans les GitHub Secrets
   créés à l'étape 1.

### 3. Workflow quotidien

```bash
git push origin main
```

Le pipeline tourne en trois jobs séquentiels :

1. **test** : PHPUnit + migrations sur une Postgres jetable (skipé proprement
   si pas de `phpunit.xml`).
2. **build-and-push** : build de `docker/php/Dockerfile.prod`, push sur GHCR
   avec deux tags : `latest` et `sha-XXXXXXX` (court SHA du commit).
3. **deploy** : `POST` sur le webhook Dokploy → Dokploy fait un `docker compose pull`
   puis `up -d` côté VPS.

Suivre l'exécution dans **Actions** puis dans Dokploy → **Deployments**.
**Rollback en un clic** depuis l'historique Dokploy, ou en fixant
`IMAGE_TAG=sha-XXXXXXX` dans l'env Dokploy.

### 4. Premier déploiement

Le service `migrations` du compose s'occupe automatiquement de
`doctrine:migrations:migrate` puis de l'import GTFS initial.
Il sort en `exited (0)` → c'est normal, `restart: "no"` est attendu.

### 5. Refresh GTFS automatique

Le scheduler Symfony relance l'import **chaque lundi à 4h17 (Europe/Paris)**
via `RefreshGtfsMessage` → `RefreshGtfsHandler` → `GtfsImporter::importFromUrl()`.
Pour forcer manuellement depuis Dokploy → Service `php` → **Terminal** :

```sh
bin/console app:gtfs:import
```

Vérifier l'agenda du scheduler : `bin/console debug:scheduler`.

## Licence

MIT — données STAS et Vélivert sous Licence Ouverte 2.0 (Etalab).
