<?php
require_once 'config.php';
require_once 'functions.php';

session_start();

// Генерация CSRF-токена
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Проверка авторизации
if (!isset($_SESSION['authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        // Защита от brute-force: задержка при неверном пароле
        if (password_verify($_POST['password'], PASSWORD_HASH)) {
            $_SESSION['authenticated'] = true;
            header('Location: index.php');
            exit;
        } else {
            // Задержка 2 секунды при неверном пароле
            sleep(2);
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
// Функции перенесены в functions.php

if (isset($_SESSION['authenticated'])) {
    // Получаем текущий путь из GET параметра
    $currentPath = isset($_GET['path']) ? $_GET['path'] : '';
    $safePath = getSafePath($currentPath);

    // Определяем текущую директорию
    $currentDir = UPLOADS_DIR . ($safePath ? '/' . $safePath : '');

    // Проверка валидности пути
    if (!empty($safePath) && !validatePath($safePath, UPLOADS_DIR)) {
        die('Недопустимый путь');
    }

    // Создаем директорию если не существует
    if (!file_exists($currentDir)) {
        mkdir($currentDir, 0755, true);
    }

    $fileList = getFileList($currentDir);
    $breadcrumbs = getBreadcrumbs($safePath);
    $totalSize = getDirectorySize(UPLOADS_DIR);
    $quotaBytes = DISK_QUOTA_GB > 0 ? DISK_QUOTA_GB * 1024 * 1024 * 1024 : 0;
    $remainingSpace = $quotaBytes > 0 ? $quotaBytes - $totalSize : -1;
    $percentageUsed = $quotaBytes > 0 ? ($totalSize / $quotaBytes) * 100 : 0;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Файлообменник</title>
    <link href="style.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <link rel="manifest" href="/site.webmanifest" />
</head>
<body>
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
            <div id="upload-container" class="card mb-4" data-remaining-space="<?php echo $remainingSpace; ?>" data-current-path="<?php echo htmlspecialchars($safePath); ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="card-title mb-0">Загрузка файлов</h5>
                        <div>
                            <button class="btn btn-success me-2" data-modal-toggle="createFolderModal">
                                📁 Создать папку
                            </button>
                            <a href="?logout=1" class="btn btn-danger">Выход</a>
                        </div>
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

                    <!-- Breadcrumbs навигация -->
                    <nav aria-label="breadcrumb" class="mb-3">
                        <ol class="breadcrumb">
                            <?php foreach ($breadcrumbs as $index => $crumb): ?>
                                <?php if ($index === count($breadcrumbs) - 1): ?>
                                    <li class="breadcrumb-item active" aria-current="page">
                                        📁 <?php echo htmlspecialchars($crumb['name']); ?>
                                    </li>
                                <?php else: ?>
                                    <li class="breadcrumb-item">
                                        <a href="?path=<?php echo urlencode($crumb['path']); ?>">
                                            <?php echo $index === 0 ? '🏠' : '📂'; ?>
                                            <?php echo htmlspecialchars($crumb['name']); ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ol>
                    </nav>

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
                        <?php else: foreach ($fileList as $item): ?>
                            <div class="list-group-item d-md-flex justify-content-between align-items-center file-item"
                                 data-name="<?php echo htmlspecialchars($item['name']); ?>"
                                 data-date="<?php echo $item['date']; ?>"
                                 data-type="<?php echo $item['type']; ?>"
                                 data-size="<?php echo $item['size']; ?>"
                                 data-is-dir="<?php echo $item['is_dir'] ? '1' : '0'; ?>">
                                <div class="file-info">
                                    <?php if ($item['is_dir']): ?>
                                        <!-- Папка -->
                                        <a href="?path=<?php echo urlencode($safePath ? $safePath . '/' . $item['name'] : $item['name']); ?>" class="file-name-link">
                                            <span style="font-size: 1.5rem;">📁</span>
                                            <strong class="file-name"><?php echo htmlspecialchars($item['name']); ?></strong>
                                        </a>
                                        <div class="text-muted small">
                                            <span class="file-date"><?php echo date('d.m.Y H:i:s', $item['date']); ?></span>
                                            <span class="badge bg-secondary rounded-pill ms-2">
                                                <?php echo $item['file_count']; ?> <?php echo $item['file_count'] === 1 ? 'элемент' : 'элементов'; ?>
                                            </span>
                                            <span class="file-size badge bg-info rounded-pill ms-2"><?php echo formatBytes($item['size']); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <!-- Файл -->
                                        <a href="<?php echo $item['path']; ?>" target="_blank" class="file-name-link">
                                            <span style="font-size: 1.2rem;">📄</span>
                                            <strong class="file-name"><?php echo htmlspecialchars($item['name']); ?></strong>
                                        </a>
                                        <div class="text-muted small">
                                            <span class="file-date"><?php echo date('d.m.Y H:i:s', $item['date']); ?></span>
                                            <span class="file-size badge bg-primary rounded-pill ms-2"><?php echo formatBytes($item['size']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="file-actions mt-2 mt-md-0">
                                    <?php if (!$item['is_dir']): ?>
                                        <button class="btn btn-sm btn-info share-btn" data-file-url="<?php echo htmlspecialchars($item['path']); ?>">
                                            🔗 <span class="d-none d-md-inline">Поделиться</span>
                                        </button>
                                        <a href="<?php echo $item['path']; ?>" class="btn btn-sm btn-success" download>
                                            ⬇️ <span class="d-none d-md-inline">Скачать</span>
                                        </a>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-danger delete-btn" data-modal-toggle="deleteModal"
                                            data-file="<?php echo urlencode($item['name']); ?>"
                                            data-is-dir="<?php echo $item['is_dir'] ? '1' : '0'; ?>"
                                            data-csrf-token="<?php echo $_SESSION['csrf_token']; ?>">
                                        🗑️ <span class="d-none d-md-inline">Удалить</span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно создания папки -->
    <div class="modal fade" id="createFolderModal" tabindex="-1" aria-labelledby="createFolderModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createFolderModalLabel">Создать новую папку</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createFolderForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="current_path" value="<?php echo htmlspecialchars($safePath); ?>">
                        <div class="mb-3">
                            <label for="folderName" class="form-label">Имя папки:</label>
                            <input type="text" class="form-control" id="folderName" name="folder_name" required
                                   placeholder="Введите имя папки" pattern="[a-zA-Zа-яА-ЯёЁ0-9._\-\s()]+"
                                   title="Разрешены буквы, цифры, пробелы, точки, дефисы и скобки">
                        </div>
                        <div class="alert alert-info small">
                            ℹ️ Используйте только буквы, цифры и базовые символы
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="createFolderBtn">Создать</button>
                </div>
            </div>
        </div>
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
                    <p id="deleteMessage">Вы уверены, что хотите удалить этот файл?</p>
                    <strong id="fileNameToDelete"></strong>
                    <div class="alert alert-warning mt-3" id="folderWarning" style="display: none;">
                        ⚠️ Внимание! Папка и все её содержимое будут удалены безвозвратно!
                    </div>
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

    <script src="modal.js"></script>
    <?php if (isset($_SESSION['authenticated'])): ?>
        <script src="script.js"></script>
        <script>
            // Управление модальным окном удаления
            const deleteModal = document.getElementById('deleteModal');
            if (deleteModal) {
                const deleteModalInstance = Modal.getInstance(deleteModal);

                document.addEventListener('click', function(e) {
                    const button = e.target.closest('.delete-btn');
                    if (button) {
                        const file = button.getAttribute('data-file');
                        const isDir = button.getAttribute('data-is-dir') === '1';
                        const csrfToken = button.getAttribute('data-csrf-token');
                        const fileName = decodeURIComponent(file);
                        const currentPath = '<?php echo addslashes($safePath); ?>';

                        const deleteConfirmBtn = document.getElementById('deleteConfirmBtn');
                        deleteConfirmBtn.href = 'delete.php?delete=' + file + '&csrf_token=' + csrfToken + '&path=' + encodeURIComponent(currentPath);

                        const fileNameToDeleteElement = document.getElementById('fileNameToDelete');
                        fileNameToDeleteElement.textContent = fileName;

                        const deleteMessage = document.getElementById('deleteMessage');
                        const folderWarning = document.getElementById('folderWarning');

                        if (isDir) {
                            deleteMessage.textContent = 'Вы уверены, что хотите удалить эту папку?';
                            folderWarning.style.display = 'block';
                        } else {
                            deleteMessage.textContent = 'Вы уверены, что хотите удалить этот файл?';
                            folderWarning.style.display = 'none';
                        }
                    }
                });
            }

            // Управление созданием папки
            const createFolderBtn = document.getElementById('createFolderBtn');
            if (createFolderBtn) {
                createFolderBtn.addEventListener('click', function() {
                    const form = document.getElementById('createFolderForm');
                    const formData = new FormData(form);

                    if (!form.checkValidity()) {
                        form.reportValidity();
                        return;
                    }

                    fetch('create_folder.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            location.reload();
                        } else {
                            alert('Ошибка: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Ошибка:', error);
                        alert('Произошла ошибка при создании папки');
                    });
                });
            }

            // Кнопка "Поделиться"
            const shareButtons = document.querySelectorAll('.share-btn');
            shareButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const fileUrl = this.getAttribute('data-file-url');
                    const absoluteUrl = new URL(fileUrl, window.location.href).href;

                    navigator.clipboard.writeText(absoluteUrl).then(() => {
                        const originalHTML = this.innerHTML;
                        this.innerHTML = '✅ Скопировано!';
                        this.classList.add('btn-success');
                        this.classList.remove('btn-info');

                        setTimeout(() => {
                            this.innerHTML = originalHTML;
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
