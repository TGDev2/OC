<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use phpseclib3\Net\SFTP;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

header('Content-Type: application/json');

/* -------- log -------- */
$log = new Logger('save_data');
$log->pushHandler(new StreamHandler(__DIR__ . '/save_data.log', Logger::DEBUG));

/* -------- input -------- */
$payload = json_decode(file_get_contents('php://input'), true);
if (!isset($payload['file'])) {
    echo json_encode(['status' => 'error', 'message' => 'Nom de fichier manquant']);
    $log->warning('Missing file name', $payload);
    exit;
}

$filename = basename($payload['file']);          // sécurise le nom
$price    = isset($payload['price'])   ? (float)$payload['price']   : null;
$barcode  = isset($payload['barcode']) ? preg_replace('/\D/', '', (string)$payload['barcode']) : null;

if ($price === null && $barcode === null) {
    echo json_encode(['status' => 'error', 'message' => 'Aucune donnée à enregistrer']);
    $log->warning('Nothing to save', $payload);
    exit;
}

/* -------- métadonnées -------- */
$metadata = [
    'file'    => $filename,
    'price'   => $price,
    'barcode' => $barcode,
    'updated' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)
];

/* -------- local backup -------- */
$localDir = __DIR__ . '/metadata';
if (!is_dir($localDir) && !mkdir($localDir, 0775, true) && !is_dir($localDir)) {
    $log->error('Cannot create metadata dir');
}
file_put_contents($localDir . '/' . $filename . '.json', json_encode($metadata, JSON_PRETTY_PRINT));

/* -------- remote SFTP -------- */
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$sftpHost     = $_ENV['SFTP_HOST']     ?? '';
$sftpPort     = (int)($_ENV['SFTP_PORT'] ?? 22);
$sftpUsername = $_ENV['SFTP_USERNAME'] ?? '';
$sftpPassword = $_ENV['SFTP_PASSWORD'] ?? '';
$remoteDir    = $_ENV['REMOTE_DIR']    ?? '';

$uploaded = false;
if ($sftpHost && $sftpUsername && $remoteDir) {
    $sftp = new SFTP($sftpHost, $sftpPort);
    if ($sftp->login($sftpUsername, $sftpPassword)) {
        if (!$sftp->is_dir($remoteDir) && !$sftp->mkdir($remoteDir, -1, true)) {
            $log->error('Remote dir creation failed');
        } else {
            $remotePath = rtrim($remoteDir, '/') . '/' . $filename . '.json';
            $uploaded   = $sftp->put($remotePath, json_encode($metadata));
            if (!$uploaded) $log->error('Metadata upload failed');
        }
    } else { $log->error('SFTP login failed'); }
} else { $log->warning('SFTP variables missing – upload skipped'); }

/* -------- réponse -------- */
echo json_encode([
    'status'   => 'success',
    'message'  => 'Métadonnées enregistrées',
    'uploaded' => $uploaded
]);
$log->info('Metadata saved', $metadata);
