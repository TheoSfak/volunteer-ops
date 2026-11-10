<?php
/**
 * VolunteerOps - Mission teams (v3.463.0)
 *
 * The teams of a mission as a set of functions, for the «Ομάδες» page (mission-teams-view.php, drawn by
 * assets/js/mission-teams.js from mission-teams.php). Since v3.464.0 that page is the only place a team is made or
 * changed: the Action Room's own «Νέα Ομάδα» and edit windows are gone, and its teams card shows the teams and links
 * to the page.
 *
 * What is here:
 *   - the roster a team is made from: everybody approved on the mission, with their HOME team (Επίδρασις, a partner
 *     organisation, a visitor's tag), the mission team they are on and whether their GPS is on;
 *   - the automatic split (planAutoTeams()): command says how many people a team has, whoever is left over goes into
 *     the last team, and people of one home team are kept side by side;
 *   - making, renaming and deleting a team, choosing its leader and how it moves, moving one person from a team to
 *     another, and switching GPS on and off for one person, a team or everybody.
 *
 * GPS is OFF for everybody until somebody switches it on: taking part in the Action Room is a row in
 * mission_action_room_participants and nothing here writes one unasked. The automatic split and the page's own
 * «Νέα ομάδα» make teams with no GPS at all; it is switched on from the same page, per person or per team.
 *
 * The rules that were already the Action Room's stay its rules, and are called, not copied: a team's leader is one of
 * its members, a leader or an only member cannot be taken away (teamsBlockedFromMemberMove()), two teams never read
 * the same (teamRenameDecision()), and leaving a team never switches a person's GPS off.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

if (!defined('MISSION_TEAM_CODENAMES')) {
    define('MISSION_TEAM_CODENAMES', ['Alpha','Bravo','Charlie','Delta','Echo','Foxtrot','Golf','Hotel','India',
        'Juliett','Kilo','Lima','Mike','November','Oscar','Papa','Quebec','Romeo',
        'Sierra','Tango','Uniform','Victor','Whiskey','X-ray','Yankee','Zulu']);
}

/** The largest «άτομα ανά ομάδα» the automatic split accepts; above it the number is a typing mistake. */
const MISSION_TEAM_AUTO_MAX_SIZE = 50;

/**
 * Notify every team member (individually) about their team assignment.
 * $namesByUserId must map user_id => name for all ids in $memberIds/$leaderId.
 * $onlyNotifyIds, when given, narrows who is told (a person just moved onto the
 * team) while the teammates named in the message are still the whole roster.
 * Moved here from war-room.php (v3.463.0) so the «Ομάδες» page tells people the same thing in the same words.
 */
function notifyMissionTeamMembers(int $missionId, string $missionTitle, string $codename, ?int $teamNumber, array $memberIds, int $leaderId, array $namesByUserId, ?array $onlyNotifyIds = null): void {
    $teamLabel = teamLabel($codename, $teamNumber);
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $leaderName = $namesByUserId[$leaderId] ?? '';
    $langByUserId = getUserLanguages($memberIds);
    $notifyIds = $onlyNotifyIds === null ? $memberIds : array_values(array_intersect($memberIds, $onlyNotifyIds));
    foreach ($notifyIds as $memberId) {
        $lang = $langByUserId[$memberId] ?? DEFAULT_LANGUAGE;
        $teammateNames = array_filter(array_map(
            fn($id) => $namesByUserId[$id] ?? '',
            array_values(array_diff($memberIds, [$memberId]))
        ));
        $message = t('team.notify.assigned', ['mission' => $missionTitle, 'team' => $teamLabel], $lang);
        if (!empty($teammateNames)) {
            $message .= t('team.notify.mates', ['names' => implode(', ', $teammateNames)], $lang);
        }
        $message .= $memberId === $leaderId
            ? t('team.notify.leader_self', [], $lang)
            : t('team.notify.leader_other', ['leader' => $leaderName], $lang);
        sendNotification($memberId, t('team.notify.title', ['team' => $teamLabel], $lang), $message, 'info', '', [
            'url' => $warRoomUrl,
            'tag' => 'mission-team-' . $missionId,
        ]);
    }
}

// ── The roster ───────────────────────────────────────────────────────────────────────────────────────────────

/**
 * The home team a person is grouped under by the automatic split, and shown with on the page: their volunteer team
 * (or a walk-up visitor's tag), else the organisation a guest account names, else - for a regular member with nothing
 * set - the organisation's own default team, because a member of Επίδρασις whose badge was never filled in is still a
 * member of Επίδρασις. Somebody with none of these has an empty key and is grouped with the others like them.
 *
 * @return array{key:string,name:string}
 */
