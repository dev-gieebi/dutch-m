<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannelType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Envoi effectif des notifications sur chaque canal (e-mail, SMS, WhatsApp). */
class Channels
{
    public function send(string $channel, string $recipient, ?string $subject, string $body): void
    {
        match ($channel) {
            NotificationChannelType::EMAIL->value => $this->email($recipient, $subject, $body),
            NotificationChannelType::SMS->value => $this->sms($recipient, $body),
            NotificationChannelType::WHATSAPP->value => $this->whatsapp($recipient, $body),
            default => throw new \RuntimeException('Canal indisponible'),
        };
    }

    /** E-mail via le mailer Laravel (SMTP, log…) configuré dans .env (MAIL_*). */
    private function email(string $to, ?string $subject, string $html): void
    {
        $text = trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $html)));
        Mail::html($html, function ($m) use ($to, $subject, $text) {
            $m->to($to)->subject($subject ?? 'DutCh’M')->text($text);
        });
    }

    /** SMS : `log` (par défaut, écrit dans les journaux) ou `http` (API générique : POST JSON {to, from, message}). */
    private function sms(string $to, string $body): void
    {
        $cfg = config('amoria.sms');
        if ($cfg['provider'] !== 'http') {
            Log::info("[SMS → {$to}] {$body}");

            return;
        }
        $res = Http::withToken($cfg['api_key'])->post($cfg['api_url'], ['to' => $to, 'from' => $cfg['sender'], 'message' => mb_substr($body, 0, 459)]);
        if (! $res->successful()) {
            throw new \RuntimeException("SMS refusé ({$res->status()}) : {$res->body()}");
        }
    }

    /** WhatsApp : `log` par défaut ou `cloud` (API WhatsApp Business Cloud de Meta, message texte). */
    private function whatsapp(string $to, string $body): void
    {
        $cfg = config('amoria.whatsapp');
        if ($cfg['provider'] !== 'cloud') {
            Log::info("[WhatsApp → {$to}] {$body}");

            return;
        }
        $res = Http::withToken($cfg['access_token'])->post("{$cfg['api_url']}/{$cfg['phone_number_id']}/messages", [
            'messaging_product' => 'whatsapp', 'to' => ltrim($to, '+'), 'type' => 'text', 'text' => ['body' => $body],
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException("WhatsApp refusé ({$res->status()}) : {$res->body()}");
        }
    }
}
