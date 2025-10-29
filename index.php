<?php
require_once 'config.php';

session_start();

// Генерация CSRF-токена
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Проверка авторизации
if (!isset($_SESSION['authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === PASSWORD) {
            $_SESSION['authenticated'] = true;
            header('Location: index.php');
            exit;
        }
    }
} else {
    // Обработка выхода
    if (isset($_GET['logout'])) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

// --- Вспомогательные функции ---
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

function formatBytes($bytes, $precision = 2) {
    if ($bytes === 0) return '0 байт';
    $units = ['байт', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function getFileList() {
    $files = array_diff(scandir(UPLOADS_DIR), array('.', '..'));
    $fileList = [];
    foreach ($files as $file) {
        $filePath = UPLOADS_DIR . '/' . $file;
        $fileList[] = [
            'name' => $file,
            'size' => filesize($filePath),
            'date' => filemtime($filePath),
            'type' => pathinfo($filePath, PATHINFO_EXTENSION)
        ];
    }
    usort($fileList, function($a, $b) {
        return $b['date'] - $a['date'];
    });
    return $fileList;
}

if (isset($_SESSION['authenticated'])) {
    $fileList = getFileList();
    $totalSize = getDirectorySize(UPLOADS_DIR);
    $quotaBytes = DISK_QUOTA_GB > 0 ? DISK_QUOTA_GB * 1024 * 1024 * 1024 : 0;
    $remainingSpace = $quotaBytes > 0 ? $quotaBytes - $totalSize : -1; // -1 означает бесконечность
    $percentageUsed = $quotaBytes > 0 ? ($totalSize / $quotaBytes) * 100 : 0;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Файлообменник</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <link rel="manifest" href="/site.webmanifest" />
</head>
<body class="bg-light">
    <div class="container py-5">
        <?php if (!isset($_SESSION['authenticated'])): ?>
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
            <div id="upload-container" class="card mb-4" data-remaining-space="<?php echo $remainingSpace; ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="card-title mb-0">Загрузка файлов</h5>
                        <a href="?logout=1" class="btn btn-danger">Выход</a>
                    </div>
                    
                    <div class="drop-zone mb-3">
                        <p class="mb-2 d-none d-md-block">Перетащите файлы сюда или</p>
                        <form id="uploadForm">
                            <div class="input-group">
                                <input type="file" name="file" id="fileInput" class="form-control" multiple required>
                                <button type="submit" class="btn btn-primary">Загрузить</button>
                            </div>
                        </form>
                    </div>
                    
                    <div id="uploadProgress"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-4">Список файлов</h5>
                    
                    <?php if ($quotaBytes > 0): ?>
                    <div class="quota-info mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Занято: <strong><?php echo formatBytes($totalSize); ?></strong> из <strong><?php echo formatBytes($quotaBytes); ?></strong></span>
                            <span>Свободно: <strong><?php echo formatBytes($remainingSpace); ?></strong></span>
                        </div>
                        <div class="progress mt-2" style="height: 20px;">
                            <div class="progress-bar" role="progressbar" style="width: <?php echo $percentageUsed; ?>%;" aria-valuenow="<?php echo $percentageUsed; ?>" aria-valuemin="0" aria-valuemax="100">
                                <?php echo round($percentageUsed, 1); ?>%
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <select id="sortType" class="form-select" onchange="sortFiles()">
                            <option value="date" selected>Сортировать по дате</option>
                            <option value="name">Сортировать по имени</option>
                            <option value="size">Сортировать по размеру</option>
                            <option value="type">Сортировать по типу</option>
                        </select>
                    </div>
                    <div id="fileList" class="list-group">
                        <?php if (empty($fileList)): ?>
                            <p class="text-muted">Нет загруженных файлов</p>
                        <?php else: foreach ($fileList as $file): ?>
                            <div class="list-group-item d-md-flex justify-content-between align-items-center file-item" 
                                 data-name="<?php echo htmlspecialchars($file['name']); ?>"
                                 data-date="<?php echo $file['date']; ?>"
                                 data-type="<?php echo $file['type']; ?>"
                                 data-size="<?php echo $file['size']; ?>">
                                <div class="file-info">
                                    <a href="<?php echo UPLOADS_DIR . '/' . htmlspecialchars($file['name']); ?>" target="_blank" class="file-name-link">
                                        <strong class="file-name"><?php echo htmlspecialchars($file['name']); ?></strong>
                                    </a>
                                    <div class="text-muted small">
                                        <span class="file-date"><?php echo date('d.m.Y H:i:s', $file['date']); ?></span>
                                        <span class="file-size badge bg-primary rounded-pill ms-2"><?php echo formatBytes($file['size']); ?></span>
                                    </div>
                                </div>
                                <div class="file-actions mt-2 mt-md-0">
                                    <button class="btn btn-sm btn-info share-btn" data-file-url="<?php echo htmlspecialchars(UPLOADS_DIR . '/' . $file['name']); ?>">
                                        <i class="bi bi-share me-1"></i><span class="d-none d-md-inline">Поделиться</span>
                                    </button>
                                    <a href="<?php echo UPLOADS_DIR . '/' . htmlspecialchars($file['name']); ?>" class="btn btn-sm btn-success" download>
                                        <i class="bi bi-download me-1"></i><span class="d-none d-md-inline">Скачать</span>
                                    </a>
                                    <button class="btn btn-sm btn-danger delete-btn" data-bs-toggle="modal" data-bs-target="#deleteModal" data-file="<?php echo urlencode($file['name']); ?>" data-csrf-token="<?php echo $_SESSION['csrf_token']; ?>">
                                        <i class="bi bi-trash me-1"></i><span class="d-none d-md-inline">Удалить</span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно подтверждения удаления -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите удалить этот файл?
                    <br>
                    <strong id="fileNameToDelete"></strong>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <a href="#" id="deleteConfirmBtn" class="btn btn-danger">Удалить</a>
                </div>
            </div>
        </div>
    </div>

    <footer class="text-center mt-5">
        <div class="text-muted">
            <h5>SMFM (Simple Magnitola File Manager) by @yelagin</h5>
            <a href="https://yelagin.ru/all/simple-script-filemanager/" target="_blank">https://yelagin.ru/all/simple-script-filemanager/</a>
        </div>
        <div class="mt-3">
            <p>Если скрипт вам понравился, донаты приветствуются.</p>
            <iframe src="https://yoomoney.ru/quickpay/fundraise/button?billNumber=197ONU8AQFK.250326&" width="330" height="50" frameborder="0" allowtransparency="true" scrolling="no"></iframe>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (isset($_SESSION['authenticated'])): ?>
        <script src="script.js"></script>
        <script>
            // Управление модальным окном удаления
            const deleteModal = document.getElementById('deleteModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const file = button.getAttribute('data-file');
                    const csrfToken = button.getAttribute('data-csrf-token');
                    const fileName = decodeURIComponent(file);

                    const deleteConfirmBtn = document.getElementById('deleteConfirmBtn');
                    deleteConfirmBtn.href = 'delete.php?delete=' + file + '&csrf_token=' + csrfToken;

                    const fileNameToDeleteElement = document.getElementById('fileNameToDelete');
                    fileNameToDeleteElement.textContent = fileName;
                });
            }

            // Кнопка "Поделиться"
            const shareButtons = document.querySelectorAll('.share-btn');
            shareButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const fileUrl = this.getAttribute('data-file-url');
                    const absoluteUrl = new URL(fileUrl, window.location.href).href;

                    navigator.clipboard.writeText(absoluteUrl).then(() => {
                        const originalIcon = this.innerHTML;
                        this.innerHTML = `<i class="bi bi-check-lg"></i> Скопировано!`;
                        this.classList.add('btn-success');
                        this.classList.remove('btn-info');

                        setTimeout(() => {
                            this.innerHTML = originalIcon;
                            this.classList.remove('btn-success');
                            this.classList.add('btn-info');
                        }, 2000);
                    }).catch(err => {
                        console.error('Ошибка копирования в буфер обмена:', err);
                    });
                });
            });
        </script>
    <?php endif; ?>
</body>
</html>
