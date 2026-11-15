/**
 * Mission highlight video — client-side renderer (v3.496.0).
 *
 * Renders a short (20-40s) highlight reel entirely in the admin's browser:
 * a big opening title card (mission name + date, 3-4s), a music-backed
 * slideshow of admin-picked photos/videos (crossfading into each other) with
 * a persistent lower-third (mission name + date, always on screen), and a
 * closing card (team logo + mission name + the org's own tagline, 5s). The
 * team's own logo (Settings
 * > app_logo) also stays in the corner through the title card and slideshow.
 * No ffmpeg/exec on the server - production hosting has no shell access (see
 * memory: epidrasis.iloveweb.gr has no file/shell access for the user). The
 * finished .webm Blob is handed to mission-highlight-video.php to store.
 *
 * v3.490.0-v3.492.0 opened with a zoom from a world view (or later, a single
 * local tile) onto the mission's pin, fetching Esri World_Imagery tiles
 * directly. Dropped in v3.493.0: it broke twice in a row for reasons this
 * app's own code could reproduce locally but production could not (a
 * clamping bug, then a second report even after that fix) - most likely
 * Esri's free tile service treating epidrasis.iloveweb.gr differently than
 * localhost (rate-limiting or referrer-based blocking neither this code nor
 * local testing can see). Rather than keep chasing an external dependency
 * this feature doesn't strictly need, the opening title card now leads
 * straight into the slideshow.
 */
