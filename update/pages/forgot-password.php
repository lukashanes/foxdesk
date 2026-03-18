<?php
/**
 * Forgot Password Page
 */

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$settings = get_settings();
$app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');

$error = '';
$success = false;
$current_lang = get_app_language();
$lang_options = [
    'en' => t('English'),
    'cs' => t('Czech'),
    'de' => t('German'),
    'it' => t('Italian'),
    'es' => t('Spanish')
];
$lang_params = ['page' => 'forgot-password'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = t('Security check failed. Please try again.');
    } else {
        $email = trim($_POST['email'] ?? '');
        $rate_key = 'password_reset';

        if (rate_limit_is_blocked($rate_key, 5, 900)) {
            $error = t('Too many attempts. Please wait and try again.');
        } elseif (empty($email)) {
            $error = t('Enter your email address.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('Enter a valid email address.');
        } else {
            // Check if user exists
            $user = null;
            $user = db_fetch_one("SELECT id, first_name, email FROM users WHERE email = ? AND is_active = 1", [$email]);

            if ($user) {
                // Generate reset token
                $token = generate_reset_token();
                $token_hash = hash_reset_token($token);
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                db_update('users', [
                    'reset_token' => $token_hash,
                    'reset_token_expires' => $expires
                ], 'id = ?', [$user['id']]);

                // Send email
                $reset_link = APP_URL . '/index.php?page=reset-password&token=' . $token;

                // Try to send email
                require_once BASE_PATH . '/includes/mailer.php';
                $sent = send_password_reset_email($user['email'], $user['first_name'], $reset_link);
            }

            rate_limit_record($rate_key, 900);
            log_security_event('password_reset_requested', $user['id'] ?? null, 'email=' . $email);

            // Always redirect to prevent email enumeration
            header('Location: index.php?page=login&sent=1');
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
    <title><?php echo e(t('Reset password')); ?> - <?php echo e($app_name); ?></title>
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
    <div class="login-card rounded-3xl w-full max-w-md p-8 relative z-10 animate-scale-in">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 transition-transform hover:scale-105" style="background: linear-gradient(135deg, var(--corp-blue-500) 0%, var(--corp-blue-600) 100%); box-shadow: 0 10px 40px -10px rgba(59, 130, 246, 0.5);">
                <span class="text-white text-2xl font-bold"><?php echo strtoupper(substr($app_name, 0, 1)); ?></span>
            </div>
            <h1 class="text-2xl font-bold text-gradient"><?php echo e(t('Reset password')); ?></h1>
            <p style="color: var(--text-muted);"><?php echo e(t('Enter your email to reset your password')); ?></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error mb-6 animate-fade-in">
                <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php echo csrf_field(); ?>
            <div>
                <label class="block text-sm font-medium mb-2" style="color: var(--text-secondary);"><?php echo e(t('Email')); ?></label>
                <input type="email" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>" class="form-input login-input"
                    autocomplete="username" inputmode="email" autocapitalize="none" required autofocus>
            </div>

            <button type="submit" class="btn btn-primary w-full mt-6 login-btn">
                <?php echo e(t('Send reset link')); ?>
            </button>
        </form>

        <form method="get" class="flex items-center justify-center gap-3 mt-6">
            <?php foreach ($lang_params as $key => $value): ?>
                <input type="hidden" name="<?php echo e($key); ?>" value="<?php echo e($value); ?>">
            <?php endforeach; ?>
            <label for="lang-select" class="text-xs uppercase tracking-wide" style="color: var(--text-muted);"><?php echo e(t('Language')); ?></label>
            <select id="lang-select" name="lang" class="form-select w-auto text-sm" style="border-radius: var(--radius-md);" onchange="this.form.submit()">
                <?php foreach ($lang_options as $code => $label): ?>
                    <option value="<?php echo e($code); ?>" <?php echo $code === $current_lang ? 'selected' : ''; ?>>
                        <?php echo e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="text-center mt-6">
            <a href="<?php echo url('login', ['lang' => $current_lang]); ?>"
                class="text-sm transition-colors hover:text-blue-400" style="color: var(--primary);">
                <?php echo e(t('Back to sign in')); ?>
            </a>
        </div>

        <p class="text-center text-sm mt-8" style="color: var(--text-muted);">
            &copy; <?php echo date('Y'); ?> <?php echo e($app_name); ?>
        </p>
    </div>
</body>

</html>

