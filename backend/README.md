# DutCh’M — API (Laravel)

API REST de la boutique DutCh’M : catalogue, panier, commandes mixtes stock/précommande, paiements mobile money, arrivages fournisseur, livraisons, messagerie temps réel, back-office RBAC.

Toutes les routes sont préfixées par **`/api/v1`** ; les réponses JSON sont en camelCase et les erreurs ont toujours la forme `{ statusCode, message, errors? }`.

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed      # crée les tables, le super-admin, les paramètres et les gabarits
composer front                  # compile ../frontWeb dans public/dist
composer dev                    # site + API (:8000) + Reverb (:8080) + tâches planifiées
```

Toute URL hors `/api`, `/uploads`, `/sitemap.xml`, `/robots.txt` et `/up` est servie par `SpaController` : fichier du build s'il existe, sinon `public/dist/index.html` enrichi des métadonnées SEO calculées par `App\Services\SeoService`.

Sans `composer dev` : `php artisan serve`, `php artisan reverb:start` et `php artisan schedule:work` dans trois terminaux.

## Variables d'environnement principales (`.env`)

| Variable | Rôle |
|---|---|
| `DB_*` | Connexion MySQL (moteur InnoDB forcé, fuseau `+01:00`) |
| `WEB_PUBLIC_URL` / `CORS_ORIGINS` | URL de la boutique (liens dans les e-mails) / origines autorisées (tout `localhost` est accepté hors production) |
| `JWT_ADMIN_ACCESS_SECRET`, `JWT_CUSTOMER_ACCESS_SECRET`, `JWT_TEMP_SECRET` | Secrets des jetons d'accès admin / client / temporaire (2FA) |
| `JWT_ACCESS_TTL`, `REFRESH_TTL_DAYS`, `COOKIE_SECURE`, `COOKIE_DOMAIN` | Durées de session et cookie de rafraîchissement |
| `UPLOAD_DIR`, `UPLOAD_MAX_MB` | Fichiers téléversés (images converties en WebP 1600/800/300 px), servis sous `/uploads` |
| `MAIL_*` | E-mails (mettre `MAIL_MAILER=smtp` en production) |
| `SMS_PROVIDER` (`log`/`http`), `WHATSAPP_PROVIDER` (`log`/`cloud`) | Canaux SMS / WhatsApp (`log` écrit dans `storage/logs`) |
| `PAYMENT_MODE` (`mock`/`live`), `AIRTEL_*`, `MOOV_*`, `MOCK_WEBHOOK_SECRET` | Paiements : en `mock`, la page de paiement propose de simuler succès / échec |
| `BROADCAST_CONNECTION`, `REVERB_*` | Temps réel (mettre `log` pour le désactiver) |
| `SEED_ADMIN_*` | Super-administrateur créé par `php artisan db:seed` |

## Organisation du code

```
app/
  Enums/            Statuts, rôles, permissions… (valeurs identiques côté front)
  Support/          Domain (rôles & étapes), Format, DefaultSettings, Csv, Html, Template (gabarits)
  Models/           Modèles Eloquent (sérialisation camelCase via BaseModel)
  Services/         Logique métier : Auth, Catalog, Inventory, Orders, Payments (+ Providers), Arrivals,
                    Notifications, Messaging, Customers, Marketing, Content, Dashboard, Reports, Realtime
  Http/Controllers/ Boutique (racine) et back-office (Admin/)
  Http/Middleware/  AdminAuth (JWT + permissions), CustomerAuth, RouteThrottle (limite par route)
  Listeners/        NotificationsListener : événements métier → e-mails/SMS/WhatsApp + notifications internes
config/amoria.php   Configuration métier ; config/notification_templates.php : gabarits par défaut
routes/api.php      Toutes les routes ; routes/channels.php : canaux temps réel ; routes/console.php : planification
```

## Tâches planifiées (`routes/console.php`)

- toutes les 5 min : vérification des paiements en attente, expiration et annulation des commandes impayées ;
- toutes les 5 min : réessai des notifications en échec (3 tentatives) ;
- toutes les 10 min : recalcul des prix courants, promotions et disponibilités ;
- chaque jour à 8 h (Libreville) : alerte des arrivages en retard.

## Temps réel

Laravel Reverb (protocole Pusher). Canaux : `private-admins` et `private-admin.{id}` (autorisés par le JWT administrateur via `POST /api/v1/broadcasting/auth`), `chat.{jeton}` pour la conversation côté client. Événements : `order.created`, `order.updated`, `payment.updated`, `message.created`, `conversation.updated`, `stock.low`, `notification.created`.

## Paiements

Opérateurs `airtel`, `moov`, `mock` (+ `cash` / `manual` pour les encaissements). Les webhooks `POST /api/v1/payments/webhooks/{provider}` sont vérifiés par signature HMAC (`AIRTEL_WEBHOOK_SECRET`, `MOOV_WEBHOOK_SECRET`), contrôlés (montant, devise) et idempotents (table `payment_events`).

## Tests

```bash
php artisan test
```
