<?php
/**
 * The "Close" button of the standalone print documents (certificate, mission report,
 * inventory sheets, shift participants, team debrief).
 *
 * window.close() is only allowed on a window a script opened, so a tab that came from a
 * plain link ignores it, and inside the Android app the document opens in the same
 * WebView -- there is no tab to close at all. On a phone the button looked dead. When
 * nothing closes, the button goes back to the page the document was opened from, or, with
 * no history, to the page that listed it ($printBackUrl, else the dashboard).
 *
 * Print / PDF needs nothing here: window.print() works in the phone browser, and the app
 * turns it into Android's own print dialog (MainActivity's VopsNative.printPage).
 */
$printBackUrl = $printBackUrl ?? 'dashboard.php';
?>
<script>
function vopsClosePage() {
    var back = <?= json_encode($printBackUrl) ?>;
    window.close();
    // Still on screen a moment later: nothing was closed.
    setTimeout(function () {
        if (history.length > 1) { history.back(); return; }
        try {
            if (document.referrer && new URL(document.referrer).origin === location.origin) {
                location.href = document.referrer;
                return;
            }
        } catch (e) {}
        location.href = back;
    }, 250);
}
</script>
