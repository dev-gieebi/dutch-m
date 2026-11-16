<?php

namespace App\Http\Controllers;

use App\Services\ContentService;
use App\Services\Marketing\NewsletterService;
use App\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Boutique : messagerie client, formulaire de contact, newsletter, contenus publics, avis. */
class StorefrontController extends Controller
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly NewsletterService $newsletter,
        private readonly ContentService $content,
    ) {}

    public function startConversation(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'between:2,120'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'message' => ['required', 'string', 'between:1,3000'],
            'productId' => ['nullable', 'integer'],
            'orderNumber' => ['nullable', 'string', 'max:20'],
            'subject' => ['nullable', 'string', 'max:150'],
        ], ['email.email' => 'Adresse e-mail invalide']);

        return response()->json($this->messaging->start($v, $this->customerId($request)), 201);
    }

    public function conversation(string $token): array
    {
        return $this->messaging->getByToken($token);
    }

    public function postMessage(Request $request, string $token): JsonResponse
    {
        $v = $request->validate(['body' => ['required', 'string', 'between:1,3000']]);

        return response()->json($this->messaging->postCustomer($token, $v['body']), 201);
    }

    public function contact(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'between:2,120'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'between:5,3000'],
        ], ['email.email' => 'Adresse e-mail invalide']);
        $conv = $this->messaging->start([
            'name' => $v['name'], 'phone' => $v['phone'], 'email' => $v['email'] ?? null, 'message' => $v['message'],
            'subject' => ($v['subject'] ?? '') ?: 'Formulaire de contact',
        ], $this->customerId($request));

        return response()->json(['message' => 'Merci ! Votre message a bien été transmis à notre équipe.', 'conversationToken' => $conv['publicToken']], 201);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $v = $request->validate([
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'consent' => ['required', 'boolean'],
        ], ['email.email' => 'Adresse e-mail invalide']);

        return response()->json($this->newsletter->subscribe($v), 201);
    }

    private function cached(mixed $data, int $maxAge): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', "public, max-age={$maxAge}");
    }

    public function page(string $slug): JsonResponse
    {
        return $this->cached($this->content->publicPage($slug), 300);
    }

    public function faq(): JsonResponse
    {
        return $this->cached($this->content->publicFaq(), 300);
    }

    public function banners(): JsonResponse
    {
        return $this->cached($this->content->publicBanners(), 120);
    }

    public function review(Request $request): JsonResponse
    {
        $v = $request->validate([
            'productId' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->content->createReview($this->customerId($request), $v), 201);
    }
}
