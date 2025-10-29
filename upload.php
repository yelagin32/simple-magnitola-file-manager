<?php

require_once 'config.php';

// Логирование для отладки
ini_set('log_errors', 1);
ini_set('error_log', 'upload.log');

// Функция для получения размера директории
function getDirectorySize($dir) {
    $size = 0;
    $files = glob($dir . '/*');
    if ($files === false) return 0;
    foreach ($files as $file) {
        if (is_file($file)) {
            $size += filesize($file);
        }
    }
    return $size;
}

// --- Начало обработки запроса ---

header('Content-Type: application/json');

if (!file_exists(UPLOADS_DIR)) {
    if (!mkdir(UPLOADS_DIR, 0755, true)) {
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
$fileName = isset($_FILES['file']['name']) ? basename($_FILES['file']['name']) : 'unknown.tmp';
$tmpPath = $_FILES['file']['tmp_name'];

$chunkPath = CHUNKS_DIR . '/' . $fileName . '.part' . $chunkIndex;

if (!move_uploaded_file($tmpPath, $chunkPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Ошибка сохранения временного файла.']);
    exit;
}

// Если это последний чанк, собираем файл
if (($chunkIndex + 1) === $totalChunks) {
    $finalPath = UPLOADS_DIR . '/' . $fileName;
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

    fclose($out);

    echo json_encode(['status' => 'success']);
} else {
    // Если это не последний чанк, просто подтверждаем успешное получение
    echo json_encode(['status' => 'success']);
}

?>
