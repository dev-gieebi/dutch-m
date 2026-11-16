<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/**
 * Diffusion temps réel via Laravel Reverb (protocole Pusher).
 *  - private-admins          : toute l'équipe du back-office (JWT administrateur)
 *  - private-admin.{id}      : un administrateur précis
 *  - chat.{publicToken}      : une conversation côté client (le jeton secret fait office d'autorisation)
 * Les erreurs de diffusion ne bloquent jamais la requête (le front se rabat sur le rafraîchissement périodique).
 */
class Realtime
{
    private function send(callable $fn): void
    {
        if (in_array(config('broadcasting.default'), ['null', 'log'], true)) {
            return;
        }
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("Diffusion temps réel impossible : {$e->getMessage()}");
        }
    }

    public function emitToAdmins(string $event, mixed $data): void
    {
        $this->send(fn () => Broadcast::private('admins')->as($event)->with(self::payload($data))->sendNow());
    }

    public function emitToAdmin(int $adminId, string $event, mixed $data): void
    {
        $this->send(fn () => Broadcast::private("admin.{$adminId}")->as($event)->with(self::payload($data))->sendNow());
    }

    public function emitToConversation(int $conversationId, string $event, mixed $data): void
    {
        $this->send(function () use ($conversationId, $event, $data) {
            $token = Conversation::whereKey($conversationId)->value('public_token');
            if ($token) {
                Broadcast::on("chat.{$token}")->as($event)->with(self::payload($data))->sendNow();
            }
        });
    }

    private static function payload(mixed $data): array
    {
        return is_array($data) ? $data : json_decode(json_encode($data), true);
    }
}
