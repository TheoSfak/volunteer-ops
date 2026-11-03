<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The last two pieces of the «Πίνακας» look (v3.433.0): the search in the top bar, and the ruler on the right stack.
 *
 * The matching itself (accents, capitals, the groups, the order) is tested as plain functions in
 * tests/js/war-room-console-overlay.test.js. What cannot be seen from there and is pinned here, by source:
 *  - the search asks the server for nothing and takes the map to a result through the page's own ways in;
 *  - the ruler is the page's measuring tool and nothing else: it starts the same tool from two clicks, makes no
 *    request of its own (the server's allowance for heights is shared with the point menu, the pointer's label and
 *    the card), and gives way to - and takes over from - every other tool that waits for a click;
 *  - both are for the board layout only: given back / hidden below 1200 px, and in both languages.
 */
final class ConsoleFindMeasureTest extends TestCase
{
    private static function source(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $file));
    }

    /** The text between two markers of a source file. */
    private static function between(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        self::assertNotFalse($start, "marker not found: $from");
        $end = strpos($text, $to, $start + strlen($from));
        self::assertNotFalse($end, "marker not found: $to");
        return substr($text, $start, $end - $start);
    }

    // ── the search ───────────────────────────────────────────────────────────

    public function testTheSearchAsksTheServerForNothing(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $search = self::between($js, '// ── the search:', '// ── moving the real controls in, and back');
        foreach (['fetch(', 'XMLHttpRequest', 'new Request', 'sendBeacon', 'setInterval', 'WebSocket', '.php'] as $needle) {
            $this->assertStringNotContainsString($needle, $search, "the search must read what the page holds: no «{$needle}»");
        }
    }

    public function testTheSearchReadsTheListsTheMapIsDrawnFrom(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $entries = self::between($js, 'function findEntries()', 'function paintFind()');
        foreach (['api.view()', 'pinsNow()', 'areas', 'sectors', 'restrictedAreas', 'mapPoints', 'missionIncidents', 'pointsOfInterest'] as $source) {
            $this->assertStringContainsString($source, $entries, $source);
        }
        // A person is only findable while they have a position on the map: that is where a result goes.
        $this->assertStringContainsString("p.lat === null || p.lat === undefined", $entries);
        // Every list may be missing for this viewer (the page loads some only for staff): none is assumed to exist.
        $this->assertGreaterThanOrEqual(6, substr_count($entries, "typeof "), 'each optional list is checked before it is read');
    }

    public function testAResultTakesTheMapThereThroughThePagesOwnWaysIn(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $go = self::between($js, 'function goTo(e)', "findBtn.addEventListener('click'");
        $this->assertStringContainsString("api.pickTeam(e.id, 'only')", $go, 'a team is picked as the team bar picks it, alone');
        $this->assertStringContainsString('frame(new Set(e.members.map(Number)))', $go, 'and the map is framed on it with the bar\'s own framing');
        foreach (['door.person(', 'door.point(', 'door.incident(', 'door.poi(', 'door.shape('] as $call) {
            $this->assertStringContainsString($call, $go, $call);
        }
        $this->assertStringNotContainsString('openPopup', $go, 'no popup is opened from here: the page\'s door does it');
        $this->assertStringNotContainsString('L.', $go);
    }

    public function testThePagesDoorForAShapeIsTheOneTheOverlapPickerUses(): void
    {
        $page = self::source('war-room.php');
        $door = self::between($page, 'window.wrMapGo = {', "\n};\n");
        $shapeAt = strpos($door, 'shape: (kind, id) => {');
        $this->assertNotFalse($shapeAt);
        $shape = substr($door, (int) $shapeAt);
        // What «Τι υπάρχει εδώ;» does when a row is picked: find the live layer, then openPopup(latlng).
        $this->assertStringContainsString('mapPickFind({kind, id})', $shape);
        $this->assertStringContainsString('layer.openPopup(', $shape);
        $this->assertStringContainsString('mapOverlayEnsureShown(overlay)', $shape, 'a kind of shape the viewer switched off is shown again');
        $this->assertStringContainsString("{area: 'areas', sector: 'sectors', restricted: 'restricted'}", $shape);
        // The overlay keys it uses are the page's own (MAP_OVERLAYS), so it cannot name one that does not exist.
        foreach (['areas', 'sectors', 'restricted'] as $key) {
            $this->assertStringContainsString("{key: '$key'", $page);
        }
        foreach (['person', 'incident', 'poi', 'point', 'shape'] as $name) {
            $this->assertMatchesRegularExpression('~\n    ' . $name . ': ~', $door, $name);
        }
        $this->assertSame(1, substr_count($page, 'window.wrMapGo = {'));
    }

    public function testAPersonAnIncidentAndAPointOfInterestAreFoundAgainAtTheMomentTheirPopupOpens(): void
    {
        $page = self::source('war-room.php');
        // The 5 s poll rebuilds these markers, so the one to open is looked up when it is needed, by a tag set where it is built.
        $this->assertStringContainsString('marker._wrUser = Number(pin.user_id);', $page);
        $this->assertStringContainsString('marker._wrIncident = r.id;', $page);
        $this->assertStringContainsString('marker._wrPoi = p.id;', $page);
        $this->assertStringContainsString('currentPinMarkers.find(m => m._wrUser === Number(userId))', $page);
        $this->assertStringContainsString('currentIncidentMarkers.find(m => String(m._wrIncident) === String(id))', $page);
        $this->assertStringContainsString('currentPoiMarkers.find(m => String(m._wrPoi) === String(id))', $page);
        // Through the cluster, as the map points do: a marker inside a cluster has no popup until the cluster is opened.
        $this->assertStringContainsString('sharedMarkerCluster.zoomToShowLayer(marker, () => marker.openPopup())', $page);
        // A map that follows the pins would put itself back at the next position.
        $door = self::between($page, 'function mapGoMarker(', 'window.wrMapGo = {');
        $this->assertStringContainsString('setFollowPins(false)', $door);
        $this->assertStringContainsString('setTimeout(', $door);
    }

    public function testTheSearchIsDrivenFromTheKeyboardAndClosesTheWayAPanelShould(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $search = self::between($js, '// ── the search:', '// ── moving the real controls in, and back');
        $this->assertStringContainsString("e.key === 'ArrowDown' || e.key === 'ArrowUp'", $search);
        $this->assertStringContainsString('wrcOvStep(find.flat.length, find.idx', $search, 'the arrows go round the list');
        $this->assertStringContainsString("e.key === 'Enter'", $search);
        $this->assertStringContainsString('e.isComposing', $search, 'Enter that ends a composed character is not a pick');
        $this->assertStringContainsString('setTimeout(() => findIn.focus(), 0)', $search, 'the input has the focus when the panel opens');
        // Esc in the input closes only the search: the page's own Esc cancels a measurement and must not hear it.
        $escape = self::between($search, "if (e.key === 'Escape') {", "} else if (e.key === 'ArrowDown'");
        $this->assertStringContainsString('e.stopPropagation()', $escape);
        $this->assertStringContainsString('closeFind(true)', $escape);
        // Esc anywhere, and a press outside the panel (and not on its own button), close it.
        $this->assertStringContainsString("document.addEventListener('keydown', e => { if (e.key === 'Escape' && find.open) closeFind(true); });", $search);
        $this->assertStringContainsString("document.addEventListener('mousedown', e => { if (find.open && !findPn.contains(e.target) && !findBtn.contains(e.target)) closeFind(); });", $search);
        $this->assertStringContainsString('const FIND_MAX = 8;', $search);
        // The combobox pattern, so a screen reader is told which row is the active one.
        foreach (['role="combobox"', 'aria-activedescendant', 'role="listbox"', 'role="option"', "role', 'dialog'"] as $aria) {
            $this->assertStringContainsString($aria, $search, $aria);
        }
    }

    public function testTheMagnifierSitsInTheBarNextToTheBellAndTheProfile(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString('op.appendChild(findBtn);', $js, 'it goes into the group the bell and the profile are moved into, in front of them');
        $this->assertStringContainsString("take(\$('#notifBell') && \$('#notifBell').closest('.dropdown'), op);", $js);
        $this->assertLessThan(
            strpos($js, "take(\$('#notifBell')"),
            strpos($js, 'op.appendChild(findBtn);'),
            'the magnifier is appended first, so the bell and the profile come after it'
        );
    }

    // ── the ruler ────────────────────────────────────────────────────────────

    public function testTheRulerIsTheMeasuringToolOfThePageAndMakesNoRequestOfItsOwn(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $ruler = self::between($js, '// ── the ruler:', '// ── the search:');
        foreach (['fetch(', 'XMLHttpRequest', 'mission-measure', 'elevation', 'L.polyline', 'L.marker', 'wr-measure-card'] as $needle) {
            $this->assertStringNotContainsString($needle, $ruler, "the ruler is only a button: no «{$needle}»");
        }
        $this->assertStringContainsString('m.arm()', $ruler);
        $this->assertStringContainsString('m.clear()', $ruler);
        $this->assertStringContainsString('m.active()', $ruler);
        $this->assertStringContainsString("document.addEventListener('wr-measure-changed'", $ruler);
        // The whole script has no door of its own to the measuring or elevation endpoint (the heights stay the page's).
        $this->assertStringNotContainsString('mission-measure', $js);
    }

    public function testTheRulerStartsTheExistingToolFromTwoClicks(): void
    {
        $page = self::source('war-room.php');
        $block = self::between($page, '// ── The ruler button of the «Πίνακας» look', '// ── Where a search result of the «Πίνακας» look takes the map');
        $this->assertStringNotContainsString('fetch(', $block, 'no request of its own: the allowance of the server for heights is shared');
        $this->assertStringContainsString('measureStart(point, false, true);', $block, 'the first click is the existing start');
        $this->assertStringContainsString('measureFinish(point);', $block, 'the second is the existing finish');
        $this->assertStringContainsString('const point = mapGesturePoint(e);', $block, 'a click snaps to a person, a point or a pin the way the right-click does');
        $this->assertSame(1, substr_count($page, 'function measureStart('), 'one tool: no second copy of it');
        $this->assertSame(1, substr_count($page, 'function measureFinish('));
        $this->assertSame(1, substr_count($page, 'function measureClear('));
        // The second-point hint of the button's flow, in the page's strings, in both languages.
        $this->assertStringContainsString("'measure.pick_second_hint'", $page);
        $this->assertStringContainsString("'measure.pick_first_hint'", $page);
        // And its own hint is chosen only for the button's flow: the right-click flow keeps its words.
        $this->assertStringContainsString("t(byClick ? 'measure.pick_second_hint' : (touch ? 'measure.pick_hint_touch' : 'measure.pick_hint_mouse'))", $page);
    }

    public function testTheMapLetsTheClickThroughWhileTheRulerWaitsForIt(): void
    {
        $page = self::source('war-room.php');
        $hold = self::between($page, 'function measureHold(on) {', '// What else was waiting for a click gives way');
        // The class the drawing tools and the other one-shot picks already use: crosshair, and shapes and pins take no click.
        $this->assertStringContainsString("classList.toggle('wr-draw-active', on)", $hold);
        $this->assertStringContainsString('map.doubleClickZoom.disable()', $hold, 'a double click is not two points');
        $this->assertStringContainsString('map.doubleClickZoom.enable()', $hold);
        $this->assertStringContainsString('#mapCard.wr-draw-active .leaflet-area-pane .leaflet-interactive', $page);
        // Every way out of the tool puts the map back (the existing Esc included).
        $clear = self::between($page, 'function measureClear() {', 'function measureStart(');
        $this->assertStringContainsString('measureHold(false);', $clear);
        $this->assertStringContainsString('measureAnnounce();', $clear);
        $this->assertStringContainsString("e.key === 'Escape' && (measureFrom || measureControl)", $page, 'the page\'s own Esc cancels the armed ruler too: its card is a measureControl');
    }

    public function testTheRulerAndEveryOtherToolThatWaitsForAClickGiveWayToEachOther(): void
    {
        $page = self::source('war-room.php');
        // The ruler takes over: the pen, the collection point, the command post and the building are put away first.
        $arm = self::between($page, 'function measureArm() {', '// A tool that starts waiting for its own click');
        foreach (['if (activeTool) setActiveTool(activeTool);', 'cpStopPick()', 'triageStopPick()', 'addingBuildingToSectorId = null;'] as $away) {
            $this->assertStringContainsString($away, $arm, $away);
        }
        // And each of them, starting, puts a waiting ruler away - one call in each place.
        $this->assertStringContainsString("if (activeTool) measureDisarm();", self::between($page, 'function setActiveTool(tool) {', 'const annoToolbarEl'));
        $this->assertStringContainsString('measureDisarm();', self::between($page, 'function cpStartPick(mode) {', 'cpPickMode = mode;'));
        $this->assertStringContainsString('measureDisarm();', self::between($page, 'function triageStartPick(kind) {', 'triagePickKind = kind;'));
        $this->assertStringContainsString('measureDisarm();', self::between($page, "if (addBuildingBtn) addBuildingBtn.addEventListener('click', () => {", 'map.closePopup();'));
        // Only a ruler that is WAITING gives way: a finished measurement stays beside a drawing, as it always has.
        $this->assertStringContainsString('if (measureArmed) measureClear();', self::between($page, 'function measureDisarm() {', "map?.on('click'"));
    }

    public function testTheRulerButtonIsLeftWhenTheBoardIsGivenBackAndShowsItsStateInOrange(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $disengage = self::between($js, 'function disengage() {', '// ── what the page puts at the top');
        $this->assertStringContainsString('closeFind();', $disengage);
        $this->assertStringContainsString('measureTool().armed()', $disengage, 'a ruler waiting for its clicks must not leave a crosshair on the console\'s own map');
        $this->assertStringContainsString('measureTool().clear()', $disengage);
        $css = self::source('assets/css/war-room-console-overlay.css');
        // The stack's own «active» orange is what the ruler uses; the search button has its own, in the bar.
        $this->assertMatchesRegularExpression('~\.wrc-ov-stack \.btn\.active \{ background: var\(--ov-org\)~', $css);
        $this->assertMatchesRegularExpression('~\.wrc-ov-find-btn\.active \{ background: var\(--ov-org\)~', $css);
        // In the stack's draw column, under the pen, the arrow, the text and the eraser.
        $this->assertStringContainsString("\$('[data-g=\"draw\"]', stack).appendChild(ruler);", $js);
    }

    // ── layout, both languages ───────────────────────────────────────────────

    public function testTheSearchPanelAndTheMeasuringCardStayInTheBoardLayout(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', self::source('assets/css/war-room-console-overlay.css'));
        // Hidden outside the layout with the other things the script adds, shown inside it (ConsoleSkinTest checks the pair).
        $this->assertMatchesRegularExpression('~html\[data-wrc-skin="overlay"\] \.wrc-ov-find,~', $css);
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc-ov-find \{[^}]*display: flex;[^}]*position: absolute;~', $css);
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc-ov-find\[hidden\] \{ display: none; \}~', $css);
        // The measuring card is moved out from under the acknowledgement panel, only in the layout.
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wr-measure-card \{[^}]*position: fixed;[^}]*bottom: calc\(var\(--wrc-ov-bottom, 0px\) \+ 98px\);~', $css);
        $this->assertDoesNotMatchRegularExpression('~(?<!\[data-wrc-ov\] )\.wr-measure-card \{~', $css, 'every rule for the card is inside the layout');
        // The acknowledgement panel gives the card (and the order form, which opens on the same spot) room by being shorter, never the other way round.
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc:has\(\.wr-measure-card\) > \.ack-tracker \{ max-height: calc\(100vh - var\(--wr-acktracker-top\) - 440px\); \}~', $css);
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc\[data-ov-composer="open"\] > \.ack-tracker \{ max-height: calc\(100vh - var\(--wr-acktracker-top\) - 410px\); \}~', $css);
        $this->assertGreaterThan(
            strpos($css, '.wrc[data-ov-composer="open"] > .ack-tracker'),
            strpos($css, '.wrc:has(.wr-measure-card) > .ack-tracker'),
            'the stricter of the two comes last, so it wins when both apply'
        );
    }

    public function testEverySentenceOfTheSearchAndTheRulerExistsInBothLanguages(): void
    {
        $strings = loadLangStrings('war-room');
        $js = self::source('assets/js/war-room-console-overlay.js');
        $page = self::source('war-room.php');
        preg_match_all("/'(console\.ov_(?:find[a-z_]*|ruler))'/", $js, $m);
        $used = array_values(array_unique($m[1]));
        $this->assertGreaterThanOrEqual(13, count($used), 'the search and the ruler use their words by name, never built up');
        $used = array_merge($used, ['measure.pick_first_hint', 'measure.pick_second_hint']);
        foreach (['el', 'en'] as $lang) {
            foreach ($used as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "$key in $lang");
                $this->assertNotSame('', trim((string) $strings[$lang][$key]), "$key in $lang is empty");
            }
        }
        // The text a person types into it is shown back inside the «none» line: both languages carry the {q} slot.
        $this->assertStringContainsString('{q}', $strings['el']['console.ov_find_none']);
        $this->assertStringContainsString('{q}', $strings['en']['console.ov_find_none']);
        $this->assertStringContainsString('{n}', $strings['el']['console.ov_find_more']);
        $this->assertStringContainsString('{n}', $strings['en']['console.ov_find_more']);
        // No Greek in the English strings.
        foreach ($used as $key) {
            $this->assertDoesNotMatchRegularExpression('~[Α-Ωα-ωάέήίόύώ]~u', (string) $strings['en'][$key], "$key in en");
        }
        $this->assertStringContainsString("'measure.pick_first_hint'", $page);
    }

    public function testNothingAboutTheSearchOrTheRulerReachesTheOtherLooks(): void
    {
        $page = self::source('war-room.php');
        $this->assertSame(1, substr_count($page, 'war-room-console-overlay.js?v='), 'the companion script is still loaded for the board look only');
        // The page's hooks are inert until a button of the board calls them: nothing in them runs at load time.
        $this->assertSame(1, substr_count($page, 'window.wrMeasure = {'));
        $this->assertSame(1, substr_count($page, 'window.wrMapGo = {'));
        foreach (['assets/css/war-room-console-nocturne.css', 'assets/css/war-room-console-aurora.css'] as $other) {
            $css = self::source($other);
            $this->assertStringNotContainsString('wrc-ov-', $css);
        }
    }
}
