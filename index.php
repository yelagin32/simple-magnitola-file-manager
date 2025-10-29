<?php
// Основные настройки приложения
// https://yelagin.ru/all/simple-script-filemanager/
define('PASSWORD', '1106'); // ПАРОЛЬ для доступа к файловому менеджеру СМЕНИТЕ!!!
define('CHUNKS_DIR', 'chunks'); // Директория для временного хранения частей файлов

// Включаем отображение всех ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Настройки для загрузки больших файлов
ini_set('upload_max_filesize', '4G');
ini_set('post_max_size', '4G');
ini_set('memory_limit', '4G');
ini_set('max_execution_time', '3600'); // 1 час
ini_set('max_input_time', '3600');     // 1 час

// Функция для логирования
function writeLog($message) {
    file_put_contents('upload.log', date('Y-m-d H:i:s') . ': ' . $message . "\n", FILE_APPEND);
}

// Запуск сессии для хранения состояния авторизации
session_start();

// Создаем директорию для чанков, если она не существует
if (!file_exists(CHUNKS_DIR)) {
    mkdir(CHUNKS_DIR, 0777, true);
}

// Проверка авторизации пользователя
// Если пользователь не авторизован и отправил форму с паролем,
// проверяем правильность пароля и устанавливаем флаг авторизации
if (!isset($_SESSION['authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === PASSWORD) {
            $_SESSION['authenticated'] = true;
        }
    }
}

