<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * assembleMissionAssistantItems() — the judgement layer of the Action Room's
 * «Τι μου ξέφυγε» panel (includes/functions-warroom-assistant.php).
 *
 * Why this is worth pinning: every rule here is a decision about what a
 * coordinator is shown mid-operation and what is silently left out, and every
 * one of them is a judgement call rather than a fact. Getting a threshold
 * wrong in either direction breaks the feature in a way nobody reports —
 * too eager and the badge is permanently red so nobody reads it, too quiet
 * and the one thing that mattered never appeared.
 *
 * The function is deliberately pure: no database, no clock of its own, no
 * settings lookup (the ping threshold arrives as an argument). Everything
 * below is a hand-written fixture.
 */
final class AssistantMissedItemsTest extends TestCase
{
    private const NOW = 1700000000;
    private const STALE = 540; // 3 × a 180s auto-ping, i.e. the shipped default

    /** Minutes before NOW, as an epoch. */
    private static function minsAgo(int $m): int
    {
        return self::NOW - $m * 60;
    }

    private static function assemble(array $raw, ?int $checkpointTs = null): array
    {
        return assembleMissionAssistantItems($raw, $checkpointTs, self::NOW, 'el', self::STALE);
    }

    /** Every kind present in a result, for order assertions. */
    private static function kinds(array $items): array
    {
        return array_column($items, 'kind');
    }

    // ── Shortages ───────────────────────────────────────────────────────────

    public function testAShortageNobodyAcknowledgedIsRaisedImmediately(): void
    {
        $out = self::assemble(['shortages' => [[
            'id' => 1, 'severity' => 'medium', 'title' => 'Λείπουν φακοί',
            'ts' => self::minsAgo(2), 'ack_ts' => null, 'who' => 'Νίκος',
            'codename' => 'Αετός', 'team_number' => 1,
        ]]]);

        $this->assertCount(1, $out['pending']);
        $this->assertSame('high', $out['pending'][0]['sev']);
    }

    public function testAShortageThatWasSeenMinutesAgoIsNotSomethingYouMissed(): void
    {
        // Acknowledged and only 5 minutes old: somebody is on it. Listing this
        // is how a panel teaches people to ignore it.
        $out = self::assemble(['shortages' => [[
            'id' => 1, 'severity' => 'high', 'title' => 'Λείπουν φακοί',
            'ts' => self::minsAgo(5), 'ack_ts' => self::minsAgo(4), 'who' => 'Νίκος',
            'codename' => 'Αετός', 'team_number' => 1,
        ]]]);

        $this->assertSame([], $out['pending']);
        $this->assertSame(0, $out['counts']['total']);
    }

    public function testAnAcknowledgedShortageComesBackOnceItHasSatLongEnough(): void
    {
        $out = self::assemble(['shortages' => [[
            'id' => 1, 'severity' => 'high', 'title' => 'Λείπουν φακοί',
            'ts' => self::minsAgo(45), 'ack_ts' => self::minsAgo(44), 'who' => 'Νίκος',
            'codename' => 'Αετός', 'team_number' => 1,
        ]]]);

        $this->assertCount(1, $out['pending']);
        $this->assertSame('warn', $out['pending'][0]['sev'], 'seen-but-unresolved is a nudge, not an alarm');
    }

    public function testAcknowledgementStepsUrgencyDownRatherThanFlatteningIt(): void
    {
        // A critical casualty that somebody has picked up is less urgent than
        // one nobody has seen — but it must not land in the same colour as a
        // glanced-at low-priority kit shortage. The panel's value is that its
        // shape can be read before its words.
        $incident = fn(string $severity) => ['incidents' => [[
            'id' => 1, 'severity' => $severity, 'incident_type' => 'trauma',
            'ts' => self::minsAgo(120), 'ack_ts' => self::minsAgo(119), 'who' => 'Μανώλης',
            'codename' => 'ΑΛΦΑ', 'team_number' => 1,
        ]]];

        $this->assertSame('high', self::assemble($incident('critical'))['pending'][0]['sev']);
        $this->assertSame('warn', self::assemble($incident('high'))['pending'][0]['sev']);
        $this->assertSame('warn', self::assemble($incident('low'))['pending'][0]['sev']);
    }

