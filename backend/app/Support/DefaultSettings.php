<?php

namespace App\Support;

/** Paramètres par défaut de la boutique (équivalent de settings.ts). */
final class DefaultSettings
{
    /** Clés exposées publiquement à la boutique. */
    public const PUBLIC_KEYS = [
        'shop', 'theme', 'whatsappNumber', 'supportHours', 'currency', 'payments', 'preorder', 'pickup', 'freeShippingThreshold', 'chat',
    ];

    public static function all(): array
    {
        return [
            'shop' => [
                'name' => 'DutCh’M',
                'tagline' => 'La Fleuriste du Bien-être',
                'logoUrl' => '',
                'email' => 'contact@amoria.ga',
                'phone' => '+24177270034',
                'address' => 'Quartier Louis, Libreville',
                'city' => 'Libreville',
                'socials' => ['facebook' => '', 'instagram' => '', 'tiktok' => ''],
            ],
            // Couleurs de l'interface (#RRGGBB) ; les nuances sont dérivées côté client.
            'theme' => ['primary' => '#D4A12A', 'secondary' => '#E6BE5A', 'background' => '#FCFAF5', 'text' => '#2B2115'],
            'whatsappNumber' => '+24177270034',
            'supportHours' => [
                'label' => 'Du lundi au samedi, de 9 h à 18 h',
                'slots' => array_map(fn ($day) => ['day' => $day, 'open' => '09:00', 'close' => '18:00'], [1, 2, 3, 4, 5, 6]),
            ],
            'currency' => 'XAF',
            'payments' => [
                'cashOnDelivery' => true,
                'airtelMoney' => true,
                'moovMoney' => true,
                'codForPreorder' => false,
                'depositPercent' => 50,
                'balanceOnDelivery' => true,
                'depositRefundable' => false,
                'expiryMinutes' => 30,
            ],
            'preorder' => ['defaultLeadTimeDays' => 14, 'maxQtyPerOrder' => 20],
            'notifications' => ['email' => true, 'sms' => false, 'whatsapp' => false],
            'pickup' => ['enabled' => true, 'address' => 'Boutique DutCh’M, Quartier Louis, Libreville', 'hours' => 'Lun–Sam 9 h – 18 h'],
            'freeShippingThreshold' => null,
            'chat' => [
                'welcomeMessage' => 'Bonjour et bienvenue chez DutCh’M 🌸 Comment pouvons-nous vous aider ?',
                'offHoursMessage' => 'Notre équipe est actuellement hors ligne. Nous vous répondrons dès la réouverture, généralement sous quelques heures.',
            ],
        ];
    }

    /** Indique si l'assistance est ouverte (heure de Libreville). */
    public static function isSupportOpen(array $slots, ?\DateTimeInterface $now = null): bool
    {
        $d = \Illuminate\Support\Carbon::instance($now ?? now())->setTimezone(Domain::TIMEZONE);
        $day = (int) $d->format('w');
        $hm = $d->format('H:i');
        foreach ($slots as $s) {
            if ((int) ($s['day'] ?? -1) === $day && $hm >= ($s['open'] ?? '') && $hm < ($s['close'] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
