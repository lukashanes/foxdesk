<?php
/**
 * Ticket Share Page (Public, read-only)
 */

$settings = get_settings();
$app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');

$token = trim($_GET['token'] ?? '');
$share = get_ticket_share_by_token($token);

function render_share_message($app_name, $title, $message)
{
    ?>
    <!DOCTYPE html>
    <html lang="<?php echo e(get_app_language()); ?>">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo e($title); ?> - <?php echo e($app_name); ?></title>
        <link href="tailwind.min.css" rel="stylesheet">
        <link href="theme.css" rel="stylesheet">
    </head>

    <body class="bg-gray-100 min-h-screen">
        <div class="max-w-3xl mx-auto px-4 py-10">
            <div class="card card-body text-center">
                <div class="text-lg font-semibold text-gray-800"><?php echo e($title); ?></div>
                <p class="text-sm text-gray-600 mt-2"><?php echo e($message); ?></p>
                <a href="<?php echo url('login'); ?>" class="inline-block mt-4 text-blue-600 hover:text-blue-700 text-sm">
                    <?php echo e(t('Sign in')); ?>
                </a>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
}

if (!$share || !is_ticket_share_active($share)) {
    render_share_message($app_name, t('Link not available'), t('This share link is invalid, expired, or revoked.'));
}

$ticket = get_ticket($share['ticket_id']);
if (!$ticket) {
    render_share_message($app_name, t('Ticket not found'), t('This ticket is no longer available.'));
}

$comments = get_ticket_comments($ticket['id']);
$attachments = get_ticket_attachments($ticket['id']);
$comments = array_values(array_filter($comments, function ($comment) {
    return empty($comment['is_internal']);
}));

mark_ticket_share_accessed($share['id']);
$expires_label = !empty($share['expires_at']) ? format_date($share['expires_at']) : t('Never');
?>
<!DOCTYPE html>
<html lang="<?php echo e(get_app_language()); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(get_ticket_code($ticket['id'])); ?> - <?php echo e($app_name); ?></title>
    <link href="tailwind.min.css" rel="stylesheet">
    <link href="theme.css" rel="stylesheet">
</head>

