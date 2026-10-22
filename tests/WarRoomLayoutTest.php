<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/war-room-layout.php';

/**
 * The admin console's card layout (v3.397.0): what the default order is, which
 * cards an admin who is not on the roster never gets, and how a layout saved
 * under the old default is brought forward.
 *
 * What is pinned, and why:
 *  - the sidebar leads with the two alert cards, and the six request cards sit
 *    in it, not in the left column - that pair of moves is what keeps the two
 *    columns the same length once the "me" cards are gone;
 *  - every card in the layout has a label (the show/hide checklist falls back
 *    to the raw id otherwise) and the save endpoint's whitelist knows it;
 *  - the "me" cards are dropped for an admin who is not a participant and
 *    nothing else is, so a card added later is not silently swallowed;
 *  - the saved-layout upgrade runs once, leaves a deliberate arrangement alone
 *    and, being a function of the row alone, can run on every read until the
 *    user's next save stamps the version.
 */
final class WarRoomLayoutTest extends TestCase
{
    private const REQUESTS = [
        'requestLocationCard', 'requestPhotoCard', 'requestVideoCard',
        'requestLiveCard', 'requestTaskCard', 'requestSpeakCard',
    ];

    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }

    public function testDefaultLayoutHasNoDuplicatesAndEveryCardIsLabelled(): void
    {
        $ids = warRoomAllCardIds();
        $this->assertSame(count($ids), count(array_unique($ids)), 'a card is listed in two places');
        $this->assertSame(44, count($ids));
        $labelled = array_keys(warRoomCardLabels());
        sort($ids);
        sort($labelled);
        $this->assertSame($ids, $labelled, 'the show/hide checklist needs a label for every card');
    }

    public function testSidebarLeadsWithAlertsAndHoldsTheRequestCards(): void
    {
        $layout = warRoomDefaultLayout();
        $this->assertSame(['sosAlertsCard', 'voiceMessagesCard'], array_slice($layout['sidebar'], 0, 2));
        foreach (self::REQUESTS as $id) {
            $this->assertContains($id, $layout['sidebar']);
            $this->assertNotContains($id, $layout['main']);
        }
        // Used once per mission: last, not between the broadcast and dispatch forms.
        $this->assertSame('endMissionCard', end($layout['sidebar']));
    }

    public function testPersonalCardsAreDroppedOnlyWhenAskedTo(): void
    {
        $personal = warRoomPersonalCardIds();
        $all = warRoomRenderedCardIds(false, true, true, true, true, true);
        $without = warRoomRenderedCardIds(false, true, true, true, true, true, false);

        $everything = array_merge($all['main'], $all['sidebar']);
        $remaining = array_merge($without['main'], $without['sidebar']);
        foreach ($personal as $id) {
            $this->assertContains($id, $everything);
            $this->assertNotContains($id, $remaining);
        }
        $this->assertEqualsCanonicalizing($personal, array_values(array_diff($everything, $remaining)));
        // The cards a command console runs on are all still there.
        foreach (['mapCard', 'teamsCard', 'sosAlertsCard', 'dispatchCard', 'sectorsCard', 'routeOrderCard', 'chatCard'] as $id) {
            $this->assertContains($id, $remaining);
        }
    }

    public function testUpgradeMovesSosAndTheRequestCardsOnce(): void
    {
        $saved = [
            'main' => ['mapCard', 'teamsCard', 'requestLocationCard', 'requestPhotoCard', 'activityCard', 'requestSpeakCard', 'chatCard'],
            'sidebar' => ['myLocationCard', 'mediaCard', 'sosAlertsCard', 'broadcastCard', 'dispatchCard', 'sectorsCard', 'missionMgmtCard'],
            'hidden' => ['weatherCard'],
            'half' => ['teamsCard'],
        ];
        $up = warRoomUpgradeSavedLayout($saved);

        $this->assertSame(['sosAlertsCard', 'voiceMessagesCard', 'myLocationCard', 'mediaCard'], array_slice($up['sidebar'], 0, 4));
        $this->assertSame(['mapCard', 'teamsCard', 'activityCard', 'chatCard'], $up['main']);
        // After the last order form in the sidebar, in the order they had in the left column.
        $this->assertSame(
            ['broadcastCard', 'dispatchCard', 'sectorsCard', 'requestLocationCard', 'requestPhotoCard', 'requestSpeakCard', 'missionMgmtCard'],
            array_slice($up['sidebar'], 4)
        );
        $this->assertSame($saved['hidden'], $up['hidden']);
        $this->assertSame($saved['half'], $up['half']);
        $this->assertSame(
            count(array_merge($up['main'], $up['sidebar'])),
            count(array_unique(array_merge($up['main'], $up['sidebar']))),
            'no card may end up in both columns'
        );
    }

    public function testUpgradeLeavesADeliberateArrangementAlone(): void
    {
        // SOS dragged into the left column, one request card already in the sidebar.
        $saved = [
            'main' => ['mapCard', 'sosAlertsCard', 'chatCard'],
            'sidebar' => ['mediaCard', 'requestTaskCard', 'broadcastCard'],
        ];
        $up = warRoomUpgradeSavedLayout($saved);
        $this->assertSame(['mapCard', 'sosAlertsCard', 'chatCard'], $up['main']);
        $this->assertSame(['mediaCard', 'requestTaskCard', 'broadcastCard'], $up['sidebar']);
    }

    public function testUpgradeAppendsTheRequestCardsWhenThereIsNoOrderFormToFollow(): void
    {
        $up = warRoomUpgradeSavedLayout([
            'main' => ['mapCard', 'requestPhotoCard'],
            'sidebar' => ['mediaCard', 'shiftsCard'],
        ]);
        $this->assertSame(['mediaCard', 'shiftsCard', 'requestPhotoCard'], $up['sidebar']);
        $this->assertSame(['mapCard'], $up['main']);
    }

    public function testAnUpToDateLayoutIsNotTouched(): void
    {
        $saved = [
            'v' => WAR_ROOM_LAYOUT_VERSION,
            'main' => ['mapCard', 'sosAlertsCard', 'requestPhotoCard'],
            'sidebar' => ['mediaCard'],
        ];
        $this->assertSame($saved, warRoomUpgradeSavedLayout($saved));
    }

    public function testASavedLayoutIsStampedAndComesBackUpgradedOnlyUntilThen(): void
    {
        $userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Layout Tester', 'layout-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $old = ['main' => ['mapCard', 'requestPhotoCard', 'chatCard'], 'sidebar' => ['mediaCard', 'sosAlertsCard', 'dispatchCard'], 'hidden' => [], 'half' => []];
        dbExecute("INSERT INTO war_room_layouts (user_id, layout_json) VALUES (?, ?)", [$userId, json_encode($old)]);

        $read = getWarRoomLayoutForUser($userId, false, true, false, false, false, false);
        $this->assertSame(['sosAlertsCard', 'voiceMessagesCard'], array_slice($read['sidebar'], 0, 2));
        $this->assertContains('requestPhotoCard', $read['sidebar']);
        $this->assertNotContains('requestPhotoCard', $read['main']);

        // The user arranges it their way: the row is stamped and is theirs from then on.
        saveWarRoomLayoutForUser($userId, [
            'main' => ['mapCard', 'requestPhotoCard', 'chatCard'],
            'sidebar' => ['mediaCard', 'sosAlertsCard', 'voiceMessagesCard', 'dispatchCard'],
            'hidden' => [], 'half' => [],
        ]);
        $stored = json_decode((string) dbFetchValue("SELECT layout_json FROM war_room_layouts WHERE user_id = ?", [$userId]), true);
        $this->assertSame(WAR_ROOM_LAYOUT_VERSION, $stored['v']);

        $again = getWarRoomLayoutForUser($userId, false, true, false, false, false, false);
        $this->assertContains('requestPhotoCard', $again['main']);
        // Their order is kept (a card they never placed may be slotted in around it).
        $this->assertSame(
            ['mediaCard', 'sosAlertsCard', 'voiceMessagesCard', 'dispatchCard'],
            array_values(array_intersect($again['sidebar'], ['mediaCard', 'sosAlertsCard', 'voiceMessagesCard', 'dispatchCard']))
        );
    }
}
