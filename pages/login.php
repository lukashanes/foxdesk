<?php
/**
 * Login Page — with optional TOTP 2FA
 */

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$settings = get_settings();
$app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');

$error = '';
$info_message = '';
$show_2fa_form = !empty($_SESSION['2fa_pending']);
$current_lang = get_app_language();
$lang_options = [
    'en' => t('English'),
    'cs' => t('Czech'),
    'de' => t('German'),
    'it' => t('Italian'),
    'es' => t('Spanish')
];
$lang_params = ['page' => 'login'];
if (isset($_GET['reset'])) {
    $lang_params['reset'] = $_GET['reset'];
}
if (isset($_GET['sent'])) {
    $lang_params['sent'] = $_GET['sent'];
}

// Cancel 2FA — user clicked "Back to sign in"
if (isset($_GET['cancel2fa'])) {
    unset($_SESSION['2fa_pending']);
    header('Location: index.php?page=login');
    exit;
}

// ─── Phase 2: TOTP code verification ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_2fa'])) {
    if (!csrf_is_valid()) {
        $error = t('Security check failed. Please try again.');
        $show_2fa_form = true;
    } elseif (empty($_SESSION['2fa_pending'])) {
        $error = t('Session expired. Please sign in again.');
    } else {
        $pending = $_SESSION['2fa_pending'];

        // Check if 2FA session expired (15 min max)
        if (time() - $pending['timestamp'] > 900) {
            unset($_SESSION['2fa_pending']);
            $error = t('Verification expired. Please sign in again.');
        } else {
            $rate_key_2fa = '2fa_verify_' . $pending['user_id'];

            if (rate_limit_is_blocked($rate_key_2fa, 5, 300)) {
                unset($_SESSION['2fa_pending']);
                log_security_event('2fa_brute_force_blocked', $pending['user_id']);
                $error = t('Too many attempts. Please sign in again.');
            } else {
                require_once BASE_PATH . '/includes/totp.php';
                $submitted_code = trim($_POST['code'] ?? '');

                $user_row = db_fetch_one("SELECT totp_secret, totp_backup_codes FROM users WHERE id = ?", [$pending['user_id']]);

                $valid = false;
                if ($user_row && !empty($user_row['totp_secret'])) {
                    $valid = totp_verify($user_row['totp_secret'], $submitted_code)
                          || verify_backup_code($pending['user_id'], $submitted_code);
                }

                if ($valid) {
                    // 2FA passed — complete login
                    rate_limit_clear($rate_key_2fa);
                    rate_limit_clear('login');
                    session_regenerate_id(true);
                    $_SESSION = [];
                    $_SESSION['user_id']    = $pending['user_id'];
                    $_SESSION['user_email'] = $pending['user_email'];
                    $_SESSION['user_name']  = $pending['user_name'];
                    $_SESSION['user_role']  = $pending['user_role'];
                    $_SESSION['lang']       = $pending['lang'];

                    if ($pending['remember_me']) {
                        set_remember_token($pending['user_id']);
                    }
                    log_security_event('2fa_verified', $pending['user_id']);
                    header('Location: index.php?page=dashboard');
                    exit;
                } else {
                    rate_limit_record($rate_key_2fa, 300);
                    log_security_event('2fa_failed', $pending['user_id']);
                    $error = t('Invalid or expired code. Please try again.');
                    $show_2fa_form = true;
                }
            }
        }
    }
}