function missionTeamHomeGroup(?string $homeTeamName, bool $isExternal, ?string $guestOrgName, ?string $defaultTeamName): array {
    $name = trim((string) $homeTeamName);
    if ($name === '' && $isExternal) {
        $name = trim((string) $guestOrgName);
    }
    if ($name === '' && !$isExternal) {
        $name = trim((string) $defaultTeamName);
    }
    return ['key' => mb_strtolower($name), 'name' => $name];
}

/**
 * Everybody approved on the mission, by name: who a team can be made of. One row per person however many shifts
 * they are approved on.
 *
 * @return array<int,array{user_id:int,name:string,is_external:bool,country:?string,is_captain:bool,home_key:string,
 *     home_name:string,home_color:?string,team_id:?int,gps:bool}> keyed by user id
 */
function missionTeamRoster(int $missionId): array {
    $default = dbFetchOne("SELECT name, color FROM volunteer_teams WHERE is_default = 1 ORDER BY id LIMIT 1");
    $rows = dbFetchAll(
        "SELECT u.id AS user_id, u.name, u.is_external, u.guest_org_name, u.guest_country_code, u.is_team_captain,
                COALESCE(vt.name, mvt.label) AS home_team_name, COALESCE(vt.color, mvt.color) AS home_team_color,
                mtm.team_id
           FROM users u
           JOIN (SELECT DISTINCT pr.volunteer_id
                   FROM participation_requests pr
                   JOIN shifts s ON s.id = pr.shift_id
                  WHERE s.mission_id = ? AND pr.status = ?) a ON a.volunteer_id = u.id
           LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
           LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
           LEFT JOIN mission_team_members mtm ON mtm.mission_id = ? AND mtm.user_id = u.id
          WHERE u.deleted_at IS NULL
          ORDER BY u.name, u.id",
        [$missionId, PARTICIPATION_APPROVED, $missionId]
    );
    $gpsIds = actionRoomParticipantIds($missionId);
    $roster = [];
    foreach ($rows as $row) {
        $isExternal = (bool) $row['is_external'];
        $home = missionTeamHomeGroup($row['home_team_name'], $isExternal, $row['guest_org_name'], $default['name'] ?? null);
        $usesDefault = trim((string) $row['home_team_name']) === '' && !$isExternal && $home['name'] !== '';
        $userId = (int) $row['user_id'];
        $roster[$userId] = [
            'user_id'    => $userId,
            'name'       => (string) $row['name'],
            'is_external' => $isExternal,
            'country'    => $isExternal ? ($row['guest_country_code'] ?: null) : 'GR',
            'is_captain' => (bool) $row['is_team_captain'],
            'home_key'   => $home['key'],
            'home_name'  => $home['name'],
            'home_color' => $usesDefault ? ($default['color'] ?? null) : ($row['home_team_color'] ?: null),
            'team_id'    => $row['team_id'] !== null ? (int) $row['team_id'] : null,
            'gps'        => in_array($userId, $gpsIds, true),
        ];
    }
    return $roster;
}

// ── The automatic split ──────────────────────────────────────────────────────────────────────────────────────

/**
 * Splits $people into teams of $size. Pure: the same people and the same number always give the same teams, which is
 * what lets the page show the split first and make it afterwards.
 *
 * The rules, in the words they were asked for:
 *   - command gives one number, how many people a team has;
 *   - whoever is left over goes into the LAST team (23 people in fives is 5, 5, 5 and 8, not a fifth team of 3), and
 *     fewer people than one team's worth are one team;
 *   - people of the same home team go together. The sizes of the teams are fixed by the two rules above, so this is
 *     a matter of who goes where: a home team that fits whole on one team is put whole on one team, and one that is
 *     bigger than any team is cut in as few and as large pieces as will do. People with no home team belong together
 *     with nobody, so they fill whatever places are left.
 *
 * Three ways of laying the home teams out are tried (planAutoTeamsLayout()) and the one that cuts home teams the
 * least is kept - fewest pieces, then fewest people left alone among strangers.
 *
 * Each team's leader is its first home-team captain, else its first member: a team has to have one, and command
 * changes it with one click on the page.
 *
 * @param array<int,array{user_id:int,name:string,home_key?:string,is_captain?:bool}> $people
 * @return array<int,array{member_ids:int[],leader_id:int}>
 */
