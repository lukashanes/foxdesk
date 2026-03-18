<?php
/**
 * Reset Password Page
 */

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$settings = get_settings();
$app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');

$token = $_GET['token'] ?? '';
$error = '';
$valid_token = false;
$token_hash = '';
$current_lang = get_app_language();
$lang_options = [
    'en' => t('English'),
    'cs' => t('Czech'),
    'de' => t('German'),
    'it' => t('Italian'),
    'es' => t('Spanish')
];
$lang_params = ['page' => 'reset-password'];
if (!empty($token)) {
    $lang_params['token'] = $token;
}

// Verify token (hashed only - no legacy plaintext support)
if (!empty($token)) {
    $token_hash = hash_reset_token($token);
    $user = db_fetch_one("SELECT id, email FROM users WHERE reset_token = ? AND reset_token_expires > NOW() AND is_active = 1", [$token_hash]);
    $valid_token = (bool) $user;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    if (!csrf_is_valid()) {
        $error = t('Security check failed. Please try again.');
    } else {
        $new_password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $validation = validate_password($new_password);
        if (!$validation['valid']) {
            $error = implode(' ', $validation['errors']);
        } elseif ($new_password !== $confirm_password) {
            $error = t('Passwords do not match.');
        } else {
            // Update password and clear token
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            db_update('users', [
                'password' => $hash,
                'reset_token' => null,
                'reset_token_expires' => null
            ], 'id = ?', [$user['id']]);
            log_security_event('password_reset_completed', $user['id'], 'email=' . $user['email']);

            header('Location: index.php?page=login&reset=success');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(get_app_language()); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('New password')); ?> - <?php echo e($app_name); ?></title>
    <link href="tailwind.min.css" rel="stylesheet">
    <link href="theme.css" rel="stylesheet">
    <script>
        (function() {
            const saved = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
    <style>
        .login-bg {
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, var(--corp-slate-100) 0%, var(--corp-slate-50) 50%, #f0f4ff 100%);
        }
        [data-theme="dark"] .login-bg {
            background: linear-gradient(135deg, var(--corp-slate-950) 0%, var(--corp-slate-900) 50%, #0c1929 100%);
        }
        .login-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
        }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-4">
    <div class="login-bg"></div>
    <div class="login-card rounded-3xl w-full max-w-md p-8 relative z-10">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 transition-transform hover:scale-105" style="background: linear-gradient(135deg, var(--corp-blue-500) 0%, var(--corp-blue-600) 100%); box-shadow: 0 10px 40px -10px rgba(59, 130, 246, 0.5);">
                <span class="text-white text-2xl font-bold"><?php echo strtoupper(substr($app_name, 0, 1)); ?></span>
            </div>
            <h1 class="text-2xl font-bold text-gradient"><?php echo e(t('New password')); ?></h1>
            <p style="color: var(--text-muted);"><?php echo e(t('Enter a new password')); ?></p>
        </div>

        <?php if (!$valid_token): ?>
            <div class="alert alert-error mb-6">
                <?php echo e(t('Invalid or expired reset link.')); ?>
            </div>
            <div class="text-center">
                <a href="<?php echo url('forgot-password', ['lang' => $current_lang]); ?>"
                    class="text-sm transition-colors hover:text-blue-400" style="color: var(--primary);">
                    <?php echo e(t('Request a new link')); ?>
                </a>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-error mb-6">
                    <?php echo e($error); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php echo csrf_field(); ?>
                <div class="space-y-4">
                    <div>
                        <label
                            class="block text-sm font-medium mb-2" style="color: var(--text-secondary);"><?php echo e(t('New password')); ?></label>
                        <input type="password" name="password" class="form-input" autocomplete="new-password" required
                            minlength="6" autofocus>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">
                            <?php echo e(t('New password must be at least 6 characters.')); ?>
                        </p>
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium mb-2" style="color: var(--text-secondary);"><?php echo e(t('Confirm password')); ?></label>
                        <input type="password" name="confirm_password" class="form-input" autocomplete="new-password"
                            required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full mt-6">
                    <?php echo e(t('Set new password')); ?>
                </button>
            </form>
        <?php endif; ?>

        <form method="get" class="flex items-center justify-center gap-2 text-sm text-gray-500 mt-4">
            <?php foreach ($lang_params as $key => $value): ?>
                <input type="hidden" name="<?php echo e($key); ?>" value="<?php echo e($value); ?>">
            <?php endforeach; ?>
            <label for="lang-select"
                class="text-xs uppercase tracking-wide" style="color: var(--text-muted);"><?php echo e(t('Language')); ?></label>
            <select id="lang-select" name="lang" class="form-select w-auto text-sm" onchange="this.form.submit()">
                <?php foreach ($lang_options as $code => $label): ?>
                    <option value="<?php echo e($code); ?>" <?php echo $code === $current_lang ? 'selected' : ''; ?>>
                        <?php echo e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <p class="text-center text-sm mt-8" style="color: var(--text-muted);">
            &copy; <?php echo date('Y'); ?> <?php echo e($app_name); ?>
        </p>
    </div>
</body>

</html>

