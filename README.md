# DutCh’M — La Fleuriste du Bien-être

Plateforme e-commerce complète pour **DutCh’M**, fleuriste de fleurs éternelles et objets bien-être à **Libreville, Gabon**.

- Stock local avec livraison rapide à Libreville (zones et frais configurables).
- **Précommandes / importations** (sourcing Chine) avec acompte, délai configurable (~14 jours) et suivi d'expédition étape par étape.
- Commandes mixtes : articles en stock **et** en précommande dans une même commande, avec expéditions séparées ou regroupées.
- Paiements : **espèces à la livraison**, **Airtel Money**, **Moov Money**.
- Back-office complet intégré à la boutique (route `/admin`) : commandes, catalogue, stocks, arrivages, planning livreurs, clients, messagerie temps réel, marketing, contenus, rapports, journal d'audit, utilisateurs & rôles (RBAC), 2FA TOTP optionnelle.

## Structure du projet

```
backend/    API Laravel 13 (PHP 8.3+) + MySQL 8 — préfixe /api/v1, temps réel Laravel Reverb
frontWeb/   Application React 18 + Vite : boutique publique + back-office sous /admin
```

Le front est compilé dans **`backend/public/dist`** : en lançant le backend, la boutique, le back-office et l'API sont servis ensemble sur `http://localhost:8000`, avec les métadonnées SEO injectées côté serveur (voir § SEO).

## Prérequis

- PHP ≥ 8.3 avec les extensions `pdo_mysql`, `gd` (support WebP), `zip`, `intl`, `sodium` — WampServer convient
- Composer 2, Node.js ≥ 20 et npm ≥ 10
- MySQL 8

## Démarrage rapide

```bash
cd frontWeb && npm install && cd ..   # une seule fois
cd backend
composer install
cp .env.example .env          # puis renseigner DB_*, JWT_*, REVERB_* (voir backend/README.md)
php artisan key:generate
php artisan migrate --seed    # tables + super-admin + paramètres + gabarits
composer front                # compile frontWeb → backend/public/dist (à relancer après chaque modification du front)
composer dev                  # site complet sur http://localhost:8000 (+ WebSocket Reverb :8080 + tâches planifiées)
```

Développement du front avec rechargement à chaud (facultatif) : `cd frontWeb && npm run dev` → `http://localhost:5173`, qui appelle l'API sur `:8000` (`frontWeb/.env`). Le build de production utilise `frontWeb/.env.production` (API sur la même origine). `VITE_REVERB_APP_KEY` doit être égal à `REVERB_APP_KEY` du backend.

Compte administrateur du seed : `superadmin@amoria.ga` (mot de passe défini par `SEED_ADMIN_PASSWORD`, `12345678` par défaut). Connexion via la page « Connexion » de la boutique → redirection automatique vers `/admin`. La 2FA TOTP est **optionnelle**, activable depuis « Mon profil ».

## Qualité

```bash
cd backend  && php artisan test     # tests unitaires (tarification, étapes de suivi, propagation des arrivages, formats)
cd frontWeb && npm run typecheck    # vérification TypeScript
cd frontWeb && npm run build        # build de production (→ backend/public/dist)
```

## SEO

Laravel sert l'application React et injecte dans le HTML de **chaque URL** les métadonnées de la page, lisibles par les robots sans exécuter JavaScript (aperçus WhatsApp/Facebook, premier passage de Google) :

- `<title>`, meta description, URL canonique, `robots` (+ en-tête `X-Robots-Tag`), Open Graph (`og:*`, `product:price:*`), Twitter Cards ;
- données structurées JSON-LD schema.org : `Florist` + `WebSite` avec recherche (accueil), `Product` avec offre en XAF, disponibilité et note moyenne (fiches produit), `BreadcrumbList`, `CollectionPage` + `ItemList` (catalogue, catégories, collections), `FAQPage`, `AboutPage`/`WebPage`, `ContactPage` ;
- codes HTTP justes : 404 pour un produit, une catégorie ou une URL inconnue ; `noindex, nofollow` pour le back-office, le compte, le panier, la commande, le paiement, la recherche et le suivi d'une commande ;
- contenu de secours `<noscript>` ; `sitemap.xml` (pages publiées, catégories, collections, produits avec images et dates de modification) et `robots.txt` générés dynamiquement.

Après le premier affichage, le composant `<Seo>` du front (react-helmet-async) prend le relais lors de la navigation. Renseignez les champs « Titre SEO » / « Description SEO » des produits, catégories, collections et pages dans le back-office, et mettez `WEB_PUBLIC_URL` au nom de domaine réel en production (URL canoniques et sitemap).

## Production

1. **Site + API** : déployer `backend/` (avec `public/dist` compilé par `composer front`) derrière Apache ou Nginx, racine web = `backend/public`. `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` et `WEB_PUBLIC_URL` = domaine public (https), `COOKIE_SECURE=true`, puis `php artisan config:cache route:cache`. Le `.htaccess` fourni sert directement les fichiers du build (cache long) et envoie le reste à Laravel. Sous Nginx : `try_files $uri /dist$uri /index.php?$query_string;`.
2. **Tâches planifiées** : cron `* * * * * php /chemin/backend/artisan schedule:run`.
3. **Temps réel** : `php artisan reverb:start` géré par un superviseur (systemd, Supervisor), proxifié en WSS ; adapter `VITE_REVERB_*` dans `frontWeb/.env.production` avant le build.

## Sauvegarde / restauration

```bash
mysqldump -u root -p dutchm_db > backup-$(date +%F).sql     # sauvegarde
mysql -u root -p dutchm_db < backup-2026-01-01.sql         # restauration
```

Sauvegarder aussi le dossier des fichiers téléversés (`backend/storage/app/uploads` par défaut, voir `UPLOAD_DIR`).

## Sécurité

Argon2id · JWT courts + refresh en cookie HttpOnly avec rotation · 2FA TOTP optionnelle pour les administrateurs · verrouillage après 5 échecs · RBAC par permissions · CORS restreint · limitation de débit par route sur login/2FA/commande/paiement · journal d'audit horodaté (avant/après, IP, agent) · webhooks de paiement signés (HMAC) et idempotents.
