<?php
// Self-hosted keys retain the existing per-user ownership and scope controls.
$user = current_user();
include BASE_PATH . '/includes/components/profile-api-access.php';
?>
<a class="btn btn-secondary" href="<?php echo e(url('admin', ['section' => 'users'])); ?>"><?php echo e(t('Team & access')); ?></a>