    public function testACriticalShortageWithNoAcknowledgementOutranksAnOrdinaryOne(): void
    {
        $out = self::assemble(['shortages' => [[
            'id' => 1, 'severity' => 'critical', 'title' => 'Τελείωσε το νερό',
            'ts' => self::minsAgo(3), 'ack_ts' => null, 'who' => 'Νίκος',
            'codename' => 'Αετός', 'team_number' => 1,
        ]]]);

        $this->assertSame('critical', $out['pending'][0]['sev']);
        $this->assertSame('critical', $out['counts']['worst']);
    }

    // ── Orders ──────────────────────────────────────────────────────────────

    public function testAnUnacknowledgedOrderHardensFromWarnToHighWithAge(): void
    {
        $order = fn(int $mins) => ['orders' => [[
            'id' => 7, 'order_type' => 'task', 'task_text' => 'Σαρώστε το ρέμα',
            'ts' => self::minsAgo($mins), 'total' => 4, 'acked' => 1,
        ]]];

        $this->assertSame('warn', self::assemble($order(12))['pending'][0]['sev']);
        $this->assertSame('high', self::assemble($order(45))['pending'][0]['sev']);
    }

    public function testAnOrderYouSentIsNeverMarkedAsNewsToYou(): void
    {
        // Created well after the checkpoint, but you are the one who sent it.
        $out = self::assemble(
            ['orders' => [[
                'id' => 7, 'order_type' => 'task', 'task_text' => '',
                'ts' => self::minsAgo(12), 'total' => 3, 'acked' => 0,
            ]]],
            self::minsAgo(60)
        );

        $this->assertFalse($out['pending'][0]['is_new']);
    }

    // ── Silence from the field ──────────────────────────────────────────────

    public function testSomeoneWhoJustCameOnDutyIsNotAccusedOfSilence(): void
    {
        // Five minutes into a shift, half the roster is still walking to the
        // vehicle. Flagging that is the fastest way to make the panel noise.
        $out = self::assemble(['silent' => [[
            'id' => 3, 'who' => 'Μαρία', 'last_ping_ts' => null,
            'on_duty_since' => self::minsAgo(5), 'codename' => 'Λέων', 'team_number' => 2,
        ]]]);

        $this->assertSame([], $out['pending']);
    }

    public function testSomeoneOnDutyWhoHasNeverSentAPositionIsEventuallyRaised(): void
    {
        $out = self::assemble(['silent' => [[
            'id' => 3, 'who' => 'Μαρία', 'last_ping_ts' => null,
            'on_duty_since' => self::minsAgo(40), 'codename' => 'Λέων', 'team_number' => 2,
        ]]]);

        $this->assertCount(1, $out['pending']);
        $this->assertSame('silent', $out['pending'][0]['kind']);
    }

    public function testOnePersonIsOneRowEvenWhenTheQueryReturnsThemTwice(): void
    {
        // Reported from a live console: the same name eight times over. The
        // cause was a join across mission_team_members without a mission
        // filter, and a volunteer legitimately holds two overlapping approved
        // shifts on a long operation. Whatever produces the duplicates, the
        // coordinator must see one line per person.
        $person = fn(?int $ping) => [
            'id' => 3, 'who' => 'Θεόδωρος', 'last_ping_ts' => $ping,
            'on_duty_since' => self::minsAgo(300), 'codename' => null, 'team_number' => null,
        ];
        $out = self::assemble(['silent' => [
            $person(self::NOW - self::STALE * 4),
            $person(self::NOW - self::STALE * 4),
            $person(self::NOW - self::STALE * 4),
        ]]);

        $this->assertCount(1, $out['pending']);
        $this->assertSame(1, $out['counts']['pending'], 'the badge must not count them either');
    }