// Обработка выхода из системы
// При получении параметра logout уничтожаем сессию и перенаправляем на главную
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Обработка удаления файла
// Проверяем авторизацию и наличие параметра delete
if (isset($_GET['delete']) && isset($_SESSION['authenticated'])) {
    $fileToDelete = basename($_GET['delete']); // Получаем имя файла безопасным способом
    if (file_exists($fileToDelete) && $fileToDelete !== 'index.php') { // Проверяем существование файла и защищаем index.php от удаления
        unlink($fileToDelete);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Обработка загрузки файла
if (isset($_SESSION['authenticated']) && isset($_FILES['file'])) {
    $response = ['status' => 'error', 'message' => 'Неизвестная ошибка'];
    
    if (isset($_POST['chunk']) && isset($_POST['chunks'])) {
        $chunk = intval($_POST['chunk']);
        $chunks = intval($_POST['chunks']);
        $fileName = basename($_FILES['file']['name']);
        
        writeLog("Начало обработки чанка {$chunk} из {$chunks} для файла {$fileName}");
        
        // Создаем временную директорию для чанков этого файла
        $fileChunkDir = CHUNKS_DIR . '/' . md5($fileName);
        if (!file_exists($fileChunkDir)) {
            mkdir($fileChunkDir, 0777, true);
            writeLog("Создана директория для чанков: {$fileChunkDir}");
        }
        
        // Сохраняем чанк
        $chunkFile = $fileChunkDir . '/' . $chunk;
        
        // Проверяем, не загружен ли уже этот чанк
        if (file_exists($chunkFile) && filesize($chunkFile) > 0) {
            writeLog("Чанк {$chunk} уже существует, пропускаем");
            $response = [
                'status' => 'success',
                'message' => 'Чанк уже существует',
                'chunk' => $chunk,
                'chunks' => $chunks
            ];
        } else {
            if (move_uploaded_file($_FILES['file']['tmp_name'], $chunkFile)) {
                writeLog("Чанк {$chunk} успешно сохранен: {$chunkFile}");
                
                // Проверяем наличие всех чанков и их размер
                $allChunksUploaded = true;
                $uploadedChunks = [];
                
                for ($i = 0; $i < $chunks; $i++) {
                    $currentChunk = $fileChunkDir . '/' . $i;
                    if (!file_exists($currentChunk) || filesize($currentChunk) === 0) {
                        $allChunksUploaded = false;
                        break;
                    }
                    $uploadedChunks[] = $currentChunk;
                }
                
                writeLog("Проверка чанков: " . ($allChunksUploaded ? "все загружены" : "загружены не все"));
                
                if ($allChunksUploaded) {
                    writeLog("Все чанки загружены, начинаем объединение");
                    
                    // Все чанки загружены, объединяем их
                    $finalFileName = $fileName;
                    $counter = 1;
                    
                    while (file_exists($finalFileName)) {
                        $finalFileName = pathinfo($fileName, PATHINFO_FILENAME) 
                            . "_{$counter}." 
                            . pathinfo($fileName, PATHINFO_EXTENSION);
                        $counter++;
                    }
                    
                    writeLog("Создаем итоговый файл: {$finalFileName}");
                    
                    try {
                        // Открываем финальный файл для записи
                        $finalFile = fopen($finalFileName, 'wb');
                        if (!$finalFile) {
                            throw new Exception("Не удалось открыть файл для записи: {$finalFileName}");
                        }
                        
                        // Проверяем свободное место на диске
                        $totalSize = 0;
                        for ($i = 0; $i < $chunks; $i++) {
                            $chunkPath = $fileChunkDir . '/' . $i;
                            $totalSize += filesize($chunkPath);
                        }
                        
                        $requiredSpace = $totalSize * 2; // Умножаем на 2, так как нужно место для чанков и финального файла
                        $freeSpace = disk_free_space(dirname($finalFileName));
                        if ($freeSpace < $requiredSpace) {
                            throw new Exception("Недостаточно места на диске. Требуется: " . 
                                number_format($requiredSpace / (1024*1024), 2) . " МБ, доступно: " . 
                                number_format($freeSpace / (1024*1024), 2) . " МБ");
                        }
                        
                        // Объединяем чанки с дополнительными проверками
                        for ($i = 0; $i < $chunks; $i++) {
                            $chunkPath = $fileChunkDir . '/' . $i;
                            writeLog("Обработка чанка {$i}: {$chunkPath}");
                            
                            // Проверяем размер чанка
                            $chunkSize = filesize($chunkPath);
                            if ($chunkSize === 0) {
                                throw new Exception("Чанк {$i} пуст: {$chunkPath}");
                            }
                            
                            // Читаем чанк с проверкой
                            $chunkContent = file_get_contents($chunkPath);
                            if ($chunkContent === false) {
                                throw new Exception("Не удалось прочитать чанк {$i}: {$chunkPath}");
                            }
                            
                            // Записываем с проверкой
                            $bytesWritten = fwrite($finalFile, $chunkContent);
                            if ($bytesWritten === false || $bytesWritten !== strlen($chunkContent)) {
                                throw new Exception("Ошибка записи чанка {$i}: записано {$bytesWritten} из " . strlen($chunkContent) . " байт");
                            }
                            
                            // Очищаем память
                            unset($chunkContent);
                        }
                        
                        // Закрываем файл
                        fclose($finalFile);
                        
                        // Проверяем итоговый файл
                        if (!file_exists($finalFileName) || filesize($finalFileName) !== $totalSize) {
                            throw new Exception("Ошибка проверки итогового файла. Ожидаемый размер: {$totalSize}, фактический: " . filesize($finalFileName));
                        }
                        
                        writeLog("Файл успешно создан: {$finalFileName}, размер: " . filesize($finalFileName));
                        
                        // Удаляем чанки и директорию только после успешного создания файла
                        foreach ($uploadedChunks as $chunk) {
                            unlink($chunk);
                        }
                        rmdir($fileChunkDir);
                        
                        $response = [
                            'status' => 'success',
                            'message' => 'Файл успешно загружен!',
                            'filename' => $finalFileName
                        ];
                        
                    } catch (Exception $e) {
                        writeLog("Критическая ошибка: " . $e->getMessage());
                        if (isset($finalFile)) {
                            fclose($finalFile);
                        }
                        if (file_exists($finalFileName)) {
                            unlink($finalFileName);
                        }
                        $response = [
                            'status' => 'error',
                            'message' => 'Ошибка при создании файла: ' . $e->getMessage()
                        ];
                    }
                } else {
                    $response = [
                        'status' => 'success',
                        'message' => 'Чанк успешно загружен',
                        'chunk' => $chunk,
                        'chunks' => $chunks,
                        'uploaded' => count($uploadedChunks)
                    ];
                }
            } else {
                writeLog("Ошибка при сохранении чанка {$chunk}");
                $response = [
                    'status' => 'error',
                    'message' => 'Ошибка при сохранении чанка'
                ];
            }
        }
    } else {
        // Обычная загрузка файла (для маленьких файлов)
        $filename = basename($_FILES['file']['name']);
        $counter = 1;
        
        while (file_exists($filename)) {
            $filename = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME) 
                . "_{$counter}." 
                . pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $counter++;
        }
        
        if (move_uploaded_file($_FILES['file']['tmp_name'], $filename)) {
            $response = [
                'status' => 'success',
                'message' => 'Файл успешно загружен!',
                'filename' => $filename
            ];
        }
    }
    
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Файлообменник</title>
    <!-- Подключение стилей Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Стили для элементов прогресса загрузки */
        .progress { 
            height: 20px;
            background-color: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        .progress-bar {
            transition: width 0.3s ease-in-out;
            position: relative;
            overflow: hidden;
        }
        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                45deg,
                rgba(255,255,255,0.2) 25%,
                transparent 25%,
                transparent 50%,
                rgba(255,255,255,0.2) 50%,
                rgba(255,255,255,0.2) 75%,
                transparent 75%
            );
            background-size: 30px 30px;
            animation: progress-animation 1s linear infinite;
        }
        @keyframes progress-animation {
            0% { background-position: 0 0; }
            100% { background-position: 30px 0; }
        }
        .upload-item { 
            margin-bottom: 15px;
            padding: 15px;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .upload-status { 
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .upload-status-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
        }
        .upload-status-icon.pending {
            background: #ffc107;
            animation: pulse 1s infinite;
        }
        .upload-status-icon.success {
            background: #28a745;
        }
        .upload-status-icon.error {
            background: #dc3545;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        /* Стили для зоны перетаскивания */
        .drop-zone {
            border: 3px dashed #ccc;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            transition: all 0.3s ease;
            position: relative;
        }
        .drop-zone.dragover {
            background: #e9ecef;
            border-color: #0d6efd;
            transform: scale(1.02);
        }
        .drop-zone::before {
            content: '📁';
            font-size: 2em;
            display: block;
            margin-bottom: 10px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <?php if (!isset($_SESSION['authenticated'])): ?>
            <!-- Форма входа для неавторизованных пользователей -->
            <div class="row justify-content-center">
                <div class="col-md-6 col-lg-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title text-center mb-4">Вход</h5>
                            <form method="POST" autocomplete="off">
                                <div class="mb-3">
                                    <input type="password" name="password" class="form-control" placeholder="Введите пароль" required autocomplete="new-password">
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Войти</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Панель управления для авторизованных пользователей -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="card-title mb-0">Загрузка файлов</h5>
                        <a href="?logout=1" class="btn btn-danger">Выход</a>
                    </div>
                    
                    <!-- Зона для перетаскивания файлов -->
                    <div class="drop-zone mb-3">
                        <p class="mb-2">Перетащите файлы сюда или</p>
                        <!-- Форма загрузки файлов с поддержкой множественной загрузки -->
                        <form id="uploadForm">
                            <div class="input-group">
                                <input type="file" name="file" id="fileInput" class="form-control" multiple required>
                                <button type="submit" class="btn btn-primary">Загрузить</button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Контейнер для отображения прогресса загрузки -->
                    <div id="uploadProgress"></div>
                </div>
            </div>

            <!-- Список загруженных файлов -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-4">Список файлов</h5>
                    <div class="mb-3">
                        <select id="sortType" class="form-select" onchange="sortFiles()">
                            <option value="name">Сортировать по имени</option>
                            <option value="date" selected>Сортировать по дате</option>
                            <option value="type">Сортировать по типу файла</option>
                        </select>
                    </div>
                    <div id="fileList" class="list-group">
                        <?php
                        // Получаем список файлов в текущей директории, исключая системные файлы и upload.log
$files = array_diff(scandir('.'), array('.', '..', 'index.php', 'chunks', 'upload.log'));

                        // Подсчитываем общее количество файлов и их размер для информационной полоски
                        $totalFiles = count($files);
                        $totalSize = 0;
                        
                        foreach ($files as $file) {
                            $totalSize += filesize($file);
                        }
                        
                        // Форматируем размер в читаемый вид
                        $formattedTotalSize = '';
                        if ($totalSize >= 1073741824) { // 1 GB
                            $formattedTotalSize = number_format($totalSize / 1073741824, 2) . ' гигабайт';
                        } elseif ($totalSize >= 1048576) { // 1 MB
                            $formattedTotalSize = number_format($totalSize / 1048576, 2) . ' мегабайт';
                        } elseif ($totalSize >= 1024) { // 1 KB
                            $formattedTotalSize = number_format($totalSize / 1024, 2) . ' килобайт';
                        } else {
                            $formattedTotalSize = $totalSize . ' байт';
                        }
                        ?>

                        <!-- Информационная полоска с общей статистикой -->
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle"></i>
                            <strong>Загружено <?php echo $totalFiles; ?> файлов</strong> - занято <strong><?php echo $formattedTotalSize; ?></strong>
                        </div>

                        <?php if (empty($files)): ?>
                            <p class="text-muted">Нет загруженных файлов</p>
                        <?php else:
                            $fileList = array();
                            foreach ($files as $file) {
                                $fileList[] = array(
                                    'name' => $file,
                                    'size' => filesize($file),
                                    'date' => filemtime($file),
                                    'type' => pathinfo($file, PATHINFO_EXTENSION)
                                );
                            }
                            
                            // Сортировка по умолчанию по дате (от новых к старым)
                            usort($fileList, function($a, $b) {
                                return $b['date'] - $a['date'];
                            });

                            foreach ($fileList as $file):
                                $formattedSize = $file['size'] > 1048576 
                                    ? number_format($file['size'] / 1048576, 2) . ' MB'
                                    : number_format($file['size'] / 1024, 2) . ' KB';
                                $formattedDate = date('d.m.Y H:i:s', $file['date']);
                        ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center" 
                                 data-name="<?php echo htmlspecialchars($file['name']); ?>"
                                 data-date="<?php echo $file['date']; ?>"
                                 data-type="<?php echo $file['type']; ?>">
                                <div class="flex-grow-1">
                                    <a href="<?php echo htmlspecialchars($file['name']); ?>">
                                        <?php echo htmlspecialchars($file['name']); ?>
                                    </a>
                                    <div class="text-muted small">
                                        <SMALL><?php echo $formattedDate; ?></SMALL>
                                    </div>
                                </div>
                                <div class="ms-2">
                                    <span class="badge bg-primary rounded-pill"><?php echo $formattedSize; ?></span>
                                    <a href="?delete=<?php echo urlencode($file['name']); ?>" 
                                       class="btn btn-sm btn-danger ms-2" 
                                       onclick="return confirm('Вы уверены, что хотите удалить этот файл?');">Удалить</a>
                                </div>
                            </div>
                        <?php endforeach;
                        endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Отдельный блок для логов загрузки -->
    <?php if (file_exists('upload.log')): ?>
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="card-title mb-2">Логи загрузки</h6>
                <!-- Кнопка для просмотра лога в новой вкладке -->
                <a href="upload.log" target="_blank" class="btn btn-outline-secondary btn-sm">
                    Открыть upload.log
                </a>
                <!-- Кнопка для скачивания лога -->
                <a href="upload.log" download class="btn btn-outline-primary btn-sm ms-2">
                    Скачать upload.log
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Подключение скриптов Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Функция для обработки загрузки файла
    function uploadFile(file) {
        const uploadProgress = document.getElementById('uploadProgress');
        const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB chunks
        let start = 0;
        let end = CHUNK_SIZE;
        let chunkCounter = 0;
        let totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        let retryCount = 0;
        const MAX_RETRIES = 3;
        let isCanceled = false; // Флаг для отмены загрузки
        let currentXhr = null;  // Для хранения текущего XMLHttpRequest объекта
        
        // Создание элемента для отображения прогресса загрузки
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
                    <!-- Добавлен элемент для отображения деталей ошибки -->
                    <div class="error-details text-danger mt-1" style="display: none;"></div>
                </div>
                <div class="upload-speed">0 KB/s</div>
            </div>
            <div class="progress">
                <div class="progress-bar" role="progressbar" style="width: 0%">0%</div>
            </div>
            <!-- Добавлен контейнер для действий, таких как кнопка отмены -->
            <div class="upload-actions mt-2 text-end" style="display: none;">
                <button class="btn btn-sm btn-secondary cancel-upload-btn">Отменить</button>
            </div>
        `;
        uploadProgress.appendChild(uploadItem);

        // Получаем ссылки на элементы внутри uploadItem
        const statusIcon = uploadItem.querySelector('.upload-status-icon');
        const statusText = uploadItem.querySelector('.status-text');
        const uploadSize = uploadItem.querySelector('.upload-size');
        const speedText = uploadItem.querySelector('.upload-speed');
        const progressBar = uploadItem.querySelector('.progress-bar');
        const errorDetails = uploadItem.querySelector('.error-details');
        const uploadActions = uploadItem.querySelector('.upload-actions');
        const cancelButton = uploadItem.querySelector('.cancel-upload-btn');

        // Обработчик кнопки отмены
        cancelButton.addEventListener('click', () => {
            isCanceled = true;
            if (currentXhr) {
                currentXhr.abort(); // Прерываем текущий запрос
            }
            statusText.textContent = 'Загрузка отменена пользователем.';
            statusIcon.classList.remove('pending');
            statusIcon.classList.add('error');
            progressBar.classList.add('bg-danger');
            progressBar.style.width = '100%'; // Показываем полную полосу красным
            errorDetails.style.display = 'none'; // Скрываем предыдущие детали ошибки
            uploadActions.style.display = 'none'; // Скрываем кнопки действий
            speedText.textContent = '0 KB/s';
        });

        function uploadChunk() {
            if (isCanceled) { // Проверяем, не была ли загрузка отменена
                return;
            }

            const chunk = file.slice(start, end);
            const xhr = new XMLHttpRequest();
            currentXhr = xhr; // Сохраняем текущий XHR объект
            const formData = new FormData();
            
            formData.append('file', chunk, file.name);
            formData.append('chunk', chunkCounter);
            formData.append('chunks', totalChunks);
            
            let startTime = Date.now();
            let lastLoaded = 0;
            let uploadTimeout;

            xhr.timeout = 300000; // 5 минут на каждый чанк

            xhr.ontimeout = function() {
                if (isCanceled) return; // Не повторяем, если уже отменено
                clearTimeout(uploadTimeout);
                retryUpload("Таймаут");
            };

            xhr.onerror = function() {
                if (isCanceled) return; // Не повторяем, если уже отменено
                clearTimeout(uploadTimeout);
                retryUpload("Ошибка сети");
            };

            function retryUpload(reason) {
                if (isCanceled) return; // Не повторяем, если уже отменено
                clearTimeout(uploadTimeout);
                
                if (retryCount < MAX_RETRIES) {
                    retryCount++;
                    statusText.textContent = `Повторная попытка (${retryCount}/${MAX_RETRIES}): ${reason}`;
                    errorDetails.textContent = `Причина: ${reason}. Повторная попытка через ${1000 * retryCount} мс.`;
                    errorDetails.style.display = 'block';
                    uploadActions.style.display = 'none'; // Скрываем кнопку отмены при повторной попытке
                    
                    setTimeout(() => {
                        uploadChunk(); // Повторяем загрузку текущего чанка
                    }, 1000 * retryCount); // Увеличиваем задержку с каждой попыткой
                } else {
                    statusText.textContent = `Ошибка загрузки после ${MAX_RETRIES} попыток`;
                    errorDetails.textContent = `Ошибка загрузки после ${MAX_RETRIES} попыток. Причина: ${reason}`;
                    errorDetails.style.display = 'block';
                    statusIcon.classList.remove('pending');
                    statusIcon.classList.add('error');
                    progressBar.classList.add('bg-danger');
                    uploadActions.style.display = 'block'; // Показываем кнопку отмены
                }
            }

            xhr.upload.onprogress = function(e) {
                if (isCanceled) { // Если отменено, не обновляем прогресс
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
                if (isCanceled) return; // Если отменено, не обрабатываем ответ
                clearTimeout(uploadTimeout);
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                        if (response.status === 'success') {
                            retryCount = 0; // Сбрасываем счетчик попыток при успехе
                            
                            if (end < file.size) {
                                // Загружаем следующий чанк
                                start = end;
                                end = Math.min(start + CHUNK_SIZE, file.size);
                                chunkCounter++;
                                uploadChunk();
                            } else {
                                // Загрузка завершена
                                statusIcon.classList.remove('pending');
                                statusIcon.classList.add('success');
                                statusText.textContent = 'Загрузка завершена';
                                progressBar.classList.remove('bg-danger'); // Убираем красный, если был
                                progressBar.classList.add('bg-success');
                                errorDetails.style.display = 'none'; // Скрываем детали ошибки
                                uploadActions.style.display = 'none'; // Скрываем кнопки действий
                                
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

            xhr.open('POST', '', true);
            xhr.send(formData);
        }

        uploadChunk(); // Начинаем загрузку первого чанка
    }

    // Обработчик отправки формы загрузки файлов
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const files = document.getElementById('fileInput').files;
        document.getElementById('uploadProgress').innerHTML = '';
        Array.from(files).forEach(uploadFile);
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
        document.getElementById('uploadProgress').innerHTML = '';
        Array.from(files).forEach(uploadFile);
    }

    // Функция форматирования размера файла в читаемый вид
    function formatFileSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        return (bytes / 1024).toFixed(2) + ' KB';
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
            }
        });

        files.forEach(file => fileList.appendChild(file));
    }
    </script>



</body>
</html>