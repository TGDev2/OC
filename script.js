/* ----------- éléments DOM ----------- */
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

/* modal et inputs de validation */
const validationModalEl = document.getElementById('validationModal');
const priceInput = document.getElementById('priceInput');
const barcodeInput = document.getElementById('barcodeInput');
const saveDataButton = document.getElementById('saveDataButton');
const validationModal = new bootstrap.Modal(validationModalEl);

/* ---- variables d’état ---- */
let currentStream = null;
let imageData;
let positionData;
let uploadedFileName = null;

/* ----------- helpers ----------- */
function showMessage(text, type) {
    message.textContent = text;
    message.className = `alert alert-${type}`;
    message.style.display = 'block';
    setTimeout(() => { message.style.display = 'none'; }, 4000);
}

/* ----------- caméra ----------- */
async function startCamera(deviceId = null) {
    if (currentStream) currentStream.getTracks().forEach(t => t.stop());

    const constraints = deviceId
        ? { video: { deviceId: { exact: deviceId } } }
        : { video: { facingMode: 'environment' } };

    try {
        currentStream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = currentStream;

        // zoom dynamique si supporté
        const track = currentStream.getVideoTracks()[0];
        if (typeof track.getCapabilities === 'function') {
            const caps = track.getCapabilities();
            if (caps.zoom) {
                zoomRange.min = caps.zoom.min;
                zoomRange.max = caps.zoom.max;
                zoomRange.step = caps.zoom.step || 0.1;
                zoomRange.value = track.getSettings().zoom || 1;
                zoomRange.disabled = false;
            } else { zoomRange.disabled = true; }
        }
    } catch (e) {
        console.error('Caméra KO :', e);
        showMessage('Accès caméra refusé ou indisponible', 'danger');
    }
}

/* ----------- UI callbacks ----------- */
startButton.addEventListener('click', async () => {
    await startCamera();
    const devices = await navigator.mediaDevices.enumerateDevices();
    const videoDevices = devices.filter(d => d.kind === 'videoinput');
    cameraSelect.innerHTML = '';
    videoDevices.forEach((d, i) => {
        const opt = document.createElement('option');
        opt.value = d.deviceId;
        opt.text = d.label || `Caméra ${i + 1}`;
        cameraSelect.appendChild(opt);
    });
    startContainer.style.display = 'none';
    cameraContainer.style.display = 'block';
});

cameraSelect.addEventListener('change', () => startCamera(cameraSelect.value));

zoomRange.addEventListener('input', () => {
    if (!currentStream) return;
    currentStream.getVideoTracks()[0]
        .applyConstraints({ advanced: [{ zoom: parseFloat(zoomRange.value) }] })
        .catch(e => console.error('Zoom KO :', e));
});

captureButton.addEventListener('click', () => {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    if (currentStream) currentStream.getTracks().forEach(t => t.stop());

    imageData = canvas.toDataURL('image/jpeg');
    previewImage.src = imageData;
    cameraContainer.style.display = 'none';
    photoContainer.style.display = 'block';
});

retakeButton.addEventListener('click', async () => {
    await startCamera(cameraSelect.value || null);
    photoContainer.style.display = 'none';
    cameraContainer.style.display = 'block';
});

/* ----------- envoi image ----------- */
confirmButton.addEventListener('click', () => {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(sendImageWithCoords, sendImage);
    } else { sendImage(); }
});

function sendImageWithCoords(geo) {
    if (geo) positionData = geo;
    sendImage();
}

function sendImage() {
    loader.style.display = 'block';

    fetch('send-photo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ image: imageData, location: positionData })
    })
        .then(r => r.json())
        .then(data => {
            loader.style.display = 'none';

            if (data.status !== 'success') {
                showMessage(data.message || 'Erreur serveur', 'danger');
                resetToStart();
                return;
            }

            // pré-remplissage form
            uploadedFileName = data.file;
            priceInput.value = data.price !== null ? data.price.toFixed(2) : '';
            barcodeInput.value = data.barcode ?? '';

            validationModal.show();
        })
        .catch(err => {
            loader.style.display = 'none';
            console.error('fetch KO', err);
            showMessage('Erreur réseau', 'danger');
            resetToStart();
        });
}

/* ----------- sauvegarde métadonnées corrigées ----------- */
saveDataButton.addEventListener('click', () => {
    if (!uploadedFileName) return;

    const payload = {
        file: uploadedFileName,
        price: priceInput.value ? parseFloat(priceInput.value) : null,
        barcode: barcodeInput.value.trim() || null
    };

    fetch('save-data.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(r => r.json())
        .then(resp => {
            const type = resp.status === 'success' ? 'success' : 'danger';
            showMessage(resp.message || 'Erreur', type);
            validationModal.hide();
            resetToStart();
        })
        .catch(err => {
            console.error('save KO', err);
            showMessage('Erreur réseau', 'danger');
        });
});

/* ----------- utils ----------- */
function resetToStart() {
    photoContainer.style.display = 'none';
    startContainer.style.display = 'flex';
}
