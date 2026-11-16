<?php

namespace App\Http\Controllers;

use App\Support\AdminPrincipal;
use App\Support\RequestMeta;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function admin(Request $request): AdminPrincipal
    {
        return $request->attributes->get('admin');
    }

    protected function customerId(Request $request): ?int
    {
        return $request->attributes->get('customerId');
    }

    protected function meta(Request $request): RequestMeta
    {
        return RequestMeta::from($request);
    }

    /** Pagination commune : page ≥ 1, limit entre 1 et 100 (défaut 20). */
    protected function pagination(Request $request, int $defaultLimit = 20): array
    {
        $v = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ['page' => (int) ($v['page'] ?? 1), 'limit' => (int) ($v['limit'] ?? $defaultLimit)];
    }
}
