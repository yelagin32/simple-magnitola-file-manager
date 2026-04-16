<?php
require_once 'config.php';
require_once 'functions.php';

session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Доступ запрещен.']);
    exit;
}

// Проверка CSRF токена
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Неверный CSRF токен.']);
    exit;
}

header('Content-Type: application/json');

$folderName = isset($_POST['folder_name']) ? trim($_POST['folder_name']) : '';
$currentPath = isset($_POST['current_path']) ? $_POST['current_path'] : '';

// Валидация имени папки
if (empty($folderName)) {
    echo json_encode(['status' => 'error', 'message' => 'Имя папки не может быть пустым.']);
    exit;
}

// Санитизация имени папки
$folderName = sanitizeFileName($folderName);

if (empty($folderName) || $folderName === '.') {
    echo json_encode(['status' => 'error', 'message' => 'Недопустимое имя папки.']);
    exit;
}

// Получаем безопасный путь
$safePath = getSafePath($currentPath);
$targetDir = UPLOADS_DIR . ($safePath ? '/' . $safePath : '');

// Проверка валидности пути
if (!validatePath($safePath, UPLOADS_DIR)) {
    echo json_encode(['status' => 'error', 'message' => 'Недопустимый путь.']);
    exit;
}

// Создаем целевую директорию если не существует
if (!file_exists($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Не удалось создать родительскую директорию.']);
        exit;
    }
}

$newFolderPath = $targetDir . '/' . $folderName;

// Проверка существования папки
if (file_exists($newFolderPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Папка с таким именем уже существует.']);
    exit;
}

// Создание папки
if (mkdir($newFolderPath, 0755)) {
    echo json_encode(['status' => 'success', 'message' => 'Папка успешно создана.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Не удалось создать папку.']);
}
