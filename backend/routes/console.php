<?php

use App\Services\Arrivals\ArrivalsService;
use App\Services\Catalog\ProductIndexer;
use App\Services\Notifications\NotificationsService;
use App\Services\Payments\PaymentsService;
use Illuminate\Support\Facades\Schedule;

/*
| Tâches planifiées : `php artisan schedule:work` en développement,
| ou en production une tâche cron « * * * * * php artisan schedule:run ».
*/

// Vérification des paiements en attente, expiration et annulation des commandes impayées.
Schedule::call(fn () => app(PaymentsService::class)->reconcilePending())->everyFiveMinutes()->name('payments:reconcile')->withoutOverlapping();

// Réessai des notifications en échec (3 tentatives maximum).
Schedule::call(fn () => app(NotificationsService::class)->retryFailed())->everyFiveMinutes()->name('notifications:retry')->withoutOverlapping();

// Recalcul des prix courants, promotions et disponibilités des produits.
Schedule::call(fn () => app(ProductIndexer::class)->refresh())->everyTenMinutes()->name('products:reindex')->withoutOverlapping();

// Alerte quotidienne des arrivages en retard.
Schedule::call(fn () => app(ArrivalsService::class)->flagLateArrivals())->dailyAt('08:00')->timezone('Africa/Libreville')->name('arrivals:late');
