<?php
// Inclure l'autoloader de Composer
require_once __DIR__ . '/vendor/autoload.php';
$exifToolPath = __DIR__ . '/exiftool/exiftool';

use phpseclib3\Net\SFTP;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Injecte les coordonnées GPS dans l'image via exifTool.
 *
 * @param string $imgPath Chemin de l'image.
 * @param float $lat Latitude.
 * @param float $lon Longitude.
 * @param string $exifToolPath Chemin vers exifTool.
 * @param Logger $log Logger.
 * @return bool
 */
function injectCoordinates($imgPath, $lat, $lon, $exifToolPath, $log) {
    $command = sprintf(
        'perl %s -overwrite_original -GPSLatitude=%f -GPSLatitudeRef=%s -GPSLongitude=%f -GPSLongitudeRef=%s %s',
        escapeshellarg($exifToolPath),
        $lat,
        $lat >= 0 ? 'N' : 'S',
        $lon,
        $lon >= 0 ? 'E' : 'W',
        escapeshellarg($imgPath)
    );

    exec($command, $output, $returnCode);

    if ($returnCode === 0) {
        $log->info("Métadonnées de localisation ajoutées avec succès.");
        return true;
    } else {
        unlink($imgPath);
        $log->error("Erreur lors de l'ajout des métadonnées de localisation");
        return false;
    }
}

// Charger les variables d'environnement
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Initialiser le logger
$log = new Logger('send_photo');
$log->pushHandler(new StreamHandler(__DIR__ . '/send_photo.log', Logger::DEBUG));

$log->info("Démarrage du script d'envoi de photo.");

// Récupérer les données reçues
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['image'])) {
    $log->info("Données de l'image reçues.");
    $imageData = $data['image'];

    // Nettoyer et décoder l'image
    $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
    $decodedImage = base64_decode($imageData);

    if ($decodedImage === false) {
        $log->error("Erreur lors du décodage de l'image.");
        exit;
    } else {
        $log->info("Décodage de l'image réussi.");
    }

    // Traitement des coordonnées GPS, si disponibles
    if(isset($data['location'])) {
        $tempDir = sys_get_temp_dir();
        $tempImagePath = $tempDir . DIRECTORY_SEPARATOR . 'photo_' . date('YmdHis') . '_' . uniqid() . '.jpg';

        if(file_put_contents($tempImagePath, $decodedImage)) {
            $latitude = $data['location']['coords']['latitude'];
            $longitude = $data['location']['coords']['longitude'];

            if (injectCoordinates($tempImagePath, $latitude, $longitude, $exifToolPath, $log)) {
                $newImageData = file_get_contents($tempImagePath);
                if($newImageData !== false) {
                    $decodedImage = $newImageData;
                    $log->info("L'image avec métadonnées a été créée avec succès.");
                }
            }
            if(file_exists($tempImagePath)){
                unlink($tempImagePath);
            }
        } else {
            $log->error("Erreur lors de la création de l'image temporaire");
        }
    }

    // Générer un nom de fichier unique
    $filename = 'photo_' . date('YmdHis') . '_' . uniqid() . '.jpg';
    $log->info("Nom du fichier généré", ['filename' => $filename]);

    // Récupérer les informations SFTP depuis les variables d'environnement
    $sftpHost     = $_ENV['SFTP_HOST'];
    $sftpPort     = intval($_ENV['SFTP_PORT']);
    $sftpUsername = $_ENV['SFTP_USERNAME'];
    $sftpPassword = $_ENV['SFTP_PASSWORD'];
    $remoteDir    = $_ENV['REMOTE_DIR'];

    $log->info("Tentative de connexion SFTP", [
        'host' => $sftpHost,
        'port' => $sftpPort,
        'username' => $sftpUsername
    ]);

    $sftp = new SFTP($sftpHost, $sftpPort);
    if (!$sftp->login($sftpUsername, $sftpPassword)) {
        $log->error("Erreur lors de la connexion SFTP.");
        echo 'Erreur lors de la connexion SFTP.';
        exit;
    }
    $log->info("Connexion SFTP réussie.");

    // Vérifier/créer le répertoire distant
    if (!$sftp->is_dir($remoteDir)) {
        $log->info("Répertoire distant non trouvé, tentative de création.", ['remoteDir' => $remoteDir]);
        if (!$sftp->mkdir($remoteDir, -1, true)) {
            $log->error("Erreur lors de la création du répertoire distant.");
            echo 'Erreur lors de la création du répertoire distant.';
            exit;
        }
        $log->info("Répertoire distant créé avec succès.");
    } else {
        $log->info("Répertoire distant confirmé.", ['remoteDir' => $remoteDir]);
    }

    $remoteFilePath = rtrim($remoteDir, '/') . '/' . $filename;
    $log->info("Chemin distant du fichier", ['remoteFilePath' => $remoteFilePath]);

    if ($sftp->put($remoteFilePath, $decodedImage, SFTP::SOURCE_STRING)) {
        $log->info("Fichier envoyé avec succès", ['remoteFilePath' => $remoteFilePath]);
        echo 'La photo a été envoyée avec succès.';
    } else {
        $log->error("Erreur lors de l'envoi du fichier via SFTP.");
        echo 'Erreur lors de l\'envoi du fichier via SFTP.';
    }
} else {
    $log->warning("Aucune donnée d'image reçue.");
    echo 'Aucune donnée d\'image reçue.';
}

$log->info("Fin du script.");
?>
