<?php

namespace App\Support\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/** Colonnes communes à toutes les tables métier. */
final class Columns
{
    /** id bigint non signé + created_at / updated_at en datetime(6). */
    public static function base(Blueprint $t): void
    {
        $t->id();
        $t->dateTime('created_at', 6)->useCurrent();
        $t->dateTime('updated_at', 6)->useCurrent()->useCurrentOnUpdate();
    }

    /** Montant entier en FCFA. */
    public static function money(Blueprint $t, string $name, bool $nullable = false): ColumnDefinition
    {
        $col = $t->integer($name);

        return $nullable ? $col->nullable() : $col->default(0);
    }

    /** Clé étrangère bigint non signée, avec contrainte optionnelle. */
    public static function fk(Blueprint $t, string $name, ?string $table = null, string $onDelete = 'set null', bool $nullable = true, bool $index = true): void
    {
        $col = $t->unsignedBigInteger($name);
        if ($nullable) {
            $col->nullable();
        }
        if ($table) {
            $t->foreign($name)->references('id')->on($table)->onDelete($onDelete);
        } elseif ($index) {
            $t->index($name);
        }
    }
}
