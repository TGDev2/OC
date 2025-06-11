<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use phpseclib3\Net\SFTP;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use App\PriceBarcodeExtractor;

$exifToolPath = __DIR__ . '/exiftool/exiftool';

/* -------- helpers GPS -------- */
function injectCoordinates(string $imgPath, float $lat, float $lon, string $exifToolPath, Logger $log): bool
{
    $cmd = sprintf(
        'perl %s -overwrite_original -GPSLatitude=%f -GPSLatitudeRef=%s -GPSLongitude=%f -GPSLongitudeRef=%s %s',
        escapeshellarg($exifToolPath),
        $lat,
        $lat >= 0 ? 'N' : 'S',
        $lon,
        $lon >= 0 ? 'E' : 'W',
        escapeshellarg($imgPath)
    );
    exec($cmd, $out, $rc);
    if ($rc === 0) {
        $log->info('GPS metadata added');
        return true;
    }
    $log->error('Failed to add GPS metadata');
    return false;
}

/* -------- init -------- */
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$log = new Logger('send_photo');
$log->pushHandler(new StreamHandler(__DIR__ . '/send_photo.log', Logger::DEBUG));
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['image'])) {
    echo json_encode(['status' => 'error', 'message' => 'Aucune image reçue']);
    $log->warning('No image payload');
    exit;
}

/* -------- décodage image -------- */
$imageBase64 = preg_replace('/^data:image\/\w+;base64,/', '', $data['image']);
$decoded     = base64_decode($imageBase64);
if ($decoded === false) {
    echo json_encode(['status' => 'error', 'message' => 'Décodage image impossible']);
    $log->error('Base64 decoding failed');
    exit;
}

/* -------- écriture image -------- */
$tmpDir  = sys_get_temp_dir();
$tmpFile = $tmpDir . DIRECTORY_SEPARATOR . 'photo_' . uniqid() . '.jpg';
file_put_contents($tmpFile, $decoded);

if (isset($data['location'])) {
    $lat = (float) $data['location']['coords']['latitude'];
    $lon = (float) $data['location']['coords']['longitude'];
    injectCoordinates($tmpFile, $lat, $lon, $exifToolPath, $log);
}

/* -------- OCR & code-barres -------- */
$extractor = new PriceBarcodeExtractor($log);
$ocrData   = $extractor->extract($tmpFile);

/* -------- upload SFTP -------- */
$filename     = 'photo_' . date('YmdHis') . '_' . uniqid() . '.jpg';
$sftpHost     = $_ENV['SFTP_HOST'];
$sftpPort     = (int)$_ENV['SFTP_PORT'];
$sftpUsername = $_ENV['SFTP_USERNAME'];
$sftpPassword = $_ENV['SFTP_PASSWORD'];
$remoteDir    = $_ENV['REMOTE_DIR'];

$sftp = new SFTP($sftpHost, $sftpPort);
if (!$sftp->login($sftpUsername, $sftpPassword)) {
    $log->error('SFTP login failed');
    echo json_encode(['status' => 'error', 'message' => 'Connexion SFTP impossible']);
    @unlink($tmpFile);
    exit;
}
if (!$sftp->is_dir($remoteDir) && !$sftp->mkdir($remoteDir, -1, true)) {
    $log->error('Remote dir creation failed');
    echo json_encode(['status' => 'error', 'message' => 'Répertoire distant indisponible']);
    @unlink($tmpFile);
    exit;
}
$remotePath = rtrim($remoteDir, '/') . '/' . $filename;
if (!$sftp->put($remotePath, file_get_contents($tmpFile), SFTP::SOURCE_STRING)) {
    $log->error('SFTP upload failed');
    echo json_encode(['status' => 'error', 'message' => 'Upload SFTP échoué']);
    @unlink($tmpFile);
    exit;
}
@unlink($tmpFile);

/* -------- réponse JSON -------- */
echo json_encode([
    'status'  => 'success',
    'message' => 'Upload réussi',
    'price'   => $ocrData['price'],
    'barcode' => $ocrData['barcode'],
    'file'    => $filename          // ← utilisé pour la phase de validation
]);
$log->info('Process finished OK', $ocrData);
