<?php

declare(strict_types=1);

namespace App;

use thiagoalessio\TesseractOCR\TesseractOCR;
use Monolog\Logger;

/**
 * Service d’extraction du prix et du code-barres à partir d’une image.
 */
class PriceBarcodeExtractor
{
    private Logger $log;
    private ImagePreprocessor $preprocessor;

    public function __construct(Logger $log)
    {
        $this->log         = $log;
        $this->preprocessor = new ImagePreprocessor($log);
    }

    /**
     * @param  string $imagePath  Chemin du fichier image (JPEG/PNG…).
     * @return array{price: float|null, barcode: string|null}
     */
    public function extract(string $imagePath): array
    {
        // 1. Pré-traitement
        $processedPath = $this->preprocessor->process($imagePath);

        // 2. OCR sur l’image pré-traitée
        $ocrText = (new TesseractOCR($processedPath))
            ->lang('fra', 'eng')
            ->whitelist(range(0, 9) + ['€', ',', '.', ' ']) // réduit le bruit
            ->run();

        $this->log->debug('OCR brut', ['text' => $ocrText]);

        // 3. Extraction des infos
        $price   = $this->extractPrice($ocrText);
        $barcode = $this->scanBarcode($processedPath) ?? $this->extractDigits($ocrText);

        // 4. Nettoyage du fichier temporaire
        if ($processedPath !== $imagePath && is_file($processedPath)) {
            @unlink($processedPath);
        }

        return ['price' => $price, 'barcode' => $barcode];
    }

    private function extractPrice(string $text): ?float
    {
        if (preg_match('/(?:€\s*|\b)(\d{1,3}(?:[ .]\d{3})*(?:[,.]\d{2}))/u', $text, $m)) {
            $normalized = str_replace([' ', ','], ['', '.'], $m[1]);
            return (float) $normalized;
        }
        return null;
    }

    private function extractDigits(string $text): ?string
    {
        // Recherche d’une suite de 8 à 13 chiffres (EAN-8 / EAN-13)
        if (preg_match('/\b(\d{8,13})\b/', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    private function scanBarcode(string $imagePath): ?string
    {
        $cmd = sprintf('zbarimg -q --raw %s 2>/dev/null', escapeshellarg($imagePath));
        exec($cmd, $output, $code);
        if ($code === 0 && isset($output[0])) {
            return trim($output[0]);
        }
        return null;
    }
}
