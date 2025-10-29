<?php
require_once 'config.php';

session_start();

if (!isset($_SESSION['authenticated'])) {
    http_response_code(403);
    echo "Доступ запрещен";
    exit;
}

if (isset($_GET['delete'])) {
    $fileToDelete = basename($_GET['delete']);
    $filePath = UPLOADS_DIR . '/' . $fileToDelete;

    if (file_exists($filePath)) {
        unlink($filePath);
    }
}

header('Location: index.php');
exit;
?>
