<?php
/**
 * Общие функции для SMFM
 */

/**
 * Получить размер директории (рекурсивно)
 */
function getDirectorySize($dir) {
    $size = 0;

    if (!is_dir($dir)) {
        return 0;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }

    return $size;
}

/**
 * Форматирование размера в читаемый вид
 */
function formatBytes($bytes, $precision = 2) {
    if ($bytes === 0) return '0 байт';
    $units = ['байт', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Санитизация имени файла/папки
 */
function sanitizeFileName($fileName) {
    // Удаляем путь, оставляем только имя
    $fileName = basename($fileName);

    // Защита от path traversal
    $fileName = str_replace(['..', '/', '\\'], '', $fileName);

    // Удаляем опасные символы
    $fileName = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ._\-\s()]/u', '', $fileName);

    // Убираем множественные пробелы
    $fileName = preg_replace('/\s+/', ' ', $fileName);

    // Обрезаем до 255 символов
    if (strlen($fileName) > 255) {
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $fileName = substr($name, 0, 255 - strlen($ext) - 1) . '.' . $ext;
    }

    return trim($fileName);
}

/**
 * Проверка расширения файла (whitelist)
 */
function isAllowedFileType($fileName) {
    $allowedExtensions = [
        // Документы
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods',
        // Изображения
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'ico',
        // Видео
        'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'm4v',
        // Аудио
        'mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a', 'wma',
        // Архивы
        'zip', 'rar', '7z', 'tar', 'gz', 'bz2',
        // Android
        'apk',
        // Другое
        'json', 'xml', 'csv', 'log'
    ];

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($ext, $allowedExtensions);
}

/**
 * Валидация пути (защита от path traversal)
 */
function validatePath($path, $baseDir) {
    // Нормализуем путь
    $realBase = realpath($baseDir);
    $realPath = realpath($baseDir . '/' . $path);

    // Если путь не существует, проверяем родительскую директорию
    if ($realPath === false) {
        $parentDir = dirname($baseDir . '/' . $path);
        $realPath = realpath($parentDir);

        if ($realPath === false) {
            return false;
        }
    }

    // Проверяем, что путь находится внутри базовой директории
    return strpos($realPath, $realBase) === 0;
}

/**
 * Получить безопасный путь относительно базовой директории
 */
function getSafePath($path) {
    // Удаляем опасные символы
    $path = str_replace(['..', '\\'], '', $path);
    $path = trim($path, '/');

    // Разбиваем на части и очищаем каждую
    $parts = explode('/', $path);
    $safeParts = [];

    foreach ($parts as $part) {
        $part = sanitizeFileName($part);
        if (!empty($part) && $part !== '.') {
            $safeParts[] = $part;
        }
    }

    return implode('/', $safeParts);
}

/**
 * Получить список файлов и папок в директории
 */
function getFileList($dir) {
    if (!is_dir($dir)) {
        return [];
    }

    $items = array_diff(scandir($dir), ['.', '..']);
    $fileList = [];

    foreach ($items as $item) {
        // Пропускаем скрытые файлы
        if (substr($item, 0, 1) === '.') {
            continue;
        }

        $itemPath = $dir . '/' . $item;
        $isDir = is_dir($itemPath);

        $fileList[] = [
            'name' => $item,
            'path' => $itemPath,
            'is_dir' => $isDir,
            'size' => $isDir ? getDirectorySize($itemPath) : filesize($itemPath),
            'date' => filemtime($itemPath),
            'type' => $isDir ? 'folder' : strtolower(pathinfo($itemPath, PATHINFO_EXTENSION)),
            'file_count' => $isDir ? count(array_diff(scandir($itemPath), ['.', '..'])) : 0
        ];
    }

    // Сортируем: сначала папки, потом файлы, по дате
    usort($fileList, function($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) {
            return $b['is_dir'] - $a['is_dir'];
        }
        return $b['date'] - $a['date'];
    });

    return $fileList;
}

/**
 * Создать breadcrumbs для навигации
 */
function getBreadcrumbs($currentPath) {
    $breadcrumbs = [['name' => 'Главная', 'path' => '']];

    if (empty($currentPath)) {
        return $breadcrumbs;
    }

    $parts = explode('/', $currentPath);
    $accumulated = '';

    foreach ($parts as $part) {
        if (empty($part)) continue;

        $accumulated .= ($accumulated ? '/' : '') . $part;
        $breadcrumbs[] = [
            'name' => $part,
            'path' => $accumulated
        ];
    }

    return $breadcrumbs;
}

/**
 * Получить уникальное имя файла при конфликте
 */
function getUniqueFileName($dir, $fileName) {
    $filePath = $dir . '/' . $fileName;

    if (!file_exists($filePath)) {
        return $fileName;
    }

    $pathInfo = pathinfo($fileName);
    $baseName = $pathInfo['filename'];
    $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';

    $counter = 1;
    while (file_exists($dir . '/' . $baseName . ' (' . $counter . ')' . $extension)) {
        $counter++;
    }

    return $baseName . ' (' . $counter . ')' . $extension;
}
