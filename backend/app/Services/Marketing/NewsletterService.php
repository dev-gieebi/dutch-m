<?php

namespace App\Services\Marketing;

use App\Models\NewsletterSubscriber;
use App\Support\Format;
use App\Support\Paginated;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NewsletterService
{
    public function subscribe(array $dto, string $source = 'web'): array
    {
        if (empty($dto['consent'])) {
            throw new HttpException(400, 'Votre consentement est requis');
        }
        $email = ! empty($dto['email']) ? strtolower(trim($dto['email'])) : null;
        $phone = ! empty($dto['phone']) ? Format::normalizeGabonPhone($dto['phone']) : null;
        if (! empty($dto['phone']) && ! $phone) {
            throw new HttpException(400, 'Numéro gabonais invalide');
        }
        if (! $email && ! $phone) {
            throw new HttpException(400, 'Indiquez un e-mail ou un téléphone');
        }
        $existing = NewsletterSubscriber::where(fn ($w) => $w->when($email, fn ($q) => $q->orWhere('email', $email))->when($phone, fn ($q) => $q->orWhere('phone', $phone)))->first();
        if ($existing) {
            $existing->update(['unsubscribed_at' => null, 'consent_at' => now(), 'email' => $existing->email ?? $email, 'phone' => $existing->phone ?? $phone]);
        } else {
            NewsletterSubscriber::create(['email' => $email, 'phone' => $phone, 'consent_at' => now(), 'source' => $source]);
        }

        return ['message' => 'Merci ! Vous êtes inscrit(e) à la newsletter DutCh’M.'];
    }

    public function list(array $q): array
    {
        $qb = NewsletterSubscriber::query();
        if (! empty($q['q'])) {
            $qb->where(fn ($w) => $w->where('email', 'like', "%{$q['q']}%")->orWhere('phone', 'like', "%{$q['q']}%"));
        }
        $total = (clone $qb)->count();

        return Paginated::make($qb->orderByDesc('created_at')->forPage($q['page'], $q['limit'])->get()->toArray(), $total, $q);
    }

    public function unsubscribe(int $id): array
    {
        NewsletterSubscriber::whereKey($id)->update(['unsubscribed_at' => now()]);

        return ['success' => true];
    }
}
