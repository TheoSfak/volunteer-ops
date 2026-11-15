/**
 * Mission highlight video — client-side renderer (v3.490.0).
 *
 * Renders a short (20-40s) highlight reel entirely in the admin's browser:
 * a satellite zoom from a world view down to the mission's pin, then a
 * music-backed slideshow of admin-picked photos/videos with a title card.
 * No ffmpeg/exec on the server - production hosting has no shell access
 * (see memory: epidrasis.iloveweb.gr has no file/shell access for the user).
 * The finished .webm Blob is handed to mission-highlight-video.php to store.
 *
 * Tile math/zoom: draws Esri World_Imagery tiles directly (same tile server
 * as assets/js/esri-imagery.js), loaded as <img crossorigin="anonymous">.
 * The M3 spike (canvas + captureStream + MediaRecorder, a stricter consumer
 * than esri-imagery.js's own getImageData() call) proved this does NOT taint
 * the canvas - no same-origin tile-proxy is needed. Rather than Leaflet's
 * full multi-tile-per-level rendering, each zoom level uses ONE source tile
 * (the one containing the mission's lat/lng), progressively cropped tighter
 * around that point and scaled to cover the canvas as the virtual zoom
 * advances through that level's band, crossfading into the next level's tile
 * at the band's end - a deliberate simplification for a short creative clip,
 * not a mapping tool.
 *
 * Capped at zoom 16 (not higher): esri-imagery.js's own docblock states real
 * Esri imagery can be as shallow as z17 in some places, so 16 stays one level
 * inside that worst case everywhere without needing that module's full
 * grey-tile-detection machinery here too.
 */
