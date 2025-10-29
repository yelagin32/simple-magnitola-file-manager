// Функция для обработки загрузки файла
function uploadFile(file, remainingSpace) {
    if (remainingSpace !== -1 && file.size > remainingSpace) {
        alert(`Ошибка: Недостаточно места для загрузки файла ${file.name}. Требуется ${formatFileSize(file.size)}, доступно ${formatFileSize(remainingSpace)}.`);
        return;
    }

    const uploadProgress = document.getElementById('uploadProgress');
    const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB chunks
    const fileId = file.name + '-' + file.size + '-' + file.lastModified;

    // Возобновление загрузки: получаем номер чанка, с которого нужно начать
    let startChunk = localStorage.getItem(fileId) ? parseInt(localStorage.getItem(fileId), 10) : 0;

    let start = startChunk * CHUNK_SIZE;
    let chunkCounter = startChunk;
    let totalChunks = Math.ceil(file.size / CHUNK_SIZE);
    let retryCount = 0;
    const MAX_RETRIES = 3;
    let isCanceled = false;
    let currentXhr = null;

    const uploadItem = document.createElement('div');
    uploadItem.classList.add('upload-item');
    uploadItem.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <strong>${file.name}</strong>
                <div class="upload-status">
                    <span class="upload-status-icon pending"></span>
                    <span class="status-text">Подготовка к загрузке...</span>
                    <span class="upload-size">${formatFileSize(start)} / ${formatFileSize(file.size)}</span>
                </div>
                <div class="error-details text-danger mt-1" style="display: none;"></div>
            </div>
            <div>
                <div class="upload-speed">0 KB/s</div>
                <div class="eta"></div>
            </div>
        </div>
        <div class="progress">
            <div class="progress-bar" role="progressbar" style="width: ${(start / file.size) * 100}%">${Math.round((start / file.size) * 100)}%</div>
        </div>
        <div class="upload-actions mt-2 text-end">
            <button class="btn btn-sm btn-secondary cancel-upload-btn">Отменить</button>
        </div>
    `;
    uploadProgress.appendChild(uploadItem);

    const statusIcon = uploadItem.querySelector('.upload-status-icon');
    const statusText = uploadItem.querySelector('.status-text');
    const uploadSize = uploadItem.querySelector('.upload-size');
    const speedText = uploadItem.querySelector('.upload-speed');
    const etaText = uploadItem.querySelector('.eta');
    const progressBar = uploadItem.querySelector('.progress-bar');
    const errorDetails = uploadItem.querySelector('.error-details');
    const cancelButton = uploadItem.querySelector('.cancel-upload-btn');

    if (start > 0) {
        statusText.textContent = 'Возобновление загрузки...';
    }

    cancelButton.addEventListener('click', () => {
        isCanceled = true;
        if (currentXhr) {
            currentXhr.abort();
        }
        localStorage.removeItem(fileId);
        statusText.textContent = 'Загрузка отменена пользователем.';
        statusIcon.classList.remove('pending');
        statusIcon.classList.add('error');
        progressBar.classList.add('bg-danger');
        errorDetails.style.display = 'none';
        speedText.textContent = '0 KB/s';
        etaText.textContent = '';
    });

    function uploadChunk() {
        if (isCanceled || start >= file.size) {
            return;
        }

        const end = Math.min(start + CHUNK_SIZE, file.size);
        const chunk = file.slice(start, end);
        const xhr = new XMLHttpRequest();
        currentXhr = xhr;
        const formData = new FormData();

        formData.append('file', chunk, file.name);
        formData.append('chunk', chunkCounter);
        formData.append('chunks', totalChunks);

        let startTime = Date.now();

        xhr.timeout = 300000; // 5 минут

        xhr.ontimeout = () => retryUpload("Таймаут");
        xhr.onerror = () => retryUpload("Ошибка сети");

        function retryUpload(reason) {
            if (isCanceled) return;

            if (retryCount < MAX_RETRIES) {
                retryCount++;
                statusText.textContent = `Повторная попытка (${retryCount}/${MAX_RETRIES})...`;
                setTimeout(() => uploadChunk(), 1000 * retryCount);
            } else {
                statusText.textContent = `Ошибка загрузки.`;
                errorDetails.textContent = `Не удалось загрузить после ${MAX_RETRIES} попыток. Причина: ${reason}`;
                errorDetails.style.display = 'block';
                statusIcon.classList.remove('pending');
                statusIcon.classList.add('error');
                progressBar.classList.add('bg-danger');
                localStorage.removeItem(fileId); // Очищаем, так как возобновление не удалось
            }
        }

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const speed = e.loaded / ((Date.now() - startTime) / 1000);
                const remainingBytes = file.size - (start + e.loaded);
                const eta = speed > 0 ? remainingBytes / speed : 0;

                const totalLoaded = start + e.loaded;
                const percentComplete = (totalLoaded / file.size) * 100;

                progressBar.style.width = percentComplete + '%';
                progressBar.textContent = percentComplete.toFixed(0) + '%';

                uploadSize.textContent = `${formatFileSize(totalLoaded)} / ${formatFileSize(file.size)}`;
                speedText.textContent = `${formatFileSize(speed)}/s`;
                etaText.textContent = eta > 0 ? `~ ${formatTime(eta)}` : '';
            }
        };

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);

                    if (response.status === 'success') {
                        retryCount = 0; // Сбрасываем счетчик при успехе
                        start = end;
                        chunkCounter++;

                        if (start < file.size) {
                            localStorage.setItem(fileId, chunkCounter); // Сохраняем следующий чанк
                            uploadChunk();
                        } else {
                            // Последний чанк загружен
                            localStorage.removeItem(fileId);
                            statusIcon.classList.remove('pending');
                            statusIcon.classList.add('success');
                            statusText.textContent = 'Загрузка завершена';
                            progressBar.classList.add('bg-success');
                            cancelButton.style.display = 'none';
                            etaText.textContent = '';

                            setTimeout(() => location.reload(), 1000);
                        }
                    } else {
                        retryUpload(response.message || "Ошибка сервера");
                    }
                } catch (e) {
                    retryUpload("Ошибка обработки ответа");
                }
            } else {
                retryUpload(`Ошибка HTTP: ${xhr.status}`);
            }
        };

        xhr.open('POST', 'upload.php', true);
        xhr.send(formData);
        statusText.textContent = `Загрузка... (${chunkCounter + 1}/${totalChunks})`;
    }

    uploadChunk();
}


// Обработчик отправки формы загрузки файлов
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const files = document.getElementById('fileInput').files;
    const remainingSpace = parseInt(document.getElementById('upload-container').dataset.remainingSpace, 10);
    document.getElementById('uploadProgress').innerHTML = '';
    
    let totalUploadSize = 0;
    Array.from(files).forEach(file => totalUploadSize += file.size);

    if (remainingSpace !== -1 && totalUploadSize > remainingSpace) {
        alert(`Ошибка: Суммарный размер файлов (${formatFileSize(totalUploadSize)}) превышает доступную квоту (${formatFileSize(remainingSpace)}).`);
        return;
    }

    Array.from(files).forEach(file => uploadFile(file, remainingSpace));
});

// Обработчики для drag and drop
const dropZone = document.querySelector('.drop-zone');

if(dropZone) {
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, highlight, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, unhighlight, false);
    });

    function highlight(e) {
        dropZone.classList.add('dragover');
    }

    function unhighlight(e) {
        dropZone.classList.remove('dragover');
    }

    dropZone.addEventListener('drop', handleDrop, false);

    function handleDrop(e) {
        const files = e.dataTransfer.files;
        const remainingSpace = parseInt(document.getElementById('upload-container').dataset.remainingSpace, 10);
        document.getElementById('uploadProgress').innerHTML = '';

        let totalUploadSize = 0;
        Array.from(files).forEach(file => totalUploadSize += file.size);

        if (remainingSpace !== -1 && totalUploadSize > remainingSpace) {
            alert(`Ошибка: Суммарный размер файлов (${formatFileSize(totalUploadSize)}) превышает доступную квоту (${formatFileSize(remainingSpace)}).`);
            return;
        }

        Array.from(files).forEach(file => uploadFile(file, remainingSpace));
    }
}


// Функция форматирования размера файла в читаемый вид
function formatFileSize(bytes) {
    if (bytes === 0) return '0 байт';
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' ГБ';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' МБ';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' КБ';
    return bytes + ' байт';
}

function formatTime(seconds) {
    if (seconds === Infinity || isNaN(seconds)) return '';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    return [
        h > 0 ? h + 'ч' : '',
        m > 0 ? m + 'м' : '',
        s > 0 ? s + 'с' : '',
    ].filter(Boolean).join(' ');
}

// Функция сортировки файлов
function sortFiles() {
    const fileList = document.getElementById('fileList');
    const sortType = document.getElementById('sortType').value;
    const files = Array.from(fileList.getElementsByClassName('list-group-item'));

    files.sort((a, b) => {
        if (sortType === 'name') {
            return a.dataset.name.localeCompare(b.dataset.name);
        } else if (sortType === 'date') {
            return parseInt(b.dataset.date) - parseInt(a.dataset.date);
        } else if (sortType === 'type') {
            return a.dataset.type.localeCompare(b.dataset.type);
        } else if (sortType === 'size') {
            return parseInt(b.dataset.size) - parseInt(a.dataset.size);
        }
    });

    files.forEach(file => fileList.appendChild(file));
}