function planAutoTeams(array $people, int $size): array {
    $people = array_values($people);
    $count = count($people);
    $size = max(1, min(MISSION_TEAM_AUTO_MAX_SIZE, $size));
    if ($count === 0) {
        return [];
    }

    // Home team by home team: captains first, then by name. The biggest home team first; no home team is not a group.
    $groups = [];
    $loose = [];
    foreach ($people as $person) {
        $key = (string) ($person['home_key'] ?? '');
        if ($key === '') {
            $loose[] = $person;
        } else {
            $groups[$key][] = $person;
        }
    }
    $byCaptainThenName = fn($a, $b) =>
        [empty($a['is_captain']) ? 1 : 0, mb_strtolower((string) $a['name']), (int) $a['user_id']]
        <=> [empty($b['is_captain']) ? 1 : 0, mb_strtolower((string) $b['name']), (int) $b['user_id']];
    foreach ($groups as &$members) {
        usort($members, $byCaptainThenName);
    }
    unset($members);
    usort($loose, $byCaptainThenName);
    uksort($groups, fn($a, $b) => [count($groups[$b]), (string) $a] <=> [count($groups[$a]), (string) $b]);
    $groups = array_values($groups);

    // How many people each team holds: the number asked for, and the last team takes whoever is left over.
    $teamCount = max(1, intdiv($count, $size));
    $capacities = array_fill(0, $teamCount, $size);
    $capacities[$teamCount - 1] = $count - $size * ($teamCount - 1);

    $best = null;
    $bestScore = null;
    foreach (['small', 'large', 'row'] as $mode) {
        $layout = planAutoTeamsLayout($groups, $loose, $capacities, $mode);
        $pieces = 0;
        $alone = 0;
        foreach ($groups as $index => $members) {
            foreach ($layout as $team) {
                $here = count(array_filter($team, fn($placed) => $placed[0] === $index));
                $pieces += $here > 0 ? 1 : 0;
                $alone += ($here === 1 && count($members) > 1) ? 1 : 0;
            }
        }
        if ($bestScore === null || [$pieces, $alone] < $bestScore) {
            $best = $layout;
            $bestScore = [$pieces, $alone];
        }
    }

    $teams = [];
    foreach ($best as $team) {
        $members = array_column($team, 1);
        $leaderId = (int) $members[0]['user_id'];
        foreach ($members as $person) {
            if (!empty($person['is_captain'])) {
                $leaderId = (int) $person['user_id'];
                break;
            }
        }
        $teams[] = [
            'member_ids' => array_map(fn($person) => (int) $person['user_id'], $members),
            'leader_id'  => $leaderId,
        ];
    }
    return $teams;
}

/**
 * One way of putting the home teams ($groups, biggest first) and the people with none ($loose) onto teams of the
 * given capacities. Answers, per team, a list of [group index or -1, person], home team by home team.
 *
 *   'row'   - everybody in one row, home team after home team, cut where a team fills up;
 *   'small' - a home team that fits whole on a team goes onto the team it fills most nearly; one that fits nowhere
 *             whole first fills the team with the LEAST room left, and what remains of it is placed the same way;
 *   'large' - the same, but one that fits nowhere whole first fills the team with the MOST room left.
 *
 * In the last two the people with no home team take the places that are left, first team first.
 */
function planAutoTeamsLayout(array $groups, array $loose, array $capacities, string $mode): array {
    $teams = array_fill(0, count($capacities), []);
    $room = $capacities;

    if ($mode === 'row') {
        $at = 0;
        $row = [];
        foreach ($groups as $index => $members) {
            foreach ($members as $person) {
                $row[] = [$index, $person];
            }
        }
        foreach ($loose as $person) {
            $row[] = [-1, $person];
        }
        foreach ($row as $placed) {
            while ($room[$at] === 0) {
                $at++;
            }
            $teams[$at][] = $placed;
            $room[$at]--;
        }
        return $teams;
    }

    foreach ($groups as $index => $members) {
        while (!empty($members)) {
            $need = count($members);
            $pick = null;
            // The team it fits on whole with the least room to spare...
            foreach ($room as $team => $free) {
                if ($free >= $need && ($pick === null || $free < $room[$pick])) {
                    $pick = $team;
                }
            }
            // ...or, fitting nowhere whole, the team with the least (or the most) room that still has any.
            if ($pick === null) {
                foreach ($room as $team => $free) {
                    if ($free > 0 && ($pick === null || ($mode === 'small' ? $free < $room[$pick] : $free > $room[$pick]))) {
                        $pick = $team;
                    }
                }
            }
            foreach (array_splice($members, 0, $room[$pick]) as $person) {
                $teams[$pick][] = [$index, $person];
                $room[$pick]--;
            }
        }
    }
    foreach ($loose as $person) {
        foreach ($room as $team => $free) {
            if ($free > 0) {
                $teams[$team][] = [-1, $person];
                $room[$team]--;
                break;
            }
        }
    }
    return $teams;
}

/** Who the automatic split works on: the people on no team, or everybody when the teams are being made again. */
function missionTeamAutoPool(array $roster, bool $redo): array {
    return array_values(array_filter($roster, fn($person) => $redo || $person['team_id'] === null));
}

/**
 * Runs $work inside a transaction: its own when none is open, the caller's otherwise (a test runs every case inside
 * one transaction that it rolls back). Anything thrown undoes the transaction this call opened and goes on up.
 */
