<?php
$api_scope_catalog = function_exists('api_token_scope_catalog') ? api_token_scope_catalog($user) : [];
$profile_api_tokens = [];
if (function_exists('api_tokens_table_exists') && api_tokens_table_exists()) {
    $profile_api_tokens = db_fetch_all("SELECT * FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC", [(int) $user['id']]);
}
$new_profile_api_token = $_SESSION['new_profile_api_token'] ?? null;
$new_profile_api_token_scopes = $_SESSION['new_profile_api_token_scopes'] ?? [];
unset($_SESSION['new_profile_api_token'], $_SESSION['new_profile_api_token_scopes']);

?>
<div id="api-access" data-profile-panel="integrations" data-profile-force="<?php echo $new_profile_api_token ? 'integrations' : ''; ?>" class="card card-body">
            <div class="mb-4">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-theme-muted"><?php echo e(t('API access')); ?></h3>
                <p class="text-sm mt-1 text-theme-secondary"><?php echo e(t('Create a scoped key for assistants, CLI tools, or automations.')); ?></p>
            </div>

            <?php if ($new_profile_api_token): ?>
                <div class="mb-5 p-4 rounded-lg border border-green-200 bg-green-50 text-green-950" data-api-key-ready>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold"><?php echo e(t('API key created. Copy it now.')); ?></p>
                            <p class="text-sm mt-1"><?php echo e(t('Copy this API key now. It will not be shown again.')); ?></p>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" data-api-key-copy
                            onclick="copyProfileApiKey('profile-generated-api-token', this)">
                            <?php echo get_icon('copy', 'w-4 h-4 mr-1'); ?><?php echo e(t('Copy')); ?>
                        </button>
                    </div>
                    <code id="profile-generated-api-token" class="block mt-3 p-3 rounded bg-white border text-xs font-mono break-all select-all"><?php echo e($new_profile_api_token); ?></code>
                    <?php if (!empty($new_profile_api_token_scopes)): ?>
                        <p class="text-xs mt-2"><?php echo e(t('Scopes')); ?>: <?php echo e(implode(', ', $new_profile_api_token_scopes)); ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form action="<?php echo e(url('profile')); ?>#api-access" method="post" class="space-y-4 mb-5">
                    <?php echo csrf_field(); ?>
                    <div>
                        <label for="api-token-name" class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Token name')); ?></label>
                        <input type="text" name="api_token_name" id="api-token-name" class="form-input"
                            placeholder="<?php echo e(t('Codex local assistant')); ?>" maxlength="120">
                    </div>
                    <p class="text-xs text-theme-muted"><?php echo e(t('The key stays active until you revoke it.')); ?></p>

                    <?php if (!empty($api_scope_catalog)): ?>
                        <div>
                            <div class="text-sm font-medium mb-2 text-theme-secondary"><?php echo e(t('Allowed actions')); ?></div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <?php foreach ($api_scope_catalog as $scope => $label): ?>
                                    <label class="flex items-start gap-2 text-sm cursor-pointer rounded-lg border p-2 border-theme-light">
                                        <input type="checkbox" name="api_token_scopes[]" value="<?php echo e($scope); ?>" class="mt-0.5 rounded"
                                            <?php echo in_array($scope, ['work:read', 'tickets:read', 'tickets:write', 'comments:write'], true) ? 'checked' : ''; ?>>
                                        <span>
                                            <span class="font-medium text-theme-primary"><?php echo e($scope); ?></span>
                                            <span class="block text-xs text-theme-muted"><?php echo e(t($label)); ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" name="create_api_token" class="btn btn-primary">
                        <?php echo e(t('Create API key')); ?>
                    </button>
                </form>
            <?php endif; ?>

            <div class="overflow-x-auto">
                <table class="w-full tickets-table">
                    <thead>
                        <tr>
                            <th class="th-label text-left py-2"><?php echo e(t('Name')); ?></th>
                            <th class="th-label text-left py-2"><?php echo e(t('Prefix')); ?></th>
                            <th class="th-label text-left py-2"><?php echo e(t('Scopes')); ?></th>
                            <th class="th-label text-left py-2"><?php echo e(t('Last used')); ?></th>
                            <th class="th-label text-right py-2"><?php echo e(t('Actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($profile_api_tokens)): ?>
                            <tr>
                                <td colspan="5" class="py-4 text-sm text-theme-muted"><?php echo e(t('No API keys yet.')); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($profile_api_tokens as $token): ?>
                            <?php
                            $token_scopes = !empty($token['scopes_json']) ? json_decode((string) $token['scopes_json'], true) : ['*'];
                            if (!is_array($token_scopes) || empty($token_scopes)) {
                                $token_scopes = ['*'];
                            }
                            $is_active_token = !empty($token['is_active']) && empty($token['revoked_at']);
                            ?>
                            <tr class="<?php echo $is_active_token ? '' : 'opacity-60'; ?>">
                                <td class="py-2 text-sm text-theme-primary"><?php echo e($token['name']); ?></td>
                                <td class="py-2 text-xs font-mono text-theme-muted"><?php echo e($token['token_prefix']); ?>...</td>
                                <td class="py-2 text-xs text-theme-muted"><?php echo e(implode(', ', $token_scopes)); ?></td>
                                <td class="py-2 text-xs text-theme-muted"><?php echo e(!empty($token['last_used_at']) ? format_date($token['last_used_at']) : t('Never')); ?></td>
                                <td class="py-2 text-right">
                                    <?php if ($is_active_token): ?>
                                        <form action="<?php echo e(url('profile')); ?>#api-access" method="post" class="inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="token_id" value="<?php echo (int) $token['id']; ?>">
                                            <button type="submit" name="revoke_api_token" class="btn btn-danger btn-sm"
                                                onclick="return confirm('<?php echo e(t('Revoke this API key?')); ?>')">
                                                <?php echo e(t('Revoke')); ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-xs text-theme-muted"><?php echo e(t('Revoked')); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

