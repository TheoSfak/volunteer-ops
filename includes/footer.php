<?php
if (!defined('VOLUNTEEROPS')) die('Direct access not permitted');

// ── Achievement badge popup ─────────────────────────────────────────────────
$_pendingBadges = [];
if (isLoggedIn()) {
    try {
        $_pendingBadges = dbFetchAll(
            "SELECT a.* FROM user_achievements ua
             JOIN achievements a ON ua.achievement_id = a.id
             WHERE ua.user_id = ? AND ua.notified = 0
             ORDER BY ua.earned_at ASC",
            [getCurrentUserId()]
        );
        if (!empty($_pendingBadges)) {
            dbExecute(
                "UPDATE user_achievements SET notified = 1 WHERE user_id = ? AND notified = 0",
                [getCurrentUserId()]
            );
        }
    } catch (Exception $e) {
        $_pendingBadges = [];
    }
}
?>
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
                            &nbsp;&bull;&nbsp; Powered by <a href="https://activeweb.gr" target="_blank" rel="noopener" class="text-decoration-none">ActiveWeb</a>
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

<?php if (!empty($_pendingBadges)): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     ACHIEVEMENT BADGE POPUP — fires once per session when new badges earned
     ══════════════════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>

<style>
#vo-badge-overlay {
    position: fixed; inset: 0; z-index: 99999;
    display: flex; align-items: center; justify-content: center;
    background: rgba(10, 10, 30, 0.82);
    backdrop-filter: blur(6px);
    animation: voBgIn .35s ease;
}
@keyframes voBgIn { from { opacity: 0; } to { opacity: 1; } }

#vo-badge-modal {
    position: relative;
    background: linear-gradient(145deg, #1a1a3e 0%, #2d1b69 50%, #1a2744 100%);
    border: 2px solid rgba(255, 215, 0, 0.6);
    border-radius: 20px;
    width: min(92vw, 560px);
    max-height: 88vh;
    overflow-y: auto;
    box-shadow: 0 0 60px rgba(255, 215, 0, 0.25), 0 25px 60px rgba(0,0,0,0.7);
    animation: voModalIn .5s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes voModalIn {
    from { transform: scale(.5) translateY(80px); opacity:0; }
    to   { transform: scale(1) translateY(0);     opacity:1; }
}

/* Corner ribbons */
#vo-badge-modal::before, #vo-badge-modal::after {
    content: '🎉';
    position: absolute;
    font-size: 28px;
    animation: voSpin 3s linear infinite;
}
#vo-badge-modal::before { top: -14px; left: -14px; }
#vo-badge-modal::after  { bottom: -14px; right: -14px; animation-direction: reverse; }
@keyframes voSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.vo-badge-header {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%);
    border-radius: 18px 18px 0 0;
    padding: 1.5rem 2rem 1.2rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.vo-badge-header::before {
    content: '';
    position: absolute; inset: 0;
    background: repeating-linear-gradient(-45deg,
        transparent, transparent 8px,
        rgba(255,255,255,.07) 8px, rgba(255,255,255,.07) 16px);
}
.vo-trophy-emoji {
    font-size: 56px;
    display: block;
    animation: voTrophy .6s ease .3s both;
    filter: drop-shadow(0 4px 12px rgba(0,0,0,.5));
    position: relative;
}
@keyframes voTrophy {
    from { transform: scale(0) rotate(-20deg); opacity:0; }
    50%  { transform: scale(1.2) rotate(5deg); }
    to   { transform: scale(1) rotate(0); opacity:1; }
}
.vo-badge-header h2 {
    color: #fff;
    font-size: 1.5rem;
    font-weight: 700;
    margin: .4rem 0 .1rem;
    text-shadow: 0 2px 6px rgba(0,0,0,.4);
    position: relative;
}
.vo-badge-header p {
    color: rgba(255,255,255,.9);
    font-size: .9rem;
    margin: 0;
    position: relative;
}

.vo-badges-grid {
    padding: 1.5rem;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 1rem;
}

