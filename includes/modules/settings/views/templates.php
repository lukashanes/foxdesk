        <!-- Email Templates -->
        <?php
        $template_info = settings_email_template_catalog();
        $display_templates = settings_email_template_display_rows($templates, $template_lang);
        $selected_template = (string) ($_GET['template'] ?? ($display_templates[0]['template_key'] ?? ''));
        if (!in_array($selected_template, array_column($display_templates, 'template_key'), true)) $selected_template = (string) ($display_templates[0]['template_key'] ?? '');
        ?>

        <div class="mb-2 flex justify-between items-center">
            <h3 class="font-semibold text-theme-primary"><?php echo e(t('Email Templates')); ?></h3>

            <form action="" method="get" class="flex items-center gap-2">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="section" value="settings">
                <input type="hidden" name="tab" value="templates">
                <label class="text-sm text-theme-secondary" for="settings-template-picker"><?php echo e(t('Templates')); ?></label>
                <select id="settings-template-picker" name="template" onchange="this.form.submit()" class="form-select">
                    <?php foreach ($display_templates as $choice): ?><option value="<?php echo e($choice['template_key']); ?>" <?php echo $selected_template === $choice['template_key'] ? 'selected' : ''; ?>><?php echo e($template_info[$choice['template_key']]['name'] ?? $choice['template_key']); ?></option><?php endforeach; ?>
                </select>

                <label class="text-sm text-theme-secondary"><?php echo e(t('Language:')); ?></label>
                <select name="lang" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                    <?php foreach (get_supported_languages() as $code => $language): ?>
                        <option value="<?php echo e($code); ?>" <?php echo $template_lang === $code ? 'selected' : ''; ?>><?php echo e(foxdesk_locale_option_label($code)); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="space-y-3">
            <?php foreach ($display_templates as $template):
                if ($template['template_key'] !== $selected_template) continue;
                $info = $template_info[$template['template_key']] ?? null;
                ?>
                <div class="admin-list-card">
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="template_key" value="<?php echo e($template['template_key']); ?>">
                        <input type="hidden" name="template_lang" value="<?php echo e($template_lang); ?>">

                        <div class="px-6 py-3 border-b bg-theme-secondary">
                            <div>
                                <h4 class="font-semibold text-theme-primary">
                                    <?php echo e($info['name'] ?? $template['template_key']); ?>
                                </h4>
                                <?php if ($info): ?>
                                    <p class="text-sm text-theme-muted"><?php echo e($info['description']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="p-6">
                            <?php if ($info && !empty($info['variables'])): ?>
                                <div class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                    <div class="text-sm font-medium text-blue-800 mb-2"><?php echo e(t('Available variables:')); ?>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($info['variables'] as $var => $desc): ?>
                                            <span class="inline-flex items-center border border-blue-200 rounded px-2 py-1 text-xs"
                                                style="background: var(--bg-primary);" title="<?php echo e($desc); ?>">
                                                <code class="text-blue-600"><?php echo e($var); ?></code>
                                                <span class="ml-1 text-theme-muted">- <?php echo e($desc); ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Email subject')); ?></label>
                                    <input type="text" name="template_subject" value="<?php echo e($template['subject']); ?>"
                                        dir="<?php echo e(get_app_direction($template_lang)); ?>" class="form-input">
                                    <p class="text-xs mt-1 text-theme-muted">
                                        <?php echo e(t('You can use variables in the subject, e.g. {ticket_title}.')); ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Email body')); ?></label>
                                    <textarea name="template_body" rows="8"
                                        dir="<?php echo e(get_app_direction($template_lang)); ?>" class="form-textarea font-mono text-sm"><?php echo e($template['body']); ?></textarea>
                                </div>
                            </div>
                            <div class="mt-4 flex justify-end">
                                <button type="submit" name="save_template" class="btn btn-primary btn-sm">
                                    <?php echo get_icon('save', 'mr-1'); ?>         <?php echo e(t('Save')); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