    public function testTheFreshestFixWinsWhenAPersonHasTwoShifts(): void
    {
        // A morning shift running late and an afternoon shift already begun.
        // Judged per row, the stale morning ping would report someone as
        // silent while their current shift is pinging normally.
        $person = fn(int $ping) => [
            'id' => 3, 'who' => 'Θεόδωρος', 'last_ping_ts' => $ping,
            'on_duty_since' => self::minsAgo(300), 'codename' => null, 'team_number' => null,
        ];
        $out = self::assemble(['silent' => [
            $person(self::NOW - self::STALE * 5),  // the old shift's last fix
            $person(self::NOW - 30),               // pinging right now
        ]]);

        $this->assertSame([], $out['pending'], 'somebody currently pinging is not silent');
    }

    public function testAFreshPingIsNotSilenceAndALongOneIsEscalated(): void
    {
        $silent = fn(int $secondsAgo) => ['silent' => [[
            'id' => 3, 'who' => 'Μαρία', 'last_ping_ts' => self::NOW - $secondsAgo,
            'on_duty_since' => self::minsAgo(120), 'codename' => 'Λέων', 'team_number' => 2,
        ]]];

        $this->assertSame([], self::assemble($silent(self::STALE - 60))['pending']);
        $this->assertSame('warn', self::assemble($silent(self::STALE + 60))['pending'][0]['sev']);
        $this->assertSame('high', self::assemble($silent(self::STALE * 3 + 60))['pending'][0]['sev']);
    }

    // ── Chat ────────────────────────────────────────────────────────────────

    public function testGreekQuestionsAreCountedAndLiftTheRoomAboveChatter(): void
    {
        // Greek's question mark is the semicolon. A room where somebody asked
        // something and nobody answered is the whole point of this feature;
        // a room where three people said "ελήφθη" is not.
        $chatter = self::assemble(['chat' => [
            ['team_id' => null, 'who' => 'Νίκος', 'message' => 'Ελήφθη', 'ts' => self::minsAgo(4), 'codename' => null, 'team_number' => null],
        ]]);
        $this->assertSame('info', $chatter['new'][0]['sev']);

        $question = self::assemble(['chat' => [
            ['team_id' => null, 'who' => 'Νίκος', 'message' => 'Ελήφθη', 'ts' => self::minsAgo(4), 'codename' => null, 'team_number' => null],
            ['team_id' => null, 'who' => 'Μαρία', 'message' => 'Να συνεχίσουμε βόρεια;', 'ts' => self::minsAgo(2), 'codename' => null, 'team_number' => null],
        ]]);
        $this->assertSame('warn', $question['new'][0]['sev']);
        $this->assertStringContainsString('2', $question['new'][0]['title']);
    }

    public function testCountedPhrasesUseTheSingularWhenThereIsExactlyOne(): void
    {
        // Greek inflects noun, adjective and verb with the number, so a naive
        // "{n} νέα μηνύματα" prints "1 νέα μηνύματα" — the line that makes a
        // carefully built console look machine-translated.
        $one = self::assemble(['chat' => [
            ['team_id' => null, 'who' => 'Νίκος', 'message' => 'Να συνεχίσουμε;', 'ts' => self::minsAgo(2), 'codename' => null, 'team_number' => null],
        ]]);
        $this->assertStringContainsString('1 νέο μήνυμα', $one['new'][0]['title']);
        $this->assertStringContainsString('1 πιθανή ερώτηση', $one['new'][0]['title']);
        $this->assertStringNotContainsString('μηνύματα', $one['new'][0]['title']);
        $this->assertStringNotContainsString('ερωτήσεις', $one['new'][0]['title']);

        $many = self::assemble(['chat' => [
            ['team_id' => null, 'who' => 'Νίκος', 'message' => 'Να συνεχίσουμε;', 'ts' => self::minsAgo(3), 'codename' => null, 'team_number' => null],
            ['team_id' => null, 'who' => 'Μαρία', 'message' => 'Πού είστε;', 'ts' => self::minsAgo(2), 'codename' => null, 'team_number' => null],
        ]]);
        $this->assertStringContainsString('2 νέα μηνύματα', $many['new'][0]['title']);
        $this->assertStringContainsString('2 πιθανές ερωτήσεις', $many['new'][0]['title']);

        // One recipient outstanding reads "δεν έχει", not "δεν έχουν".
        $order = self::assemble(['orders' => [[
            'id' => 7, 'order_type' => 'task', 'task_text' => '',
            'ts' => self::minsAgo(20), 'total' => 3, 'acked' => 2,
        ]]]);
        $this->assertStringContainsString('1 από 3 δεν έχει απαντήσει', $order['pending'][0]['title']);
    }

