<?php

require_once 'config.php';
require_once 'functions.php';

// Проверка авторизации
session_start();

if (!isset($_SESSION['authenticated'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Доступ запрещен. Требуется авторизация.']);
    exit;
}

// Логирование для отладки
ini_set('log_errors', 1);
ini_set('error_log', 'upload.log');

// --- Начало обработки запроса ---

header('Content-Type: application/json');

// Получаем текущий путь для загрузки
$currentPath = isset($_POST['current_path']) ? $_POST['current_path'] : '';
$safePath = getSafePath($currentPath);

// Определяем целевую директорию
$targetDir = UPLOADS_DIR . ($safePath ? '/' . $safePath : '');

// Проверка валидности пути
if (!empty($safePath) && !validatePath($safePath, UPLOADS_DIR)) {
    echo json_encode(['status' => 'error', 'message' => 'Недопустимый путь для загрузки.']);
    exit;
}

if (!file_exists($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Не удалось создать директорию для загрузок.']);
        exit;
    }
}

if (!file_exists(CHUNKS_DIR)) {
    if (!mkdir(CHUNKS_DIR, 0755, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Не удалось создать директорию для временных файлов.']);
        exit;
    }
}

$chunkIndex = isset($_POST['chunk']) ? intval($_POST['chunk']) : 0;
$totalChunks = isset($_POST['chunks']) ? intval($_POST['chunks']) : 1;
$originalFileName = isset($_FILES['file']['name']) ? $_FILES['file']['name'] : 'unknown.tmp';
$tmpPath = $_FILES['file']['tmp_name'];

// Санитизация имени файла
$fileName = sanitizeFileName($originalFileName);

// Проверка на пустое имя после санитизации
if (empty($fileName) || $fileName === '.') {
    echo json_encode(['status' => 'error', 'message' => 'Недопустимое имя файла.']);
    exit;
}

// Проверка расширения файла
if (!isAllowedFileType($fileName)) {
    $ext = pathinfo($fileName, PATHINFO_EXTENSION);
    echo json_encode(['status' => 'error', 'message' => "Тип файла .{$ext} не разрешен для загрузки."]);
    exit;
}

$chunkPath = CHUNKS_DIR . '/' . $fileName . '.part' . $chunkIndex;

if (!move_uploaded_file($tmpPath, $chunkPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Ошибка сохранения временного файла.']);
    exit;
}

// Если это последний чанк, собираем файл
if (($chunkIndex + 1) === $totalChunks) {
    // Проверяем на конфликт имен и получаем уникальное имя если нужно
    $uniqueFileName = getUniqueFileName($targetDir, $fileName);
    $finalPath = $targetDir . '/' . $uniqueFileName;
    $fileSize = 0;

    // Проверка квоты перед сборкой файла
    $quotaBytes = DISK_QUOTA_GB > 0 ? DISK_QUOTA_GB * 1024 * 1024 * 1024 : 0;
    if ($quotaBytes > 0) {
        $currentSize = getDirectorySize(UPLOADS_DIR);
        // Суммируем размеры всех чанков для получения размера файла
        for ($i = 0; $i < $totalChunks; $i++) {
            $partPath = CHUNKS_DIR . '/' . $fileName . '.part' . $i;
            if (file_exists($partPath)) {
                $fileSize += filesize($partPath);
            }
        }

        if (($currentSize + $fileSize) > $quotaBytes) {
            // Удаляем все временные файлы, чтобы не занимать место
            for ($i = 0; $i < $totalChunks; $i++) {
                $partPath = CHUNKS_DIR . '/' . $fileName . '.part' . $i;
                if (file_exists($partPath)) {
                    unlink($partPath);
                }
            }
            echo json_encode(['status' => 'error', 'message' => 'Ошибка: Превышена дисковая квота.']);
            exit;
        }
    }

    $out = fopen($finalPath, 'wb');
    if ($out === false) {
        echo json_encode(['status' => 'error', 'message' => 'Не удалось открыть файл для записи.']);
        exit;
    }

    // Блокировка файла для предотвращения одновременной записи
    if (!flock($out, LOCK_EX)) {
        fclose($out);
        echo json_encode(['status' => 'error', 'message' => 'Не удалось заблокировать файл для записи.']);
        exit;
    }

    for ($i = 0; $i < $totalChunks; $i++) {
        $partPath = CHUNKS_DIR . '/' . $fileName . '.part' . $i;
        $in = fopen($partPath, 'rb');
        if ($in === false) continue;

        while ($buff = fread($in, 4096)) {
            fwrite($out, $buff);
        }

        fclose($in);
        unlink($partPath); // Удаляем чанк после добавления
    }

    // Снимаем блокировку и закрываем файл
    flock($out, LOCK_UN);
    fclose($out);

    echo json_encode(['status' => 'success']);
} else {
    // Если это не последний чанк, просто подтверждаем успешное получение
    echo json_encode(['status' => 'success']);
}

?>
