/**
 * Mission highlight video — client-side renderer (v3.491.0).
 *
 * Renders a short (20-40s) highlight reel entirely in the admin's browser:
 * a big opening title card (mission name + date), a quick real-satellite
 * zoom onto the mission's pin, a music-backed slideshow of admin-picked
 * photos/videos, and a closing card (team logo + mission name + the org's
 * own tagline). The team's own logo (Settings > app_logo) also stays in the
 * corner for the whole clip. No ffmpeg/exec on the server - production
 * hosting has no shell access (see memory: epidrasis.iloveweb.gr has no
 * file/shell access for the user). The finished .webm Blob is handed to
 * mission-highlight-video.php to store.
 *
 * Tile math/zoom: draws ONE Esri World_Imagery tile directly (same tile
 * server as assets/js/esri-imagery.js), loaded as <img crossorigin=
 * "anonymous"> - the M3 spike (canvas + captureStream + MediaRecorder, a
 * stricter consumer than esri-imagery.js's own getImageData() call) proved
 * this does NOT taint the canvas, no same-origin tile-proxy is needed.
 *
 * v3.491.0 dropped the original multi-zoom-level "whole Earth down to the
 * pin" cascade (crossfading through z2..z16, a different source tile per
 * level): its crop math placed the pin's own pixel position as the crop
 * centre WITHOUT clamping it inside the tile's 0..256 bounds, so whenever
 * the point sat left/above the tile's centre (about half the time, and
 * always true at the lowest zoom bands) the source rectangle spilled past
 * the tile's edge; drawImage does not stretch to cover that shortfall, it
 * just paints a narrower slice, leaving the uncovered destination edge as
 * flat background colour - a real-world "shows black" rather than the
 * hoped-for seamless zoom. Rather than fetch neighbouring tiles to patch
 * that, the fix is simpler: one single tile at PIN_ZOOM, Ken-Burns-zoomed
 * into the pin with the same clamped-crop approach the photo/video
 * slideshow already uses (which never spills), so the mission's real
 * location is visible immediately and correctly from the first frame.
 */
