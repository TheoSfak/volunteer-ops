<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Scenario on a page of its own (v3.462.0): mission-scenario-view.php, the «Σενάριο» button of the Action Room's header
 * (and so of the Console's, which shares the header), the slim summary card that is left in the Action Room, and the place of that
 * card in the right column.
 *
 * The things that must hold: command staff only, and a volunteer is sent away BEFORE any markup or script of the scenario is
 * printed; the page holds no plan and no mission id inside the script; every word the script asks for is in the page's own small
 * table in both languages; the button is in the command block of the header and shows even when heart rates are off; the card in
 * the Action Room has no controls of its own any more; and a saved layout that put the card low is brought up once.
 *
 * What the script does in each mode is tests/js/war-room-scenario.test.js.
 */
final class ScenarioPageTest extends TestCase
{
    private function source(string $rel): string
    {
        return str_replace("\r\n", "\n", (string) \WarRoomSource::read($rel));
    }

    // ── the page's gate ────────────────────────────────────────────────────────────────────────────────────────

    public function testThePageIsGatedBeforeAnythingIsPrinted(): void
    {
        $page = $this->source('mission-scenario-view.php');
        $login = strpos($page, 'requireLogin();');
        $mission = strpos($page, 'FROM missions m');
        $gate = strpos($page, 'canManageActionRoom(');
        $open = strpos($page, '$mission[\'status\'] !== STATUS_OPEN || empty($mission[\'show_in_ops\'])');
        $header = strpos($page, "include __DIR__ . '/includes/header.php';");
        $script = strpos($page, 'war-room-scenario.js?v=');
        foreach (['login' => $login, 'mission' => $mission, 'gate' => $gate, 'open' => $open, 'header' => $header, 'script' => $script] as $name => $at) {
            $this->assertNotFalse($at, $name);
        }
        $this->assertTrue($login < $mission && $mission < $gate && $gate < $open && $open < $header && $header < $script, 'login, mission, command gate, open gate, then markup, then the script');
        // A volunteer is refused with the scenario's own words and sent back to the mission; nothing is printed first.
        $refuse = substr($page, $gate, 330);
        $this->assertStringContainsString("t('scenario.no_permission')", $refuse);
        $this->assertStringContainsString("redirect('mission-view.php?id=' . \$missionId)", $refuse);
        // The endpoint only answers for an open mission on the board: so does the page, and says why.
        $this->assertStringContainsString("t('common.mission_not_found_or_inactive')", substr($page, $open - 120, 400));
        $this->assertStringContainsString("redirect('war-room.php?id=' . \$missionId)", substr($page, $open, 400));
        // An unknown mission is not found, and goes to the dashboard.
        $this->assertStringContainsString("t('common.mission_not_found')", substr($page, $mission, 600));
    }

    public function testThePageUsesTheSameGateAsTheEndpoint(): void
    {
        $page = $this->source('mission-scenario-view.php');
        $endpoint = $this->source('mission-scenario.php');
        $needle = "canManageActionRoom(\$mission['responsible_user_id'] ? (int) \$mission['responsible_user_id'] : null,";
        $this->assertStringContainsString($needle, $page);
        $this->assertStringContainsString($needle, $endpoint);
        // And not the weaker «can see the Action Room» tests.
        $this->assertStringNotContainsString('isApprovedParticipant', $page);
        $this->assertStringNotContainsString('hasPagePermission', $page);
    }

    public function testThePageHoldsNoPlanAndTheScriptHoldsNoMission(): void
    {
        $page = $this->source('mission-scenario-view.php');
        // The page is a frame: the plan is drawn by the script from mission-scenario.php, never printed here.
        $this->assertStringNotContainsString('loadMissionScenario', $page);
        $this->assertStringNotContainsString('mission_scenario', $page);
        $this->assertStringNotContainsString('INSERT', $page);
        $this->assertStringNotContainsString('UPDATE', $page);
        // The mission and the token come in through a boot script of the page, and the one script file is the same for every mission.
        $this->assertStringContainsString('const WR_MISSION_ID = <?= (int) $missionId ?>;', $page);
        $this->assertStringContainsString('const csrfToken = <?= json_encode(csrfToken(), $jsonFlags) ?>;', $page);
        $js = $this->source('assets/js/war-room-scenario.js');
        $this->assertStringNotContainsString('<?', $js, 'the script has no PHP in it: it is the same text for every mission');
        $this->assertStringContainsString("const ENDPOINT = 'mission-scenario.php';", $js);
        // Boot first, then the helpers the script reaches for, then the script, then the start.
        $boot = strpos($page, 'const WR_MISSION_ID');
        $utils = strpos($page, 'war-room-utils.js');
        $script = strpos($page, 'war-room-scenario.js?v=');
        $init = strpos($page, 'window.wrScenario.init();');
        $this->assertTrue($boot < $utils && $utils < $script && $script < $init);
        $this->assertStringContainsString('<div id="scenarioBody" data-sc-mode="page">', $page, 'the card element itself says it is the whole card (the comment above does not count)');
        $this->assertSame(1, substr_count($page, 'id="scenarioBody"'));
        $this->assertStringContainsString('class="container-fluid px-0 wr-sc-page"', $page);
    }

    public function testThePageHasNoEndpointOrMigrationOfItsOwn(): void
    {
        $this->assertSame(216, DB_SCHEMA_VERSION, 'v3.462.0 changes no table');
        $page = $this->source('mission-scenario-view.php');
        $this->assertStringNotContainsString('isPost()', $page, 'it is a page to read: every change goes through mission-scenario.php');
        $this->assertStringContainsString("'war-room.php?id=' . \$missionId", $page);
        $this->assertStringContainsString('href="war-room.php?id=<?= $missionId ?>"', $page, 'a way back');
    }

    // ── the words ──────────────────────────────────────────────────────────────────────────────────────────────

    public function testEveryKeyTheScriptAsksForIsInThePageTableInBothLanguages(): void
    {
        $js = $this->source('assets/js/war-room-scenario.js');
        preg_match_all("/'((?:scenario|common|restricted_area)\\.[a-z_0-9]+)'/", $js, $m);
        $keys = array_values(array_unique($m[1]));
        $this->assertGreaterThan(120, count($keys), 'the script asks for a lot of words: the regex found them');
        // No key is built from pieces, which no regex could see.
        $this->assertDoesNotMatchRegularExpression("/'(?:scenario|common|restricted_area)\\.'\\s*\\+/", $js);
        $this->assertDoesNotMatchRegularExpression('/`(?:scenario|common|restricted_area)\.\$\{/', $js);
        foreach (['el', 'en'] as $lang) {
            $table = scenarioPageStrings($lang);
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $table['strings'], "$key ($lang) is missing from the page's table");
                $this->assertNotSame('', trim((string) $table['strings'][$key]), "$key ($lang)");
                $this->assertArrayHasKey($key, $table['fallback'], "$key (fallback)");
            }
        }
    }

    public function testThePageTableIsASmallPartOfTheWholeAndHasNothingElse(): void
    {
        $all = loadLangStrings('war-room');
        $table = scenarioPageStrings('el');
        $this->assertArrayNotHasKey('hero.btn_vitals_report', $table['strings']);
        $this->assertArrayNotHasKey('chat.title', $table['strings']);
        foreach (array_keys($table['strings']) as $key) {
            $this->assertTrue(str_starts_with($key, 'scenario.') || in_array($key, scenarioPageExtraStringKeys(), true), $key);
        }
        $this->assertLessThan(strlen(json_encode($all['el'], JSON_UNESCAPED_UNICODE)) / 5, strlen(json_encode($table['strings'], JSON_UNESCAPED_UNICODE)), 'a fifth of the whole table at most');
        // An unknown language falls back to nothing of its own, but the default one is there.
        $unknown = scenarioPageStrings('xx');
        $this->assertSame([], $unknown['strings']);
        $this->assertNotEmpty($unknown['fallback']);
    }

    public function testTheNewWordsAreInBothLanguagesInTheSameOrder(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (['hero.btn_scenario', 'scenario.page_label', 'scenario.page_live', 'scenario.page_open_room', 'scenario.page_map_hint',
                  'scenario.summary_empty', 'scenario.summary_unsent', 'scenario.summary_ready', 'scenario.summary_waiting_one',
                  'scenario.summary_waiting_many', 'scenario.summary_blocked', 'scenario.summary_failed', 'scenario.summary_more',
                  'scenario.summary_open_btn', 'scenario.summary_open_title', 'scenario.deeplink_gone'] as $key) {
            $this->assertNotSame('', trim((string) ($strings['el'][$key] ?? '')), "$key (el)");
            $this->assertNotSame('', trim((string) ($strings['en'][$key] ?? '')), "$key (en)");
        }
        // The plural that cannot be number-neutral has a form for one and for more.
        $this->assertStringContainsString('{n}', $strings['el']['scenario.summary_waiting_many']);
        $this->assertStringNotContainsString('{n}', $strings['el']['scenario.summary_waiting_one']);
        $this->assertStringContainsString('{n}', $strings['en']['scenario.summary_waiting_many']);
    }

    // ── the button, and what is left in the Action Room ───────────────────────────────────────────────────────

    public function testTheHeaderButtonIsInTheCommandBlockAndShowsEvenWithoutHeartRates(): void
    {
        $war = $this->source('war-room.php');
        $block = strpos($war, '<?php if ($canManageWarRoom && !$volunteerTabs): ?>' . "\n" . '        <button type="button" class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#reportModal">');
        $button = strpos($war, 'id="scenarioHeroBtn"');
        $vitals = strpos($war, "<?php if (vitalsEnabled()): ?>\n        <!-- A new tab, not a modal like its neighbour");
        $this->assertNotFalse($block);
        $this->assertNotFalse($button);
        $this->assertNotFalse($vitals);
        $this->assertTrue($block < $button && $button < $vitals, 'after «Αναφορά Χρόνων», before and outside the heart-rate block');
        $this->assertStringNotContainsString('vitalsEnabled', substr($war, $block, $button - $block), 'no heart-rate condition between the start of the command block and the button');
        $this->assertStringNotContainsString('<?php endif; ?>', substr($war, $block, $button - $block), 'and the command block is still open where the button is');
        // It is a link to the page, in a tab named for the mission, with the words of the header (the Console reads its tooltip from them).
        $tag = substr($war, (int) strrpos(substr($war, 0, $button), '<a '), 420);
        $this->assertStringContainsString('href="mission-scenario-view.php?id=<?= $missionId ?>"', $tag);
        $this->assertStringContainsString('target="wrScenarioPage<?= $missionId ?>"', $tag);
        $this->assertStringContainsString('class="btn btn-outline-light"', $tag);
        $this->assertStringContainsString("t('hero.btn_scenario')", $tag);
        $this->assertStringContainsString('bi-collection-play-fill', $tag);
        // Nothing of it outside the command block: it appears once in the page.
        $this->assertSame(1, substr_count($war, 'id="scenarioHeroBtn"'));
        // The Console shares this very header: its icon-only buttons take their tooltip and aria-label from the words of the button.
        $console = $this->source('assets/js/war-room-console.js');
        $this->assertStringContainsString("\$\$('.war-room-hero .btn').forEach(btn => {", $console);
        $this->assertStringContainsString("btn.setAttribute('title', label)", $console);
        $this->assertStringContainsString('.war-room-hero .btn:not(#wrConsoleToggle) { font-size: 0;', $this->source('assets/css/war-room-console.css'));
    }

    public function testTheCardInTheActionRoomIsASummaryAndALink(): void
    {
        $war = $this->source('war-room.php');
        $start = strpos($war, 'data-card-id="scenarioCard"');
        $this->assertNotFalse($start);
        $card = substr($war, $start, 1300);
        $card = substr($card, 0, (int) strpos($card, 'data-card-id="sectorsCard"'));
        $this->assertStringContainsString('<div id="scenarioBody" data-sc-mode="room"></div>', $card);
        $this->assertStringContainsString('href="mission-scenario-view.php?id=<?= $missionId ?>"', $card);
        $this->assertStringContainsString('id="scenarioOpenBtn"', $card);
        $this->assertStringContainsString("t('scenario.summary_open_btn')", $card);
        // The long note moved to the page; the card has no controls of its own.
        $this->assertStringNotContainsString("t('scenario.note')", $card);
        $this->assertStringNotContainsString('<button', $card);
        $this->assertSame(1, substr_count($war, 'data-card-id="scenarioCard"'), 'the card is in the page once');
        $this->assertSame(1, substr_count($war, 'id="scenarioBody"'));
        // System admin only (v3.466.0): the card and the script sit in blocks gated tighter than $canManageWarRoom.
        $script = strpos($war, '/assets/js/war-room-scenario.js?v=');
        $this->assertStringContainsString('if ($canManageWarRoom && isSystemAdmin())', substr($war, $script - 200, 200));
        $cardBlock = strpos($war, 'data-card-id="scenarioCard"');
        $this->assertStringContainsString('if (isSystemAdmin())', substr($war, max(0, $cardBlock - 200), 200));
    }

    public function testTheMapActionsAreStillThePagesOwnFunctions(): void
    {
        // The page's links land in the Action Room, which calls these three; if one is renamed the link silently does nothing.
        $war = $this->source('war-room.php');
        $this->assertStringContainsString("\nfunction openDivideSectorsForStagedArea(stagedArea) {", $war);
        $this->assertStringContainsString('function armStagedBuilding(sector) {', $war);
        $this->assertStringContainsString("function scrollToCard(", $war);
        $js = $this->source('assets/js/war-room-scenario.js');
        foreach (['sc_do', 'sc_item'] as $param) {
            $this->assertStringContainsString("'" . $param . "'", $js);
        }
        $this->assertStringContainsString("if (el.dataset.scLink === '1') return;", $js, 'a link of the page is left to the browser');
    }

    // ── where the card sits ────────────────────────────────────────────────────────────────────────────────────

    public function testTheCardLeadsTheSidebarRightAfterTheAlerts(): void
    {
        $sidebar = warRoomDefaultLayout()['sidebar'];
        $this->assertSame(['sosAlertsCard', 'voiceMessagesCard', 'scenarioCard'], array_slice($sidebar, 0, 3));
        $this->assertSame(3, WAR_ROOM_LAYOUT_VERSION);
        $this->assertSame(1, count(array_keys($sidebar, 'scenarioCard', true)));
    }

    public function testASavedLayoutThatHadTheCardLowIsBroughtUpOnce(): void
    {
        $old = ['v' => 2, 'main' => ['mapCard'], 'sidebar' => ['sosAlertsCard', 'voiceMessagesCard', 'mediaCard', 'dispatchCard', 'scenarioCard', 'sectorsCard']];
        $up = warRoomUpgradeSavedLayout($old);
        $this->assertSame(['sosAlertsCard', 'voiceMessagesCard', 'scenarioCard', 'mediaCard', 'dispatchCard', 'sectorsCard'], $up['sidebar']);
        $this->assertSame(['mapCard'], $up['main']);

        // From version 1 as well (the v2 step runs first and the card still ends up right under the alerts).
        $v1 = warRoomUpgradeSavedLayout(['main' => ['mapCard'], 'sidebar' => ['mediaCard', 'sosAlertsCard', 'dispatchCard', 'scenarioCard']]);
        $this->assertSame(['sosAlertsCard', 'voiceMessagesCard', 'scenarioCard', 'mediaCard', 'dispatchCard'], $v1['sidebar']);

        // With no alert cards in the sidebar it goes to the top.
        $noAlerts = warRoomUpgradeSavedLayout(['v' => 2, 'main' => ['sosAlertsCard'], 'sidebar' => ['mediaCard', 'scenarioCard']]);
        $this->assertSame(['scenarioCard', 'mediaCard'], $noAlerts['sidebar']);

        // One that was dragged into the left column was put there on purpose.
        $left = warRoomUpgradeSavedLayout(['v' => 2, 'main' => ['mapCard', 'scenarioCard'], 'sidebar' => ['sosAlertsCard', 'mediaCard']]);
        $this->assertSame(['mapCard', 'scenarioCard'], $left['main']);
        $this->assertSame(['sosAlertsCard', 'voiceMessagesCard', 'mediaCard'], $left['sidebar'], 'the v2 step brings the alert pair together; the card stays where it was put');
    }

    public function testALayoutSavedUnderTheCurrentVersionIsTheUsersOwnAndIsNotTouched(): void
    {
        $mine = ['v' => WAR_ROOM_LAYOUT_VERSION, 'main' => ['mapCard'], 'sidebar' => ['sosAlertsCard', 'mediaCard', 'dispatchCard', 'scenarioCard']];
        $this->assertSame($mine, warRoomUpgradeSavedLayout($mine));
    }
}