<body class="bg-gray-100 min-h-screen">
    <header class="bg-white border-b">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 bg-blue-50 dark:bg-blue-900/200 rounded-lg flex items-center justify-center">
                    <span class="text-white font-bold"><?php echo strtoupper(substr($app_name, 0, 1)); ?></span>
                </div>
                <div>
                    <div class="text-lg font-semibold text-gray-800"><?php echo e($app_name); ?></div>
                    <div class="text-xs text-gray-500"><?php echo e(t('Public ticket view (read-only)')); ?></div>
                </div>
            </div>
            <a href="<?php echo url('login'); ?>"
                class="text-sm text-blue-600 hover:text-blue-700"><?php echo e(t('Sign in')); ?></a>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 py-6 space-y-5">
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-100 text-blue-700 text-sm p-3 rounded-lg">
            <?php echo e(t('This link is read-only. To reply or manage tickets, please sign in.')); ?>
        </div>

        <div class="card card-body">
            <div class="flex flex-wrap items-center gap-2 text-sm text-gray-500">
                <span class="font-mono"><?php echo get_ticket_code($ticket['id']); ?></span>
                <span>&middot;</span>
                <span><?php echo e(get_type_label($ticket['type'])); ?></span>
                <span>&middot;</span>
                <span><?php echo e(t('Expires: {date}', ['date' => $expires_label])); ?></span>
            </div>
            <h1 class="text-xl lg:text-2xl font-semibold text-gray-800 mt-1"><?php echo e($ticket['title']); ?></h1>
            <div class="mt-2 text-sm text-gray-500 flex items-center gap-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                    style="background-color: <?php echo e($ticket['status_color']); ?>20; color: <?php echo e($ticket['status_color']); ?>">
                    <?php echo e($ticket['status_name']); ?>
                </span>
                <span><?php echo format_date($ticket['created_at']); ?></span>
            </div>

            <?php if (!empty($ticket['description'])): ?>
                <div class="mt-4 text-gray-700">
                    <?php echo render_content($ticket['description']); ?>
                </div>
            <?php endif; ?>

            <?php
            $initial_attachments = array_filter($attachments, function ($attachment) {
                return empty($attachment['comment_id']);
            });
            ?>
            <?php if (!empty($initial_attachments)): ?>
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-medium text-gray-700 mb-2"><?php echo e(t('Attachments')); ?></h4>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($initial_attachments as $attachment): ?>
                            <?php $_share_url = e(upload_url(UPLOAD_DIR . $attachment['filename'])); ?>
                            <?php if (is_image_mime($attachment['mime_type'] ?? '')): ?>
                                <a href="<?php echo $_share_url; ?>" target="_blank"
                                   class="block rounded-lg overflow-hidden border border-gray-200 hover:shadow-md transition"
                                   style="max-width: 180px;">
                                    <img src="<?php echo $_share_url; ?>" alt="<?php echo e($attachment['original_name']); ?>"
                                         class="w-full h-28 object-cover" loading="lazy">
                                    <div class="px-2 py-1 text-xs text-gray-600 truncate"><?php echo e($attachment['original_name']); ?></div>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo $_share_url; ?>" target="_blank"
                                    class="flex items-center space-x-2 bg-gray-100 hover:bg-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 transition">
                                    <span class="truncate max-w-[180px]"><?php echo e($attachment['original_name']); ?></span>
                                    <span class="text-gray-400 text-xs"><?php echo format_file_size($attachment['file_size']); ?></span>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="px-4 lg:px-6 py-4 border-b">
                <h3 class="font-semibold text-gray-800"><?php echo e(t('Comments')); ?>
                    (<?php echo count($comments); ?>)</h3>
            </div>

            <?php if (empty($comments)): ?>
                <div class="p-6 text-center text-gray-500"><?php echo e(t('No comments yet.')); ?></div>
            <?php else: ?>
                <div class="divide-y">
                    <?php foreach ($comments as $comment): ?>
                        <?php
                        $comment_attachments = array_filter($attachments, function ($attachment) use ($comment) {
                            return $attachment['comment_id'] == $comment['id'];
                        });
                        ?>
                        <div class="p-4 lg:p-6">
                            <div class="flex items-start space-x-3 lg:space-x-4">
                                <div
                                    class="w-8 lg:w-10 h-8 lg:h-10 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                                    <span class="text-blue-600 font-medium text-sm lg:text-base">
                                        <?php echo strtoupper(substr($comment['first_name'], 0, 1)); ?>
                                    </span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 mb-2">
                                        <span
                                            class="font-medium text-gray-800"><?php echo e($comment['first_name'] . ' ' . $comment['last_name']); ?></span>
                                        <?php if (!empty($comment['time_spent']) && $comment['time_spent'] > 0): ?>
                                            <span class="badge bg-blue-100 text-blue-600">
                                                <?php echo get_icon('clock', 'mr-1 w-3 h-3 inline'); ?>            <?php echo e(format_duration_minutes($comment['time_spent'])); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span
                                            class="text-sm text-gray-500"><?php echo format_date($comment['created_at']); ?></span>
                                    </div>
                                    <div class="text-gray-700 break-words">
                                        <?php echo render_content($comment['content']); ?>
                                    </div>

                                    <?php if (!empty($comment_attachments)): ?>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <?php foreach ($comment_attachments as $attachment): ?>
                                                <?php $_share_c_url = e(upload_url(UPLOAD_DIR . $attachment['filename'])); ?>
                                                <?php if (is_image_mime($attachment['mime_type'] ?? '')): ?>
                                                    <a href="<?php echo $_share_c_url; ?>" target="_blank"
                                                       class="block rounded overflow-hidden border border-gray-200 hover:shadow-sm transition"
                                                       style="max-width: 120px;">
                                                        <img src="<?php echo $_share_c_url; ?>" alt="<?php echo e($attachment['original_name']); ?>"
                                                             class="w-full h-20 object-cover" loading="lazy">
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?php echo $_share_c_url; ?>" target="_blank"
                                                        class="flex items-center space-x-2 bg-gray-100 hover:bg-gray-200 rounded px-2 py-1 text-xs text-gray-600 transition">
                                                        <span class="truncate max-w-[140px]"><?php echo e($attachment['original_name']); ?></span>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>

</html>
