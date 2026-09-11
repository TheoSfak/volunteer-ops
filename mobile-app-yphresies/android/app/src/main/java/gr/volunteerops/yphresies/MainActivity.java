package gr.volunteerops.yphresies;

import android.annotation.SuppressLint;
import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.provider.Settings;
import android.webkit.CookieManager;
import android.webkit.JavascriptInterface;
import android.webkit.URLUtil;
import android.webkit.WebView;
import android.widget.Toast;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.WebViewListener;

/**
 * Guarantees window.Capacitor actually exists on every page this WebView loads.
 *
 * Capacitor injects its JS bridge one of two ways, and for a server.url app
 * pointed at a live site BOTH can silently do nothing:
 *   - WebViewCompat.addDocumentStartJavaScript() requires WebView 106+;
 *   - the older fallback (WebViewLocalServer.handleProxyRequest) re-fetches
 *     every HTML page over its own HttpURLConnection and rewrites the response,
 *     which skips every non-GET navigation — so the page you land on right
 *     after submitting a form never gets the bridge — plus every fetch that
 *     errors or times out.
 *
 * In all of those cases the page renders completely normally and only
 * window.Capacitor is missing, so nothing looks broken. Every plugin is simply
 * gone. That is how background GPS ended up silently never starting: war-room's
 * hook found no BackgroundGeolocation plugin and gave up, while the page around
 * it looked perfect.
 *
 * This listener closes the hole: after each navigation it asks the page whether
 * the bridge is there and, if not, evaluates the exact same script Capacitor
 * would have injected. evaluateJavascript() goes straight to the WebView's JS
 * engine, so it depends on neither mechanism above and works on any WebView
 * version and any HTTP method.
 */
public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        getBridge()
            .addWebViewListener(
                new WebViewListener() {
                    @Override
                    public void onPageStarted(WebView webView) {
                        ensureBridgeInjected(webView);
                    }

                    @Override
                    public void onPageCommitVisible(WebView webView, String url) {
                        ensureBridgeInjected(webView);
                    }

                    @Override
                    public void onPageLoaded(WebView webView) {
                        ensureBridgeInjected(webView);
                    }
                }
            );

        installDownloadHandler();
        installNativeBridge();
    }

    /**
     * Exposes window.VopsNative to the page.
     *
     * Exists for one thing the web side genuinely cannot do: Android stops
     * showing the permission dialog after a refusal, and no page can re-trigger
     * it. The only route back is this app's own permission screen in system
     * settings, and only native code can open that. Without it a volunteer who
     * tapped "deny" once is stuck with an error they cannot clear, on a device
     * they are holding in the field.
     *
     * addJavascriptInterface exposes this to every page the WebView loads,
     * which is safe here only because the WebView is pinned to our own origin
     * by capacitor.config.json's server.url. Keep the surface to exactly this
     * one parameterless method — anything that takes input from the page would
     * deserve a much harder look.
     */
    @SuppressLint("JavascriptInterface")
    private void installNativeBridge() {
        final WebView webView = getBridge().getWebView();
        if (webView == null) {
            return;
        }
        webView.addJavascriptInterface(new NativeBridge(), "VopsNative");
    }

    public class NativeBridge {
        @JavascriptInterface
        public void openAppSettings() {
            runOnUiThread(() -> {
                try {
                    Intent intent = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS);
                    intent.setData(Uri.fromParts("package", getPackageName(), null));
                    intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                    startActivity(intent);
                } catch (Exception e) {
                    Toast.makeText(MainActivity.this, "Δεν άνοιξαν οι ρυθμίσεις.", Toast.LENGTH_LONG).show();
                }
            });
        }
    }

    /**
     * An Android WebView does nothing at all with a download — no error, no
     * prompt, no file. Every link that serves a file rather than a page was
     * therefore silently dead inside the app while working perfectly in the
     * mobile browser: the APK self-update link, CSV exports, report PDFs,
     * certificate prints. Only the browser has a download manager; a WebView
     * has to be handed one.
     *
     * Cookies are copied onto the request because most of these downloads sit
     * behind the login session — without them DownloadManager would fetch the
     * login page and cheerfully save that as the .csv.
     */
    private void installDownloadHandler() {
        final WebView webView = getBridge().getWebView();
        if (webView == null) {
            return;
        }
        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) -> {
            try {
                DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
                String name = URLUtil.guessFileName(url, contentDisposition, mimeType);

                String cookies = CookieManager.getInstance().getCookie(url);
                if (cookies != null) {
                    request.addRequestHeader("Cookie", cookies);
                }
                if (userAgent != null) {
                    request.addRequestHeader("User-Agent", userAgent);
                }
                request.setMimeType(mimeType);
                request.setTitle(name);
                request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);

                // Public Downloads needs no permission from Android 10 on, and
                // is where a user actually looks for a file — but on 7..9 it
                // would require WRITE_EXTERNAL_STORAGE, a runtime prompt this
                // activity has no business raising. Older devices get the
                // app-private external dir instead: less discoverable, but it
                // downloads, and the completion notification still opens it.
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                    request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name);
                } else {
                    request.setDestinationInExternalFilesDir(this, Environment.DIRECTORY_DOWNLOADS, name);
                }

                DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
                if (dm == null) {
                    return;
                }
                dm.enqueue(request);
                Toast.makeText(this, "Λήψη: " + name, Toast.LENGTH_LONG).show();
            } catch (Exception e) {
                Toast.makeText(this, "Η λήψη απέτυχε.", Toast.LENGTH_LONG).show();
            }
        });
    }

    /**
     * Hooked at three points in the page lifecycle rather than one because
     * evaluateJavascript is asynchronous: the earliest hook usually wins the
     * race against the page's own inline scripts, and the later ones are there
     * for when it doesn't. The page-side hook in war-room.php keeps re-checking
     * for a while precisely so a bridge that lands late is still picked up.
     *
     * Re-injects only when the bridge is genuinely absent — re-running
     * native-bridge.js over a working bridge would re-register its listeners,
     * so the check is load-bearing, not an optimisation.
     */
    private void ensureBridgeInjected(final WebView webView) {
        final String script = getBridge().getVopsBridgeScript();
        if (script == null) {
            return;
        }
        webView.evaluateJavascript(
            "!!(window.Capacitor && window.Capacitor.Plugins)",
            value -> {
                if (!"true".equals(value)) {
                    webView.evaluateJavascript(script, null);
                }
            }
        );
    }
}