    public function testEveryAssistantStringExistsInBothLanguages(): void
    {
        // A missing key renders as the raw key on a live console. The two
        // blocks are hundreds of lines apart, so nothing but this notices.
        $strings = loadLangStrings('war-room');
        $pick = fn(array $a) => array_keys(array_filter(
            $a, fn($k) => str_starts_with($k, 'assistant.'), ARRAY_FILTER_USE_KEY
        ));
        $el = $pick($strings['el']);
        $en = $pick($strings['en']);

        $this->assertSame([], array_values(array_diff($el, $en)), 'Greek keys with no English twin');
        $this->assertSame([], array_values(array_diff($en, $el)), 'English keys with no Greek twin');
        $this->assertNotEmpty($el);
    }

    public function testAsciiAndGreekQuestionMarksBothCount(): void
    {
        $this->assertTrue(assistantLooksLikeQuestion('Where are you?'));
        $this->assertTrue(assistantLooksLikeQuestion('Πού είστε;'));
        $this->assertTrue(assistantLooksLikeQuestion("Πού είστε\u{037E}"));
        $this->assertTrue(assistantLooksLikeQuestion('Πού είστε;   '), 'trailing spaces must not hide it');
        $this->assertFalse(assistantLooksLikeQuestion('Ελήφθη.'));
        $this->assertFalse(assistantLooksLikeQuestion('   '));
    }

    public function testEachChatRoomGetsItsOwnRowWithItsOwnLastLine(): void
    {
        $out = self::assemble(['chat' => [
            ['team_id' => null, 'who' => 'Νίκος', 'message' => 'πρώτο', 'ts' => self::minsAgo(9), 'codename' => null, 'team_number' => null],
            ['team_id' => 4, 'who' => 'Μαρία', 'message' => 'άλφα', 'ts' => self::minsAgo(8), 'codename' => 'Αετός', 'team_number' => 1],
            ['team_id' => 4, 'who' => 'Κώστας', 'message' => 'βήτα', 'ts' => self::minsAgo(1), 'codename' => 'Αετός', 'team_number' => 1],
        ]]);

        $this->assertCount(2, $out['new']);
        $teamRow = array_values(array_filter($out['new'], fn($i) => str_contains($i['title'], 'Αετός')))[0];
        $this->assertStringContainsString('βήτα', $teamRow['detail'], 'the LAST line of the room, not the first');
        $this->assertStringContainsString('Κώστας', $teamRow['detail']);
    }

    // ── What «Το είδα» does and does not dismiss ───────────────────────────

    public function testMarkingSeenDismissesAdvisoriesButNeverObligations(): void
    {
        // Reported from a live console: "you press the blue button and the
        // message does not go away". It was right about silence — a
        // coordinator who has already raised that crew on the radio has dealt
        // with it, and a button that visibly does nothing looks broken. It
        // must still be wrong about an unanswered SOS.
        $raw = [
            'silent' => [['id' => 3, 'who' => 'Θεόδωρος', 'last_ping_ts' => self::NOW - self::STALE * 4,
                          'on_duty_since' => self::minsAgo(300), 'codename' => null, 'team_number' => null]],
            'poi'    => [['id' => 1, 'ts' => self::minsAgo(90), 'photos' => 2]],
            'sos'    => [['id' => 2, 'ts' => self::minsAgo(20), 'ack_ts' => null, 'who' => 'Μαρία',
                          'codename' => null, 'team_number' => null]],
            'orders' => [['id' => 7, 'order_type' => 'task', 'task_text' => '',
                          'ts' => self::minsAgo(40), 'total' => 3, 'acked' => 0]],
        ];

        $before = self::kinds(self::assemble($raw)['pending']);
        $this->assertContains('silent', $before);
        $this->assertContains('poi', $before);

        // Now they press the button.
        $after = self::kinds(self::assemble($raw, self::NOW - 10)['pending']);
        $this->assertNotContains('silent', $after, 'silence is advisory and clears');
        $this->assertNotContains('poi', $after, 'an unchecked clue is advisory and clears');
        $this->assertContains('sos', $after, 'an unanswered SOS is an obligation and stays');
        $this->assertContains('order', $after, 'an unconfirmed order is an obligation and stays');
    }

