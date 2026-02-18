<?php if (!defined('VOLUNTEEROPS')) die('Direct access not permitted'); ?>
        </div><!-- /.content-wrapper -->
        
        <!-- Footer -->
        <footer class="mt-auto py-3 bg-light border-top">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-md-6 text-center text-md-start">
                        <span class="text-muted fs-6">
                            &copy; <?= date('Y') ?> VolunteerOps. Με επιφύλαξη παντός δικαιώματος.
                        </span>
                    </div>
                    <div class="col-md-6 text-center text-md-end">
                        <span class="fw-semibold fs-6">
                            Made with <span class="text-danger">&hearts;</span> by <span class="text-primary">Theodore Sfakianakis</span>
                        </span>
                    </div>
                </div>
            </div>
        </footer>
    </div><!-- /.main-content -->
    
    <!-- jQuery (required for Summernote) -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Flatpickr -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/gr.js"></script>
    
    <script>
        // Sidebar scroll position persistence
        (function() {
            var sidebar = document.getElementById('sidebar');
            if (sidebar) {
                // Restore scroll position
                var saved = sessionStorage.getItem('sidebarScroll');
                if (saved !== null) {
                    sidebar.scrollTop = parseInt(saved, 10);
                }
                // Save scroll position before navigating away
                sidebar.querySelectorAll('a').forEach(function(link) {
                    link.addEventListener('click', function() {
                        sessionStorage.setItem('sidebarScroll', sidebar.scrollTop);
                    });
                });
                // Also save on beforeunload for form submits etc
                window.addEventListener('beforeunload', function() {
                    sessionStorage.setItem('sidebarScroll', sidebar.scrollTop);
                });
            }
        })();

        // Sidebar toggle for mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }
        
        document.getElementById('sidebarOverlay').addEventListener('click', function() {
            toggleSidebar();
        });
        
        // Initialize flatpickr with Greek locale
        flatpickr.localize(flatpickr.l10ns.gr);
        
        document.querySelectorAll('.datepicker').forEach(function(el) {
            flatpickr(el, {
                dateFormat: 'd/m/Y',
                allowInput: true
            });
        });
        
        document.querySelectorAll('.datetimepicker').forEach(function(el) {
            flatpickr(el, {
                enableTime: true,
                dateFormat: 'd/m/Y H:i',
                time_24hr: true,
                allowInput: true
            });
        });
        
        // Initialize Bootstrap tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (el) {
            return new bootstrap.Tooltip(el);
        });
    </script>
    
    <?php if (isset($pageScripts)): ?>
    <?= $pageScripts ?>
    <?php endif; ?>
</body>
</html>
