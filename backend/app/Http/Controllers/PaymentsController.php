<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Paiements mobile money côté boutique + webhooks opérateurs. */
class PaymentsController extends Controller
{
    public function __construct(private readonly PaymentsService $payments) {}

    private function token(Request $request): string
    {
        return $request->validate(['token' => ['required', 'string', 'between:10,64']])['token'];
    }

    public function initiate(Request $request): JsonResponse
    {
        $v = $request->validate([
            'orderNumber' => ['required', 'string', 'regex:/^AMO-\d{4}-\d{6}$/'],
            'trackingToken' => ['required', 'string', 'between:10,64'],
            'method' => ['required', 'in:AIRTEL_MONEY,MOOV_MONEY'],
            'payerPhone' => ['required', 'string', 'max:32'],
        ]);

        return response()->json($this->payments->initiate($v), 201);
    }

    public function status(Request $request, int $id): array
    {
        return $this->payments->status($id, $this->token($request));
    }

    public function check(Request $request, int $id): array
    {
        return $this->payments->check($id, $this->token($request));
    }

    public function simulate(Request $request, int $id): array
    {
        $v = $request->validate(['outcome' => ['required', 'in:success,failure'], 'token' => ['required', 'string', 'between:10,64']]);

        return $this->payments->simulate($id, $v['token'], $v['outcome']);
    }

    public function webhook(Request $request, string $provider): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }

        return $this->payments->handleWebhook($provider, $headers, $request->getContent());
    }
}
