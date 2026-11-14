<?php
/**
 * VolunteerOps - Fleet Management
 * Vehicles (cars, motorbikes, vans, boats...) and their assignment to shifts.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** Vehicle types, in display order. */
function getVehicleTypes(): array {
    return [
        'car'       => 'Αυτοκίνητο',
        'motorbike' => 'Μοτοσυκλέτα',
        'van'       => 'Van',
        'truck'     => 'Φορτηγό',
        'boat'      => 'Σκάφος',
        'other'     => 'Άλλο',
    ];
}

function getVehicleTypeIcon(string $type): string {
    $icons = [
        'car'       => 'bi-car-front',
        'motorbike' => 'bi-scooter',
        'van'       => 'bi-truck-front',
        'truck'     => 'bi-truck',
        'boat'      => 'bi-water',
        'other'     => 'bi-question-circle',
    ];
    return $icons[$type] ?? 'bi-question-circle';
}

/** Vehicle statuses, in display order. */
function getVehicleStatuses(): array {
    return [
        'available'      => 'Διαθέσιμο',
        'maintenance'    => 'Σε συντήρηση',
        'out_of_service' => 'Εκτός λειτουργίας',
    ];
}

/**
 * Check if current user can manage the fleet (add/edit vehicles, change status).
 */
function canManageFleet(): bool {
    return isSystemAdmin() || hasPagePermission('fleet_manage');
}

/**
 * Check if current user can at least view the fleet (read-only).
 */
function canViewFleet(): bool {
    return canManageFleet() || hasPagePermission('fleet_view');
}

/**
 * Vehicles that can be newly assigned to a shift right now - i.e. active and
 * not in maintenance/out_of_service. Does NOT exclude vehicles already
 * assigned to this shift (so re-saving the form still shows them checked).
 */
function getAssignableVehicles(): array {
    return dbFetchAll(
        "SELECT id, plate_number, label, type, status
         FROM vehicles
         WHERE is_active = 1 AND status = 'available'
         ORDER BY label"
    );
}

/** All active vehicles (any status), for display purposes. */
function getAllActiveVehicles(): array {
    return dbFetchAll(
        "SELECT id, plate_number, label, type, status
         FROM vehicles
         WHERE is_active = 1
         ORDER BY label"
    );
}

/** The vehicles currently assigned to a shift. */
function getShiftVehicles(int $shiftId): array {
    return dbFetchAll(
        "SELECT v.id, v.plate_number, v.label, v.type, v.status
         FROM shift_vehicles sv
         JOIN vehicles v ON v.id = sv.vehicle_id
         WHERE sv.shift_id = ?
         ORDER BY v.label",
        [$shiftId]
    );
}

/**
 * Replace a shift's vehicle assignments with $requestedVehicleIds.
 *
 * A vehicle already assigned to this shift is always kept, even if its
 * status has since moved to maintenance/out_of_service - this only blocks
 * NEW assignment, it never silently drops one that was already made.
 * A vehicle not currently assigned is only added if it is active and
 * status = 'available'; anything else is skipped and returned so the
 * caller can tell the user.
 *
 * @return int[] vehicle ids that were requested but skipped (not assignable)
 */
function syncShiftVehicles(int $shiftId, array $requestedVehicleIds, ?int $userId): array {
    $requestedVehicleIds = array_values(array_unique(array_map('intval', $requestedVehicleIds)));

    $currentIds = array_column(getShiftVehicles($shiftId), 'id');

    $assignable = [];
    if (!empty($requestedVehicleIds)) {
        $placeholders = implode(',', array_fill(0, count($requestedVehicleIds), '?'));
        $rows = dbFetchAll(
            "SELECT id FROM vehicles WHERE id IN ($placeholders) AND is_active = 1 AND status = 'available'",
            $requestedVehicleIds
        );
        $assignable = array_column($rows, 'id');
    }

    $skipped = [];
    $finalIds = [];
    foreach ($requestedVehicleIds as $vehicleId) {
        if (in_array($vehicleId, $currentIds, true) || in_array($vehicleId, $assignable, true)) {
            $finalIds[] = $vehicleId;
        } else {
            $skipped[] = $vehicleId;
        }
    }

    dbExecute("DELETE FROM shift_vehicles WHERE shift_id = ?", [$shiftId]);
    foreach ($finalIds as $vehicleId) {
        dbInsert(
            "INSERT INTO shift_vehicles (shift_id, vehicle_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())",
            [$shiftId, $vehicleId, $userId]
        );
    }

    return $skipped;
}

// =============================================
// HANDOVER (checkout / check-in) + CHECKLIST, M2
// =============================================

/** Checklist statuses, in display order. */
function getChecklistStatuses(): array {
    return [
        'ok'      => 'Εντάξει',
        'damaged' => 'Φθορά',
        'missing' => 'Λείπει',
    ];
}

/** Checklist items that apply to a vehicle of the given type: type-specific ones plus every type-agnostic one. */
function getChecklistItemsForType(string $type): array {
    return dbFetchAll(
        "SELECT * FROM vehicle_checklist_items
         WHERE is_active = 1 AND (type = ? OR type IS NULL)
         ORDER BY sort_order, label",
        [$type]
    );
}

