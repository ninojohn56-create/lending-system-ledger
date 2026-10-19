<?php
/**
 * Shared HTML Document Footer
 * File: includes/footer.php
 */
?>
<?php if (empty($hideLayout)): ?>
            <footer class="mt-5 pt-3 border-top text-muted small d-flex flex-wrap justify-content-between align-items-center no-print">
                <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All financial values in Philippine Peso (<?= e(APP_CURRENCY) ?>).</span>
                <span>Built with PHP 8, MySQL (PDO) &amp; Bootstrap 5</span>
            </footer>
        </main>
    </div>
<?php endif; ?>

<!-- Bootstrap 5.3 Bundle JS (with Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
