<?php

namespace App\Support;

/** Règles de validation partagées. */
final class Rules
{
    public const ADMIN_PASSWORD_REGEX = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{10,128}$/';

    public const ADMIN_PASSWORD_MESSAGE = 'Le mot de passe doit contenir au moins 10 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial';

    public const SLUG_REGEX = '/^[a-z0-9-]+$/';

    /** Ne garde que les clés effectivement présentes dans la requête validée. */
    public static function present(array $validated, array $keys): array
    {
        return array_intersect_key($validated, array_flip($keys));
    }
}
