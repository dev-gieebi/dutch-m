<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Adresse IP et agent utilisateur de la requête (journal d'audit, sessions). */
final class RequestMeta
{
    public function __construct(
        public readonly ?string $ip,
        public readonly ?string $userAgent,
    ) {}

    public static function from(Request $request): self
    {
        $ua = substr((string) $request->userAgent(), 0, 255);

        return new self($request->ip(), $ua !== '' ? $ua : null);
    }

    public static function none(): self
    {
        return new self(null, null);
    }
}
