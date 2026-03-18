<?php
/**
 * Page header component
 * Expected variables:
 * - $page_header_subtitle (string, optional)
 * - $page_header_actions (string HTML, optional)
 * - $page_header_breadcrumbs (array, optional): [['label' => '...', 'url' => '...'], ...]
 */
?>
<div class="page-header mb-2">
    <?php if (!empty($page_header_breadcrumbs) && is_array($page_header_breadcrumbs)): ?>
        <nav class="text-xs text-gray-500 mb-2">
            <?php foreach ($page_header_breadcrumbs as $index => $crumb): ?>
                <?php if (!empty($crumb['url'])): ?>
                    <a href="<?php echo e($crumb['url']); ?>" class="hover:text-blue-600"><?php echo e($crumb['label']); ?></a>
                <?php else: ?>
                    <span><?php echo e($crumb['label']); ?></span>
                <?php endif; ?>
                <?php if ($index < count($page_header_breadcrumbs) - 1): ?>
                    <span class="mx-1">/</span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <!-- Title removed as per UI cleanup request (redundant with top bar) -->
            <?php if (!empty($page_header_subtitle)): ?>
                <p class="text-sm text-gray-500 mt-1"><?php echo e($page_header_subtitle); ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($page_header_actions)): ?>
            <div class="flex flex-wrap items-center gap-2">
                <?php echo $page_header_actions; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
