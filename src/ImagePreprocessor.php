<?php

declare(strict_types=1);

namespace App;

use Intervention\Image\ImageManagerStatic as Image;
use Monolog\Logger;

/**
 * Prépare une image pour l’OCR : ré-orientation, mise à l’échelle,
 * passage en niveaux de gris, augmentation du contraste et sharpening.
 */
final class ImagePreprocessor
{
    private Logger $log;

    public function __construct(Logger $log)
    {
        $this->log = $log;
        // Laisse Image choisir la meilleure driver dispo (Imagick > GD)
        Image::configure(['driver' => extension_loaded('imagick') ? 'imagick' : 'gd']);
    }

    /**
     * Retourne le chemin du fichier pré-traité (fichier temporaire).
     * Si un problème survient, retourne le chemin original pour fallback.
     */
    public function process(string $sourcePath): string
    {
        try {
            $image = Image::make($sourcePath)
                ->orientate()             // corrige la rotation EXIF
                ->resize(null, 1000, static function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                })
                ->greyscale()
                ->contrast(15)
                ->sharpen(10)
                ->threshold(90);

            $tmpFile = tempnam(sys_get_temp_dir(), 'pre_') . '.jpg';
            $image->save($tmpFile, 90, 'jpg');

            $this->log->info('Image pré-traitée', ['tmpFile' => $tmpFile]);

            return $tmpFile;
        } catch (\Throwable $e) {
            $this->log->warning('Pré-traitement échoué : fallback sur l’image brute', [
                'error' => $e->getMessage()
            ]);
            return $sourcePath;
        }
    }
}