// ─── Phase 1: Handle login form ─────────────────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['verify_2fa'])) {
    if (!csrf_is_valid()) {
        $error = t('Security check failed. Please try again.');
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rate_key = 'login';

        if (rate_limit_is_blocked($rate_key, 5, 900)) {
            $error = t('Too many attempts. Please wait and try again.');
        } elseif (empty($email) || empty($password)) {
            $error = t('Enter email and password.');
        } elseif (login($email, $password)) {
            // Password correct — check if 2FA is needed
            require_once BASE_PATH . '/includes/totp.php';
            ensure_totp_columns();
            $logged_user = current_user();

            $user_has_2fa = is_2fa_enabled($logged_user);
            $role_requires_2fa = is_2fa_required_for_role($logged_user['role']);

            if ($user_has_2fa || $role_requires_2fa) {
                // Park the session — user is NOT logged in yet
                $pending = [
                    'user_id'      => $_SESSION['user_id'],
                    'user_email'   => $_SESSION['user_email'],
                    'user_name'    => $_SESSION['user_name'],
                    'user_role'    => $_SESSION['user_role'],
                    'lang'         => $_SESSION['lang'] ?? 'en',
                    'remember_me'  => !empty($_POST['remember_me']),
                    'totp_enabled' => $user_has_2fa,
                    'timestamp'    => time(),
                ];

                // Wipe the login session — is_logged_in() must return false
                $_SESSION = [];
                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                if ($user_has_2fa) {
                    // User has 2FA set up → show code entry form
                    $_SESSION['2fa_pending'] = $pending;
                    log_security_event('2fa_challenge', $pending['user_id']);
                    $show_2fa_form = true;
                } else {
                    // 2FA required by role but user hasn't set it up → force setup
                    $_SESSION['user_id']    = $pending['user_id'];
                    $_SESSION['user_email'] = $pending['user_email'];
                    $_SESSION['user_name']  = $pending['user_name'];
                    $_SESSION['user_role']  = $pending['user_role'];
                    $_SESSION['lang']       = $pending['lang'];
                    $_SESSION['2fa_setup_required'] = true;

                    if (!empty($pending['remember_me'])) {
                        set_remember_token($pending['user_id']);
                    }
                    rate_limit_clear($rate_key);
                    header('Location: index.php?page=profile&setup2fa=1');
                    exit;
                }
            } else {
                // No 2FA needed — complete login normally
                if (!empty($_POST['remember_me'])) {
                    set_remember_token($_SESSION['user_id']);
                }
                rate_limit_clear($rate_key);
                header('Location: index.php?page=dashboard');
                exit;
            }
        } else {
            rate_limit_record($rate_key, 900);
            log_security_event('login_failed', null, 'email=' . $email);
            $error = t('Invalid email or password.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(get_app_language()); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('Sign in')); ?> - <?php echo e($app_name); ?></title>
    <link href="tailwind.min.css" rel="stylesheet">
    <link href="theme.css" rel="stylesheet">
    <script>
        // Apply theme immediately to prevent flash
        (function () {
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
            box-shadow:
                0 25px 50px -12px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
        }

        .login-logo {
            background: linear-gradient(135deg, var(--corp-blue-500) 0%, var(--corp-blue-600) 100%);
            box-shadow: 0 10px 40px -10px rgba(59, 130, 246, 0.5);
        }

        /* Modern Split Layout Fixes */
        .split-layout {
            display: flex;
            min-height: 100vh;
            margin: 0;
            width: 100%;
        }

        .split-left {
            display: none;
        }

        .split-right {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            background-color: var(--surface-primary);
        }

        @media (min-width: 1024px) {
            .split-left {
                display: flex;
                width: 50%;
                align-items: center;
                justify-content: center;
                position: relative;
                overflow: hidden;
                background-color: var(--corp-slate-900);
                border-right: 1px solid var(--border-light);
            }

            .split-right {
                width: 50%;
            }
        }
    </style>
</head>

<body class="split-layout">
    <!-- Left Half: Dark Brand Area -->
    <div class="split-left">
        <div class="absolute inset-0 z-0">
            <div class="absolute inset-0 bg-gradient-to-br from-[#3c50e0] to-[#1c2434] opacity-90"></div>
        </div>
        <div class="relative z-10 text-center text-white p-12 max-w-lg">
            <?php $app_logo = get_setting('app_logo', ''); ?>
            <?php if ($app_logo): ?>
                <img src="<?php echo e(upload_url($app_logo)); ?>" alt="<?php echo e($app_name); ?>"
                    class="w-24 h-24 rounded-full object-cover mx-auto mb-8 shadow-2xl ring-4 ring-white/10">
            <?php else: ?>
                <div
                    class="w-24 h-24 rounded-full flex items-center justify-center mx-auto mb-8 shadow-2xl bg-[#3c50e0] ring-4 ring-white/10">
                    <span class="text-white text-4xl font-bold"><?php echo strtoupper(substr($app_name, 0, 1)); ?></span>
                </div>
            <?php endif; ?>
            <h1 class="text-4xl font-bold mb-4"><?php echo e(t('Welcome to {app}', ['app' => $app_name])); ?></h1>
            <p class="text-slate-300 text-lg">
                <?php echo e($settings['login_welcome_text'] ?? t('Manage your tickets, track time, and support your customers with our corporate enterprise helpdesk.')); ?>
            </p>
        </div>
    </div>

    <!-- Right Half: Form Area -->
    <div class="split-right" style="position: relative;">
        <!-- Theme Toggle -->
        <button onclick="toggleTheme()"
            class="theme-toggle p-2 rounded-full hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors bg-white shadow-sm border border-slate-200 lg:bg-transparent lg:shadow-none lg:border-transparent"
            style="position: absolute; top: 1.5rem; right: 1.5rem; z-index: 50;"
            title="<?php echo e(t('Toggle theme')); ?>">
            <svg class="theme-toggle__icon theme-toggle__icon--light w-5 h-5 text-slate-500" fill="none"
                stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z">
                </path>
            </svg>
            <svg class="theme-toggle__icon theme-toggle__icon--dark w-5 h-5 text-slate-400" fill="none"
                stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
            </svg>
        </button>

        <div class="w-full max-w-sm animate-fade-in">

            <?php if (!empty($show_2fa_form) && !empty($_SESSION['2fa_pending'])): ?>
                <?php
                    // Mask email: show first 3 chars + *** + @domain
                    $pending_email = $_SESSION['2fa_pending']['user_email'];
                    $at_pos = strpos($pending_email, '@');
                    $masked_email = ($at_pos > 3
                        ? substr($pending_email, 0, 3) . '***'
                        : substr($pending_email, 0, 1) . '***')
                        . substr($pending_email, $at_pos);
                ?>
                <!-- ═══ 2FA Code Entry Form ═══ -->
                <div class="text-left mb-8">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center" style="background: var(--surface-secondary);">
                            <svg class="w-5 h-5" style="color: var(--primary);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold" style="color: var(--text-primary);">
                                <?php echo e(t('Two-factor authentication')); ?>
                            </h2>
                        </div>
                    </div>
                    <p class="text-sm" style="color: var(--text-muted);">
                        <?php echo e(t('Enter the 6-digit code from your authenticator app.')); ?>
                    </p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error mb-6 animate-fade-in text-sm rounded-lg p-3 bg-red-50 text-red-600 border border-red-200 dark:bg-red-900/30 dark:border-red-800/50 dark:text-red-400">
                        <?php echo e($error); ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-medium mb-1.5" style="color: var(--text-primary);">
                                <?php echo e(t('Verification code')); ?>
                            </label>
                            <input type="text" name="code" maxlength="9" pattern="[a-zA-Z0-9\-]{6,9}"
                                inputmode="numeric" autocomplete="one-time-code"
                                class="form-input w-full rounded-lg border-slate-300 dark:border-slate-700 bg-transparent px-4 py-3 text-center text-2xl tracking-[0.3em] font-mono focus:border-[#3c50e0] focus:ring-1 focus:ring-[#3c50e0]"
                                placeholder="000000" required autofocus>
                            <p class="text-xs mt-1.5" style="color: var(--text-muted);">
                                <?php echo e(t('Or enter a backup code (e.g. xxxx-xxxx)')); ?>
                            </p>
                        </div>
                    </div>

                    <button type="submit" name="verify_2fa" value="1"
                        class="btn btn-primary w-full mt-6 py-2.5 text-base rounded-lg transition-transform active:scale-[0.98]">
                        <?php echo e(t('Verify')); ?>
                    </button>
                </form>

                <div class="flex items-center justify-center mt-6">
                    <a href="<?php echo url('login', ['cancel2fa' => '1']); ?>"
                        class="text-sm transition-colors" style="color: var(--text-muted);">
                        &larr; <?php echo e(t('Back to sign in')); ?>
                    </a>
                </div>

            <?php else: ?>
                <!-- ═══ Standard Login Form ═══ -->
                <div class="text-left mb-8">
                    <h2 class="text-3xl font-bold mb-2" style="color: var(--text-primary);"><?php echo e(t('Sign in')); ?>
                    </h2>
                    <p style="color: var(--text-muted);"><?php echo e(t('Sign in to your account')); ?></p>
                </div>

                <?php if ($error): ?>
                    <div
                        class="alert alert-error mb-6 animate-fade-in text-sm rounded-lg p-3 bg-red-50 text-red-600 border border-red-200 dark:bg-red-900/30 dark:border-red-800/50 dark:text-red-400">
                        <?php echo e($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
                    <div
                        class="alert alert-success mb-6 animate-fade-in text-sm rounded-lg p-3 bg-green-50 text-green-600 border border-green-200 dark:bg-green-900/30 dark:border-green-800/50 dark:text-green-400">
                        <?php echo e(t('Password updated successfully. You can sign in now.')); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['sent']) && $_GET['sent'] === '1'): ?>
                    <div
                        class="alert alert-info mb-6 animate-fade-in text-sm rounded-lg p-3 bg-blue-50 text-blue-600 border border-blue-200 dark:bg-blue-900/30 dark:border-blue-800/50 dark:text-blue-400">
                        <?php echo e(t('If an account exists for this email, a reset link has been sent.')); ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-medium mb-1.5"
                                style="color: var(--text-primary);"><?php echo e(t('Email')); ?></label>
                            <input type="email" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>"
                                class="form-input w-full rounded-lg border-slate-300 dark:border-slate-700 bg-transparent px-4 py-2.5 focus:border-[#3c50e0] focus:ring-1 focus:ring-[#3c50e0]"
                                autocomplete="username" inputmode="email" autocapitalize="none" required autofocus>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5"
                                style="color: var(--text-primary);"><?php echo e(t('Password')); ?></label>
                            <input type="password" name="password"
                                class="form-input w-full rounded-lg border-slate-300 dark:border-slate-700 bg-transparent px-4 py-2.5 focus:border-[#3c50e0] focus:ring-1 focus:ring-[#3c50e0]"
                                autocomplete="current-password" required>
                        </div>
                    </div>

                    <div class="flex items-center justify-between mt-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="remember_me" value="1"
                                class="form-checkbox rounded" style="width: 16px; height: 16px;">
                            <span class="text-sm" style="color: var(--text-secondary);"><?php echo e(t('Remember me')); ?></span>
                        </label>
                        <a href="<?php echo url('forgot-password', ['lang' => $current_lang]); ?>"
                            class="text-sm font-medium transition-colors hover:text-[#3243bd]"
                            style="color: var(--primary);">
                            <?php echo e(t('Forgot password?')); ?>
                        </a>
                    </div>

                    <button type="submit"
                        class="btn btn-primary w-full mt-6 py-2.5 text-base rounded-lg transition-transform active:scale-[0.98]">
                        <?php echo e(t('Sign in')); ?>
                    </button>
                </form>

                <form method="get" class="flex flex-col items-center justify-center gap-2 mt-8">
                    <?php foreach ($lang_params as $key => $value): ?>
                        <input type="hidden" name="<?php echo e($key); ?>" value="<?php echo e($value); ?>">
                    <?php endforeach; ?>
                    <div class="flex items-center justify-center gap-3 px-3 py-1.5 mt-4 mx-auto max-w-[fit-content]">
                        <div class="text-[11px] uppercase tracking-wider font-semibold" style="color: var(--text-muted);">
                            <?php echo e(t('Language')); ?></div>
                        <select id="lang-select" name="lang"
                            class="bg-transparent text-sm border-none shadow-none focus:ring-0 cursor-pointer p-0 font-medium text-slate-700 dark:text-slate-300"
                            style="min-width: 100px; padding-right: 0; outline: none;" onchange="this.form.submit()">
                            <?php foreach ($lang_options as $code => $label): ?>
                                <option value="<?php echo e($code); ?>" <?php echo $code === $current_lang ? 'selected' : ''; ?>>
                                    <?php echo e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            <?php endif; ?>

            <p class="text-center text-xs mt-8" style="color: var(--text-muted);">
                &copy; <?php echo date('Y'); ?> <?php echo e($app_name); ?>
            </p>
        </div>
    </div>

    <script>
        function toggleTheme() {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme');
            const next = current === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
        }
    </script>
</body>

</html>