(function (root) {
    'use strict';

    const TITLE_SCREEN_MS = 3500; // opening card: mission name + date, big letters (3-4s)
    const OUTRO_MS = 5000;  // closing card: team logo + mission name, fading to black
    const TRANSITION_MS = 500; // crossfade between consecutive slideshow items
    const MIN_ITEM_MS = 1200;
    const MIN_TOTAL_MS = 20000;
    const MAX_TOTAL_MS = 40000;
    // Corner logo footprint (title card + slideshow) and outro logo footprint, both as a
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
    // zoomed in. v3.495.0 shipped 0.38 but still lost faces often enough to
    // report - moved further up toward the top third.
    const FACE_BIAS_Y = 0.3;

    /** Total render duration and the per-slideshow-item slice, given how many media items were picked. */
    function computeTiming(selectedCount) {
        const n = Math.max(1, selectedCount);
        const overhead = TITLE_SCREEN_MS + OUTRO_MS;
        const total = Math.max(MIN_TOTAL_MS, Math.min(MAX_TOTAL_MS, overhead + n * 3000));
        const slideshowMs = total - overhead;
        const perItemMs = Math.max(MIN_ITEM_MS, slideshowMs / n);
        return {totalMs: Math.round(overhead + perItemMs * n), perItemMs, tooMany: perItemMs <= MIN_ITEM_MS && n > 1 && (slideshowMs / n) < MIN_ITEM_MS};
    }

    function isMissionHighlightVideoSupported() {
        return typeof MediaRecorder !== 'undefined'
            && typeof HTMLCanvasElement !== 'undefined'
            && typeof HTMLCanvasElement.prototype.captureStream === 'function'
            && (MediaRecorder.isTypeSupported('video/mp4') || MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus') || MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus') || MediaRecorder.isTypeSupported('video/webm'));
    }

    // MP4 (H.264/AAC) first where the browser can actually record it: far
    // better native playback elsewhere (iOS, WhatsApp, Windows' own player)
    // than WebM, which several of those don't open at all. Falls back to
    // WebM where MediaRecorder can't produce MP4.
    function pickMimeType() {
        if (MediaRecorder.isTypeSupported('video/mp4;codecs=avc1.42E01E,mp4a.40.2')) return 'video/mp4;codecs=avc1.42E01E,mp4a.40.2';
        if (MediaRecorder.isTypeSupported('video/mp4')) return 'video/mp4';
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

    function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }

    /**
     * Opening card (phase 0): big mission name + date, centered, on a dark
     * backdrop - "big letters at the beginning" rather than a small banner
     * mid-slideshow. `progress` is 0..1 over TITLE_SCREEN_MS; the text fades
     * and rises in over the first ~25%, holds, then fades out over the last
     * ~25% so it doesn't just cut into the slideshow.
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

    /** Persistent corner brand mark (title card + slideshow, not the outro which gets its own centered logo): the team's own logo, drawn at its native aspect ratio with no background chip so its own transparency (if any) stays intact. */
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

    /** Persistent lower-third (slideshow only - the title card and outro already show this big and centered): a dark gradient strip with the mission name and date, always on screen while photos/videos play. */
    function drawBottomBanner(ctx, canvasW, canvasH, missionTitle, dateLabel) {
        ctx.save();
        const bannerH = canvasH * 0.16;
        const grad = ctx.createLinearGradient(0, canvasH - bannerH, 0, canvasH);
        grad.addColorStop(0, 'rgba(0,0,0,0)');
        grad.addColorStop(1, 'rgba(0,0,0,0.72)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, canvasH - bannerH, canvasW, bannerH);

        ctx.fillStyle = '#ffffff';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';
        ctx.shadowColor = 'rgba(0,0,0,0.8)';
        ctx.shadowBlur = 6;
        const titleSize = Math.round(canvasW * 0.034);
        ctx.font = 'bold ' + titleSize + 'px sans-serif';
        ctx.fillText(fitSingleLine(ctx, missionTitle, canvasW * 0.92), canvasW / 2, canvasH - bannerH * 0.42, canvasW * 0.92);

        if (dateLabel) {
            const dateSize = Math.round(canvasW * 0.022);
            ctx.font = dateSize + 'px sans-serif';
            ctx.fillStyle = 'rgba(255,255,255,0.85)';
            ctx.fillText(dateLabel, canvasW / 2, canvasH - bannerH * 0.14, canvasW * 0.92);
        }
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
     * Outro (phase 2): fades the last slide to dark, then brings in the
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
     * @param {{title:string,start_datetime:?string}} opts.missionLocation
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

            let stopped = false;
            let visibilityHandler = null;
            const cleanup = () => {
                if (visibilityHandler) document.removeEventListener('visibilitychange', visibilityHandler);
            };
            const fail = (err) => { if (stopped) return; stopped = true; cleanup(); reject(err); };

            Promise.resolve().then(async () => {
                // ── Preload: media elements, music, logo ───────────────────────
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
                // Capped relative to the slot itself: with many items perItemMs can
                // shrink down near MIN_ITEM_MS, and a fixed 500ms crossfade would
                // then eat most of a short slot's actual viewing time.
                const transitionMs = Math.min(TRANSITION_MS, timing.perItemMs * 0.3);

                function frame(now) {
                    if (stopped) return;
                    const elapsed = now - startTime;
                    if (opts.onProgress) opts.onProgress(Math.min(1, elapsed / timing.totalMs));

                    ctx.fillStyle = '#0b1220';
                    ctx.fillRect(0, 0, canvasW, canvasH);

                    const slideshowEnd = TITLE_SCREEN_MS + timing.perItemMs * media.length;

                    if (elapsed < TITLE_SCREEN_MS) {
                        // ── Phase 0: opening title card (big letters) ──
                        drawOpeningTitle(ctx, canvasW, canvasH, loc.title || '', formatMissionDate(loc.start_datetime), elapsed / TITLE_SCREEN_MS);
                        drawCornerLogo(ctx, canvasW, canvasH, logoImg);
                    } else if (elapsed < slideshowEnd) {
                        // ── Phase 1: slideshow ──
                        const phase2Elapsed = elapsed - TITLE_SCREEN_MS;
                        const index = Math.min(media.length - 1, Math.floor(phase2Elapsed / timing.perItemMs));
                        const itemElapsed = phase2Elapsed - index * timing.perItemMs;
                        if (index !== lastItemIndex) {
                            lastItemIndex = index;
                            itemStartTime = now;
                            const el = mediaElements[index];
                            // paused-check: a video already playing here was pre-started by
                            // the previous item's crossfade-in below, and restarting it from
                            // 0 again right as it becomes "current" would visibly jump/restart it.
                            if (el && el.tagName === 'VIDEO' && el.paused) { el.currentTime = 0; el.play().catch(() => {}); }
                        }
                        const el = mediaElements[index];
                        const progress = Math.min(1, itemElapsed / timing.perItemMs);
                        const direction = index % 2 === 0 ? 1 : -1;
                        if (el) {
                            if (el.tagName === 'VIDEO' && el.ended) { el.currentTime = 0; el.play().catch(() => {}); }
                            drawKenBurns(ctx, el, canvasW, canvasH, progress, direction);
                        }
                        // Crossfade into the next item over the slot's last `transitionMs`,
                        // rather than a hard cut - a static (progress 0) frame of the next
                        // item fading in on top keeps this simple and robust.
                        const nextIndex = index + 1;
                        const remainingMs = timing.perItemMs - itemElapsed;
                        if (nextIndex < media.length && remainingMs < transitionMs) {
                            const nextEl = mediaElements[nextIndex];
                            if (nextEl) {
                                if (nextEl.tagName === 'VIDEO' && nextEl.paused) { nextEl.currentTime = 0; nextEl.play().catch(() => {}); }
                                const fadeAlpha = 1 - Math.max(0, remainingMs) / transitionMs;
                                const nextDirection = nextIndex % 2 === 0 ? 1 : -1;
                                ctx.save();
                                ctx.globalAlpha = fadeAlpha;
                                drawKenBurns(ctx, nextEl, canvasW, canvasH, 0, nextDirection);
                                ctx.restore();
                            }
                        }
                        drawBottomBanner(ctx, canvasW, canvasH, loc.title || '', formatMissionDate(loc.start_datetime));
                        drawCornerLogo(ctx, canvasW, canvasH, logoImg);
                    } else {
                        // ── Phase 2: outro (its own big, centered logo - no corner mark here) ──
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
            computeTiming, kenBurnsTransform, formatMissionDate,
            MIN_TOTAL_MS, MAX_TOTAL_MS, MIN_ITEM_MS,
        };
    }
})(typeof window !== 'undefined' ? window : globalThis);
