<?php

namespace App\Services\Inventory;

/** Effets de bord collectés pendant une transaction, déclenchés après validation. */
final class StockEffects
{
    /** @var array<int, true> */
    public array $lowStock = [];

    /** @var array<int, true> */
    public array $backInStock = [];

    /** @var array<int, true> */
    public array $productIds = [];

    public function addProduct(int $id): void
    {
        $this->productIds[$id] = true;
    }
}
