<?php

namespace Database\Seeders;

use App\Enums\NotificationChannelType;
use App\Enums\RoleCode;
use App\Models\AdminUser;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Services\Auth\AdminAuthService;
use App\Support\DefaultSettings;
use Illuminate\Database\Seeder;

/**
 * Seed minimal : le super-administrateur et les données système
 * (paramètres par défaut + gabarits de notifications).
 * Les rôles et permissions sont définis dans le code (App\Support\Domain).
 * Le catalogue, les zones, les clients et tout le contenu se créent depuis l'admin.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $cfg = config('amoria.seed_admin');
        $email = strtolower($cfg['email']);
        if (! AdminUser::withTrashed()->where('email', $email)->exists()) {
            AdminUser::create([
                'email' => $email,
                'full_name' => $cfg['full_name'],
                'password_hash' => AdminAuthService::hashPassword($cfg['password']),
                'role_codes' => [RoleCode::SUPER_ADMIN->value],
            ]);
            $this->command?->info("✓ Super-administrateur créé : {$cfg['full_name']} <{$email}>");
        }

        foreach (DefaultSettings::all() as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
        $this->command?->info('✓ Paramètres');

        foreach (config('notification_templates') as $code => $def) {
            foreach (NotificationChannelType::values() as $channel) {
                NotificationTemplate::firstOrCreate(['code' => $code, 'channel' => $channel], [
                    'name' => $def['name'],
                    'subject' => $channel === 'EMAIL' ? $def['email']['subject'] : null,
                    'body' => match ($channel) {
                        'EMAIL' => $def['email']['body'],
                        'SMS' => $def['sms'],
                        default => $def['whatsapp'],
                    },
                ]);
            }
        }
        $this->command?->info('✓ Gabarits de notifications');
    }
}
