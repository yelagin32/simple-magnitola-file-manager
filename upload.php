<?php
require_once 'config.php';

// Функция для логирования
function writeLog($message) {
    file_put_contents('upload.log', date('Y-m-d H:i:s') . ': ' . $message . "\n", FILE_APPEND);
}

session_start();

if (!isset($_SESSION['authenticated'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Доступ запрещен']);
    exit;
}

if (isset($_FILES['file'])) {
    $response = ['status' => 'error', 'message' => 'Неизвестная ошибка'];

    if (isset($_POST['chunk']) && isset($_POST['chunks'])) {
        $chunk = intval($_POST['chunk']);
        $chunks = intval($_POST['chunks']);
        $fileName = basename($_FILES['file']['name']);

        writeLog("Начало обработки чанка {$chunk} из {$chunks} для файла {$fileName}");

        $fileChunkDir = CHUNKS_DIR . '/' . md5($fileName);
        if (!file_exists($fileChunkDir)) {
            mkdir($fileChunkDir, 0777, true);
            writeLog("Создана директория для чанков: {$fileChunkDir}");
        }

        $chunkFile = $fileChunkDir . '/' . $chunk;

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

                    $finalFileName = UPLOADS_DIR . '/' . $fileName;
                    $counter = 1;
                    while (file_exists($finalFileName)) {
                        $finalFileName = UPLOADS_DIR . '/' . pathinfo($fileName, PATHINFO_FILENAME)
                            . "_{$counter}."
                            . pathinfo($fileName, PATHINFO_EXTENSION);
                        $counter++;
                    }

                    writeLog("Создаем итоговый файл: {$finalFileName}");

                    try {
                        $finalFile = fopen($finalFileName, 'wb');
                        if (!$finalFile) {
                            throw new Exception("Не удалось открыть файл для записи: {$finalFileName}");
                        }

                        $totalSize = 0;
                        for ($i = 0; $i < $chunks; $i++) {
                            $chunkPath = $fileChunkDir . '/' . $i;
                            $totalSize += filesize($chunkPath);
                        }

                        $freeSpace = disk_free_space(UPLOADS_DIR);
                        if ($freeSpace < $totalSize) {
                            throw new Exception("Недостаточно места на диске. Требуется: " .
                                number_format($totalSize / (1024*1024), 2) . " МБ, доступно: " .
                                number_format($freeSpace / (1024*1024), 2) . " МБ");
                        }

                        for ($i = 0; $i < $chunks; $i++) {
                            $chunkPath = $fileChunkDir . '/' . $i;
                            $chunkContent = file_get_contents($chunkPath);
                            if ($chunkContent === false) {
                                throw new Exception("Не удалось прочитать чанк {$i}: {$chunkPath}");
                            }
                            fwrite($finalFile, $chunkContent);
                            unset($chunkContent);
                        }

                        fclose($finalFile);

                        if (!file_exists($finalFileName) || filesize($finalFileName) !== $totalSize) {
                            throw new Exception("Ошибка проверки итогового файла. Ожидаемый размер: {$totalSize}, фактический: " . filesize($finalFileName));
                        }

                        writeLog("Файл успешно создан: {$finalFileName}, размер: " . filesize($finalFileName));

                        foreach ($uploadedChunks as $chunk) {
                            unlink($chunk);
                        }
                        rmdir($fileChunkDir);

                        $response = [
                            'status' => 'success',
                            'message' => 'Файл успешно загружен!',
                            'filename' => basename($finalFileName)
                        ];

                    } catch (Exception $e) {
                        writeLog("Критическая ошибка: " . $e->getMessage());
                        if (isset($finalFile) && is_resource($finalFile)) {
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
        // Обычная загрузка файла
        $filename = basename($_FILES['file']['name']);
        $path = UPLOADS_DIR . '/' . $filename;
        $counter = 1;
        while (file_exists($path)) {
            $filename = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME)
                . "_{$counter}."
                . pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $path = UPLOADS_DIR . '/' . $filename;
            $counter++;
        }

        if (move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
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
