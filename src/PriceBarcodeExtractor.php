<?php

declare(strict_types=1);

namespace App;

use thiagoalessio\TesseractOCR\TesseractOCR;
use Monolog\Logger;

/**
 * Service d’extraction du prix et du code-barres à partir d’une image.
 */
final class PriceBarcodeExtractor
{
    /** Liste des caractères autorisés pour Tesseract (réduction du bruit). */
    private const CHAR_WHITELIST = '0123456789€,. ';

    private Logger $log;
    private ImagePreprocessor $preprocessor;

    public function __construct(Logger $log)
    {
        $this->log          = $log;
        $this->preprocessor = new ImagePreprocessor($log);
    }

    /**
     * @param  string $imagePath Chemin du fichier image.
     * @return array{price: float|null, barcode: string|null}
     */
    public function extract(string $imagePath): array
    {
        // 1. Pré-traitement
        $processedPath = $this->preprocessor->process($imagePath);

        // 2. OCR
        $ocrText = (new TesseractOCR($processedPath))
            ->lang('fra', 'eng')
            ->whitelist(...str_split(self::CHAR_WHITELIST))
            ->run();

        $this->log->debug('OCR brut', ['text' => $ocrText]);

        // 3. Extraction
        $price   = $this->extractPrice($ocrText);
        $barcode = $this->scanBarcode($processedPath) ?? $this->extractDigits($ocrText);

        // 4. Nettoyage
        if ($processedPath !== $imagePath && is_file($processedPath)) {
            @unlink($processedPath);
        }

        return ['price' => $price, 'barcode' => $barcode];
    }

    /** Extrait le prix (format français ou international, € avant ou après). */
    private function extractPrice(string $text): ?float
    {
        $pattern = '/(?:€\s*)?(\d{1,3}(?:[ .]\d{3})*(?:[,.]\d{1,2}))\s*(?:€)?/u';

        if (preg_match($pattern, $text, $m)) {
            $normalized = str_replace([' ', ','], ['', '.'], $m[1]);
            return (float) $normalized;
        }
        return null;
    }

    /** Extrait un EAN-8 à EAN-13 présent dans le texte OCR. */
    private function extractDigits(string $text): ?string
    {
        return preg_match('/\b(\d{8,13})\b/', $text, $m) ? $m[1] : null;
    }

    /** Tente une lecture directe du code-barres avec zbarimg. */
    private function scanBarcode(string $imagePath): ?string
    {
        $cmd = sprintf('zbarimg -q --raw %s 2>/dev/null', escapeshellarg($imagePath));
        exec($cmd, $output, $code);

        if ($code === 0 && isset($output[0])) {
            $barcode = trim($output[0]);
            $this->log->info('Barcode détecté via zbarimg', ['barcode' => $barcode]);
            return $barcode;
        }

        $this->log->notice('Aucun barcode détecté via zbarimg');
        return null;
    }
}
