<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * createMissionRoute() (v3.455.0) is the body of mission-route.php's `create`, moved into a function so that
 * something other than the form - a step of a scenario - can send the very same route. These tests pin what the
 * form always got: the checks (and the order they run in), the rows, the order behind the route, the audit entry,
 * who is told, and the answer. The move itself was proved against the REAL endpoint, in-process, before and after
 * (28 requests, answer and every row written byte-identical); these tests keep it that way.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class CreateMissionRouteTest extends TestCase
{
    private int $missionId;
    private int $otherMissionId;
    private int $adminId;
    private int $teamId;
    private int $shiftId;
    /** @var int[] Two volunteers on $teamId, both on the Action Room. */
    private array $members = [];
    /** On $teamId and approved on the mission, but not on the Action Room (their phone is not in the operation). */
    private int $offDuty;
    /** A volunteer on a second team, on the Action Room. */
    private int $secondTeamId;
    private int $secondTeamMember;
    /** A volunteer on no team, approved and on the Action Room. */
    private int $bystander;
    private array $mission;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Route Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mk = function (string $title) use ($missionTypeId): int {
            return (int) dbInsert(
                "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
                [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
            );
        };
        $this->missionId = $mk('Create Route Mission');
        $this->otherMissionId = $mk('Other Route Mission');
        $this->mission = ['id' => $this->missionId, 'title' => 'Create Route Mission'];
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = $this->makeTeam('ΑΛΦΑ', 1);
        $this->secondTeamId = $this->makeTeam('ΒΗΤΑ', 2);
        foreach (['Άννα Α.', 'Βασίλης Β.'] as $name) {
            $this->members[] = $this->makeVolunteer($name, $this->teamId);
        }
        $this->offDuty = $this->makeVolunteer('Εκτός Action Room', $this->teamId, false);
        $this->secondTeamMember = $this->makeVolunteer('Γιώργος Γ.', $this->secondTeamId);
        $this->bystander = $this->makeVolunteer('Χωρίς ομάδα', null);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'cr-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeVolunteer(string $name, ?int $teamId, bool $inActionRoom = true): int
    {
        $id = $this->makeUser($name);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $id, PARTICIPATION_APPROVED]
        );
        if ($inActionRoom) {
            dbInsert("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->missionId, $id]);
        }
        if ($teamId !== null) {
            dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$teamId, $this->missionId, $id]);
        }
        forgetActionRoomParticipantIds($this->missionId);
        return $id;
    }

    private function makeTeam(string $codename, int $number, ?int $missionId = null): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$missionId ?? $this->missionId, $codename, $number, $this->adminId]
        );
    }

    private function wp(float $lat = 35.20, float $lng = 24.90, array $more = []): array
    {
        return ['lat' => $lat, 'lng' => $lng] + $more;
    }

    /** A form posting a route, the way the route window does. */
    private function form(array $over = []): array
    {
        return $over + [
            'team_id' => (string) $this->teamId,
            'ring_index' => '',
            'title' => 'Περίπολος Α',
            'waypoints' => json_encode([
                $this->wp(35.20, 24.90, ['label' => 'Γέφυρα', 'instructions' => 'Κοίτα κάτω από τη γέφυρα', 'dwell_minutes' => 5, 'require_photo' => 1, 'require_video' => 1, 'require_note' => 1]),
                $this->wp(35.21, 24.91),
            ]),
            'add_return_waypoint' => '0',
            'member_ids' => '[]',
            'is_closed_loop' => '0',
            'travel_mode' => 'vehicle',
        ];
    }

    private function make(array $input): array
    {
        return createMissionRoute($this->mission, $this->adminId, $input);
    }

    private function routeCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM mission_routes WHERE mission_id = ?", [$this->missionId]);
    }

    private function orderCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM mission_orders WHERE mission_id = ?", [$this->missionId]);
    }

    private function notificationCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM notifications");
    }

    private function auditCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_route'");
    }

    /** @return int[] route members, sorted. */
    private function memberIds(int $routeId): array
    {
        $ids = array_map('intval', array_column(dbFetchAll("SELECT user_id FROM mission_route_members WHERE route_id = ?", [$routeId]), 'user_id'));
        sort($ids);
        return $ids;
    }

    /** @return int[] ids that got a notification about the order $orderId. */
    private function notifiedAbout(int $orderId): array
    {
        $ids = [];
        foreach (dbFetchAll("SELECT user_id, data FROM notifications WHERE data LIKE ?", ['%"orderId":' . $orderId . '%']) as $row) {
            if ((int) (json_decode((string) $row['data'], true)['orderId'] ?? 0) === $orderId) {
                $ids[] = (int) $row['user_id'];
            }
        }
        sort($ids);
        return $ids;
    }

    public function testARouteForATeamIsMadeWithItsMembersItsOrderAndItsWaypoints(): void
    {
        $r = $this->make($this->form());

        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertSame(['ok', 'id'], array_keys($r));
        $route = dbFetchOne("SELECT * FROM mission_routes WHERE id = ?", [$r['id']]);
        $this->assertSame($this->missionId, (int) $route['mission_id']);
        $this->assertSame($this->teamId, (int) $route['team_id']);
        $this->assertSame('Περίπολος Α', $route['title']);
        $this->assertSame(0, (int) $route['is_closed_loop']);
        $this->assertNull($route['ring_index']);
        $this->assertSame('vehicle', $route['travel_mode']);
        $this->assertSame($this->adminId, (int) $route['created_by']);
        $this->assertNull($route['completed_at']);
        $this->assertNull($route['cancelled_at']);

        // The people of the team who take part in the Action Room - not the one whose phone is not in it.
        $this->assertSame($this->members, $this->memberIds($r['id']));

        // The order behind the route: what carries the push, the banner and «Ελήφθη».
        $order = dbFetchOne("SELECT * FROM mission_orders WHERE id = ?", [$route['order_id']]);
        $this->assertSame('route', $order['order_type']);
        $this->assertSame($this->missionId, (int) $order['mission_id']);
        $this->assertSame(t('route.order_summary_titled', ['title' => 'Περίπολος Α', 'count' => 2]), $order['task_text']);
        $recipients = array_map('intval', array_column(dbFetchAll("SELECT user_id FROM mission_order_recipients WHERE order_id = ? ORDER BY user_id", [$order['id']]), 'user_id'));
        $this->assertSame($this->members, $recipients);
        $this->assertSame($this->members, $this->notifiedAbout((int) $order['id']));
        $note = dbFetchOne("SELECT title FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$this->members[0]]);
        $this->assertSame(t('order.route.title', ['team' => 'ΑΛΦΑ 1']), $note['title']);

        // Each waypoint, in order, with a progress row for the team.
        $wps = dbFetchAll("SELECT * FROM mission_route_waypoints WHERE route_id = ? ORDER BY seq", [$r['id']]);
        $this->assertCount(2, $wps);
        $this->assertSame([1, 2], [(int) $wps[0]['seq'], (int) $wps[1]['seq']]);
        $this->assertEquals(35.20, (float) $wps[0]['lat']);
        $this->assertSame('Γέφυρα', $wps[0]['label']);
        $this->assertSame('Κοίτα κάτω από τη γέφυρα', $wps[0]['instructions']);
        $this->assertSame(5, (int) $wps[0]['dwell_minutes']);
        $this->assertSame([1, 1, 1], [(int) $wps[0]['require_photo'], (int) $wps[0]['require_video'], (int) $wps[0]['require_note']]);
        $this->assertNull($wps[1]['label']);
        $this->assertSame([0, 0, 0], [(int) $wps[1]['require_photo'], (int) $wps[1]['require_video'], (int) $wps[1]['require_note']]);
        $progress = dbFetchAll("SELECT waypoint_id, team_id FROM mission_route_progress WHERE route_id = ? ORDER BY waypoint_id", [$r['id']]);
        $this->assertCount(2, $progress);
        foreach ($progress as $row) {
            $this->assertSame($this->teamId, (int) $row['team_id']);
        }

        $audit = dbFetchOne("SELECT * FROM audit_logs WHERE action = 'create_mission_route' AND record_id = ?", [$r['id']]);
        $this->assertNotNull($audit);
        $this->assertSame('mission_routes', $audit['table_name']);
        $this->assertSame($this->members, array_map('intval', json_decode((string) $audit['notes'], true)['new']['member_ids']));
        $this->assertNotContains($this->adminId, $this->notifiedAbout((int) $order['id']), 'the sender is not told');
    }

    public function testWaypointsAreTrimmedCutClampedAndKeepTheirFlags(): void
    {
        $r = $this->make($this->form(['title' => '  ' . str_repeat('Τ', 300) . '  ', 'waypoints' => json_encode([
            $this->wp(35.2, 24.9, ['label' => str_repeat('Ω', 300), 'instructions' => str_repeat('ε', 2500), 'dwell_minutes' => -4, 'require_note' => '1']),
            $this->wp(35.3, 24.9, ['label' => '  padded  ', 'instructions' => '   ', 'dwell_minutes' => 'abc']),
            ['lat' => '35.4', 'lng' => '24.9', 'dwell_minutes' => '7'],
        ])]));

        $this->assertTrue($r['ok']);
        $this->assertSame(str_repeat('Τ', 255), dbFetchValue("SELECT title FROM mission_routes WHERE id = ?", [$r['id']]));
        $wps = dbFetchAll("SELECT * FROM mission_route_waypoints WHERE route_id = ? ORDER BY seq", [$r['id']]);
        $this->assertSame(str_repeat('Ω', 255), $wps[0]['label']);
        $this->assertSame(str_repeat('ε', 2000), $wps[0]['instructions']);
        $this->assertSame(0, (int) $wps[0]['dwell_minutes'], 'a negative dwell time is none');
        $this->assertSame(1, (int) $wps[0]['require_note']);
        $this->assertSame('padded', $wps[1]['label']);
        $this->assertNull($wps[1]['instructions']);
        $this->assertNull($wps[1]['dwell_minutes'], 'a dwell time that is not a number is none');
        $this->assertEquals(35.4, (float) $wps[2]['lat']);
        $this->assertSame(7, (int) $wps[2]['dwell_minutes']);
        // An empty title is no title.
        $this->assertNull(dbFetchValue("SELECT title FROM mission_routes WHERE id = ?", [$this->make($this->form(['title' => '   ']))['id']]));
    }

    public function testEachMistakeIsRefusedWithItsOwnMessageAndWritesNothing(): void
    {
        $foreignTeam = $this->makeTeam('ΔΕΛΤΑ', 9, $this->otherMissionId);
        $many = fn(int $n) => json_encode(array_map(fn($i) => $this->wp(35.0 + $i * 0.001, 24.0 + $i * 0.001), range(1, $n)));
        $routes = $this->routeCount();
        $orders = $this->orderCount();
        $notes = $this->notificationCount();
        $audit = $this->auditCount();

        foreach ([
            'a team of another mission' => [$this->form(['team_id' => (string) $foreignTeam]), t('common.team_not_found')],
            'a team that is not there' => [$this->form(['team_id' => '987654321']), t('common.team_not_found')],
            'team 0' => [$this->form(['team_id' => '0']), t('common.team_not_found')],
            'a ring of 4' => [$this->form(['ring_index' => '4']), t('common.invalid_request')],
            'a ring below 0' => [$this->form(['ring_index' => '-1']), t('common.invalid_request')],
            'no waypoints' => [$this->form(['waypoints' => '[]']), t('route.needs_one_waypoint')],
            'waypoints that are not json' => [$this->form(['waypoints' => 'not json']), t('route.needs_one_waypoint')],
            'no waypoints field' => [array_diff_key($this->form(), ['waypoints' => 1]), t('route.needs_one_waypoint')],
            'thirty-one waypoints' => [$this->form(['waypoints' => $many(31)]), t('route.too_many_waypoints')],
            'a latitude of 91' => [$this->form(['waypoints' => json_encode([$this->wp(91, 24.9)])]), t('route.invalid_waypoint')],
            'a longitude of 181' => [$this->form(['waypoints' => json_encode([$this->wp(35.0, 181)])]), t('route.invalid_waypoint')],
            'a waypoint at 0,0' => [$this->form(['waypoints' => json_encode([$this->wp(0, 0)])]), t('route.invalid_waypoint')],
            'a waypoint that is not numbers' => [$this->form(['waypoints' => json_encode([['lat' => 'north', 'lng' => 24.9]])]), t('route.invalid_waypoint')],
            'a waypoint with no longitude' => [$this->form(['waypoints' => json_encode([['lat' => 35.0]])]), t('route.invalid_waypoint')],
            'a waypoint that is not an object' => [$this->form(['waypoints' => json_encode([35.0])]), t('route.invalid_waypoint')],
            'a route of people picked and nobody picked' => [$this->form(['team_id' => '', 'member_ids' => '[]']), t('route.select_at_least_one_member')],
        ] as $why => [$input, $message]) {
            $r = $this->make($input);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame($message, $r['error'], $why);
            $this->assertSame(['ok', 'error'], array_keys($r), "$why: the answer the endpoint always gave");
        }
        $this->assertSame($routes, $this->routeCount());
        $this->assertSame($orders, $this->orderCount());
        $this->assertSame($notes, $this->notificationCount());
        $this->assertSame($audit, $this->auditCount());
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_route_waypoints w JOIN mission_routes r ON r.id = w.route_id WHERE r.mission_id = ?", [$this->missionId]));
    }

    public function testTheChecksRunInTheOrderTheEndpointAlwaysRanThem(): void
    {
        $foreignTeam = $this->makeTeam('ΔΕΛΤΑ', 9, $this->otherMissionId);
        $emptyTeam = $this->makeTeam('ΕΨΙΛΟΝ', 5);
        $bad = ['team_id' => (string) $foreignTeam, 'ring_index' => '9', 'waypoints' => '[]'];

        // A form with several mistakes gets the message of the first, as it always did: team, ring, waypoints, the
        // people (who is on the team is asked last, after the shape).
        $this->assertSame(t('common.team_not_found'), $this->make($bad + $this->form())['error']);
        $this->assertSame(t('common.invalid_request'), $this->make(['team_id' => (string) $emptyTeam] + $bad + $this->form())['error']);
        $this->assertSame(t('route.needs_one_waypoint'), $this->make(['team_id' => (string) $emptyTeam, 'ring_index' => '2'] + $bad + $this->form())['error']);
        $this->assertSame(t('route.team_has_no_gps_members'), $this->make(['team_id' => (string) $emptyTeam, 'ring_index' => '2', 'waypoints' => $this->form()['waypoints']] + $this->form())['error']);
    }

    public function testTheReturnWaypointIsTheTeamsLastPingAndCountsAgainstThirty(): void
    {
        $many = fn(int $n) => json_encode(array_map(fn($i) => $this->wp(35.0 + $i * 0.001, 24.0 + $i * 0.001), range(1, $n)));

        // No ping yet: nothing to return to, and that is not an error.
        $plain = $this->make($this->form(['add_return_waypoint' => '1']));
        $this->assertTrue($plain['ok']);
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_route_waypoints WHERE route_id = ?", [$plain['id']]));
        $this->assertTrue($this->make($this->form(['add_return_waypoint' => '1', 'waypoints' => $many(30)]))['ok'], 'thirty points and no ping to return to');

        // The team's latest ping becomes one more real waypoint, tracked like any other.
        dbInsert("INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, created_at) VALUES (?, ?, ?, ?, ?)", [$this->members[0], $this->shiftId, 35.3, 25.3, date('Y-m-d H:i:s', time() - 600)]);
        dbInsert("INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, created_at) VALUES (?, ?, ?, ?, ?)", [$this->members[1], $this->shiftId, 35.4, 25.4, date('Y-m-d H:i:s', time() - 60)]);
        $r = $this->make($this->form(['add_return_waypoint' => '1']));
        $wps = dbFetchAll("SELECT * FROM mission_route_waypoints WHERE route_id = ? ORDER BY seq", [$r['id']]);
        $this->assertCount(3, $wps);
        $this->assertEquals(35.4, (float) $wps[2]['lat'], 'the newest ping of anyone on the team');
        $this->assertEquals(25.4, (float) $wps[2]['lng']);
        $this->assertSame(t('route.return_to_start_label'), $wps[2]['label']);
        $this->assertSame(3, (int) dbFetchValue("SELECT COUNT(*) FROM mission_route_progress WHERE route_id = ?", [$r['id']]));
        $this->assertSame(t('route.order_summary_titled', ['title' => 'Περίπολος Α', 'count' => 3]), dbFetchValue("SELECT task_text FROM mission_orders WHERE id = (SELECT order_id FROM mission_routes WHERE id = ?)", [$r['id']]), 'the order counts the return point');

        // The return point counts against the thirty: it is refused, with its own message, rather than stored as the 31st.
        $before = $this->routeCount();
        $over = $this->make($this->form(['add_return_waypoint' => '1', 'waypoints' => $many(30)]));
        $this->assertFalse($over['ok']);
        $this->assertSame(t('route.too_many_waypoints_with_return'), $over['error']);
        $this->assertSame($before, $this->routeCount());
        $this->assertTrue($this->make($this->form(['add_return_waypoint' => '1', 'waypoints' => $many(29)]))['ok']);
        // Only asked for with the word '1'.
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_route_waypoints WHERE route_id = ?", [$this->make($this->form(['add_return_waypoint' => 'true']))['id']]));
    }

    public function testASubsetOfTheTeamIsKeptAndAForgedIdNeverAddsSomeoneWhoIsNotOnIt(): void
    {
        $subset = $this->make($this->form(['member_ids' => json_encode([$this->members[1]])]));
        $this->assertSame([$this->members[1]], $this->memberIds($subset['id']));

        // Ids of people on no team, on another team and nowhere are not on this one: nothing of the subset
        // survives, so it is the whole team (the composer's default), never those people.
        $forged = $this->make($this->form(['member_ids' => json_encode([$this->bystander, $this->secondTeamMember, 999999991])]));
        $this->assertSame($this->members, $this->memberIds($forged['id']));
        // Part forged, part real: only the real one.
        $mixed = $this->make($this->form(['member_ids' => json_encode([$this->members[0], $this->secondTeamMember])]));
        $this->assertSame([$this->members[0]], $this->memberIds($mixed['id']));
        $this->assertNotContains($this->secondTeamMember, $this->notifiedAbout((int) dbFetchValue("SELECT order_id FROM mission_routes WHERE id = ?", [$mixed['id']])));
        // Somebody on the team whose phone is not in the Action Room is not a recipient even when named.
        $offDuty = $this->make($this->form(['member_ids' => json_encode([$this->offDuty])]));
        $this->assertSame($this->members, $this->memberIds($offDuty['id']));
    }

    public function testATeamWithNobodyInTheActionRoomCannotBeSentARoute(): void
    {
        $quiet = $this->makeTeam('ΕΨΙΛΟΝ', 5);
        $this->makeVolunteer('Μόνο στη βάρδια', $quiet, false);
        $routes = $this->routeCount();
        $orders = $this->orderCount();
        $notes = $this->notificationCount();

        foreach ([$quiet, $this->makeTeam('ΖΗΤΑ', 6)] as $team) {
            $r = $this->make($this->form(['team_id' => (string) $team]));
            $this->assertFalse($r['ok']);
            $this->assertSame(t('route.team_has_no_gps_members'), $r['error']);
        }
        $this->assertSame($routes, $this->routeCount());
        $this->assertSame($orders, $this->orderCount());
        $this->assertSame($notes, $this->notificationCount());
    }

    public function testARouteOfPeopleFromSeveralTeamsNeedsThemPickedAndHasNoTeamOrTravelMode(): void
    {
        $r = $this->make($this->form([
            'team_id' => '',
            'member_ids' => json_encode([$this->members[0], $this->secondTeamMember, $this->bystander, $this->offDuty, 999999991]),
            'add_return_waypoint' => '1',
            'travel_mode' => 'vehicle',
            'is_closed_loop' => '1',
        ]));

        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $route = dbFetchOne("SELECT * FROM mission_routes WHERE id = ?", [$r['id']]);
        $this->assertNull($route['team_id']);
        $this->assertNull($route['travel_mode'], 'a route with no team has none to travel');
        // Approved on the mission AND in the Action Room, and picked: not the one off duty, not the invented id.
        $expected = [$this->members[0], $this->secondTeamMember, $this->bystander];
        sort($expected);
        $this->assertSame($expected, $this->memberIds($r['id']));
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_route_waypoints WHERE route_id = ?", [$r['id']]), 'no return point without a team');
        $this->assertNull(dbFetchValue("SELECT team_id FROM mission_route_progress WHERE route_id = ? LIMIT 1", [$r['id']]));
        // Nobody picked who qualifies is refused, not widened to the mission.
        $none = $this->make($this->form(['team_id' => '', 'member_ids' => json_encode([$this->offDuty, 999999991])]));
        $this->assertFalse($none['ok']);
        $this->assertSame(t('route.select_at_least_one_member'), $none['error']);
    }

    public function testALoopNeedsThreePointsAndTheTravelModeBelongsToATeam(): void
    {
        $three = json_encode([$this->wp(35.20, 24.90), $this->wp(35.21, 24.91), $this->wp(35.22, 24.92)]);
        $this->assertSame(1, (int) dbFetchValue("SELECT is_closed_loop FROM mission_routes WHERE id = ?", [$this->make($this->form(['is_closed_loop' => '1', 'waypoints' => $three]))['id']]));
        $this->assertSame(0, (int) dbFetchValue("SELECT is_closed_loop FROM mission_routes WHERE id = ?", [$this->make($this->form(['is_closed_loop' => '1']))['id']]), 'two points make no loop');
        $this->assertSame(0, (int) dbFetchValue("SELECT is_closed_loop FROM mission_routes WHERE id = ?", [$this->make($this->form(['is_closed_loop' => '0', 'waypoints' => $three]))['id']]));

        foreach (['foot' => 'foot', 'vehicle' => 'vehicle', 'bike' => null, '' => null] as $asked => $stored) {
            $this->assertSame($stored, dbFetchValue("SELECT travel_mode FROM mission_routes WHERE id = ?", [$this->make($this->form(['travel_mode' => $asked]))['id']]), "asked for '$asked'");
        }
        $this->assertSame(2, (int) dbFetchValue("SELECT ring_index FROM mission_routes WHERE id = ?", [$this->make($this->form(['ring_index' => '2']))['id']]));
    }

    public function testPrepareChecksTheRouteItselfAndWritesNothing(): void
    {
        $routes = $this->routeCount();
        $orders = $this->orderCount();
        $notes = $this->notificationCount();
        $audit = $this->auditCount();

        $ok = prepareMissionRoute($this->mission, $this->form([
            'title' => '  Περίπολος Β  ', 'add_return_waypoint' => '1', 'is_closed_loop' => '1', 'member_ids' => json_encode([$this->members[0], '7']), 'travel_mode' => 'foot',
        ]));

        $this->assertTrue($ok['ok']);
        $this->assertSame($this->teamId, $ok['team_id']);
        $this->assertSame('ΑΛΦΑ 1', $ok['team_label']);
        $this->assertSame('Περίπολος Β', $ok['title']);
        $this->assertCount(2, $ok['waypoints'], 'the return point is not part of what was drawn');
        $this->assertSame('Γέφυρα', $ok['waypoints'][0]['label']);
        $this->assertSame(1, $ok['waypoints'][0]['require_photo']);
        $this->assertTrue($ok['add_return']);
        $this->assertTrue($ok['closed_loop_asked']);
        $this->assertSame([$this->members[0], 7], $ok['member_ids']);
        $this->assertSame('foot', $ok['travel_mode']);
        $this->assertNull($ok['ring_index']);

        // No team: a route of people picked; the return point needs a team.
        $cross = prepareMissionRoute($this->mission, $this->form(['team_id' => '', 'add_return_waypoint' => '1']));
        $this->assertTrue($cross['ok']);
        $this->assertNull($cross['team_id']);
        $this->assertNull($cross['team']);
        $this->assertFalse($cross['add_return']);

        // It checks the route, not the people on the day: a team with nobody in the Action Room passes here and is
        // refused when the route is sent.
        $quiet = $this->makeTeam('ΕΨΙΛΟΝ', 5);
        $this->assertTrue(prepareMissionRoute($this->mission, $this->form(['team_id' => (string) $quiet]))['ok']);
        $this->assertFalse(prepareMissionRoute($this->mission, $this->form(['waypoints' => '[]']))['ok']);

        $this->assertSame($routes, $this->routeCount());
        $this->assertSame($orders, $this->orderCount());
        $this->assertSame($notes, $this->notificationCount());
        $this->assertSame($audit, $this->auditCount());
    }

    public function testTheEndpointOnlyHandsOverWhatTheFormPostedAndAnswersWhatTheFunctionAnswers(): void
    {
        $src = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../mission-route.php'));
        $start = strpos($src, "if (\$action === 'create') {");
        $this->assertNotFalse($start);
        $block = substr($src, $start, strpos($src, "\n}\n", $start) - $start);
        $this->assertStringContainsString('createMissionRoute($mission, (int) $userId, [', $block);
        foreach (['team_id', 'ring_index', 'title', 'waypoints', 'add_return_waypoint', 'member_ids', 'is_closed_loop', 'travel_mode'] as $field) {
            $this->assertStringContainsString("'$field'", $block);
            $this->assertStringContainsString("post('$field')", $block);
        }
        // Command only, as it was; a refusal is answered as it is; a success answers the live list of routes.
        $this->assertStringContainsString("if (!\$canManageWarRoom) {", $block);
        $this->assertStringContainsString('echo json_encode($result);', $block);
        $this->assertStringContainsString("echo json_encode(['ok' => true, 'routes' => loadRoutesForUser(\$missionId, \$userId, \$canManageWarRoom)]);", $block);
        foreach (['INSERT INTO', 'createMissionOrderAndNotify', 'logAudit'] as $gone) {
            $this->assertStringNotContainsString($gone, $block);
        }
        // The login, the CSRF token and the mission gate come before any action, as they did.
        $this->assertLessThan($start, strpos($src, 'hash_equals($_SESSION[\'csrf_token\']'));
        $this->assertLessThan($start, strpos($src, 'requireLogin();'));
        $this->assertTrue(function_exists('createMissionRoute') && function_exists('prepareMissionRoute') && function_exists('resolveMissionRouteRecipients'));
        // Everything the function calls lives where a scenario's endpoint (which never loads this one) can reach it.
        $this->assertStringNotContainsString("\nfunction ", $src);
    }
}