(function (root) {
    'use strict';

    const TILE_SIZE = 256;
    const ESRI_IMAGERY_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
    // A single, reliably-covered "local area" zoom level - not esri-imagery.js's
    // full grey-tile-detection range, just one level inside its documented
    // worst case (imagery can be as shallow as z17 in some places).
    const PIN_ZOOM = 15;

    const TITLE_SCREEN_MS = 2200; // opening card: mission name + date, big letters, before the zoom starts
    const PHASE1_MS = 6000;  // world-view zoom + pin drop
    const OUTRO_MS = 3000;  // closing card: team logo + mission name, fading to black
    const MIN_ITEM_MS = 1200;
    const MIN_TOTAL_MS = 20000;
    const MAX_TOTAL_MS = 40000;
    // Corner logo footprint (phases 0-2) and outro logo footprint, both as a
    // fraction of canvas size - kept in one place since both draw functions
    // need them and the aspect ratio must stay the uploaded file's own.
    const CORNER_LOGO_MAX_W = 0.16, CORNER_LOGO_MAX_H = 0.14, CORNER_LOGO_PAD = 0.03;
    const OUTRO_LOGO_MAX_W = 0.22, OUTRO_LOGO_MAX_H = 0.22;
    // Ken Burns vertical crop centre, as a fraction of the source photo/video
    // height. There is no face-detection API on desktop Chrome (Shape
    // Detection's FaceDetector never shipped outside Android/ChromeOS), so
    // this is a cheap heuristic rather than real face tracking: rescue-op
    // photos are overwhelmingly people standing/walking, and a dead-centre
    // crop (0.5) keeps cropping heads off in favour of legs/ground once
    // zoomed in. Biasing toward the upper third fixes the common case.
    const FACE_BIAS_Y = 0.38;

    function esriTileUrl(z, x, y) {
        return ESRI_IMAGERY_URL.replace('{z}', z).replace('{y}', y).replace('{x}', x);
    }

    /** Standard Web Mercator slippy-map tile containing (lat, lng) at integer zoom z, plus the point's pixel offset inside that 256px tile. */
    function latLngToTile(lat, lng, z) {
        const n = Math.pow(2, z);
        const latRad = lat * Math.PI / 180;
        const x = (lng + 180) / 360 * n;
        const y = (1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2 * n;
        const tileX = Math.floor(x);
        const tileY = Math.floor(y);
        return {
            z, x: tileX, y: tileY,
            fx: (x - tileX) * TILE_SIZE,
            fy: (y - tileY) * TILE_SIZE,
        };
    }

    /** Total render duration and the per-slideshow-item slice, given how many media items were picked. */
    function computeTiming(selectedCount) {
        const n = Math.max(1, selectedCount);
        const overhead = TITLE_SCREEN_MS + PHASE1_MS + OUTRO_MS;
        const total = Math.max(MIN_TOTAL_MS, Math.min(MAX_TOTAL_MS, overhead + n * 3000));
        const slideshowMs = total - overhead;
        const perItemMs = Math.max(MIN_ITEM_MS, slideshowMs / n);
        return {totalMs: Math.round(overhead + perItemMs * n), perItemMs, tooMany: perItemMs <= MIN_ITEM_MS && n > 1 && (slideshowMs / n) < MIN_ITEM_MS};
    }

    function isMissionHighlightVideoSupported() {
        return typeof MediaRecorder !== 'undefined'
            && typeof HTMLCanvasElement !== 'undefined'
            && typeof HTMLCanvasElement.prototype.captureStream === 'function'
            && (MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus') || MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus') || MediaRecorder.isTypeSupported('video/webm'));
    }

    function pickMimeType() {
        if (MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus')) return 'video/webm;codecs=vp9,opus';
        if (MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')) return 'video/webm;codecs=vp8,opus';
        return 'video/webm';
    }

    function loadImage(url, crossOrigin) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            if (crossOrigin) img.crossOrigin = crossOrigin;
            img.onload = () => resolve(img);
            img.onerror = () => reject(new Error('image failed: ' + url));
            img.src = url;
        });
    }

    function loadVideo(url) {
        return new Promise((resolve, reject) => {
            const v = document.createElement('video');
            v.src = url;
            v.muted = true;
            v.playsInline = true;
            v.preload = 'auto';
            v.onloadeddata = () => resolve(v);
            v.onerror = () => reject(new Error('video failed: ' + url));
            v.load();
        });
    }

    /**
     * Zooms from the whole 256x256 tile down to a tight crop centred on the
     * pin's own pixel position (fx, fy) inside it. `progress` 0..1. Unlike
     * the old multi-level cascade this replaced, (cx, cy) is clamped to stay
     * `half` away from every edge, so the crop square can never spill past
     * the tile's own bounds - the actual cause of the old "shows black" bug
     * (drawImage doesn't stretch to cover a source rect that runs off the
     * image, it just paints a narrower slice of the destination).
     */
    function drawPinZoom(ctx, img, fx, fy, canvasW, canvasH, progress) {
        const startCrop = TILE_SIZE, endCrop = TILE_SIZE * 0.3;
        const cropSize = startCrop - (startCrop - endCrop) * easeOutCubic(Math.min(1, progress));
        const half = cropSize / 2;
        const cx = Math.max(half, Math.min(TILE_SIZE - half, fx));
        const cy = Math.max(half, Math.min(TILE_SIZE - half, fy));
        const sx = cx - half, sy = cy - half;
        const scale = Math.max(canvasW, canvasH) / cropSize;
        const destW = cropSize * scale, destH = cropSize * scale;
        const destX = (canvasW - destW) / 2, destY = (canvasH - destH) / 2;
        ctx.drawImage(img, sx, sy, cropSize, cropSize, destX, destY, destW, destH);
    }

    function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }
    function easeOutBack(t) {
        const c1 = 1.70158, c3 = c1 + 1;
        return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2);
    }

    function drawPinDrop(ctx, canvasW, canvasH, progress) {
        // progress 0..1 over the drop animation, eased for a small bounce landing.
        const eased = easeOutBack(Math.min(1, progress));
        const dropHeight = canvasH * 0.4;
        const x = canvasW / 2;
        const y = canvasH / 2 - (1 - eased) * dropHeight;
        const r = Math.min(canvasW, canvasH) * 0.02;
        ctx.save();
        ctx.fillStyle = '#dc2626';
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = Math.max(2, r * 0.25);
        ctx.beginPath();
        ctx.arc(x, y - r, r, 0, Math.PI * 2);
        ctx.moveTo(x, y - r);
        ctx.lineTo(x - r * 0.8, y + r * 1.6);
        ctx.lineTo(x + r * 0.8, y + r * 1.6);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();
        ctx.restore();
    }

    /**
     * Opening card (phase 0): big mission name + date, centered, on a dark
     * backdrop - "big letters at the beginning" rather than a small banner
     * mid-slideshow. `progress` is 0..1 over TITLE_SCREEN_MS; the text fades
     * and rises in over the first ~25%, holds, then fades out over the last
     * ~25% so it doesn't just cut into the zoom.
     */
    function drawOpeningTitle(ctx, canvasW, canvasH, missionTitle, dateLabel, progress) {
        ctx.save();
        ctx.fillStyle = '#0b1220';
        ctx.fillRect(0, 0, canvasW, canvasH);
        const vignette = ctx.createRadialGradient(canvasW / 2, canvasH / 2, canvasH * 0.2, canvasW / 2, canvasH / 2, canvasH * 0.75);
        vignette.addColorStop(0, 'rgba(255,255,255,0.05)');
        vignette.addColorStop(1, 'rgba(0,0,0,0.35)');
        ctx.fillStyle = vignette;
        ctx.fillRect(0, 0, canvasW, canvasH);

        const fadeIn = Math.min(1, progress / 0.25);
        const fadeOut = progress > 0.75 ? Math.max(0, (1 - progress) / 0.25) : 1;
        const alpha = Math.min(fadeIn, fadeOut);
        const rise = (1 - easeOutCubic(fadeIn)) * canvasH * 0.04;

        ctx.globalAlpha = alpha;
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';
        ctx.shadowColor = 'rgba(0,0,0,0.85)';
        ctx.shadowBlur = 10;
        const titleSize = Math.round(canvasW * 0.085);
        ctx.font = 'bold ' + titleSize + 'px sans-serif';
        ctx.fillText(missionTitle, canvasW / 2, canvasH / 2 + rise, canvasW * 0.92);

        if (dateLabel) {
            const dateSize = Math.round(canvasW * 0.034);
            ctx.font = dateSize + 'px sans-serif';
            ctx.fillStyle = 'rgba(255,255,255,0.85)';
            ctx.fillText(dateLabel, canvasW / 2, canvasH / 2 + rise + titleSize * 1.15, canvasW * 0.92);
        }
        ctx.restore();
    }

    /** Persistent corner brand mark (phases 0-2): the team's own logo, drawn at its native aspect ratio with no background chip so its own transparency (if any) stays intact. */
    function drawCornerLogo(ctx, canvasW, canvasH, logoImg) {
        if (!logoImg) return;
        const srcW = logoImg.naturalWidth || logoImg.width || 1;
        const srcH = logoImg.naturalHeight || logoImg.height || 1;
        let w = canvasW * CORNER_LOGO_MAX_W, h = w * (srcH / srcW);
        const maxH = canvasH * CORNER_LOGO_MAX_H;
        if (h > maxH) { h = maxH; w = h * (srcW / srcH); }
        const pad = canvasW * CORNER_LOGO_PAD;
        ctx.save();
        ctx.shadowColor = 'rgba(0,0,0,0.55)';
        ctx.shadowBlur = 8;
        ctx.drawImage(logoImg, canvasW - pad - w, pad, w, h);
        ctx.restore();
    }

    /** Shrinks `text` to fit `maxWidth` on one line (current ctx.font), adding an ellipsis - never wraps, since the outro has room for exactly one subtitle line under the mission name. */
    function fitSingleLine(ctx, text, maxWidth) {
        let t = String(text || '');
        if (!t || ctx.measureText(t).width <= maxWidth) return t;
        while (t.length > 1 && ctx.measureText(t + '…').width > maxWidth) t = t.slice(0, -1);
        return t + '…';
    }

    /**
     * Outro (phase 3): fades the last slide to dark, then brings in the
     * team's own logo big and centered with the mission name underneath and
     * the org's own tagline (Settings > app_description) under that, holding
     * before a final fade to black - a proper close rather than the old
     * abrupt cut to a flat colour. `progress` is 0..1 over OUTRO_MS.
     */
    function drawOutro(ctx, canvasW, canvasH, progress, logoImg, missionTitle, orgTagline) {
        const bgAlpha = Math.min(1, progress / 0.45);
        ctx.save();
        ctx.globalAlpha = bgAlpha;
        ctx.fillStyle = '#05080e';
        ctx.fillRect(0, 0, canvasW, canvasH);
        ctx.restore();

        let contentAlpha;
        if (progress < 0.35) contentAlpha = 0;
        else if (progress > 0.85) contentAlpha = Math.max(0, (1 - progress) / 0.15);
        else contentAlpha = Math.min(1, (progress - 0.35) / 0.2);
        if (contentAlpha <= 0) return;

        ctx.save();
        ctx.globalAlpha = contentAlpha;
        let blockBottom = canvasH * 0.42;
        if (logoImg) {
            const srcW = logoImg.naturalWidth || logoImg.width || 1;
            const srcH = logoImg.naturalHeight || logoImg.height || 1;
            let w = canvasW * OUTRO_LOGO_MAX_W, h = w * (srcH / srcW);
            const maxH = canvasH * OUTRO_LOGO_MAX_H;
            if (h > maxH) { h = maxH; w = h * (srcW / srcH); }
            const cy = canvasH * 0.4;
            ctx.drawImage(logoImg, (canvasW - w) / 2, cy - h / 2, w, h);
            blockBottom = cy + h / 2;
        }
        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.shadowColor = 'rgba(0,0,0,0.8)';
        ctx.shadowBlur = 8;
        ctx.font = 'bold ' + Math.round(canvasW * 0.042) + 'px sans-serif';
        ctx.fillText(missionTitle || '', canvasW / 2, blockBottom + canvasH * 0.09, canvasW * 0.88);
        if (orgTagline) {
            ctx.font = Math.round(canvasW * 0.022) + 'px sans-serif';
            ctx.fillStyle = 'rgba(255,255,255,0.7)';
            ctx.fillText(fitSingleLine(ctx, orgTagline, canvasW * 0.8), canvasW / 2, blockBottom + canvasH * 0.15, canvasW * 0.88);
        }
        ctx.restore();
    }

    function kenBurnsTransform(progress, direction) {
        // Slow 1.0 -> 1.12 scale, slight pan, direction alternates per item so a
        // run of several photos doesn't all drift the same way.
        const scale = 1 + 0.12 * progress;
        const panX = direction * 0.04 * progress;
        return {scale, panX};
    }

    /** cover-draw a photo/video frame with a slow Ken Burns pan+zoom. */
    function drawKenBurns(ctx, source, canvasW, canvasH, progress, direction) {
        const {scale, panX} = kenBurnsTransform(progress, direction);
        const srcW = source.naturalWidth || source.videoWidth || canvasW;
        const srcH = source.naturalHeight || source.videoHeight || canvasH;
        const srcAspect = srcW / srcH, dstAspect = canvasW / canvasH;
        let cropW, cropH;
        if (srcAspect > dstAspect) { cropH = srcH; cropW = srcH * dstAspect; } else { cropW = srcW; cropH = srcW / dstAspect; }
        cropW /= scale; cropH /= scale;
        const cx = srcW / 2 + panX * srcW;
        const cy = srcH * FACE_BIAS_Y;
        const sx = Math.max(0, Math.min(srcW - cropW, cx - cropW / 2));
        const sy = Math.max(0, Math.min(srcH - cropH, cy - cropH / 2));
        ctx.drawImage(source, sx, sy, cropW, cropH, 0, 0, canvasW, canvasH);
    }

    /**
     * Renders the highlight video. Returns a Promise<Blob> (webm).
     *
     * @param {Object} opts
     * @param {string} opts.orientation 'landscape' (1280x720) or 'portrait' (720x1280)
     * @param {{lat:number,lng:number,title:string,start_datetime:?string}} opts.missionLocation
     * @param {Array<{url:string,type:'photo'|'video'}>} opts.selectedMedia same-origin URLs (mission-photo-view.php)
     * @param {string} opts.musicTrackUrl same-origin or public URL to the chosen track's mp3
     * @param {?string} [opts.teamLogoUrl] same-origin URL to the org's app_logo setting (uploads/logos/...); omitted/null draws no logo
     * @param {?string} [opts.orgTagline] the org's app_description setting, shown under the mission name in the outro
     * @param {(fraction:number)=>void} [opts.onProgress]
     * @param {AbortSignal} [opts.signal] abort the render (e.g. the modal was closed)
     */
    function renderMissionHighlightVideo(opts) {
        return new Promise((resolve, reject) => {
            if (!isMissionHighlightVideoSupported()) {
                reject(new Error('unsupported-browser'));
                return;
            }
            const orientation = opts.orientation === 'portrait' ? 'portrait' : 'landscape';
            const canvasW = orientation === 'portrait' ? 720 : 1280;
            const canvasH = orientation === 'portrait' ? 1280 : 720;
            const canvas = document.createElement('canvas');
            canvas.width = canvasW;
            canvas.height = canvasH;
            const ctx = canvas.getContext('2d');

            const media = opts.selectedMedia || [];
            const timing = computeTiming(media.length);
            const loc = opts.missionLocation || {};
            const hasPin = typeof loc.lat === 'number' && typeof loc.lng === 'number';

            let stopped = false;
            let visibilityHandler = null;
            const cleanup = () => {
                if (visibilityHandler) document.removeEventListener('visibilitychange', visibilityHandler);
            };
            const fail = (err) => { if (stopped) return; stopped = true; cleanup(); reject(err); };

            Promise.resolve().then(async () => {
                // ── Preload: the one pin-area tile, media elements, music ──────
                let pinTile = null;
                if (hasPin) {
                    const t = latLngToTile(loc.lat, loc.lng, PIN_ZOOM);
                    pinTile = await loadImage(esriTileUrl(t.z, t.x, t.y), 'anonymous')
                        .then(img => ({img, fx: t.fx, fy: t.fy}))
                        .catch(() => null);
                }

                const mediaElements = await Promise.all(media.map(m =>
                    (m.type === 'video' ? loadVideo(m.url) : loadImage(m.url)).catch(() => null)
                ));

                const logoImg = opts.teamLogoUrl ? await loadImage(opts.teamLogoUrl).catch(() => null) : null;

                const audioCtx = new (root.AudioContext || root.webkitAudioContext)();
                const musicEl = document.createElement('audio');
                musicEl.src = opts.musicTrackUrl;
                musicEl.crossOrigin = 'anonymous';
                musicEl.loop = true;
                await new Promise((res) => { musicEl.oncanplaythrough = res; musicEl.onerror = res; musicEl.load(); });
                const musicSource = audioCtx.createMediaElementSource(musicEl);
                const destNode = audioCtx.createMediaStreamDestination();
                musicSource.connect(destNode);
                // Not connected to audioCtx.destination: the admin doesn't need
                // to hear it played back live through their speakers too, only
                // captured into the recording.

                const videoStream = canvas.captureStream(30);
                const combined = new root.MediaStream([
                    ...videoStream.getVideoTracks(),
                    ...destNode.stream.getAudioTracks(),
                ]);
                const mimeType = pickMimeType();
                const recorder = new MediaRecorder(combined, {
                    mimeType,
                    videoBitsPerSecond: 2_200_000, // keeps a 20-40s clip comfortably under HIGHLIGHT_VIDEO_MAX_SIZE
                });
                const chunks = [];
                recorder.ondataavailable = (e) => { if (e.data.size > 0) chunks.push(e.data); };

                visibilityHandler = () => {
                    if (document.hidden) {
                        try { recorder.stop(); } catch (e) { /* already stopped */ }
                        try { musicEl.pause(); } catch (e) {}
                        fail(new Error('backgrounded'));
                    }
                };
                document.addEventListener('visibilitychange', visibilityHandler);
                if (opts.signal) {
                    opts.signal.addEventListener('abort', () => {
                        try { recorder.stop(); } catch (e) {}
                        fail(new Error('aborted'));
                    });
                }

                recorder.onstop = () => {
                    cleanup();
                    try { musicEl.pause(); } catch (e) {}
                    try { audioCtx.close(); } catch (e) {}
                    if (stopped) return; // already rejected (backgrounded/aborted)
                    stopped = true;
                    resolve(new Blob(chunks, {type: mimeType}));
                };

                musicEl.currentTime = 0;
                musicEl.play().catch(() => {});
                recorder.start(250);

                const startTime = performance.now();
                let lastItemIndex = -1;
                let itemStartTime = 0;

                function frame(now) {
                    if (stopped) return;
                    const elapsed = now - startTime;
                    if (opts.onProgress) opts.onProgress(Math.min(1, elapsed / timing.totalMs));

                    ctx.fillStyle = '#0b1220';
                    ctx.fillRect(0, 0, canvasW, canvasH);

                    const slideshowStart = TITLE_SCREEN_MS + PHASE1_MS;
                    const slideshowEnd = slideshowStart + timing.perItemMs * media.length;

                    if (elapsed < TITLE_SCREEN_MS) {
                        // ── Phase 0: opening title card (big letters) ──
                        drawOpeningTitle(ctx, canvasW, canvasH, loc.title || '', formatMissionDate(loc.start_datetime), elapsed / TITLE_SCREEN_MS);
                        drawCornerLogo(ctx, canvasW, canvasH, logoImg);
                    } else if (elapsed < slideshowStart) {
                        // ── Phase 1: real satellite zoom onto the pin, then it drops ──
                        const phase1Elapsed = elapsed - TITLE_SCREEN_MS;
                        const zoomMs = PHASE1_MS * 0.75;
                        const dropMs = PHASE1_MS - zoomMs;
                        if (pinTile) {
                            drawPinZoom(ctx, pinTile.img, pinTile.fx, pinTile.fy, canvasW, canvasH, phase1Elapsed / zoomMs);
                        }
                        if (phase1Elapsed > zoomMs) {
                            drawPinDrop(ctx, canvasW, canvasH, (phase1Elapsed - zoomMs) / dropMs);
                        }
                        drawCornerLogo(ctx, canvasW, canvasH, logoImg);
                    } else if (elapsed < slideshowEnd) {
                        // ── Phase 2: slideshow ──
                        const phase2Elapsed = elapsed - slideshowStart;
                        const index = Math.min(media.length - 1, Math.floor(phase2Elapsed / timing.perItemMs));
                        const itemElapsed = phase2Elapsed - index * timing.perItemMs;
                        if (index !== lastItemIndex) {
                            lastItemIndex = index;
                            itemStartTime = now;
                            const el = mediaElements[index];
                            if (el && el.tagName === 'VIDEO') { el.currentTime = 0; el.play().catch(() => {}); }
                        }
                        const el = mediaElements[index];
                        const progress = Math.min(1, itemElapsed / timing.perItemMs);
                        const direction = index % 2 === 0 ? 1 : -1;
                        if (el) {
                            if (el.tagName === 'VIDEO' && el.ended) { el.currentTime = 0; el.play().catch(() => {}); }
                            drawKenBurns(ctx, el, canvasW, canvasH, progress, direction);
                        }
                        drawCornerLogo(ctx, canvasW, canvasH, logoImg);
                    } else {
                        // ── Phase 3: outro (its own big, centered logo - no corner mark here) ──
                        const outroElapsed = elapsed - slideshowEnd;
                        drawOutro(ctx, canvasW, canvasH, Math.min(1, outroElapsed / OUTRO_MS), logoImg, loc.title || '', opts.orgTagline || '');
                    }

                    if (elapsed >= timing.totalMs) {
                        if (!stopped) {
                            try { recorder.stop(); } catch (e) { fail(e); }
                        }
                        return;
                    }
                    root.requestAnimationFrame(frame);
                }
                root.requestAnimationFrame(frame);
            }).catch(fail);
        });
    }

    function formatMissionDate(iso) {
        if (!iso) return '';
        const d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        const pad = (n) => String(n).padStart(2, '0');
        return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    root.renderMissionHighlightVideo = renderMissionHighlightVideo;
    root.isMissionHighlightVideoSupported = isMissionHighlightVideoSupported;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            latLngToTile, computeTiming, kenBurnsTransform, formatMissionDate,
            PIN_ZOOM, MIN_TOTAL_MS, MAX_TOTAL_MS, MIN_ITEM_MS,
        };
    }
})(typeof window !== 'undefined' ? window : globalThis);
