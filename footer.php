</div> <!-- End .content-wrapper -->

<footer class="py-3 mt-auto">
    <div class="container-fluid px-lg-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 text-muted small">
        <div>
            <strong><?= APP_NAME ?></strong> &copy; <?= date('Y') ?> &middot; Built with PHP, MySQL, HTML5 & Bootstrap 5
        </div>
        <div class="d-flex align-items-center gap-3">
            <span><i class="bi bi-shield-lock text-success me-1"></i> CSRF & PDO Prepared Statements</span>
            <span><i class="bi bi-check2-circle text-primary me-1"></i> Transaction Safe ACID</span>
        </div>
    </div>
</footer>

<!-- Bootstrap 5.3.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// 1. Dark Mode / Light Mode Toggle Logic
(() => {
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const themeIcon = document.getElementById('themeIcon');
    const themeText = document.getElementById('themeText');

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        localStorage.setItem('theme', theme);

        if (themeIcon) {
            if (theme === 'dark') {
                themeIcon.className = 'bi bi-sun-fill text-warning';
                if (themeText) themeText.textContent = 'Light';
            } else {
                themeIcon.className = 'bi bi-moon-stars-fill text-light';
                if (themeText) themeText.textContent = 'Dark';
            }
        }
    }

    // Set initial icon and text based on current theme
    const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
    applyTheme(currentTheme);

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const activeTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
            const nextTheme = activeTheme === 'dark' ? 'light' : 'dark';
            applyTheme(nextTheme);
        });
    }
})();

// 2. Client-side Form Validation (Bootstrap standard)
(() => {
    'use strict'
    const forms = document.querySelectorAll('.needs-validation')
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
            }
            form.classList.add('was-validated')
        }, false)
    })
})();
</script>
</body>
</html>