    public function testSilenceComesBackWhenSomebodyPingsAndGoesQuietAgain(): void
    {
        // Dismissal must not be permanent, and it needs no stored state: the
        // item's timestamp is that person's last fix, so a newer fix followed
        // by fresh silence is newer than the checkpoint.
        $checkpoint = self::minsAgo(60);
        $raw = ['silent' => [[
            'id' => 3, 'who' => 'Θεόδωρος',
            'last_ping_ts' => self::minsAgo(30),          // pinged AFTER the checkpoint
            'on_duty_since' => self::minsAgo(300), 'codename' => null, 'team_number' => null,
        ]]];

        $out = self::assemble($raw, $checkpoint);
        $this->assertSame(['silent'], self::kinds($out['pending']));
    }

    // ── Identical rows, and orders with no recorded type ───────────────────

    public function testIdenticalRowsCollapseWhileTheBadgeStillCountsThemAll(): void
    {
        // A real mission had ten separate orders with identical text awaiting
        // acknowledgement: ten genuine obligations, and ten identical lines a
        // coordinator has to read past. The list must say it once with a
        // count; the badge must still say ten, or the panel would be hiding
        // work.
        $orders = [];
        for ($i = 0; $i < 10; $i++) {
            $orders[] = ['id' => 100 + $i, 'order_type' => 'task', 'task_text' => 'Σάρωση βόρειας κοίτης',
                         'ts' => self::minsAgo(60 + $i), 'total' => 4, 'acked' => 0];
        }
        $out = self::assemble(['orders' => $orders]);

        $this->assertCount(1, $out['pending'], 'ten identical lines become one row');
        $this->assertSame(10, $out['pending'][0]['count']);
        $this->assertSame(10, $out['counts']['pending'], 'the badge must not shrink');
        // The survivor is as neglected as its oldest member.
        $this->assertSame(self::minsAgo(69), $out['pending'][0]['ts']);
    }

    public function testOrdersThatDifferAreNotMergedTogether(): void
    {
        // Two orders where a different number of people have answered are two
        // different facts; merging them would erase the one that matters.
        $out = self::assemble(['orders' => [
            ['id' => 1, 'order_type' => 'task', 'task_text' => 'Α', 'ts' => self::minsAgo(40), 'total' => 4, 'acked' => 0],
            ['id' => 2, 'order_type' => 'task', 'task_text' => 'Α', 'ts' => self::minsAgo(40), 'total' => 4, 'acked' => 3],
        ]]);

        $this->assertCount(2, $out['pending']);
    }

    public function testAnOrderWithNoRecordedTypeIsNamedNotKeyed(): void
    {
        // mission_orders.order_type really does hold an empty string in
        // production data, and t() falls back to its own key — which printed
        // "report.type_ — 4 από 4 δεν έχουν απαντήσει" on a real panel.
        $out = self::assemble(['orders' => [[
            'id' => 1, 'order_type' => '', 'task_text' => 'Σάρωση',
            'ts' => self::minsAgo(40), 'total' => 4, 'acked' => 0,
        ]]]);

        $this->assertStringNotContainsString('report.type_', $out['pending'][0]['title']);
        $this->assertStringContainsString('Εντολή', $out['pending'][0]['title']);
    }

