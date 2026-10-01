<?php
// ============================================================
// search-ajax.php  (warehouse)
// AJAX endpoint for the global search bar on warehouse pages.
// Returns up to 8 matches across Shipment, Batch, Vendor, Clinic.
//
// Result shape per row:
//   { Type, ID, VendorID, Text }
//
// VendorID is only populated for Batch matches (since BatchNumber
// is only unique per vendor in the schema). Empty string otherwise.
// ============================================================

session_start();

// Same gatekeeper as the page itself — no leaking data to anonymous callers
if (!isset($_SESSION['emp_id']) || $_SESSION['role'] !== 'warehouse staff') {
    header('Content-Type: application/json');
    die(json_encode(array()));
}

$conn = new mysqli("mydb.itap.purdue.edu", "g1154084", "Group1!", "g1154084");
if ($conn->connect_error) {
    header('Content-Type: application/json');
    die(json_encode(array()));
}

$q = isset($_GET['q']) ? $conn->real_escape_string(trim($_GET['q'])) : '';

// Don't query the DB until at least 2 characters typed
if (strlen($q) < 2) {
    header('Content-Type: application/json');
    echo json_encode(array());
    exit();
}

// Searches each table directly rather than joining through ShipmentLot
// (which is what driver/search-ajax.php does). This way Vendor matches
// return the actual VendorID, Batch matches return the actual batch,
// etc. — letting the warehouse JS route to entity-specific pages.
//
// Each branch produces the same 4 columns so UNION works cleanly.
$sql = "
    SELECT 'Shipment' AS Type,
           ShipmentID AS ID,
           ''         AS VendorID,
           ShipmentID AS Text
    FROM Shipment
    WHERE ShipmentID LIKE '%$q%'

    UNION

    SELECT 'Batch'                                   AS Type,
           BatchNumber                               AS ID,
           VendorID                                  AS VendorID,
           CONCAT(BatchNumber, ' (', VendorID, ')')  AS Text
    FROM Batch
    WHERE BatchNumber LIKE '%$q%'

    UNION

    SELECT 'Vendor' AS Type,
           VendorID AS ID,
           ''       AS VendorID,
           VendorName AS Text
    FROM Vendor
    WHERE VendorName LIKE '%$q%'

    UNION

    SELECT 'Clinic'                  AS Type,
           CAST(ClinicID AS CHAR)    AS ID,
           ''                        AS VendorID,
           ClinicName                AS Text
    FROM Clinic
    WHERE ClinicName LIKE '%$q%'

    UNION

    SELECT 'Warehouse'                                     AS Type,
           WarehouseID                                     AS ID,
           ''                                              AS VendorID,
           CONCAT(WarehouseID, ' — ', WarehouseName)       AS Text
    FROM Warehouse
    WHERE WarehouseID LIKE '%$q%' OR WarehouseName LIKE '%$q%'

    LIMIT 8
";

$result = $conn->query($sql);
$data   = array();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = array(
            'Type'     => $row['Type'],
            'ID'       => $row['ID'],
            'VendorID' => $row['VendorID'],
            'Text'     => $row['Text'],
        );
    }
    $result->free();
}

$conn->close();

header('Content-Type: application/json');
echo json_encode($data);
?>
