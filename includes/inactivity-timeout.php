<?php
/**
 * VolunteerOps - Client-side inactivity auto-logout timer
 * Extracted out of includes/footer.php so bare/print-only pages (their own
 * <html> shell, no shared header/footer chrome) can include just this and
 * still respect the "session_timeout_minutes" setting, without pulling in
 * the rest of footer.php's chrome-dependent JS (sidebar, service worker,
 * tooltips, ...) that assumes DOM elements those pages don't have.
 *
 * Cross-tab aware: real activity (mousemove/keydown/click/scroll/touchstart)
 * in ANY tab of this app is broadcast via localStorage, and every tab's own
 * idle check reads that shared value, not just its own local DOM events.
 * Without this, a genuinely idle tab (e.g. a Settings tab left open in the
 * background while actively working in the Action Room) would fire its own
 * logout on schedule — which destroys the one session ALL tabs share,
 * force-logging-out the actively-used tab too. A real report from live
 * testing: the Action Room still got logged out despite its own exemption
 * below, because that exemption only stops Action Room from initiating its
 * OWN logout — it did nothing to stop a completely different, genuinely
 * idle tab from killing the shared session out from under it.
 *
 * Skipped on Action Room (war-room.php) for its OWN timeout: an admin
 * legitimately sits watching the live map/alerts for long stretches with no
 * clicks, including kiosk/fullscreen mode (see includes/auth.php's
 * WAR_ROOM_ACTION_SCRIPTS for the equivalent server-side exemption).
 * But per the paragraph above, an open Action Room tab now also sends its
 * own periodic heartbeat into the same shared activity value, so it
 * actively PROTECTS every other tab's session too for as long as it's
 * open — not just itself.
 *
 * Uses a plain emoji rather than a Bootstrap Icons class in the warning
 * banner — some of this partial's includers (mission-certificate-print.php,
 * inventory-print.php, inventory-shelf-print.php) never load that font.
 */
if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}
if (function_exists('isLoggedIn') && isLoggedIn()):
    $__voIsWarRoomPage = basename($_SERVER['PHP_SELF'], '.php') === 'war-room';
?>
<script>
(function() {
    var STORAGE_KEY = 'vo_last_activity';
    function touchActivity() {
        try { localStorage.setItem(STORAGE_KEY, String(Date.now())); } catch (e) {}
    }

    <?php if ($__voIsWarRoomPage): ?>
    // Action Room itself never times out (see docblock above) — this tab's
    // only job here is to keep signaling "someone has an Action Room open"
    // so any OTHER idle tab's check below doesn't log the shared session out
    // from under it. Unconditional on a timer, not tied to whether the
    // page's own live poll is currently succeeding — the whole point of the
    // War Room exemption is to keep working through exactly the kind of
    // connectivity hiccup that would make a network-dependent heartbeat
    // itself go silent at the worst possible moment.
    touchActivity();
    setInterval(touchActivity, 20000);
    <?php else: ?>
    var timeoutMinutes = <?= (int)(getSetting('session_timeout_minutes', '120')) ?>;
    // Same 5–1440 range settings.php's form advertises and now enforces on
    // save — clamp instead of jumping to an unrelated default, so an
    // out-of-range value (e.g. a pre-existing row from before that
    // validation existed) still respects the closest valid bound rather
    // than silently ignoring what was actually configured.
    if (timeoutMinutes < 5) timeoutMinutes = 5;
    if (timeoutMinutes > 1440) timeoutMinutes = 1440;
    var timeoutMs = timeoutMinutes * 60 * 1000;
    var warningMs = timeoutMs - 60000; // warn 1 minute before

    function sharedIdleMs() {
        var last = 0;
        try { last = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10) || 0; } catch (e) {}
        // No recorded activity at all (private-browsing storage cleared
        // mid-session, a privacy extension wiping localStorage, ...) must
        // read as "just now", not as idle since the Unix epoch — the latter
        // would compute an astronomically large idle time and log out on
        // the very next check, the opposite of "nothing to worry about yet".
        if (!last) return 0;
        return Date.now() - last;
    }
    function hideBanner() {
        var banner = document.getElementById('inactivityWarning');
        if (banner) banner.style.display = 'none';
    }
    function showBanner() {
        var banner = document.getElementById('inactivityWarning');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'inactivityWarning';
            banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:9999;background:#dc3545;color:#fff;text-align:center;padding:10px 16px;font-size:14px;font-weight:600;box-shadow:0 2px 8px rgba(0,0,0,.3);';
            banner.innerHTML = '⚠️ Θα αποσυνδεθείτε σε 1 λεπτό λόγω αδράνειας. Κουνήστε το ποντίκι ή πατήστε ένα πλήκτρο.';
            document.body.appendChild(banner);
        }
        banner.style.display = 'block';
    }

    ['mousemove','keydown','click','scroll','touchstart'].forEach(function(evt) {
        document.addEventListener(evt, touchActivity, { passive: true });
    });
    touchActivity();

    // Polls the shared idle time every 5s rather than a single setTimeout
    // scheduled once at page load — a fixed setTimeout can only ever look at
    // THIS tab's own last reset, but idle time here needs to be measured
    // against whichever tab (this one, or another one open elsewhere) saw
    // activity most recently. Cheap enough to not matter even at the
    // smallest allowed timeout (5 minutes).
    setInterval(function() {
        var idleMs = sharedIdleMs();
        if (idleMs >= timeoutMs) {
            window.location.href = 'logout.php?reason=inactivity';
        } else if (idleMs >= warningMs) {
            showBanner();
        } else {
            hideBanner();
        }
    }, 5000);
    <?php endif; ?>
})();
</script>
<?php endif; ?>
