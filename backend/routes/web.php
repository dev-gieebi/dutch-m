<?php

use App\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

Route::get('sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('robots.txt', [SeoController::class, 'robots']);
Route::get('uploads/{path}', [SeoController::class, 'upload'])->where('path', '.*');

// All other web routes with web middleware
Route::middleware('web')->group(function () {
    // Fichiers du build React (JS, CSS, images) servis depuis public/dist
    Route::get('/{file}', function (string $file) {
        $path = public_path('dist/'.$file);
        abort_unless(is_file($path), 404);

        // Type MIME fixé selon l'extension (la détection automatique renvoie text/x-java pour les .js sous Windows)
        $types = [
            'js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json',
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        ];
        $type = $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

        return response()->file($path, ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=31536000, immutable']);
    })->where('file', '^(assets/.+|favicon\.(ico|png)|logo\.png)$');

    // Catch-all route for SPA
    Route::get('/{any?}', function () {
        return file_get_contents(public_path('dist/index.html'));
    })->where('any', '^(?!api|uploads|storage|broadcasting).*$');
});
