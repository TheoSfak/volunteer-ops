<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Scenario, as a printable briefing (v3.467.0): mission-scenario-print.php and scenarioPrintItemLine() in
 * includes/functions-scenario.php, the server-side twin of assets/js/war-room-scenario.js's
 * itemKind()/itemTarget()/itemTitle()/stateLine() for a page that has no script.
 *
 * The things that must hold: the same gate as the Scenario page (canManageActionRoom() AND isSystemAdmin()), but
 * NOT its extra STATUS_OPEN/show_in_ops requirement - printing reads nothing live and sends nothing, so a closed
 * mission's plan stays printable; the page has no POST handler of its own; and scenarioPrintItemLine() describes
 * every kind of staged order the way the live card does, just as plain text.
 *
 * The item-describing tests need no fixture of their own: scenarioPrintItemLine() is a pure function of the
 * array loadMissionScenario() already hands it, so a hand-built item array is enough - no transaction needed.
 */
final class ScenarioPrintTest extends TestCase
{
    private function source(string $rel): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $rel));
    }

    private function item(array $over = []): array
    {
        return $over + [
            'type' => 'point', 'label' => null, 'order_type' => null,
            'audience' => 'team', 'team_label' => 'Alpha 1', 'slot_label' => null,
            'sent' => false, 'state' => null, 'error' => null, 'done_n' => null, 'of_n' => null, 'wait' => null,
        ];
    }

    // ── The gate ───────────────────────────────────────────────────────────────────────────────────────────────

    public function testThePageIsGatedBeforeAnythingIsPrintedAndDoesNotRequireAnOpenMission(): void
    {
        $page = $this->source('mission-scenario-print.php');
        $login = strpos($page, 'requireLogin();');
        $mission = strpos($page, 'FROM missions m');
        $gate = strpos($page, 'canManageActionRoom(');
        $header = strpos($page, '<!DOCTYPE html>');
        foreach (['login' => $login, 'mission' => $mission, 'gate' => $gate, 'header' => $header] as $name => $at) {
            $this->assertNotFalse($at, $name);
        }
        $this->assertTrue($login < $mission && $mission < $gate && $gate < $header, 'login, mission, the gate, then markup');
        $refuse = substr($page, $gate, 330);
        $this->assertStringContainsString("t('scenario.no_permission')", $refuse);
        $this->assertStringContainsString("redirect('mission-view.php?id=' . \$missionId)", $refuse);
        // Unlike the view page, no open-mission gate: a closed mission's plan stays printable.
        $this->assertStringNotContainsString('show_in_ops', $page);
        $this->assertStringNotContainsString('STATUS_OPEN', $page);
    }

    public function testThePageUsesTheSameAccountGateAsTheViewPage(): void
    {
        $page = $this->source('mission-scenario-print.php');
        $view = $this->source('mission-scenario-view.php');
        $needle = "canManageActionRoom(\$mission['responsible_user_id'] ? (int) \$mission['responsible_user_id'] : null,";
        $this->assertStringContainsString($needle, $page);
        $this->assertStringContainsString($needle, $view);
        $this->assertStringContainsString('!isSystemAdmin()', $page);
    }

    public function testThePageHasNoPostHandlerAndWritesNothing(): void
    {
        $page = $this->source('mission-scenario-print.php');
        $this->assertStringNotContainsString('isPost()', $page);
        $this->assertStringNotContainsString('INSERT', $page);
        $this->assertStringNotContainsString('UPDATE', $page);
        $this->assertStringContainsString('loadMissionScenario(', $page, 'a plain read, unlike the view page which holds no plan at all');
    }

    public function testThePageUsesWindowPrintNotAServerSidePdfLibrary(): void
    {
        $page = $this->source('mission-scenario-print.php');
        $this->assertStringContainsString('window.print()', $page);
        $this->assertStringContainsString("require __DIR__ . '/includes/print-close-script.php';", $page);
    }

    public function testTheButtonOnTheViewPageOpensItInATabNamedForTheMission(): void
    {
        $view = $this->source('mission-scenario-view.php');
        $this->assertStringContainsString('href="mission-scenario-print.php?id=<?= $missionId ?>"', $view);
        $this->assertStringContainsString('target="wrScenarioPrint<?= $missionId ?>"', $view);
        $this->assertStringContainsString("t('scenario.print_hero_btn')", $view);
    }

    // ── scenarioPrintItemLine() ───────────────────────────────────────────────────────────────────────────────

    public function testAPointToARealTeamUnsent(): void
    {
        $line = scenarioPrintItemLine($this->item(['label' => 'Ρέμα']));
        $this->assertSame(t('scenario.kind_point') . ' «Ρέμα»', $line['title']);
        $this->assertSame('Alpha 1', $line['target']);
        $this->assertSame(t('scenario.print_not_sent'), $line['state']);
    }

    public function testAnUnsentItemThatFailedShowsWhy(): void
    {
        $line = scenarioPrintItemLine($this->item(['error' => 'Η ομάδα δεν υπάρχει πια.']));
        $this->assertSame(t('common.send_failed') . ': Η ομάδα δεν υπάρχει πια.', $line['state']);
    }

    public function testADispatchToEveryoneSentAndUnderWaySaysHowManyTeams(): void
    {
        $line = scenarioPrintItemLine($this->item(['audience' => 'all', 'team_label' => null, 'sent' => true, 'state' => 'open', 'done_n' => 2, 'of_n' => 3]));
        $this->assertSame(t('common.all_teams'), $line['target']);
        $this->assertSame(t('scenario.state_open_broadcast', ['done' => 2, 'of' => 3]), $line['state']);
    }

    public function testASingleTeamOrderUnderWayWithNoCountIsPlainlyOpen(): void
    {
        $line = scenarioPrintItemLine($this->item(['sent' => true, 'state' => 'open']));
        $this->assertSame(t('scenario.state_open'), $line['state']);
    }

    public function testASectorDoneAndOneWithdrawn(): void
    {
        $done = scenarioPrintItemLine($this->item(['type' => 'sector', 'label' => 'Τομέας 1', 'sent' => true, 'state' => 'done']));
        $this->assertSame(t('scenario.kind_sector') . ' «Τομέας 1»', $done['title']);
        $this->assertSame(t('scenario.state_done'), $done['state']);

        $gone = scenarioPrintItemLine($this->item(['type' => 'sector', 'sent' => true, 'state' => 'withdrawn']));
        $this->assertSame(t('scenario.state_withdrawn'), $gone['state']);
    }

    public function testAZoneAnAreaAndABuildingGoToEveryoneAndStandOnceMade(): void
    {
        foreach (['restricted' => 'scenario.kind_restricted', 'search_area' => 'scenario.kind_search_area', 'building' => 'scenario.kind_building'] as $type => $kindKey) {
            $line = scenarioPrintItemLine($this->item(['type' => $type, 'audience' => 'none', 'team_label' => null, 'sent' => true, 'state' => 'standing']));
            $this->assertSame(t($kindKey), $line['title'], $type);
            $this->assertSame(t('scenario.target_everyone'), $line['target'], $type);
            $this->assertSame(t('scenario.state_standing'), $line['state'], $type);
        }
    }

    public function testAnOrderNamesItsOwnKindAndGoesToASlotOrEveryoneOnDuty(): void
    {
        $task = scenarioPrintItemLine($this->item(['type' => 'order', 'order_type' => 'task', 'label' => 'Μέτρησε αποθέματα', 'audience' => 'slot', 'team_label' => null, 'slot_label' => 'Ομάδα Α']));
        $this->assertSame(t('scenario.order_type_task') . ' «Μέτρησε αποθέματα»', $task['title']);
        $this->assertSame('Ομάδα Α', $task['target'], 'the slot stands for no team yet');

        $mapped = scenarioPrintItemLine($this->item(['type' => 'order', 'order_type' => 'speak', 'audience' => 'slot', 'team_label' => 'Beta 2', 'slot_label' => 'Ομάδα Α']));
        $this->assertSame('Ομάδα Α → Beta 2', $mapped['target']);

        $all = scenarioPrintItemLine($this->item(['type' => 'order', 'order_type' => 'message', 'audience' => 'all', 'team_label' => null]));
        $this->assertSame(t('scenario.target_all_people'), $all['target']);

        $ack = scenarioPrintItemLine($this->item(['type' => 'order', 'order_type' => 'message', 'sent' => true, 'state' => 'done']));
        $this->assertSame(t('scenario.state_done_ack'), $ack['state'], 'a message is finished by being acknowledged');
    }

    public function testAnOrderToATeamThatHasNotSeenItIsWaitingWithWhatTheTeamStillOwes(): void
    {
        $line = scenarioPrintItemLine($this->item([
            'sent' => true, 'state' => 'waiting',
            'wait' => ['phase_n' => 1, 'done' => 2, 'of' => 3, 'cant' => false, 'team_label' => 'Alpha 1'],
        ]));
        $this->assertSame(t('scenario.state_waiting', ['team' => 'Alpha 1', 'phase' => t('scenario.phase_n', ['n' => 1]), 'done' => 2, 'of' => 3]), $line['state']);
    }

    public function testATeamThatSaidCannotIsNamedAndACancelledWaitIsSaidPlainly(): void
    {
        $cant = scenarioPrintItemLine($this->item([
            'sent' => true, 'state' => 'waiting',
            'wait' => ['phase_n' => 1, 'done' => 0, 'of' => 2, 'cant' => true, 'team_label' => 'Alpha 1'],
        ]));
        $this->assertSame(t('scenario.state_waiting_cant', ['team' => 'Alpha 1', 'phase' => t('scenario.phase_n', ['n' => 1]), 'done' => 0, 'of' => 2]), $cant['state']);

        $cancelled = scenarioPrintItemLine($this->item(['sent' => true, 'state' => 'cancelled']));
        $this->assertSame(t('scenario.state_cancelled'), $cancelled['state']);

        $split = scenarioPrintItemLine($this->item(['sent' => true, 'state' => 'expanded']));
        $this->assertSame(t('scenario.state_split'), $split['state']);
    }

    public function testALongOrderIsClippedTheSameWayTheLiveCardClipsIt(): void
    {
        $long = str_repeat('α', 90);
        $line = scenarioPrintItemLine($this->item(['type' => 'order', 'order_type' => 'task', 'label' => $long]));
        $this->assertSame(t('scenario.order_type_task') . ' «' . mb_substr($long, 0, 57) . '…»', $line['title']);
    }
}
