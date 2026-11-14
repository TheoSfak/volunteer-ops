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
    $status = dbFetchValue("SELECT status FROM vehicles WHERE id = ?", [$vehicleId]);
    if ($status !== 'available') {
        return ['success' => false, 'error' => 'Το όχημα δεν είναι διαθέσιμο (' . (getVehicleStatuses()[$status] ?? $status) . ') και δεν μπορεί να παραληφθεί.'];
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

    $flaggedLabels = [];
    foreach ($checks as $itemId => $check) {
        if (($check['status'] ?? 'ok') !== 'ok') {
            $item = dbFetchOne("SELECT label FROM vehicle_checklist_items WHERE id = ?", [(int) $itemId]);
            $flaggedLabels[] = ($item['label'] ?? ('#' . $itemId)) . ' (' . (getChecklistStatuses()[$check['status']] ?? $check['status']) . ')';
        }
    }
    $flagged = !empty($flaggedLabels);
    if ($flagged) {
        openMaintenance(
            $vehicleId,
            'repair',
            'Βλάβη/έλλειψη στο checklist επιστροφής: ' . implode(', ', $flaggedLabels),
            null,
            null,
            $km,
            $userId
        );
    }

    return ['success' => true, 'flagged' => $flagged];
}

// =============================================
// REFUEL + MAINTENANCE COST LOG, M3
// =============================================

/** Maintenance types, in display order. */
function getMaintenanceTypes(): array {
    return [
        'scheduled'  => 'Προγραμματισμένη',
        'repair'     => 'Επισκευή',
        'inspection' => 'Έλεγχος / ΚΤΕΟ',
    ];
}

/** Open (unresolved) maintenance records for one vehicle. */
function getOpenMaintenanceRecords(int $vehicleId): array {
    return dbFetchAll(
        "SELECT * FROM vehicle_maintenance WHERE vehicle_id = ? AND status = 'open' ORDER BY opened_at DESC",
        [$vehicleId]
    );
}

/** Every open maintenance record across the fleet. */
function getAllOpenMaintenanceRecords(): array {
    return dbFetchAll(
        "SELECT m.*, v.label AS vehicle_label, v.plate_number
         FROM vehicle_maintenance m
         JOIN vehicles v ON v.id = m.vehicle_id
         WHERE m.status = 'open'
         ORDER BY m.opened_at"
    );
}

/** One vehicle's full maintenance history, most recent first. */
function getVehicleMaintenanceHistory(int $vehicleId): array {
    return dbFetchAll(
        "SELECT * FROM vehicle_maintenance WHERE vehicle_id = ? ORDER BY opened_at DESC",
        [$vehicleId]
    );
}

/**
 * Open a maintenance record. Moves the vehicle to status='maintenance',
 * unless it is already 'out_of_service' - that is a deliberate retirement,
 * never silently reversed by a maintenance ticket, automatic or not.
 */
function openMaintenance(int $vehicleId, string $type, string $description, ?float $cost, ?string $workshop, ?float $odometer, ?int $userId): array {
    if (!array_key_exists($type, getMaintenanceTypes())) {
        $type = 'repair';
    }
    $maintenanceId = (int) dbInsert(
        "INSERT INTO vehicle_maintenance (vehicle_id, type, description, cost, workshop, odometer_value, opened_by, opened_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$vehicleId, $type, $description ?: null, $cost, $workshop ?: null, $odometer, $userId]
    );
    dbExecute("UPDATE vehicles SET status = 'maintenance' WHERE id = ? AND status != 'out_of_service'", [$vehicleId]);

    return ['success' => true, 'maintenance_id' => $maintenanceId];
}

/**
 * Close a maintenance record. If no other maintenance record for the same
 * vehicle is still open, the vehicle returns to 'available' - an admin who
 * wants it out_of_service instead can still set that by hand on vehicles.php.
 */
function closeMaintenance(int $maintenanceId, int $userId, ?string $notes, ?float $cost): array {
    $record = dbFetchOne("SELECT * FROM vehicle_maintenance WHERE id = ?", [$maintenanceId]);
    if (!$record || $record['status'] !== 'open') {
        return ['success' => false, 'error' => 'Η εργασία συντήρησης δεν είναι ανοιχτή.'];
    }

    dbExecute(
        "UPDATE vehicle_maintenance SET status = 'done', closed_by = ?, closed_at = NOW(), notes = ?, cost = COALESCE(?, cost) WHERE id = ?",
        [$userId, $notes ?: null, $cost, $maintenanceId]
    );

    $stillOpen = (int) dbFetchValue(
        "SELECT COUNT(*) FROM vehicle_maintenance WHERE vehicle_id = ? AND status = 'open'",
        [$record['vehicle_id']]
    );
    if ($stillOpen === 0) {
        dbExecute("UPDATE vehicles SET status = 'available' WHERE id = ? AND status = 'maintenance'", [$record['vehicle_id']]);
    }

    return ['success' => true];
}

/** Record a refuel. Updates the vehicle's odometer when a reading is given. */
function addRefuel(int $vehicleId, ?float $liters, ?float $cost, ?float $odometer, ?string $station, ?int $userId): int {
    $refuelId = (int) dbInsert(
        "INSERT INTO vehicle_refuels (vehicle_id, liters, cost, odometer_value, station, user_id)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$vehicleId, $liters, $cost, $odometer, $station ?: null, $userId]
    );
    if ($odometer !== null) {
        dbExecute("UPDATE vehicles SET odometer_value = ? WHERE id = ?", [$odometer, $vehicleId]);
    }
    return $refuelId;
}

/** One vehicle's refuel history, most recent first. */
function getVehicleRefuels(int $vehicleId): array {
    return dbFetchAll(
        "SELECT * FROM vehicle_refuels WHERE vehicle_id = ? ORDER BY created_at DESC",
        [$vehicleId]
    );
}

/** Total fuel/maintenance cost and liters for one vehicle - the logbook's cost summary. */
function getVehicleCostSummary(int $vehicleId): array {
    $fuel = dbFetchOne(
        "SELECT COALESCE(SUM(cost), 0) AS total_cost, COALESCE(SUM(liters), 0) AS total_liters
         FROM vehicle_refuels WHERE vehicle_id = ?",
        [$vehicleId]
    );
    $maintenance = dbFetchOne(
        "SELECT COALESCE(SUM(cost), 0) AS total_cost FROM vehicle_maintenance WHERE vehicle_id = ?",
        [$vehicleId]
    );
    return [
        'fuel_cost'        => (float) $fuel['total_cost'],
        'fuel_liters'      => (float) $fuel['total_liters'],
        'maintenance_cost' => (float) $maintenance['total_cost'],
        'total_cost'       => (float) $fuel['total_cost'] + (float) $maintenance['total_cost'],
    ];
}
