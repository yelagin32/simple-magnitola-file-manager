<?php
/**
 * Генератор хеша пароля для SMFM
 *
 * Использование:
 * 1. Через браузер: откройте этот файл и введите пароль в форму
 * 2. Через CLI: php generate_hash.php your_password
 *
 * ВАЖНО: Удалите этот файл после генерации хеша!
 */

// CLI режим
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Использование: php generate_hash.php your_password\n";
        exit(1);
    }

    $password = $argv[1];
    $hash = password_hash($password, PASSWORD_BCRYPT);

    echo "\n=== Хеш пароля сгенерирован ===\n";
    echo "Пароль: {$password}\n";
    echo "Хеш: {$hash}\n\n";
    echo "Скопируйте этот хеш в config.php:\n";
    echo "define('PASSWORD_HASH', '{$hash}');\n\n";
    echo "ВАЖНО: Удалите generate_hash.php после настройки!\n";
    exit(0);
}

// Web режим
$hash = '';
$password = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['password'])) {
    $password = $_POST['password'];
    $hash = password_hash($password, PASSWORD_BCRYPT);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Генератор хеша пароля - SMFM</title>
    <link href="style.css" rel="stylesheet">
    <style>
        .container-small { max-width: 600px; margin: 0 auto; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 1rem; margin-bottom: 1rem; border-radius: 0.375rem; }
        .success { background: #d1e7dd; border-left: 4px solid #198754; padding: 1rem; margin-bottom: 1rem; border-radius: 0.375rem; }
        .code-box { background: #f8f9fa; border: 1px solid #dee2e6; padding: 1rem; border-radius: 0.375rem; font-family: monospace; word-break: break-all; }
        ol { margin-left: 1.5rem; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <div class="container container-small">
        <div class="card" style="margin-top: 3rem;">
            <div class="card-body">
                        <h3 class="card-title text-center mb-4">🔐 Генератор хеша пароля</h3>

                        <div class="warning">
                            <strong>⚠️ Важно:</strong> Удалите этот файл после генерации хеша! Он представляет угрозу безопасности.
                        </div>

                        <?php if ($hash): ?>
                            <div class="success">
                                <strong>✅ Хеш успешно сгенерирован!</strong>
                            </div>

                            <div class="mb-3">
                                <label class="form-label"><strong>Ваш пароль:</strong></label>
                                <div class="code-box"><?php echo htmlspecialchars($password); ?></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label"><strong>Хеш для config.php:</strong></label>
                                <div class="code-box" id="hashValue"><?php echo htmlspecialchars($hash); ?></div>
                                <button class="btn btn-sm btn-primary mt-2" onclick="copyHash()">📋 Копировать хеш</button>
                            </div>

                            <div class="alert alert-info">
                                <strong>Следующие шаги:</strong>
                                <ol class="mb-0 mt-2">
                                    <li>Откройте <code>config.php</code></li>
                                    <li>Найдите строку <code>define('PASSWORD_HASH', '...');</code></li>
                                    <li>Замените старый хеш на новый</li>
                                    <li><strong>Удалите файл generate_hash.php</strong></li>
                                </ol>
                            </div>

                            <a href="generate_hash.php" class="btn btn-secondary w-100">Сгенерировать новый хеш</a>
                        <?php else: ?>
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="password" class="form-label">Введите новый пароль:</label>
                                    <input type="text" name="password" id="password" class="form-control" required autocomplete="off" placeholder="Ваш надежный пароль">
                                    <small class="form-text text-muted">Используйте сложный пароль (минимум 8 символов, буквы и цифры)</small>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Сгенерировать хеш</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="text-center mt-3 text-muted">
                    <small>SMFM by @yelagin</small>
                </div>
            </div>
        </div>

    <script>
        function copyHash() {
            const hashText = document.getElementById('hashValue').textContent;
            navigator.clipboard.writeText(hashText).then(() => {
                alert('✅ Хеш скопирован в буфер обмена!');
            }).catch(err => {
                console.error('Ошибка копирования:', err);
                alert('❌ Не удалось скопировать. Скопируйте вручную.');
            });
        }
    </script>
</body>
</html>
