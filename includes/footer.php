        </div><!-- /.content-wrapper -->
        
        <!-- Footer -->
        <footer class="mt-auto py-3 bg-light border-top">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-md-6 text-center text-md-start">
                        <span class="text-muted">
                            &copy; <?= date('Y') ?> VolunteerOps. Με επιφύλαξη παντός δικαιώματος.
                        </span>
                    </div>
                    <div class="col-md-6 text-center text-md-end">
                        <span class="text-muted">
                            Made with <span class="text-danger">&hearts;</span> by Theodore Sfakianakis
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
        
        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function(alert) {
            setTimeout(function() {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
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
