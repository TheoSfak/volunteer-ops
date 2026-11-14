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
