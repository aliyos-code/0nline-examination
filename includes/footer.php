<?php
/**
 * Shared page footer: closes the container, renders the site footer and loads
 * the Bootstrap bundle plus the application script.
 */
?>
</main>

<footer class="app-footer text-center py-3 mt-4">
    <div class="container">
        <span class="text-muted small">
            &copy; <?php echo date('Y'); ?> Online Examination and Result Management System
        </span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo url('assets/js/script.js'); ?>"></script>
</body>
</html>