.vo-badge-card {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,215,0,.3);
    border-radius: 14px;
    padding: 1.2rem .8rem;
    text-align: center;
    animation: voBadgeIn .5s ease both;
    transition: transform .2s, border-color .2s;
    cursor: default;
}
.vo-badge-card:hover {
    transform: translateY(-4px);
    border-color: rgba(255,215,0,.7);
}
@keyframes voBadgeIn {
    from { transform: translateY(30px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}
.vo-badge-icon {
    font-size: 40px;
    line-height: 1;
    display: block;
    margin-bottom: .5rem;
    filter: drop-shadow(0 2px 8px rgba(255,215,0,.5));
    animation: voPulse 2s ease-in-out infinite;
}
@keyframes voPulse {
    0%, 100% { transform: scale(1);    filter: drop-shadow(0 2px 8px rgba(255,215,0,.5)); }
    50%       { transform: scale(1.1); filter: drop-shadow(0 4px 16px rgba(255,215,0,.9)); }
}
.vo-badge-name {
    color: #fde68a;
    font-weight: 700;
    font-size: .9rem;
    line-height: 1.2;
    margin-bottom: .3rem;
}
.vo-badge-desc {
    color: rgba(255,255,255,.65);
    font-size: .75rem;
    line-height: 1.3;
}

.vo-close-btn {
    display: block;
    margin: 0 1.5rem 1.5rem;
    padding: .75rem;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
    font-weight: 700;
    font-size: 1rem;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    width: calc(100% - 3rem);
    transition: transform .15s, box-shadow .15s;
    text-shadow: 0 1px 3px rgba(0,0,0,.3);
    box-shadow: 0 4px 15px rgba(245,158,11,.4);
}
.vo-close-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(245,158,11,.6);
}

/* Scrollbar style */
#vo-badge-modal::-webkit-scrollbar { width: 6px; }
#vo-badge-modal::-webkit-scrollbar-track { background: rgba(255,255,255,.05); }
#vo-badge-modal::-webkit-scrollbar-thumb { background: rgba(255,215,0,.4); border-radius: 3px; }
</style>

<div id="vo-badge-overlay">
    <div id="vo-badge-modal">
        <div class="vo-badge-header">
            <span class="vo-trophy-emoji">🏆</span>
            <h2>Συγχαρητήρια!</h2>
            <p>
                <?php if (count($_pendingBadges) === 1): ?>
                    Κέρδισες ένα νέο badge!
                <?php else: ?>
                    Κέρδισες <?= count($_pendingBadges) ?> νέα badges!
                <?php endif; ?>
            </p>
        </div>

        <div class="vo-badges-grid">
            <?php foreach ($_pendingBadges as $i => $_badge): ?>
            <div class="vo-badge-card" style="animation-delay: <?= $i * 0.12 ?>s">
                <span class="vo-badge-icon"><?= $_badge['icon'] ?: '🏆' ?></span>
                <div class="vo-badge-name"><?= h($_badge['name']) ?></div>
                <div class="vo-badge-desc"><?= h($_badge['description'] ?: '') ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <button class="vo-close-btn" onclick="voClosePopup()">
            🎉 Υπέροχα! Δες όλα τα Επιτεύγματα →
        </button>
    </div>
</div>

<script>
(function() {
    var colors = ['#FFD700','#FF6B6B','#4ECDC4','#45B7D1','#96CEB4','#FFEAA7','#DDA0DD','#98FB98'];

    function fire(opts) {
        confetti(Object.assign({
            particleCount: 80,
            spread: 70,
            startVelocity: 45,
            gravity: 0.9,
            colors: colors
        }, opts));
    }

    function launchFireworks() {
        // Initial burst from center
        fire({ origin: { x: 0.5, y: 0.6 }, particleCount: 120, spread: 90 });
        setTimeout(function() {
            // Side cannons
            fire({ origin: { x: 0.1, y: 0.5 }, angle: 60,  spread: 55 });
            fire({ origin: { x: 0.9, y: 0.5 }, angle: 120, spread: 55 });
        }, 300);
        setTimeout(function() {
            fire({ origin: { x: 0.3, y: 0.7 }, particleCount: 60, spread: 60 });
            fire({ origin: { x: 0.7, y: 0.7 }, particleCount: 60, spread: 60 });
        }, 700);
        // Final shower
        setTimeout(function() {
            fire({ origin: { x: 0.5, y: 0.3 }, particleCount: 150, spread: 120, startVelocity: 55 });
        }, 1400);
        // Ribbon-style streamers
        setTimeout(function() {
            confetti({
                particleCount: 200,
                angle: 90,
                spread: 180,
                origin: { x: 0.5, y: 0 },
                colors: colors,
                gravity: 0.6,
                scalar: 1.2,
                drift: 0.5
            });
        }, 2200);
    }

    // Slight delay so modal animates in first
    setTimeout(launchFireworks, 400);

    window.voClosePopup = function() {
        document.getElementById('vo-badge-overlay').style.animation = 'voBgIn .3s ease reverse forwards';
        setTimeout(function() {
            var el = document.getElementById('vo-badge-overlay');
            if (el) el.remove();
            window.location.href = 'achievements.php';
        }, 320);
    };

    // Also close on backdrop click
    document.getElementById('vo-badge-overlay').addEventListener('click', function(e) {
        if (e.target === this) voClosePopup();
    });
})();
</script>
<?php endif; ?>

</body>
</html>