function missionTeamsTransaction(callable $work) {
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $result = $work();
        if ($ownTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ── Making a team ────────────────────────────────────────────────────────────────────────────────────────────

/**
 * The name, number and colour of the next team of a mission. A name command typed is used as it is and carries no
 * number; otherwise the first NATO name and the first colour no team of the mission wears yet (so deleting Alpha and
 * making a team does not give a second Charlie), cycling once every name is taken, with a two-digit number that is
 * unique in the mission. 'team_number' is null for a typed name, and 'failed' says no free number was found.
 *
 * @return array{codename:string,team_number:?int,color:string,custom:bool,failed:bool}
 */
function missionTeamNextIdentity(int $missionId, string $customName = ''): array {
    $existing = dbFetchAll("SELECT codename, team_number, color FROM mission_teams WHERE mission_id = ?", [$missionId]);
    $count = count($existing);
    $usedColors = array_filter(array_column($existing, 'color'));
    $color = MISSION_TEAM_COLORS[$count % count(MISSION_TEAM_COLORS)];
    foreach (MISSION_TEAM_COLORS as $candidate) {
        if (!in_array($candidate, $usedColors, true)) {
            $color = $candidate;
            break;
        }
    }

    $customName = trim(mb_substr(trim($customName), 0, 20));
    if ($customName !== '') {
        return ['codename' => $customName, 'team_number' => null, 'color' => $color, 'custom' => true, 'failed' => false];
    }

    $usedNames = array_map(fn($name) => mb_strtolower((string) $name), array_column($existing, 'codename'));
    $codename = MISSION_TEAM_CODENAMES[$count % count(MISSION_TEAM_CODENAMES)];
    foreach (MISSION_TEAM_CODENAMES as $candidate) {
        if (!in_array(mb_strtolower($candidate), $usedNames, true)) {
            $codename = $candidate;
            break;
        }
    }
    $usedNumbers = array_map('intval', array_filter(array_column($existing, 'team_number'), fn($n) => $n !== null));
    $free = array_values(array_diff(range(10, 99), $usedNumbers));
    if (empty($free)) {
        return ['codename' => $codename, 'team_number' => null, 'color' => $color, 'custom' => false, 'failed' => true];
    }
    return ['codename' => $codename, 'team_number' => $free[random_int(0, count($free) - 1)], 'color' => $color, 'custom' => false, 'failed' => false];
}

/**
 * Writes one team and its members. No checks and no transaction of its own: the caller has made both. Members who
 * are on another team of the mission are taken off it in the same breath (a person is on one team at most). Returns
 * the new team, or null when no free team number was left.
 *
 * @return ?array{id:int,codename:string,team_number:?int,color:string}
 */
function missionTeamInsert(int $missionId, int $byUserId, string $customName, array $memberIds, int $leaderId, string $transport): ?array {
    $identity = missionTeamNextIdentity($missionId, $customName);
    if ($identity['failed']) {
        return null;
    }
    $teamId = (int) dbInsert(
        "INSERT INTO mission_teams (mission_id, codename, team_number, color, transport, leader_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$missionId, $identity['codename'], $identity['team_number'], $identity['color'], normalizeTeamTransport($transport), $leaderId, $byUserId]
    );
    if (!empty($memberIds)) {
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        dbExecute(
            "DELETE FROM mission_team_members WHERE mission_id = ? AND user_id IN ($placeholders)",
            array_merge([$missionId], $memberIds)
        );
    }
    foreach ($memberIds as $memberId) {
        dbInsert(
            "INSERT INTO mission_team_members (team_id, mission_id, user_id, added_at) VALUES (?, ?, ?, NOW())",
            [$teamId, $missionId, (int) $memberId]
        );
    }
    return ['id' => $teamId, 'codename' => $identity['codename'], 'team_number' => $identity['team_number'], 'color' => $identity['color']];
}

/** user id => name of everybody approved on the mission (the people a team may hold). */
function missionTeamApprovedNames(int $missionId): array {
    $names = [];
    foreach (dbFetchAll(
        "SELECT DISTINCT pr.volunteer_id, u.name
           FROM participation_requests pr
           JOIN shifts s ON s.id = pr.shift_id
           JOIN users u ON u.id = pr.volunteer_id
          WHERE s.mission_id = ? AND pr.status = ?",
        [$missionId, PARTICIPATION_APPROVED]
    ) as $row) {
        $names[(int) $row['volunteer_id']] = (string) $row['name'];
    }
    return $names;
}

/**
 * Makes one team: the page's «Νέα ομάδα» ends here.
 *
 * $input: member_ids (int[]), leader_id (int), name (string, '' = the next NATO name), transport (string) and
 * gps_ids - null to leave everybody's GPS exactly as it is (the page: GPS is its own switch), or the members whose
 * GPS is to be ON, every other member of the new team being switched off.
 *
 * Refused, with the words to show and 'level' how grave it is ('warning' or 'error'): nobody chosen, a member who leads or
 * is alone on another team, a leader who is not a member, a name another team already reads as, no number left.
 *
 * @return array{ok:bool,error?:string,level?:string,team_id?:int,label?:string}
 */
function createMissionTeam(array $mission, int $byUserId, array $input): array {
    $missionId = (int) $mission['id'];
    $names = missionTeamApprovedNames($missionId);
    $memberIds = array_values(array_unique(array_intersect(
        array_map('intval', (array) ($input['member_ids'] ?? [])),
        array_keys($names)
    )));
    $leaderId = (int) ($input['leader_id'] ?? 0);
    $customName = trim((string) ($input['name'] ?? ''));
    $transport = normalizeTeamTransport($input['transport'] ?? 'foot');
    $gpsIds = isset($input['gps_ids']) && is_array($input['gps_ids'])
        ? array_values(array_intersect(array_map('intval', $input['gps_ids']), $memberIds))
        : null;

    // A person already on another team can be picked and is MOVED into the new one: splitting a big team in two is
    // the common case, and it used to mean emptying people out of the old team first in a separate save.
    $otherAssignments = loadOtherTeamAssignments($missionId);
    $movedIds = array_values(array_filter($memberIds, fn($id) => isset($otherAssignments[$id])));
    $blockedTeams = teamsBlockedFromMemberMove($movedIds, $otherAssignments);

    if (empty($memberIds)) {
        return ['ok' => false, 'level' => 'warning', 'error' => t('team.create.select_member_warning')];
    }
    if (!empty($blockedTeams)) {
        return ['ok' => false, 'level' => 'warning', 'error' => t('team.move.leader_blocked', ['teams' => implode(', ', $blockedTeams)])];
    }
    if (!in_array($leaderId, $memberIds, true)) {
        return ['ok' => false, 'level' => 'warning', 'error' => t('team.leader_must_be_member')];
    }
    if ($customName !== '') {
        $clash = teamRenameDecision($customName, '', dbFetchAll("SELECT codename, team_number FROM mission_teams WHERE mission_id = ?", [$missionId]))['clash'];
        if ($clash !== null) {
            return ['ok' => false, 'level' => 'warning', 'error' => t('team.rename.duplicate', ['name' => $clash])];
        }
    }

    // missionTeamInsert() answers null before it has written anything, so a refusal here leaves nothing behind.
    $team = missionTeamsTransaction(function () use ($missionId, $byUserId, $customName, $memberIds, $leaderId, $transport, $gpsIds) {
        $team = missionTeamInsert($missionId, $byUserId, $customName, $memberIds, $leaderId, $transport);
        if ($team !== null && $gpsIds !== null) {
            applyTeamActionRoomTicks($missionId, $memberIds, $gpsIds, $byUserId);
        }
        return $team;
    });
    if ($team === null) {
        return ['ok' => false, 'level' => 'error', 'error' => t('team.create.number_failed')];
    }
    // The static id cache was filled before the write if anything on this request had already asked - drop it after
    // the commit too, since the rollback path leaves the table as it was.
    forgetActionRoomParticipantIds($missionId);
    logAudit('create_mission_team', 'mission_teams', $team['id'], null, [
        'mission_id' => $missionId,
        'member_ids' => $memberIds,
        'leader_id'  => $leaderId,
        'transport'  => $transport,
        'gps_ids'    => $gpsIds,
        'moved_from' => array_map(fn($id) => ['user_id' => $id, 'team' => $otherAssignments[$id]['label']], $movedIds),
    ]);
    notifyMissionTeamMembers($missionId, (string) $mission['title'], $team['codename'], $team['team_number'], $memberIds, $leaderId, $names);
    return ['ok' => true, 'team_id' => $team['id'], 'label' => teamLabel($team['codename'], $team['team_number'])];
}

/**
 * The automatic split, carried out. $redo deletes the mission's teams first and splits everybody again; without it
 * only the people on no team are split, into new teams beside the ones that exist. All of it is one transaction: a
 * mission is never left with half the teams of a split. Nobody's GPS is touched.
 *
 * @return array{ok:bool,error?:string,teams?:array<int,string>,people?:int}
 */
function autoCreateMissionTeams(array $mission, int $byUserId, int $size, bool $redo): array {
    $missionId = (int) $mission['id'];
    if ($size < 1 || $size > MISSION_TEAM_AUTO_MAX_SIZE) {
        return ['ok' => false, 'error' => t('teamsp.auto_size_invalid', ['max' => MISSION_TEAM_AUTO_MAX_SIZE])];
    }
    $roster = missionTeamRoster($missionId);
    $pool = missionTeamAutoPool($roster, $redo);
    if (empty($pool)) {
        return ['ok' => false, 'error' => t('teamsp.auto_nobody')];
    }
    $plan = planAutoTeams($pool, $size);
    $names = array_column($roster, 'name', 'user_id');

    // A team's number is two digits and unique in the mission: ninety teams at most. Said before anything is written,
    // so the split is made whole or not at all.
    $kept = $redo ? 0 : (int) dbFetchValue("SELECT COUNT(*) FROM mission_teams WHERE mission_id = ? AND team_number IS NOT NULL", [$missionId]);
    if ($kept + count($plan) > 90) {
        return ['ok' => false, 'error' => t('team.create.number_failed')];
    }

    $made = missionTeamsTransaction(function () use ($missionId, $byUserId, $plan, $redo) {
        $made = [];
        if ($redo) {
            dbExecute("DELETE FROM mission_teams WHERE mission_id = ?", [$missionId]);
        }
        foreach ($plan as $planned) {
            $team = missionTeamInsert($missionId, $byUserId, '', $planned['member_ids'], $planned['leader_id'], 'foot');
            if ($team === null) {
                throw new RuntimeException('No free team number left for mission ' . $missionId);
            }
            $made[] = $team + $planned;
        }
        return $made;
    });
    if ($redo) {
        forgetActionRoomParticipantIds($missionId);
    }
    logAudit('auto_create_mission_teams', 'missions', $missionId, null, [
        'size'  => $size,
        'redo'  => $redo,
        'teams' => array_map(fn($team) => ['team_id' => $team['id'], 'member_ids' => $team['member_ids'], 'leader_id' => $team['leader_id']], $made),
    ]);
    foreach ($made as $team) {
        notifyMissionTeamMembers($missionId, (string) $mission['title'], $team['codename'], $team['team_number'], $team['member_ids'], $team['leader_id'], $names);
    }
    return [
        'ok'     => true,
        'teams'  => array_map(fn($team) => teamLabel($team['codename'], $team['team_number']), $made),
        'people' => count($pool),
    ];
}

// ── Changing a team ──────────────────────────────────────────────────────────────────────────────────────────

/** One team of the mission with its member ids, or null. */
function missionTeamWithMembers(int $missionId, int $teamId): ?array {
    $team = dbFetchOne("SELECT * FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
    if (!$team) {
        return null;
    }
    $team['member_ids'] = array_map('intval', array_column(
        dbFetchAll("SELECT user_id FROM mission_team_members WHERE team_id = ?", [$teamId]),
        'user_id'
    ));
    return $team;
}

/** Tells the whole team who it is now: after a new name or a new leader, in the words a new team is announced with. */
function missionTeamAnnounce(array $mission, array $team, ?array $onlyNotifyIds = null): void {
    $names = array_column(
        empty($team['member_ids']) ? [] : dbFetchAll(
            "SELECT id, name FROM users WHERE id IN (" . implode(',', array_fill(0, count($team['member_ids']), '?')) . ")",
            $team['member_ids']
        ),
        'name', 'id'
    );
    notifyMissionTeamMembers(
        (int) $mission['id'], (string) $mission['title'], (string) $team['codename'],
        $team['team_number'] !== null ? (int) $team['team_number'] : null,
        $team['member_ids'], (int) ($team['leader_id'] ?? 0), $names, $onlyNotifyIds
    );
}

/**
 * A new name for a team. Typed as it is, twenty characters at most, and the team loses its number, as in the Action
 * Room's edit window; a blank name or the same name changes nothing. Returns the words of the refusal, or null.
 */
function renameMissionTeam(array $mission, int $teamId, string $name): ?string {
    $missionId = (int) $mission['id'];
    $team = missionTeamWithMembers($missionId, $teamId);
    if (!$team) {
        return t('common.team_not_found');
    }
    $others = dbFetchAll("SELECT codename, team_number FROM mission_teams WHERE mission_id = ? AND id != ?", [$missionId, $teamId]);
    $rename = teamRenameDecision($name, (string) $team['codename'], $others);
    if ($rename['clash'] !== null) {
        return t('team.rename.duplicate', ['name' => $rename['clash']]);
    }
    if (!$rename['renamed']) {
        return null;
    }
    dbExecute("UPDATE mission_teams SET codename = ?, team_number = NULL, updated_at = NOW() WHERE id = ?", [$rename['codename'], $teamId]);
    logAudit('update_mission_team', 'mission_teams', $teamId,
        ['codename' => $team['codename'], 'team_number' => $team['team_number']],
        ['codename' => $rename['codename'], 'team_number' => null]);
    missionTeamAnnounce($mission, ['codename' => $rename['codename'], 'team_number' => null] + $team);
    return null;
}

/** A new leader for a team: one of its members. Returns the words of the refusal, or null. */
function setMissionTeamLeader(array $mission, int $teamId, int $leaderId): ?string {
    $team = missionTeamWithMembers((int) $mission['id'], $teamId);
    if (!$team) {
        return t('common.team_not_found');
    }
    if (!in_array($leaderId, $team['member_ids'], true)) {
        return t('team.leader_must_be_member');
    }
    if ((int) ($team['leader_id'] ?? 0) === $leaderId) {
        return null;
    }
    dbExecute("UPDATE mission_teams SET leader_id = ?, updated_at = NOW() WHERE id = ?", [$leaderId, $teamId]);
    logAudit('update_mission_team', 'mission_teams', $teamId, ['leader_id' => $team['leader_id']], ['leader_id' => $leaderId]);
    missionTeamAnnounce($mission, ['leader_id' => $leaderId] + $team);
    return null;
}

/** How a team moves: on foot, by motorbike or by car. Returns the words of the refusal, or null. */
function setMissionTeamTransport(array $mission, int $teamId, string $transport): ?string {
    $team = dbFetchOne("SELECT id, transport FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, (int) $mission['id']]);
    if (!$team) {
        return t('common.team_not_found');
    }
    if (!in_array($transport, TEAM_TRANSPORTS, true)) {
        return t('common.invalid_request');
    }
    if (normalizeTeamTransport($team['transport']) !== $transport) {
        dbExecute("UPDATE mission_teams SET transport = ?, updated_at = NOW() WHERE id = ?", [$transport, $teamId]);
        logAudit('update_mission_team', 'mission_teams', $teamId, ['transport' => $team['transport']], ['transport' => $transport]);
    }
    return null;
}

/**
 * Moves one person: onto $toTeamId, or onto no team when it is null. They leave the team they are on in the same
 * transaction, so they are never on two teams or - unless that was asked - on none. A leader, and the only member of
 * a team, cannot be taken away: command first gives that team another leader, or deletes it. Their GPS stays as it
 * is. The person is told about their new team. Returns the words of the refusal, or null.
 */
function moveMissionTeamMember(array $mission, int $userId, ?int $toTeamId, int $byUserId): ?string {
    $missionId = (int) $mission['id'];
    $names = missionTeamApprovedNames($missionId);
    if (!isset($names[$userId])) {
        return t('gps_participant.recipient_not_found');
    }
    $toTeam = null;
    if ($toTeamId !== null) {
        $toTeam = missionTeamWithMembers($missionId, $toTeamId);
        if (!$toTeam) {
            return t('common.team_not_found');
        }
    }
    $current = loadOtherTeamAssignments($missionId)[$userId] ?? null;
    if (($current['team_id'] ?? null) === $toTeamId) {
        return null;
    }
    if ($current !== null) {
        $blocked = teamsBlockedFromMemberMove([$userId], [$userId => $current]);
        if (!empty($blocked)) {
            return t('team.move.leader_blocked', ['teams' => implode(', ', $blocked)]);
        }
    }

    missionTeamsTransaction(function () use ($missionId, $userId, $toTeamId) {
        if ($toTeamId === null) {
            dbExecute("DELETE FROM mission_team_members WHERE mission_id = ? AND user_id = ?", [$missionId, $userId]);
        } else {
            applyTeamMemberMoves($missionId, [$userId => $toTeamId]);
        }
    });
    logAudit('move_mission_team_member', 'missions', $missionId, null, [
        'user_id' => $userId,
        'name'    => $names[$userId],
        'from'    => $current['label'] ?? null,
        'to'      => $toTeam ? teamLabel($toTeam['codename'], $toTeam['team_number']) : null,
        'by'      => $byUserId,
    ]);
    if ($toTeam) {
        $toTeam['member_ids'][] = $userId;
        missionTeamAnnounce($mission, $toTeam, [$userId]);
    }
    return null;
}

/**
 * Deletes a team and tells the people who were on it. They stay approved on the mission and keep their GPS as it
 * is; they are simply on no team. Returns the team's label, or null when there is no such team on the mission.
 */
function deleteMissionTeam(array $mission, int $teamId): ?string {
    $missionId = (int) $mission['id'];
    $team = dbFetchOne("SELECT * FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
    if (!$team) {
        return null;
    }
    $formerMembers = dbFetchAll(
        "SELECT mtm.user_id, u.name FROM mission_team_members mtm JOIN users u ON u.id = mtm.user_id WHERE mtm.team_id = ?",
        [$teamId]
    );
    dbExecute("DELETE FROM mission_teams WHERE id = ?", [$teamId]);
    logAudit('delete_mission_team', 'mission_teams', $teamId, ['mission_id' => $missionId], null);

    $label = teamLabel($team['codename'], $team['team_number']);
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $formerMemberLangs = getUserLanguages(array_column($formerMembers, 'user_id'));
    foreach ($formerMembers as $member) {
        $lang = $formerMemberLangs[(int) $member['user_id']] ?? DEFAULT_LANGUAGE;
        sendNotification(
            (int) $member['user_id'],
            t('team.delete.notify_title', [], $lang),
            t('team.delete.notify_message', ['team' => $label, 'mission' => $mission['title']], $lang),
            'warning', '', ['url' => $warRoomUrl]
        );
    }
    return $label;
}

// ── GPS ──────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Switches GPS on or off for the people named, who must be approved on the mission: one person, a team, or
 * everybody. The same row the switch of the Action Room's participants card writes (setActionRoomParticipation()),
 * with the same audit line for each person whose state really changed. Returns how many changed.
 */
function setMissionTeamsGps(array $mission, array $userIds, bool $on, int $byUserId): int {
    $missionId = (int) $mission['id'];
    $names = missionTeamApprovedNames($missionId);
    $changed = 0;
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        if (!isset($names[$userId])) {
            continue;
        }
        if (setActionRoomParticipation($missionId, $userId, $on, $byUserId)) {
            $changed++;
            logAudit($on ? 'action_room_gps_on' : 'action_room_gps_off', 'missions', $missionId, null, ['user_id' => $userId, 'name' => $names[$userId]]);
        }
    }
    return $changed;
}

// ── The page ─────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Everything the «Ομάδες» page draws: the teams in the order they were made, each with its members, and everybody
 * approved on the mission. Colours are handed over as the [background, text] pair the badges of the Action Room use.
 */
function missionTeamsPageState(int $missionId): array {
    forgetActionRoomParticipantIds($missionId);
    $roster = missionTeamRoster($missionId);
    $teams = [];
    foreach (dbFetchAll(
        "SELECT id, codename, team_number, color, transport, leader_id FROM mission_teams WHERE mission_id = ? ORDER BY created_at, id",
        [$missionId]
    ) as $row) {
        $teams[(int) $row['id']] = [
            'id'         => (int) $row['id'],
            'label'      => teamLabel($row['codename'], $row['team_number']),
            'codename'   => (string) $row['codename'],
            'colors'     => teamBadgeColors($row['color']),
            'transport'  => normalizeTeamTransport($row['transport']),
            'leader_id'  => $row['leader_id'] !== null ? (int) $row['leader_id'] : null,
            'member_ids' => [],
        ];
    }
    $people = [];
    foreach ($roster as $person) {
        // A membership row whose team is gone cannot exist (the rows cascade), but a person must never vanish from
        // the page because of one: no team it is.
        if ($person['team_id'] !== null && !isset($teams[$person['team_id']])) {
            $person['team_id'] = null;
        }
        if ($person['team_id'] !== null) {
            $teams[$person['team_id']]['member_ids'][] = $person['user_id'];
        }
        $people[] = [
            'id'          => $person['user_id'],
            'name'        => $person['name'],
            'home'        => $person['home_name'],
            'home_colors' => $person['home_name'] !== '' ? teamBadgeColors($person['home_color']) : null,
            'country'     => $person['country'],
            'external'    => $person['is_external'],
            'captain'     => $person['is_captain'],
            'team_id'     => $person['team_id'],
            'gps'         => $person['gps'],
        ];
    }
    return ['teams' => array_values($teams), 'people' => $people];
}

/**
 * The automatic split as the page shows it before it is made: the teams, by member ids, in the order they would be
 * made. The same plan autoCreateMissionTeams() carries out.
 */
function missionTeamsAutoPreview(int $missionId, int $size, bool $redo): array {
    return planAutoTeams(missionTeamAutoPool(missionTeamRoster($missionId), $redo), $size);
}

/** The keys, besides everything called teamsp.*, that assets/js/mission-teams.js asks t() for. */
function missionTeamsPageExtraStringKeys(): array {
    return [
        'common.cancel', 'common.save', 'common.loading', 'common.send_failed',
        'teams.transport.foot', 'teams.transport.motorbike', 'teams.transport.car',
        'teams.delete_confirm', 'teams.delete_btn_title', 'teams.empty',
        'gps_participant.label', 'gps_participant.tick_title', 'captain.label', 'guest.org_unknown',
    ];
}

/**
 * The words the «Ομάδες» page's script needs, in the viewer's language and in the default one it falls back to:
 * ['strings' => [...], 'fallback' => [...]]. Like the Scenario page, a tenth of the Action Room's table.
 */
function missionTeamsPageStrings(string $lang): array {
    $all = loadLangStrings('war-room');
    $extra = array_flip(missionTeamsPageExtraStringKeys());
    $pick = function (array $table) use ($extra): array {
        return array_filter($table, fn($key) => str_starts_with((string) $key, 'teamsp.') || isset($extra[$key]), ARRAY_FILTER_USE_KEY);
    };
    return [
        'strings'  => $pick($all[$lang] ?? []),
        'fallback' => $pick($all[DEFAULT_LANGUAGE] ?? []),
    ];
}
