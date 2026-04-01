/**
 * VolunteerOps - Push Notification Client
 * Manages browser push subscription lifecycle
 */
var VoPush = (function() {
    'use strict';

    var vapidPublicKey = null;
    var baseUrl = '';
    var swRegistration = null;

    /**
     * Initialize push manager
     * @param {string} key - VAPID public key (base64url)
     * @param {string} url - Base URL of the app
     */
    function init(key, url) {
        vapidPublicKey = key;
        baseUrl = url.replace(/\/+$/, '');

        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.log('[VoPush] Push not supported');
            updateUI('unsupported');
            return;
        }

        navigator.serviceWorker.ready.then(function(reg) {
            swRegistration = reg;
            checkSubscription();
        });
    }

    /**
     * Check current subscription state and update UI
     */
    function checkSubscription() {
        if (!swRegistration) return;

        swRegistration.pushManager.getSubscription().then(function(sub) {
            if (sub) {
                updateUI('subscribed');
            } else {
                updateUI('unsubscribed');
            }
        });
    }

    /**
     * Subscribe to push notifications
     */
    function subscribe() {
        if (!vapidPublicKey) return Promise.reject('No VAPID key');

        updateUI('loading');

        // Wait for SW to be ready if not yet registered
        var swReady = swRegistration
            ? Promise.resolve(swRegistration)
            : navigator.serviceWorker.ready.then(function(reg) { swRegistration = reg; return reg; });

        return swReady.then(function(reg) {

        var applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);

        return reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey
        }).then(function(subscription) {
            // Send subscription to server
            return sendToServer(subscription, 'POST');
        }).then(function() {
            updateUI('subscribed');
            return true;
        }).catch(function(err) {
            console.error('[VoPush] Subscribe failed:', err);
            var msg = err.toString();
            if (msg.indexOf('push service error') !== -1) {
                alert('Αποτυχία σύνδεσης με την υπηρεσία push (FCM).\n\nΛύση: Ανοίξτε Chrome Ρυθμίσεις → Ρυθμίσεις ιστότοπου → βρείτε ' + window.location.hostname + ' → Εκκαθάριση & επαναφορά.\nΈπειτα κλείστε και ανοίξτε ξανά το Chrome και δοκιμάστε.');
            } else {
                alert('[VoPush] Αποτυχία εγγραφής: ' + msg);
            }
            if (Notification.permission === 'denied') {
                updateUI('denied');
            } else {
                updateUI('unsubscribed');
            }
            return false;
        });

        }); // end swReady.then
    }

    /**
     * Unsubscribe from push notifications
     */
    function unsubscribe() {
        if (!swRegistration) return Promise.reject('Not initialized');

        updateUI('loading');

        return swRegistration.pushManager.getSubscription().then(function(subscription) {
            if (!subscription) {
                updateUI('unsubscribed');
                return false;
            }

            return sendToServer(subscription, 'DELETE').then(function() {
                return subscription.unsubscribe();
            }).then(function() {
                updateUI('unsubscribed');
                return true;
            });
        }).catch(function(err) {
            console.error('[VoPush] Unsubscribe failed:', err);
            updateUI('subscribed');
            return false;
        });
    }

    /**
     * Send subscription data to server
     */
    function sendToServer(subscription, method) {
        var body = {
            endpoint: subscription.endpoint,
            keys: {
                p256dh: arrayBufferToBase64(subscription.getKey('p256dh')),
                auth: arrayBufferToBase64(subscription.getKey('auth'))
            }
        };

        return fetch(baseUrl + '/api-push-subscribe.php', {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(method === 'DELETE' ? { endpoint: subscription.endpoint } : body),
            credentials: 'same-origin'
        }).then(function(response) {
            if (!response.ok) {
                return response.text().then(function(body) {
                    throw new Error('Server ' + response.status + ': ' + body.substring(0, 200));
                });
            }
            return response.json();
        });
    }

    /**
     * Send test push notification
     */
    function sendTest() {
        return fetch(baseUrl + '/api-push-subscribe.php?action=test', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ test: true }),
            credentials: 'same-origin'
        }).then(function(response) {
            return response.json();
        });
    }

    /**
     * Update UI elements based on push state
     */
    function updateUI(state) {
        var btn = document.getElementById('vo-push-toggle');
        var status = document.getElementById('vo-push-status');
        var testBtn = document.getElementById('vo-push-test');
        if (!btn) return;

        switch (state) {
            case 'subscribed':
                btn.textContent = 'Απενεργοποίηση Push';
                btn.className = 'btn btn-outline-danger btn-sm';
                btn.disabled = false;
                btn.onclick = function() { unsubscribe(); };
                if (status) { status.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Ενεργές</span>'; }
                if (testBtn) { testBtn.style.display = 'inline-block'; }
                break;
            case 'unsubscribed':
                btn.textContent = 'Ενεργοποίηση Push';
                btn.className = 'btn btn-primary btn-sm';
                btn.disabled = false;
                btn.onclick = function() { subscribe(); };
                if (status) { status.innerHTML = '<span class="badge bg-secondary"><i class="bi bi-x-circle"></i> Ανενεργές</span>'; }
                if (testBtn) { testBtn.style.display = 'none'; }
                break;
            case 'denied':
                btn.textContent = 'Αποκλεισμένες';
                btn.className = 'btn btn-secondary btn-sm';
                btn.disabled = true;
                if (status) { status.innerHTML = '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Αποκλεισμένες από τον browser</span>'; }
                if (testBtn) { testBtn.style.display = 'none'; }
                break;
            case 'unsupported':
                btn.textContent = 'Μη διαθέσιμο';
                btn.className = 'btn btn-secondary btn-sm';
                btn.disabled = true;
                if (status) { status.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-circle"></i> Ο browser δεν υποστηρίζει Push</span>'; }
                if (testBtn) { testBtn.style.display = 'none'; }
                break;
            case 'loading':
                btn.textContent = 'Παρακαλώ περιμένετε...';
                btn.className = 'btn btn-secondary btn-sm';
                btn.disabled = true;
                break;
        }
    }

    /**
     * Convert VAPID key from base64url to Uint8Array
     */
    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    /**
     * Convert ArrayBuffer to base64 string
     */
    function arrayBufferToBase64(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }

    return {
        init: init,
        subscribe: subscribe,
        unsubscribe: unsubscribe,
        sendTest: sendTest,
        checkSubscription: checkSubscription
    };
})();
