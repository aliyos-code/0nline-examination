<?php
/**
 * Administrator sidebar navigation.
 *
 * Set $active before including to highlight the current page.
 */
$active = isset($active) ? $active : '';
$items = [
    'dashboard'    => ['label' => 'Dashboard',    'icon' => 'speedometer2',             'href' => 'admin/dashboard.php'],
    'students'     => ['label' => 'Students',     'icon' => 'people',                   'href' => 'admin/students.php'],
    'examinations' => ['label' => 'Examinations', 'icon' => 'journal-text',             'href' => 'admin/examinations.php'],
    'ai'           => ['label' => 'AI Generator', 'icon' => 'robot',                     'href' => 'admin/ai_exam.php'],
    'results'      => ['label' => 'Results',      'icon' => 'clipboard-data',           'href' => 'admin/results.php'],
    'reports'      => ['label' => 'Reports',      'icon' => 'file-earmark-bar-graph',   'href' => 'admin/reports.php'],
    'announcements' => ['label' => 'Announcements', 'icon' => 'megaphone',               'href' => 'admin/announcements.php'],
    'audit'        => ['label' => 'Audit Log',    'icon' => 'shield-lock',              'href' => 'admin/audit_log.php'],
    'settings'     => ['label' => 'Settings',     'icon' => 'gear',                     'href' => 'admin/settings.php'],
    'profile'      => ['label' => 'My Profile',   'icon' => 'person-gear',              'href' => 'admin/profile.php'],
];
?>
<div class="list-group admin-sidebar shadow-sm">
    <?php foreach ($items as $key => $item): ?>
        <a href="<?php echo url($item['href']); ?>"
           class="list-group-item list-group-item-action <?php echo $active === $key ? 'active' : ''; ?>">
            <i class="bi bi-<?php echo e($item['icon']); ?> me-2"></i><?php echo e($item['label']); ?>
        </a>
    <?php endforeach; ?>
</div>
