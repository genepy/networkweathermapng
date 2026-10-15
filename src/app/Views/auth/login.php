<?php
$theme = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : '';
?>
<!DOCTYPE html>
<html lang="<?= \App\Core\I18n::locale() ?>"<?= $theme ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <?= \App\Core\Csrf::metaTag() ?>
    <script src="/assets/js/csrf.js"></script>
    <title><?= e__('Login') ?> - NetworkWeathermapNG</title>
    <link rel="stylesheet" href="/assets/css/weathermap.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--dark-color) 100%);
            margin: 0;
        }
        .login-container {
            background: var(--card-bg);
            color: var(--text-color);
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
        }
        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-logo h1 {
            margin: 0;
            color: var(--link-color);
            font-size: 24px;
        }
        .login-logo p {
            margin: 5px 0 0;
            color: var(--text-muted);
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-color);
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
            background: var(--input-bg);
            color: var(--text-color);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            background: var(--primary-color);
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-login:hover {
            background: var(--primary-hover);
        }
        .error-message {
            background: var(--danger-bg);
            color: var(--danger-text);
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <h1>NetworkWeathermapNG</h1>
            <p><?= e__('Network Visualization') ?></p>
        </div>
        
        <?php if ($error): ?>
        <div class="error-message">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="/login">
            <div class="form-group">
                <label for="username"><?= e__('Username') ?></label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password"><?= e__('Password') ?></label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn-login"><?= e__('Login') ?></button>
        </form>
    </div>
</body>
</html>