    public function testAnUnknownOrderTypeAlsoFallsBackRatherThanLeaking(): void
    {
        $this->assertStringNotContainsString(
            'report.type_',
            assistantOrderTypeLabel('some_future_type', 'el')
        );
        $this->assertSame('📋 Γενική Εντολή', assistantOrderTypeLabel('task', 'el'));
    }

    // ── The two-section contract ────────────────────────────────────────────

    public function testAnOpenItemStaysInPendingEvenWhenItIsAlsoBrandNew(): void
    {
        // The rule the whole panel rests on: something can be new AND open,
        // and its open-ness is the more important fact. It is listed once, in
        // ΕΚΚΡΕΜΟΥΝ, carrying a NEW flag — never in both sections.
        $out = self::assemble(
            ['shortages' => [[
                'id' => 1, 'severity' => 'medium', 'title' => 'Λείπουν φακοί',
                'ts' => self::minsAgo(2), 'ack_ts' => null, 'who' => 'Νίκος',
                'codename' => 'Αετός', 'team_number' => 1,
            ]]],
            self::minsAgo(30)
        );

        $this->assertCount(1, $out['pending']);
        $this->assertSame([], $out['new']);
        $this->assertTrue($out['pending'][0]['is_new']);
    }

    public function testNothingIsMarkedNewBeforeTheFirstCheckpointExists(): void
    {
        // With no checkpoint there is no "since", so calling anything new
        // would be a guess dressed up as a fact.
        $out = self::assemble(['shortages' => [[
            'id' => 1, 'severity' => 'medium', 'title' => 'Λείπουν φακοί',
            'ts' => self::minsAgo(2), 'ack_ts' => null, 'who' => 'Νίκος',
            'codename' => 'Αετός', 'team_number' => 1,
        ]]]);

        $this->assertTrue($out['is_first']);
        $this->assertFalse($out['pending'][0]['is_new']);
        $this->assertSame(
            assistantWindowStart(null, self::NOW),
            $out['since_ts'],
            'falls back to the default look-back window'
        );
        $this->assertGreaterThanOrEqual(30 * 60, self::NOW - $out['since_ts']);
        $this->assertLessThan(36 * 60, self::NOW - $out['since_ts'], 'snapping must not widen it much');
    }

    public function testTheDefaultWindowIsSnappedSoItDoesNotMoveEverySecond(): void
    {
        // The whole panel rides inside the poll payload whose md5 is the only
        // thing stopping 51KB being re-sent to every open tab every 5s. A
        // "now minus 30 minutes" that ticks every second would silently switch
        // that off for any coordinator who had never pressed «Το είδα» — for
        // their entire operation, with nothing looking wrong.
        $a = assistantWindowStart(null, self::NOW + 7);
        $b = assistantWindowStart(null, self::NOW + 71);
        $this->assertSame($a, $b, 'a minute apart must produce the same window start');
        $this->assertSame(0, $a % 300, 'snapped to five minutes');

        // A real checkpoint is never rounded — it is a fact, not an estimate,
        // and shifting it would replay messages the coordinator already read.
        $this->assertSame(self::NOW - 137, assistantWindowStart(self::NOW - 137, self::NOW));
    }

    // ── Ordering, counting and capping ──────────────────────────────────────

    public function testWorstFirstThenOldestFirstWithinASeverity(): void
    {
        $out = self::assemble([
            'poi' => [['id' => 1, 'ts' => self::minsAgo(90), 'photos' => 2]],
            'sos' => [['id' => 2, 'ts' => self::minsAgo(3), 'ack_ts' => null, 'who' => 'Νίκος', 'codename' => null, 'team_number' => null]],
            // Deliberately different text: identical rows now collapse into
            // one (see testIdenticalRowsCollapseWhileTheBadgeStillCountsThemAll),
            // and this test is about ORDER, so it needs two rows to order.
            'orders' => [
                ['id' => 3, 'order_type' => 'task', 'task_text' => 'Σάρωση νότιας πλαγιάς', 'ts' => self::minsAgo(20), 'total' => 2, 'acked' => 0],
                ['id' => 4, 'order_type' => 'task', 'task_text' => 'Σάρωση βόρειας κοίτης', 'ts' => self::minsAgo(25), 'total' => 2, 'acked' => 0],
            ],
        ]);

        $this->assertSame(['sos', 'order', 'order', 'poi'], self::kinds($out['pending']));
        $this->assertLessThan(
            $out['pending'][2]['ts'],
            $out['pending'][1]['ts'],
            'the order that has been ignored longest is named first'
        );
    }

