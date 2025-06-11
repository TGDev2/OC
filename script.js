const startButton = document.getElementById('startButton');
const captureButton = document.getElementById('captureButton');
const confirmButton = document.getElementById('confirmButton');
const retakeButton = document.getElementById('retakeButton');
const video = document.getElementById('video');
const message = document.getElementById('message');
const loader = document.getElementById('loader');
const previewImage = document.getElementById('previewImage');
const startContainer = document.getElementById('startContainer');
const cameraContainer = document.getElementById('cameraContainer');
const photoContainer = document.getElementById('photoContainer');
const cameraSelect = document.getElementById('cameraSelect');
const zoomRange = document.getElementById('zoomRange');

let currentStream = null;
let imageData;
let positionData;

/**
 * Affiche un message temporaire.
 * @param {string} text - Le texte à afficher.
 * @param {string} type - Le type de message (success, danger, etc.).
 */
function showMessage(text, type) {
    message.textContent = text;
    message.className = `alert alert-${type}`;
    message.style.display = 'block';
    setTimeout(() => {
        message.style.display = 'none';
    }, 3000);
}

/**
 * Démarre la caméra avec le deviceId spécifié ou avec la facingMode par défaut.
 * @param {string|null} deviceId - L'ID de la caméra ou null.
 */
async function startCamera(deviceId = null) {
    // Arrêter le flux en cours, s'il existe
    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
    }
    const constraints = deviceId
        ? { video: { deviceId: { exact: deviceId } } }
        : { video: { facingMode: 'environment' } };

    try {
        const newStream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = newStream;
        currentStream = newStream;

        // Configuration du zoom si pris en charge
        const track = currentStream.getVideoTracks()[0];
        if (typeof track.getCapabilities === 'function') {
            const capabilities = track.getCapabilities();
            if (capabilities.zoom) {
                zoomRange.min = capabilities.zoom.min;
                zoomRange.max = capabilities.zoom.max;
                zoomRange.step = capabilities.zoom.step || 0.1;
                zoomRange.value = track.getSettings().zoom || 1;
                zoomRange.disabled = false;
            } else {
                zoomRange.disabled = true;
            }
        }
    } catch (error) {
        console.error("Erreur lors de l'activation de la caméra :", error);
        showMessage("Accès à la caméra refusé ou non disponible.", "danger");
    }
}

/**
 * Gestion du clic sur le bouton "Activer la Caméra".
 */
startButton.addEventListener('click', async () => {
    try {
        // Démarrer la caméra par défaut (facingMode=environment)
        await startCamera();

        // Liste des caméras disponibles
        const devices = await navigator.mediaDevices.enumerateDevices();
        const videoDevices = devices.filter(d => d.kind === 'videoinput');

        // Remplissage du <select> pour le choix de la caméra
        cameraSelect.innerHTML = '';
        videoDevices.forEach((device, index) => {
            const option = document.createElement('option');
            option.value = device.deviceId;
            option.text = device.label || `Caméra ${index + 1}`;
            cameraSelect.appendChild(option);
        });

        // Passage à l'écran caméra
        console.log("Passage à l'écran de la caméra");
        startContainer.style.display = 'none';
        cameraContainer.style.display = 'block';
    } catch (error) {
        console.error(error);
        showMessage("Accès à la caméra refusé ou non disponible.", "danger");
    }
});

/**
 * Changement de caméra selon le <select>.
 */
cameraSelect.addEventListener('change', () => {
    const deviceId = cameraSelect.value;
    startCamera(deviceId);
});

/**
 * Gestion du zoom via le slider.
 */
zoomRange.addEventListener('input', () => {
    if (!currentStream) return;
    const track = currentStream.getVideoTracks()[0];
    const zoom = parseFloat(zoomRange.value);
    track.applyConstraints({ advanced: [{ zoom }] })
        .catch(err => console.error("Erreur lors de l'application du zoom :", err));
});

/**
 * Capture de la photo (conversion du flux vidéo en image).
 */
captureButton.addEventListener('click', () => {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const context = canvas.getContext('2d');
    context.drawImage(video, 0, 0, canvas.width, canvas.height);

    // Arrêter le flux vidéo
    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
    }

    // Passage à l'écran d'aperçu de la photo
    cameraContainer.style.display = 'none';
    photoContainer.style.display = 'block';

    // Stockage et affichage de l'image capturée
    imageData = canvas.toDataURL('image/jpeg');
    previewImage.src = imageData;
});

/**
 * Bouton "Reprendre" pour relancer la caméra.
 */
retakeButton.addEventListener('click', async () => {
    const selectedDeviceId = cameraSelect.value || null;
    await startCamera(selectedDeviceId);
    photoContainer.style.display = 'none';
    cameraContainer.style.display = 'block';
});

/**
 * Envoi de la photo au serveur avec ou sans coordonnées GPS.
 */
confirmButton.addEventListener('click', () => {
    // Récupération de la géolocalisation et envoi de la photo
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(sendImageWithCoords, sendImage);
    } else {
        sendImage();
    }
});

/**
 * Fonction callback pour envoyer la photo avec les coordonnées.
 * @param {Position} geolocation 
 */
function sendImageWithCoords(geolocation) {
    if (geolocation) {
        positionData = geolocation;
    }
    sendImage();
}

/**
 * Envoie de la photo via fetch vers send-photo.php.
 */
function sendImage() {
    loader.style.display = 'block';

    fetch('send-photo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            image: imageData,
            location: positionData
        })
    })
        .then(res => res.json())
        .then(data => {
            loader.style.display = 'none';

            if (data.status === 'success') {
                const priceTxt = data.price !== null ? `${data.price.toFixed(2)} €` : 'non détecté';
                const barcodeTxt = data.barcode ?? 'non détecté';
                showMessage(`Prix : ${priceTxt} | Code-barres : ${barcodeTxt}`, 'success');
            } else {
                showMessage(data.message || 'Une erreur est survenue.', 'danger');
            }

            // Retour à l’écran d’accueil
            photoContainer.style.display = 'none';
            startContainer.style.display = 'flex';
        })
        .catch(err => {
            loader.style.display = 'none';
            console.error('Erreur fetch', err);
            showMessage('Erreur réseau ou serveur.', 'danger');
        });
}
