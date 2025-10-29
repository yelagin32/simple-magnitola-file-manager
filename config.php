<?php
// Файл конфигурации

// Пароль для доступа к файловому менеджеру
define('PASSWORD', '12345678');

// Настройки для рабочей среды
error_reporting(E_ALL);
ini_set('display_errors', 0); // Отключаем отображение ошибок пользователю
ini_set('log_errors', 1); // Включаем логирование ошибок
ini_set('error_log', 'error.log'); // Указываем файл для логов ошибок

// Директории
define('UPLOADS_DIR', 'uploads'); // Директория для загруженных файлов
define('CHUNKS_DIR', 'chunks'); // Директория для временных частей файлов

// Настройки для загрузки больших файлов
ini_set('upload_max_filesize', '4G');
ini_set('post_max_size', '4G');
ini_set('memory_limit', '4G');
ini_set('max_execution_time', '3600'); // 1 час
ini_set('max_input_time', '3600');     // 1 час
?>