(function (root) {
    'use strict';

    const TILE_SIZE = 256;
    const ESRI_IMAGERY_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
    const START_ZOOM = 2;
    const END_ZOOM = 16;

    const PHASE1_MS = 6000;  // world-view zoom + pin drop
    const PHASE3_MS = 2500;  // outro
    const MIN_ITEM_MS = 1200;
    const MIN_TOTAL_MS = 20000;
    const MAX_TOTAL_MS = 40000;
    const TITLE_CARD_MS = 2500; // how long the mission name/date overlay shows at the start of phase 2

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
        const total = Math.max(MIN_TOTAL_MS, Math.min(MAX_TOTAL_MS, PHASE1_MS + PHASE3_MS + n * 3000));
        const slideshowMs = total - PHASE1_MS - PHASE3_MS;
        const perItemMs = Math.max(MIN_ITEM_MS, slideshowMs / n);
        return {totalMs: Math.round(PHASE1_MS + perItemMs * n + PHASE3_MS), perItemMs, tooMany: perItemMs <= MIN_ITEM_MS && n > 1 && (slideshowMs / n) < MIN_ITEM_MS};
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

    /** `cover`-style draw: square `cropSize`x`cropSize` region of `img` centered on (cx, cy), scaled to fill the whole canvas. */
    function drawCover(ctx, img, cx, cy, cropSize, canvasW, canvasH, alpha) {
        const half = cropSize / 2;
        const sx = cx - half, sy = cy - half;
        const scale = Math.max(canvasW, canvasH) / cropSize;
        const destW = cropSize * scale, destH = cropSize * scale;
        const destX = (canvasW - destW) / 2, destY = (canvasH - destH) / 2;
        const prevAlpha = ctx.globalAlpha;
        ctx.globalAlpha = alpha;
        // Source rect may spill past the tile's own edges near the border of
        // the world/tile - drawImage clamps that for us (it just draws less).
        ctx.drawImage(img, sx, sy, cropSize, cropSize, destX, destY, destW, destH);
        ctx.globalAlpha = prevAlpha;
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

    function drawTitleCard(ctx, canvasW, canvasH, missionTitle, dateLabel, alpha) {
        ctx.save();
        ctx.globalAlpha = alpha;
        const bannerH = canvasH * 0.22;
        const grad = ctx.createLinearGradient(0, canvasH - bannerH, 0, canvasH);
        grad.addColorStop(0, 'rgba(0,0,0,0)');
        grad.addColorStop(1, 'rgba(0,0,0,0.75)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, canvasH - bannerH, canvasW, bannerH);

        ctx.fillStyle = '#ffffff';
        ctx.textBaseline = 'alphabetic';
        const titleSize = Math.round(canvasW * 0.045);
        ctx.font = 'bold ' + titleSize + 'px sans-serif';
        ctx.textAlign = 'center';
        ctx.shadowColor = 'rgba(0,0,0,0.8)';
        ctx.shadowBlur = 6;
        ctx.fillText(missionTitle, canvasW / 2, canvasH - bannerH * 0.45, canvasW * 0.9);

        const dateSize = Math.round(canvasW * 0.028);
        ctx.font = dateSize + 'px sans-serif';
        ctx.fillText(dateLabel, canvasW / 2, canvasH - bannerH * 0.18, canvasW * 0.9);
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
        const cy = srcH / 2;
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
                // ── Preload: tile pyramid, media elements, music ──────────────
                let tiles = [];
                if (hasPin) {
                    const tilePromises = [];
                    for (let z = START_ZOOM; z <= END_ZOOM; z++) {
                        const t = latLngToTile(loc.lat, loc.lng, z);
                        tilePromises.push(
                            loadImage(esriTileUrl(t.z, t.x, t.y), 'anonymous')
                                .then(img => ({z, img, fx: t.fx, fy: t.fy}))
                                .catch(() => null)
                        );
                    }
                    tiles = (await Promise.all(tilePromises)).filter(Boolean);
                }

                const mediaElements = await Promise.all(media.map(m =>
                    (m.type === 'video' ? loadVideo(m.url) : loadImage(m.url)).catch(() => null)
                ));

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

                    if (elapsed < PHASE1_MS) {
                        // ── Phase 1: world-view zoom into the pin, then it drops ──
                        const zoomMs = PHASE1_MS * 0.75;
                        const dropMs = PHASE1_MS - zoomMs;
                        if (hasPin && tiles.length) {
                            const zt = Math.min(1, elapsed / zoomMs);
                            const vz = START_ZOOM + easeOutCubic(zt) * (END_ZOOM - START_ZOOM);
                            const zInt = Math.min(END_ZOOM - 1, Math.floor(vz));
                            const bandT = vz - zInt;
                            const cur = tiles.find(t => t.z === zInt) || tiles[0];
                            const next = tiles.find(t => t.z === zInt + 1);
                            const cropSize = TILE_SIZE / Math.pow(2, bandT);
                            drawCover(ctx, cur.img, cur.fx, cur.fy, cropSize, canvasW, canvasH, 1);
                            if (next && bandT > 0.8) {
                                const fadeAlpha = (bandT - 0.8) / 0.2;
                                drawCover(ctx, next.img, next.fx, next.fy, TILE_SIZE, canvasW, canvasH, fadeAlpha);
                            }
                        }
                        if (elapsed > zoomMs) {
                            drawPinDrop(ctx, canvasW, canvasH, (elapsed - zoomMs) / dropMs);
                        }
                    } else if (elapsed < PHASE1_MS + timing.perItemMs * media.length) {
                        // ── Phase 2: slideshow ──
                        const phase2Elapsed = elapsed - PHASE1_MS;
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
                        if (phase2Elapsed < TITLE_CARD_MS) {
                            const alpha = phase2Elapsed > TITLE_CARD_MS - 400 ? Math.max(0, (TITLE_CARD_MS - phase2Elapsed) / 400) : 1;
                            drawTitleCard(ctx, canvasW, canvasH, loc.title || '', formatMissionDate(loc.start_datetime), alpha);
                        }
                    } else {
                        // ── Phase 3: outro ──
                        const outroElapsed = elapsed - (PHASE1_MS + timing.perItemMs * media.length);
                        const alpha = Math.min(1, outroElapsed / 600);
                        ctx.save();
                        ctx.globalAlpha = alpha;
                        ctx.fillStyle = '#0b1220';
                        ctx.fillRect(0, 0, canvasW, canvasH);
                        ctx.restore();
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
            START_ZOOM, END_ZOOM, MIN_TOTAL_MS, MAX_TOTAL_MS, MIN_ITEM_MS,
        };
    }
})(typeof window !== 'undefined' ? window : globalThis);
