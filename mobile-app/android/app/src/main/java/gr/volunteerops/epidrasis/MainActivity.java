package gr.volunteerops.epidrasis;

import android.os.Bundle;
import android.webkit.WebView;

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
