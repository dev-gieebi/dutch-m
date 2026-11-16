<?php

use App\Models\AdminUser;
use App\Services\Arrivals\ArrivalsService;
use App\Services\Auth\AdminAuthService;
use App\Services\Catalog\ProductIndexer;
use App\Services\Notifications\NotificationsService;
use App\Services\Payments\PaymentsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Réinitialise le mot de passe d'un administrateur (et le déverrouille) : php artisan admin:reset-password email@exemple.com
Artisan::command('admin:reset-password {email}', function (string $email) {
    $user = AdminUser::where('email', strtolower(trim($email)))->first();
    if (! $user) {
        $this->error("Aucun administrateur avec l'adresse {$email}");

        return 1;
    }
    $password = $this->secret('Nouveau mot de passe (8 caractères minimum)');
    if (strlen((string) $password) < 8) {
        $this->error('Mot de passe trop court');

        return 1;
    }
    $user->update(['password_hash' => AdminAuthService::hashPassword($password), 'is_active' => true, 'failed_attempts' => 0, 'locked_until' => null]);
    $this->info("✓ Mot de passe réinitialisé pour {$user->email}");
})->purpose("Réinitialise le mot de passe d'un administrateur");

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
