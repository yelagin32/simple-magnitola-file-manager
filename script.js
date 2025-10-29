// Функция для обработки загрузки файла
function uploadFile(file, remainingSpace) {
    if (remainingSpace !== -1 && file.size > remainingSpace) {
        alert(`Ошибка: Недостаточно места для загрузки файла ${file.name}. Требуется ${formatFileSize(file.size)}, доступно ${formatFileSize(remainingSpace)}.`);
        return;
    }

    const uploadProgress = document.getElementById('uploadProgress');
    const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB chunks
    let start = 0;
    let end = CHUNK_SIZE;
    let chunkCounter = 0;
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
                    <span class="upload-size">0 / ${formatFileSize(file.size)}</span>
                </div>
                <div class="error-details text-danger mt-1" style="display: none;"></div>
            </div>
            <div class="upload-speed">0 KB/s</div>
        </div>
        <div class="progress">
            <div class="progress-bar" role="progressbar" style="width: 0%">0%</div>
        </div>
        <div class="upload-actions mt-2 text-end" style="display: none;">
            <button class="btn btn-sm btn-secondary cancel-upload-btn">Отменить</button>
        </div>
    `;
    uploadProgress.appendChild(uploadItem);

    const statusIcon = uploadItem.querySelector('.upload-status-icon');
    const statusText = uploadItem.querySelector('.status-text');
    const uploadSize = uploadItem.querySelector('.upload-size');
    const speedText = uploadItem.querySelector('.upload-speed');
    const progressBar = uploadItem.querySelector('.progress-bar');
    const errorDetails = uploadItem.querySelector('.error-details');
    const uploadActions = uploadItem.querySelector('.upload-actions');
    const cancelButton = uploadItem.querySelector('.cancel-upload-btn');

    cancelButton.addEventListener('click', () => {
        isCanceled = true;
        if (currentXhr) {
            currentXhr.abort();
        }
        statusText.textContent = 'Загрузка отменена пользователем.';
        statusIcon.classList.remove('pending');
        statusIcon.classList.add('error');
        progressBar.classList.add('bg-danger');
        progressBar.style.width = '100%';
        errorDetails.style.display = 'none';
        uploadActions.style.display = 'none';
        speedText.textContent = '0 KB/s';
    });

    function uploadChunk() {
        if (isCanceled) {
            return;
        }

        const chunk = file.slice(start, end);
        const xhr = new XMLHttpRequest();
        currentXhr = xhr;
        const formData = new FormData();

        formData.append('file', chunk, file.name);
        formData.append('chunk', chunkCounter);
        formData.append('chunks', totalChunks);

        let startTime = Date.now();
        let lastLoaded = 0;
        let uploadTimeout;

        xhr.timeout = 300000;

        xhr.ontimeout = function() {
            if (isCanceled) return;
            clearTimeout(uploadTimeout);
            retryUpload("Таймаут");
        };

        xhr.onerror = function() {
            if (isCanceled) return;
            clearTimeout(uploadTimeout);
            retryUpload("Ошибка сети");
        };

        function retryUpload(reason) {
            if (isCanceled) return;
            clearTimeout(uploadTimeout);

            if (retryCount < MAX_RETRIES) {
                retryCount++;
                statusText.textContent = `Повторная попытка (${retryCount}/${MAX_RETRIES}): ${reason}`;
                errorDetails.textContent = `Причина: ${reason}. Повторная попытка через ${1000 * retryCount} мс.`;
                errorDetails.style.display = 'block';
                uploadActions.style.display = 'none';

                setTimeout(() => {
                    uploadChunk();
                }, 1000 * retryCount);
            } else {
                statusText.textContent = `Ошибка загрузки после ${MAX_RETRIES} попыток`;
                errorDetails.textContent = `Ошибка загрузки после ${MAX_RETRIES} попыток. Причина: ${reason}`;
                errorDetails.style.display = 'block';
                statusIcon.classList.remove('pending');
                statusIcon.classList.add('error');
                progressBar.classList.add('bg-danger');
                uploadActions.style.display = 'block';
            }
        }

        xhr.upload.onprogress = function(e) {
            if (isCanceled) {
                xhr.abort();
                return;
            }
            clearTimeout(uploadTimeout);
            if (e.lengthComputable) {
                const currentTime = Date.now();
                const elapsedTime = (currentTime - startTime) / 1000;
                const loadDifference = e.loaded - lastLoaded;
                const speed = loadDifference / elapsedTime;

                const totalLoaded = start + e.loaded;
                const percentComplete = (totalLoaded / file.size) * 100;

                progressBar.style.width = percentComplete + '%';
                progressBar.textContent = percentComplete.toFixed(0) + '%';

                uploadSize.textContent = `${formatFileSize(totalLoaded)} / ${formatFileSize(file.size)}`;
                speedText.textContent = `${formatFileSize(speed)}/s`;

                startTime = currentTime;
                lastLoaded = e.loaded;

                uploadTimeout = setTimeout(() => {
                    xhr.abort();
                    retryUpload("Нет прогресса");
                }, 30000);
            }
        };

        xhr.onload = function() {
            if (isCanceled) return;
            clearTimeout(uploadTimeout);
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);

                    if (response.status === 'success') {
                        retryCount = 0;

                        if (end < file.size) {
                            start = end;
                            end = Math.min(start + CHUNK_SIZE, file.size);
                            chunkCounter++;
                            uploadChunk();
                        } else {
                            statusIcon.classList.remove('pending');
                            statusIcon.classList.add('success');
                            statusText.textContent = 'Загрузка завершена';
                            progressBar.classList.remove('bg-danger');
                            progressBar.classList.add('bg-success');
                            errorDetails.style.display = 'none';
                            uploadActions.style.display = 'none';

                            setTimeout(() => {
                                location.reload();
                            }, 1000);
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


// Функция форматирования размера файла в читаемый вид
function formatFileSize(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' ГБ';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' МБ';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' КБ';
    return bytes + ' байт';
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
