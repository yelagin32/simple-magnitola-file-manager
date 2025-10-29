<?php
require_once 'config.php';

session_start();

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

// Получение списка файлов
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
    // Сортировка по дате по умолчанию
    usort($fileList, function($a, $b) {
        return $b['date'] - $a['date'];
    });
    return $fileList;
}

$fileList = isset($_SESSION['authenticated']) ? getFileList() : [];

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
            <div class="card mb-4">
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
                    <div class="mb-3">
                        <select id="sortType" class="form-select" onchange="sortFiles()">
                            <option value="name">Сортировать по имени</option>
                            <option value="date" selected>Сортировать по дате</option>
                            <option value="type">Сортировать по типу файла</option>
                            <option value="size">Сортировать по размеру файла (большие в начале)</option>
                        </select>
                    </div>
                    <div id="fileList" class="list-group">
                        <?php if (empty($fileList)): ?>
                            <p class="text-muted">Нет загруженных файлов</p>
                        <?php else:
                            $totalSize = 0;
                            foreach ($fileList as $file) {
                                $totalSize += $file['size'];
                            }
                            $formattedTotalSize = '';
                            if ($totalSize >= 1073741824) {
                                $formattedTotalSize = number_format($totalSize / 1073741824, 2) . ' ГБ';
                            } elseif ($totalSize >= 1048576) {
                                $formattedTotalSize = number_format($totalSize / 1048576, 2) . ' МБ';
                            } elseif ($totalSize >= 1024) {
                                $formattedTotalSize = number_format($totalSize / 1024, 2) . ' КБ';
                            } else {
                                $formattedTotalSize = $totalSize . ' байт';
                            }
                        ?>
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle"></i>
                                <strong>Загружено <?php echo count($fileList); ?> файлов</strong> - занято <strong><?php echo $formattedTotalSize; ?></strong>
                            </div>
                        <?php
                            foreach ($fileList as $file):
                                $formattedSize = $file['size'] > 1048576
                                    ? number_format($file['size'] / 1048576, 2) . ' MB'
                                    : number_format($file['size'] / 1024, 2) . ' KB';
                                $formattedDate = date('d.m.Y H:i:s', $file['date']);
                        ?>
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
                                        <span class="file-date"><?php echo $formattedDate; ?></span>
                                        <span class="file-size badge bg-primary rounded-pill ms-2"><?php echo $formattedSize; ?></span>
                                    </div>
                                </div>
                                <div class="file-actions mt-2 mt-md-0">
                                    <button class="btn btn-sm btn-info share-btn" data-file-url="<?php echo htmlspecialchars(UPLOADS_DIR . '/' . $file['name']); ?>">
                                        <i class="bi bi-share"></i> <span class="d-none d-md-inline">Поделиться</span>
                                    </button>
                                    <a href="<?php echo UPLOADS_DIR . '/' . htmlspecialchars($file['name']); ?>" class="btn btn-sm btn-success" download>
                                        <i class="bi bi-download"></i> <span class="d-none d-md-inline">Скачать</span>
                                    </a>
                                    <button class="btn btn-sm btn-danger delete-btn" data-bs-toggle="modal" data-bs-target="#deleteModal" data-file="<?php echo urlencode($file['name']); ?>">
                                        <i class="bi bi-trash"></i> <span class="d-none d-md-inline">Удалить</span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
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
                    const deleteConfirmBtn = document.getElementById('deleteConfirmBtn');
                    deleteConfirmBtn.href = 'delete.php?delete=' + file;
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