/** The handover a vehicle is currently out on, or null if it is checked in. */
function getOpenHandover(int $vehicleId): ?array {
    return dbFetchOne(
        "SELECT * FROM vehicle_handovers WHERE vehicle_id = ? AND checkin_at IS NULL ORDER BY id DESC LIMIT 1",
        [$vehicleId]
    ) ?: null;
}

/** Every vehicle currently checked out, with its open handover. */
function getOpenHandovers(): array {
    return dbFetchAll(
        "SELECT h.*, v.label AS vehicle_label, v.plate_number, v.type AS vehicle_type
         FROM vehicle_handovers h
         JOIN vehicles v ON v.id = h.vehicle_id
         WHERE h.checkin_at IS NULL
         ORDER BY h.checkout_at DESC"
    );
}

/** A handover's full history (checkout + checkin) for one vehicle, most recent first. */
function getVehicleHandoverHistory(int $vehicleId): array {
    return dbFetchAll(
        "SELECT * FROM vehicle_handovers WHERE vehicle_id = ? ORDER BY checkout_at DESC",
        [$vehicleId]
    );
}

/** Checklist ticks recorded for one handover, keyed "<checklist_item_id>:<stage>". */
function getHandoverChecks(int $handoverId): array {
    $rows = dbFetchAll(
        "SELECT hc.*, ci.label AS item_label
         FROM vehicle_handover_checks hc
         JOIN vehicle_checklist_items ci ON ci.id = hc.checklist_item_id
         WHERE hc.handover_id = ?",
        [$handoverId]
    );
    $byKey = [];
    foreach ($rows as $row) {
        $byKey[$row['checklist_item_id'] . ':' . $row['stage']] = $row;
    }
    return $byKey;
}

/**
 * Record one stage's checklist ticks for a handover.
 * $checks: [checklist_item_id => ['status' => 'ok'|'damaged'|'missing', 'note' => string]]
 */
function saveHandoverChecks(int $handoverId, string $stage, array $checks): void {
    foreach ($checks as $itemId => $check) {
        $status = in_array($check['status'] ?? 'ok', ['ok', 'damaged', 'missing'], true) ? $check['status'] : 'ok';
        $note = trim($check['note'] ?? '') ?: null;
        dbExecute(
            "INSERT INTO vehicle_handover_checks (handover_id, checklist_item_id, stage, status, note)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note)",
            [$handoverId, (int) $itemId, $stage, $status, $note]
        );
    }
}

/**
 * Check a vehicle out: opens a handover. Fails if one is already open.
 * @return array{success:bool, error?:string, handover_id?:int}
 */
function openHandover(int $vehicleId, ?int $shiftId, int $userId, ?float $km, ?int $fuelLevel, string $notes, array $checks): array {
    if (getOpenHandover($vehicleId)) {
        return ['success' => false, 'error' => 'Το όχημα είναι ήδη εκτός - δεν έχει επιστραφεί.'];
    }

    $handoverId = (int) dbInsert(
        "INSERT INTO vehicle_handovers (vehicle_id, shift_id, checkout_by, checkout_at, checkout_km, checkout_fuel_level, checkout_notes)
         VALUES (?, ?, ?, NOW(), ?, ?, ?)",
        [$vehicleId, $shiftId ?: null, $userId, $km, $fuelLevel, $notes ?: null]
    );
    saveHandoverChecks($handoverId, 'checkout', $checks);

    if ($km !== null) {
        dbExecute("UPDATE vehicles SET odometer_value = ? WHERE id = ?", [$km, $vehicleId]);
    }

    return ['success' => true, 'handover_id' => $handoverId];
}

/**
 * Check a vehicle back in: closes its open handover.
 * Any checkin tick other than 'ok' moves the vehicle into maintenance - a
 * handover is precisely where damage from use actually gets noticed.
 * @return array{success:bool, error?:string, flagged?:bool}
 */
function closeHandover(int $vehicleId, int $userId, ?float $km, ?int $fuelLevel, string $notes, array $checks): array {
    $handover = getOpenHandover($vehicleId);
    if (!$handover) {
        return ['success' => false, 'error' => 'Το όχημα δεν είναι εκτός αυτή τη στιγμή.'];
    }

    dbExecute(
        "UPDATE vehicle_handovers
         SET checkin_by = ?, checkin_at = NOW(), checkin_km = ?, checkin_fuel_level = ?, checkin_notes = ?
         WHERE id = ?",
        [$userId, $km, $fuelLevel, $notes ?: null, $handover['id']]
    );
    saveHandoverChecks($handover['id'], 'checkin', $checks);

    if ($km !== null) {
        dbExecute("UPDATE vehicles SET odometer_value = ? WHERE id = ?", [$km, $vehicleId]);
    }

    $flagged = false;
    foreach ($checks as $check) {
        if (($check['status'] ?? 'ok') !== 'ok') {
            $flagged = true;
            break;
        }
    }
    if ($flagged) {
        dbExecute("UPDATE vehicles SET status = 'maintenance' WHERE id = ? AND status != 'out_of_service'", [$vehicleId]);
    }

    return ['success' => true, 'flagged' => $flagged];
}