    public function testTheCountsStayTruthfulWhenTheListIsCapped(): void
    {
        // Fifteen DISTINCT rows — each clue carries a different number of
        // files, so nothing collapses and the cap is what is being tested.
        $poi = [];
        for ($i = 0; $i < 15; $i++) {
            $poi[] = ['id' => $i, 'ts' => self::minsAgo(60 - $i), 'photos' => $i];
        }
        $out = self::assemble(['poi' => $poi]);

        $this->assertCount(12, $out['pending'], 'the panel shows a screenful');
        $this->assertSame(3, $out['pending_more']);
        $this->assertSame(15, $out['counts']['pending'], 'the badge must not lie about how many there are');
        $this->assertSame(15, $out['counts']['total']);
    }

    public function testTheBadgeStillCountsEveryItemWhenManyRowsCollapse(): void
    {
        // The twin of the test above: fifteen IDENTICAL clues print as one
        // line, and the badge must still say fifteen.
        $poi = [];
        for ($i = 0; $i < 15; $i++) {
            $poi[] = ['id' => $i, 'ts' => self::minsAgo(60 - $i), 'photos' => 1];
        }
        $out = self::assemble(['poi' => $poi]);

        $this->assertCount(1, $out['pending']);
        $this->assertSame(15, $out['pending'][0]['count']);
        $this->assertSame(0, $out['pending_more'], 'one row is not more than a screenful');
        $this->assertSame(15, $out['counts']['pending']);
    }

    public function testWorstIsTheHighestSeverityAcrossBothSections(): void
    {
        $out = self::assemble([
            'chat' => [['team_id' => null, 'who' => 'Νίκος', 'message' => 'ok', 'ts' => self::minsAgo(2), 'codename' => null, 'team_number' => null]],
            'sos'  => [['id' => 2, 'ts' => self::minsAgo(3), 'ack_ts' => null, 'who' => 'Μαρία', 'codename' => null, 'team_number' => null]],
        ]);

        $this->assertSame('critical', $out['counts']['worst']);
        $this->assertSame(1, $out['counts']['pending']);
        $this->assertSame(1, $out['counts']['new']);
        $this->assertSame(2, $out['counts']['total']);
    }

    public function testAnEmptyMissionProducesAnHonestlyEmptyPanel(): void
    {
        $out = self::assemble([]);

        $this->assertSame([], $out['pending']);
        $this->assertSame([], $out['new']);
        $this->assertSame(0, $out['counts']['total']);
        $this->assertNull($out['counts']['worst']);
    }

    // ── Labels ──────────────────────────────────────────────────────────────

    public function testSomeoneWithNoTeamSaysSoRatherThanRenderingBlank(): void
    {
        $out = self::assemble(['sos' => [[
            'id' => 2, 'ts' => self::minsAgo(3), 'ack_ts' => null,
            'who' => 'Νίκος', 'codename' => null, 'team_number' => null,
        ]]]);

        $this->assertNotSame('', trim($out['pending'][0]['detail']));
    }

    public function testAnAcknowledgedSosIsStillOpenButNoLongerScreaming(): void
    {
        $out = self::assemble(['sos' => [[
            'id' => 2, 'ts' => self::minsAgo(10), 'ack_ts' => self::minsAgo(9),
            'who' => 'Νίκος', 'codename' => 'Αετός', 'team_number' => 1,
        ]]]);

        $this->assertSame('high', $out['pending'][0]['sev']);
    }
}
