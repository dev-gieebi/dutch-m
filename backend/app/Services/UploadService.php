<?php

namespace App\Services;

use App\Support\Format;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Fichiers téléversés : images converties en WebP (1600 / 800 / 300 px), documents PDF. */
class UploadService
{
    private const IMAGE_SIZES = ['large' => 1600, 'medium' => 800, 'thumb' => 300];

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function root(): string
    {
        $dir = config('amoria.upload_dir');

        return preg_match('/^([a-zA-Z]:)?[\/\\\\]/', $dir) ? $dir : base_path($dir);
    }

    /** @return array{rel: string, abs: string} */
    private function datedFolder(string $kind): array
    {
        $rel = $kind.'/'.now()->format('Y/m');
        $abs = self::root().'/'.$rel;
        if (! is_dir($abs)) {
            mkdir($abs, 0775, true);
        }

        return ['rel' => $rel, 'abs' => $abs];
    }

    /** @return array{url: string, mediumUrl: string, thumbUrl: string, width: int, height: int} */
    public function saveImage(string $content, string $mimeType): array
    {
        if (! in_array($mimeType, self::IMAGE_MIMES, true)) {
            throw new HttpException(400, 'Format accepté : JPEG, PNG ou WebP');
        }
        $info = @getimagesizefromstring($content);
        if (! $info) {
            throw new HttpException(400, "Le fichier n'est pas une image valide");
        }
        if (! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new HttpException(400, 'Le contenu du fichier ne correspond pas à une image autorisée');
        }

        return $this->storeWebp($content);
    }

    public function storeWebp(string $content): array
    {
        $src = @imagecreatefromstring($content);
        if (! $src) {
            throw new HttpException(400, "Le fichier n'est pas une image valide");
        }
        $src = $this->applyExifOrientation($src, $content);
        $w = imagesx($src);
        $h = imagesy($src);
        ['rel' => $rel, 'abs' => $abs] = $this->datedFolder('images');
        $name = Format::randomToken(12);
        $urls = [];
        foreach (self::IMAGE_SIZES as $key => $width) {
            $tw = min($width, $w);
            $th = (int) round($h * $tw / $w);
            $dst = imagecreatetruecolor($tw, $th);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            $file = "{$name}-{$width}.webp";
            imagewebp($dst, "{$abs}/{$file}", 80);
            imagedestroy($dst);
            $urls[$key] = "/uploads/{$rel}/{$file}";
        }
        imagedestroy($src);

        return ['url' => $urls['large'], 'mediumUrl' => $urls['medium'], 'thumbUrl' => $urls['thumb'], 'width' => $w, 'height' => $h];
    }

    private function applyExifOrientation(\GdImage $img, string $content): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($content));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle ? (imagerotate($img, $angle, 0) ?: $img) : $img;
    }

    /** Documents d'arrivage : PDF ou images. @return array{url: string, name: string, mimeType: string} */
    public function saveDocument(string $content, string $mimeType, string $originalName): array
    {
        $isPdf = $mimeType === 'application/pdf' && str_starts_with($content, '%PDF');
        if (! $isPdf && ! in_array($mimeType, self::IMAGE_MIMES, true)) {
            throw new HttpException(400, 'Format accepté : PDF, JPEG, PNG ou WebP');
        }
        $name = mb_substr($originalName, 0, 190);
        if (! $isPdf) {
            $img = $this->saveImage($content, $mimeType);

            return ['url' => $img['url'], 'name' => $name, 'mimeType' => 'image/webp'];
        }
        ['rel' => $rel, 'abs' => $abs] = $this->datedFolder('documents');
        $file = Format::randomToken(16).'.pdf';
        file_put_contents("{$abs}/{$file}", $content);

        return ['url' => "/uploads/{$rel}/{$file}", 'name' => $name, 'mimeType' => $mimeType];
    }
}
