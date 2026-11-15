/**
 * Mission highlight video — modal wiring (v3.490.0).
 *
 * Talks to mission-highlight-video.php (status/media-list/upload) and drives
 * assets/js/mission-highlight-video.js's renderMissionHighlightVideo(). Only
 * loaded for anyone with $canManageWarRoom (see that gate around this
 * <script> include in war-room.php and mission-archive.php), which also
 * defines window.HV_CONTEXT = {missionId, missionLocation, csrf, canGenerate, teamLogoUrl}.
 *
 * No JS-side translation lookup: every string this file shows is already
 * rendered server-side by t() into the modal's own markup
 * (includes/highlight-video-modal.php) - either as static element text, or
 * (for the two messages with a placeholder) as a data-template attribute
 * this file fills in. That keeps el/en both correct with no English fallback
 * strings baked into this file.
 */
(function () {
    'use strict';
    if (!window.HV_CONTEXT) return;
    const ctx = window.HV_CONTEXT;
    // Relative, not WR_BOOT.baseUrl: this script loads and runs (this whole
    // IIFE body executes immediately) BEFORE WR_BOOT is defined further down
    // the page. mission-highlight-video.php and assets/ are siblings of
    // war-room.php/mission-archive.php, so a plain relative URL resolves
    // correctly under any BASE_URL/subdirectory without needing that global.
    const endpoint = 'mission-highlight-video.php';
    const AUDIO_DIR = 'assets/audio/mission-video/';

    const $ = (id) => document.getElementById(id);
    let mediaItems = [];
    let musicTracks = [];
    let loaded = false;

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    }

    function renderMediaGrid() {
        const grid = $('hvMediaGrid');
        if (!mediaItems.length) {
            grid.innerHTML = '<span class="text-muted small">' + escapeHtml(grid.dataset.emptyLabel || '') + '</span>';
            return;
        }
        grid.innerHTML = mediaItems.map(m => (
            '<label class="position-relative" style="width:64px;cursor:pointer;">' +
            '<input type="checkbox" class="hv-media-check position-absolute top-0 start-0 m-1" value="' + m.id + '" data-type="' + m.type + '" data-url="' + encodeURI(m.full_url) + '" checked>' +
            '<img src="' + m.thumb_url + '" title="' + escapeHtml(m.label) + '" style="width:64px;height:64px;object-fit:cover;border-radius:4px;" loading="lazy">' +
            (m.type === 'video' ? '<i class="bi bi-camera-video-fill position-absolute bottom-0 end-0 m-1 text-white" style="text-shadow:0 0 3px #000;"></i>' : '') +
            '</label>'
        )).join('');
        grid.querySelectorAll('.hv-media-check').forEach(cb => cb.addEventListener('change', updateTooManyWarning));
        updateTooManyWarning();
    }

    function updateTooManyWarning() {
        // Mirrors mission-highlight-video.js's own MIN_ITEM_MS/MAX_TOTAL_MS
        // band loosely (that module isn't loaded as an ES export here): past
        // ~15 items in a 40s cap, each gets under 1.5s - worth a heads-up.
        $('hvTooManyWarning').classList.toggle('d-none', selectedMedia().length <= 15);
    }

    function renderMusicList() {
        const list = $('hvMusicList');
        if (!musicTracks.length) {
            list.innerHTML = '';
            return;
        }
        list.innerHTML = musicTracks.map((track, i) => (
            '<label class="list-group-item d-flex align-items-center gap-2">' +
            '<input type="radio" name="hvMusicTrack" value="' + track.id + '"' + (i === 0 ? ' checked' : '') + '>' +
            '<span class="flex-grow-1">' + escapeHtml(track.title) + '<br><span class="text-muted small">' + escapeHtml(track.mood || '') + '</span></span>' +
            '<audio controls preload="none" style="height:30px;max-width:200px;" src="' + AUDIO_DIR + encodeURIComponent(track.filename) + '"></audio>' +
            '</label>'
        )).join('');
    }

    function selectedMedia() {
        return Array.from(document.querySelectorAll('.hv-media-check:checked')).map(cb => ({
            url: decodeURI(cb.dataset.url), type: cb.dataset.type,
        }));
    }

    function selectedMusicTrack() {
        const checked = document.querySelector('input[name="hvMusicTrack"]:checked');
        const track = checked ? musicTracks.find(x => x.id === checked.value) : null;
        return track ? {id: track.id, url: AUDIO_DIR + encodeURIComponent(track.filename)} : null;
    }

    function selectedOrientation() {
        return $('hvOrientationPortrait').checked ? 'portrait' : 'landscape';
    }

    function showError(key) {
        const box = $('hvErrorBox');
        box.textContent = box.dataset['msg' + key] || box.dataset.msgSaveFailed;
        box.classList.remove('d-none');
    }
    function clearError() { $('hvErrorBox').classList.add('d-none'); }

    function setBuilderVisible(visible) {
        $('hvBuilderSection').classList.toggle('d-none', !visible);
    }

    function showExisting(video) {
        if (!video) {
            $('hvExistingNotice').classList.add('d-none');
            // A non-admin (view-only, $canManageWarRoom) sees an explanatory
            // notice instead of the generate form - only system admins may
            // generate/replace the video (locked-in decision), even though
            // anyone who can manage this War Room may watch/download it.
            setBuilderVisible(ctx.canGenerate);
            $('hvNotGeneratedNotice').classList.toggle('d-none', ctx.canGenerate);
            return;
        }
        $('hvNotGeneratedNotice').classList.add('d-none');
        $('hvExistingNotice').classList.remove('d-none');
        setBuilderVisible(false);
        $('hvRegenerateBtn').classList.toggle('d-none', !ctx.canGenerate);
        $('hvExistingPlayer').src = video.view_url;
        $('hvDownloadLink').href = video.view_url;
        const when = new Date((video.created_at || '').replace(' ', 'T'));
        const whenLabel = isNaN(when.getTime()) ? '' : when.toLocaleString();
        const label = $('hvExistingLabel');
        label.textContent = (label.dataset.template || '')
            .replace('{name}', video.created_by_name || '').replace('{date}', whenLabel);
    }

    async function loadModalData() {
        if (loaded) return;
        loaded = true;

        // Unsupported-browser only matters for GENERATING (Canvas/MediaRecorder);
        // a view-only visitor ($canManageWarRoom but not system admin) just
        // watches a plain <video>, which works everywhere, so this gate must
        // not block their read-only path.
        if (ctx.canGenerate && (!window.isMissionHighlightVideoSupported || !window.isMissionHighlightVideoSupported())) {
            $('hvUnsupportedNotice').classList.remove('d-none');
            setBuilderVisible(false);
            return;
        }

        const fetches = [
            fetch(endpoint + '?action=status&mission_id=' + ctx.missionId).then(r => r.json()).catch(() => null),
        ];
        if (ctx.canGenerate) {
            fetches.push(
                fetch(endpoint + '?action=media-list&mission_id=' + ctx.missionId).then(r => r.json()).catch(() => null),
                fetch(AUDIO_DIR + 'manifest.json').then(r => r.json()).catch(() => []),
            );
        }
        const [statusRes, mediaRes, manifestRes] = await Promise.all(fetches);

        mediaItems = (mediaRes && mediaRes.ok) ? mediaRes.items : [];
        musicTracks = Array.isArray(manifestRes) ? manifestRes : [];
        if (ctx.canGenerate) {
            renderMediaGrid();
            renderMusicList();
        }

        showExisting(statusRes && statusRes.ok ? statusRes.video : null);
    }

    async function uploadBlob(blob, orientation, musicTrackId) {
        const form = new FormData();
        form.append('action', 'upload');
        form.append('mission_id', ctx.missionId);
        form.append('orientation', orientation);
        form.append('music_track_id', musicTrackId || '');
        form.append('csrf_token', ctx.csrf);
        form.append('video', blob, 'highlight.webm');
        const res = await fetch(endpoint, {method: 'POST', body: form}).then(r => r.json());
        if (!res.ok) throw new Error(res.error || 'upload failed');
        return res;
    }

    async function generate() {
        clearError();
        const media = selectedMedia();
        if (!media.length) {
            showError('NoMedia');
            return;
        }
        const track = selectedMusicTrack();
        const orientation = selectedOrientation();

        $('hvGenerateBtn').disabled = true;
        $('hvKeepOpenWarning').classList.remove('d-none');
        $('hvProgressWrap').classList.remove('d-none');
        $('hvProgressBar').style.width = '0%';
        const progressLabel = $('hvProgressLabel');

        try {
            const blob = await window.renderMissionHighlightVideo({
                orientation,
                missionLocation: ctx.missionLocation,
                selectedMedia: media,
                musicTrackUrl: track ? track.url : null,
                teamLogoUrl: ctx.teamLogoUrl || null,
                onProgress: (fraction) => {
                    const pct = Math.round(fraction * 100);
                    $('hvProgressBar').style.width = pct + '%';
                    progressLabel.textContent = (progressLabel.dataset.template || '').replace('{percent}', String(pct));
                },
            });
            const res = await uploadBlob(blob, orientation, track ? track.id : null);
            showExisting({
                view_url: res.view_url,
                created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                created_by_name: '',
            });
        } catch (err) {
            const msg = err && err.message;
            if (msg === 'unsupported-browser') showError('Unsupported');
            else if (msg === 'backgrounded' || msg === 'aborted') showError('Backgrounded');
            else showError('SaveFailed');
        } finally {
            $('hvGenerateBtn').disabled = false;
            $('hvKeepOpenWarning').classList.add('d-none');
            $('hvProgressWrap').classList.add('d-none');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const modalEl = $('highlightVideoModal');
        if (!modalEl) return;
        modalEl.addEventListener('show.bs.modal', loadModalData);
        $('hvGenerateBtn').addEventListener('click', generate);
        $('hvRegenerateBtn').addEventListener('click', () => {
            $('hvExistingNotice').classList.add('d-none');
            setBuilderVisible(true);
        });
    });
})();
