<?php
require_once 'config.php';
require_once 'functions.php';

session_start();

if (!isset($_SESSION['authenticated'])) {
    http_response_code(403);
    echo "Доступ запрещен";
    exit;
}

if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
    http_response_code(403);
    echo "Неверный CSRF токен";
    exit;
}

$currentPath = isset($_GET['path']) ? $_GET['path'] : '';
$safePath = getSafePath($currentPath);

if (isset($_GET['delete'])) {
    $itemToDelete = basename($_GET['delete']);
    $baseDir = UPLOADS_DIR . ($safePath ? '/' . $safePath : '');
    $itemPath = $baseDir . '/' . $itemToDelete;

    // Проверка валидности пути
    if (!validatePath($safePath . '/' . $itemToDelete, UPLOADS_DIR)) {
        http_response_code(403);
        echo "Недопустимый путь";
        exit;
    }

    if (file_exists($itemPath)) {
        if (is_dir($itemPath)) {
            // Рекурсивное удаление папки
            function deleteDirectory($dir) {
                if (!is_dir($dir)) {
                    return unlink($dir);
                }

                $items = array_diff(scandir($dir), ['.', '..']);
                foreach ($items as $item) {
                    $path = $dir . '/' . $item;
                    is_dir($path) ? deleteDirectory($path) : unlink($path);
                }

                return rmdir($dir);
            }

            deleteDirectory($itemPath);
        } else {
            // Удаление файла
            unlink($itemPath);
        }
    }
}

// Редирект обратно с сохранением текущего пути
$redirectPath = 'index.php' . ($safePath ? '?path=' . urlencode($safePath) : '');
header('Location: ' . $redirectPath);
exit;
?>