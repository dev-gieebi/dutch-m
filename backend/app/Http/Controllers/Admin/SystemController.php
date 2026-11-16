<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\SettingsService;
use App\Services\UploadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Journal d'audit, paramètres et téléversements du back-office. */
class SystemController extends Controller
{
    public function auditLogs(Request $request, AuditService $audit): array
    {
        $v = $request->validate([
            'adminUserId' => ['nullable', 'integer'],
            'entityType' => ['nullable', 'string'],
            'entityId' => ['nullable', 'string'],
            'action' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return $audit->list($v + $this->pagination($request));
    }

    public function publicSettings(SettingsService $settings)
    {
        return response()->json($settings->getPublic())->header('Cache-Control', 'public, max-age=60');
    }

    public function settings(SettingsService $settings): array
    {
        return $settings->getAll();
    }

    public function updateSettings(Request $request, SettingsService $settings): array
    {
        return $settings->update($request->json()->all(), $this->admin($request)->id, $this->meta($request));
    }

    public function upload(Request $request, UploadService $uploads): array
    {
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            throw new HttpException(400, 'Aucun fichier reçu');
        }
        if ($file->getSize() > config('amoria.upload_max_mb') * 1024 * 1024) {
            throw new HttpException(413, 'Fichier trop volumineux');
        }
        $content = file_get_contents($file->getRealPath());

        return $request->query('kind', 'image') === 'document'
            ? $uploads->saveDocument($content, $file->getMimeType() ?? '', $file->getClientOriginalName())
            : $uploads->saveImage($content, $file->getMimeType() ?? '');
    }
}
