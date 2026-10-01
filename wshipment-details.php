<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['emp_id']) || strtolower(trim($_SESSION['role'])) !== 'warehouse staff') {
    header("Location: ../index.php?error=unauthorized");
    exit();
}

$emp_id     = $_SESSION['emp_id'];
$username   = $_SESSION['username'];
$first_name = $_SESSION['name'];

$sid = '';
if (isset($_GET['sid'])) {
    $sid = trim($_GET['sid']);
} elseif (isset($_GET['id'])) {
    $sid = trim($_GET['id']);
}

if ($sid === '') {
    die("No shipment ID provided.");
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$conn = new mysqli("mydb.itap.purdue.edu", "g1154084", "Group1!", "g1154084");
if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}

$msg = '';
$err = '';

// ---------------------------------------------------------
// Fetch shipment info first, needed for forms
// ---------------------------------------------------------
$sql_card = "SELECT s.ShipmentID, s.OriginWarehouseID, s.DestinationType,
                    s.DestinationWarehouseID, s.DestinationClinicID, s.VehicleID,
                    s.DepartureTime, s.ArrivalTime, s.Status,
                    ow.WarehouseName AS OriginName,
                    dw.WarehouseName AS DestWarehouseName,
                    c.ClinicName AS DestClinicName
             FROM Shipment s
             JOIN Warehouse ow ON s.OriginWarehouseID = ow.WarehouseID
             LEFT JOIN Warehouse dw ON s.DestinationWarehouseID = dw.WarehouseID
             LEFT JOIN Clinic c ON s.DestinationClinicID = c.ClinicID
             WHERE s.ShipmentID = ?";

$stmt = $conn->prepare($sql_card);
if (!$stmt) die("Shipment query failed: " . h($conn->error));

$stmt->bind_param("s", $sid);
$stmt->execute();
$stmt->bind_result(
    $ShipmentID, $OriginWarehouseID, $DestinationType,
    $DestinationWarehouseID, $DestinationClinicID, $VehicleID,
    $DepartureTime, $ArrivalTime, $Status,
    $OriginName, $DestWarehouseName, $DestClinicName
);

if (!$stmt->fetch()) {
    die("Shipment not found for ID: " . h($sid));
}
$stmt->close();

$destination_display = ($DestinationType === 'Warehouse')
    ? $DestinationWarehouseID . " - " . $DestWarehouseName
    : "Clinic " . $DestClinicName;

// ---------------------------------------------------------
// POST: advance shipment status
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'advance_status') {
    $sql_up = "UPDATE Shipment
               SET Status = 'in transit'
               WHERE ShipmentID = ?
                 AND Status = 'scheduled'";
    $stmt_up = $conn->prepare($sql_up);

    if ($stmt_up) {
        $stmt_up->bind_param("s", $sid);
        $stmt_up->execute();
        $stmt_up->close();

        header("Location: wshipment-details.php?id=" . urlencode($sid) . "&msg=status");
        exit();
    } else {
        $err = "Status update failed: " . h($conn->error);
    }
}

// ---------------------------------------------------------
// POST: add custody event
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_custody_event') {
    $lot_key    = isset($_POST['lot_key']) ? trim($_POST['lot_key']) : '';
    $event_type = isset($_POST['event_type']) ? trim($_POST['event_type']) : '';
    $condition  = isset($_POST['condition']) ? trim($_POST['condition']) : 'Seal Intact';
    $zone_code  = isset($_POST['zone_code']) ? trim($_POST['zone_code']) : '';

    $valid_conditions = array('Seal Intact', 'Packaging Damaged');
    if (!in_array($condition, $valid_conditions, true)) {
        $condition = 'Seal Intact';
    }

    $parts = explode('|', $lot_key);

    if (count($parts) !== 3) {
        $err = "Invalid lot selected.";
    } elseif ($event_type !== 'load_out' && $event_type !== 'receive_in') {
        $err = "Invalid event type.";
    } elseif ($zone_code === '') {
        $err = "Zone is required.";
    } elseif ($VehicleID === '' || $VehicleID === null) {
        $err = "This shipment has no vehicle assigned.";
    } else {
        $ev_vendor = $parts[0];
        $ev_batch  = $parts[1];
        $ev_lotseq = (int)$parts[2];

        $from_location = null;
        $from_wh = null;
        $from_zone = null;
        $from_vehicle = null;
        $from_clinic = null;

        $to_location = null;
        $to_wh = null;
        $to_zone = null;
        $to_vehicle = null;
        $to_clinic = null;

        if ($event_type === 'load_out') {
            $from_location = 'Zone';
            $from_wh = $OriginWarehouseID;
            $from_zone = $zone_code;

            $to_location = 'Vehicle';
            $to_vehicle = $VehicleID;
        }

        if ($event_type === 'receive_in') {
            $from_location = 'Vehicle';
            $from_vehicle = $VehicleID;

            if ($DestinationType === 'Warehouse') {
                $to_location = 'Zone';
                $to_wh = $DestinationWarehouseID;
                $to_zone = $zone_code;
            } else {
                $to_location = 'Clinic';
                $to_clinic = $DestinationClinicID;
            }
        }

        $sql_insert = "INSERT INTO LotCustodyEvent
            (VendorID, BatchNumber, LotSeq, EventTime, EmployeeID,
             FromLocation, FromWarehouseID, FromZoneCode, FromVehicleID, FromClinicID,
             ToLocation, ToWarehouseID, ToZoneCode, ToVehicleID, ToClinicID,
             ConditionConfirmed)
            VALUES
            (?, ?, ?, NOW(), ?,
             ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?,
             ?)";

        $stmt_ins = $conn->prepare($sql_insert);

        if ($stmt_ins) {
            $stmt_ins->bind_param(
                "ssissssssssssss",
                $ev_vendor,
                $ev_batch,
                $ev_lotseq,
                $emp_id,
                $from_location,
                $from_wh,
                $from_zone,
                $from_vehicle,
                $from_clinic,
                $to_location,
                $to_wh,
                $to_zone,
                $to_vehicle,
                $to_clinic,
                $condition
            );

            if ($stmt_ins->execute()) {
                $stmt_ins->close();

                if ($event_type === 'load_out' && strtolower($Status) === 'scheduled') {
                    $stmt_stat = $conn->prepare("UPDATE Shipment SET Status = 'in transit' WHERE ShipmentID = ?");
                    if ($stmt_stat) {
                        $stmt_stat->bind_param("s", $sid);
                        $stmt_stat->execute();
                        $stmt_stat->close();
                    }
                }

                header("Location: wshipment-details.php?id=" . urlencode($sid) . "&msg=added");
                exit();
            } else {
                $err = "Could not add custody event: " . h($stmt_ins->error);
                $stmt_ins->close();
            }
        } else {
            $err = "Insert failed: " . h($conn->error);
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'added') {
    $msg = "Custody event added.";
}
if (isset($_GET['msg']) && $_GET['msg'] === 'status') {
    $msg = "Shipment status updated.";
}

// ---------------------------------------------------------
// Lot manifest
// ---------------------------------------------------------
$sql_lots = "SELECT sl.VendorID, v.VendorName, sl.BatchNumber, sl.LotSeq,
                    sl.QuantityVolume, b.ExpiryDate,
                    si.WarehouseID AS CurrentWarehouseID,
                    si.ZoneCode AS CurrentZoneCode
             FROM ShipmentLot sl
             LEFT JOIN Vendor v ON sl.VendorID = v.VendorID
             LEFT JOIN Batch b ON sl.VendorID = b.VendorID
                            AND sl.BatchNumber = b.BatchNumber
             LEFT JOIN StoredIn si ON sl.VendorID = si.VendorID
                                  AND sl.BatchNumber = si.BatchNumber
                                  AND sl.LotSeq = si.LotSeq
                                  AND si.EndTime IS NULL
             WHERE sl.ShipmentID = ?
             ORDER BY sl.VendorID, sl.BatchNumber, sl.LotSeq";

$stmt = $conn->prepare($sql_lots);
if (!$stmt) die("Lot query failed: " . h($conn->error));

$stmt->bind_param("s", $sid);
$stmt->execute();
$stmt->bind_result($LotVendorID, $VendorName, $BatchNumber, $LotSeq, $QuantityVolume, $ExpiryDate, $CurrentWarehouseID, $CurrentZoneCode);

$lots = array();
while ($stmt->fetch()) {
    $lots[] = array(
        "VendorID" => $LotVendorID,
        "VendorName" => $VendorName,
        "BatchNumber" => $BatchNumber,
        "LotSeq" => $LotSeq,
        "QuantityVolume" => $QuantityVolume,
        "ExpiryDate" => $ExpiryDate,
        "CurrentWarehouseID" => $CurrentWarehouseID,
        "CurrentZoneCode" => $CurrentZoneCode
    );
}
$stmt->close();

// ---------------------------------------------------------
// Zone options for loading/receiving
// ---------------------------------------------------------
$origin_zones = array();
$stmt = $conn->prepare("SELECT ZoneCode, Classification FROM StorageZone WHERE WarehouseID = ? ORDER BY ZoneCode");
if ($stmt) {
    $stmt->bind_param("s", $OriginWarehouseID);
    $stmt->execute();
    $stmt->bind_result($oz_code, $oz_class);
    while ($stmt->fetch()) {
        $origin_zones[] = array("ZoneCode" => $oz_code, "Classification" => $oz_class);
    }
    $stmt->close();
}

$dest_zones = array();
if ($DestinationType === 'Warehouse' && $DestinationWarehouseID !== null && $DestinationWarehouseID !== '') {
    $stmt = $conn->prepare("SELECT ZoneCode, Classification FROM StorageZone WHERE WarehouseID = ? ORDER BY ZoneCode");
    if ($stmt) {
        $stmt->bind_param("s", $DestinationWarehouseID);
        $stmt->execute();
        $stmt->bind_result($dz_code, $dz_class);
        while ($stmt->fetch()) {
            $dest_zones[] = array("ZoneCode" => $dz_code, "Classification" => $dz_class);
        }
        $stmt->close();
    }
}

// ---------------------------------------------------------
// Custody events
// ---------------------------------------------------------
$sql_events = "SELECT DISTINCT
                    lce.EventTime,
                    lce.EmployeeID,
                    e.FirstName,
                    e.LastName,
                    e.Role,
                    lce.FromLocation,
                    lce.FromWarehouseID,
                    lce.FromZoneCode,
                    lce.FromVehicleID,
                    lce.FromClinicID,
                    lce.ToLocation,
                    lce.ToWarehouseID,
                    lce.ToZoneCode,
                    lce.ToVehicleID,
                    lce.ToClinicID,
                    lce.ConditionConfirmed
               FROM LotCustodyEvent lce
               JOIN ShipmentLot sl
                 ON lce.VendorID = sl.VendorID
                AND lce.BatchNumber = sl.BatchNumber
                AND lce.LotSeq = sl.LotSeq
               JOIN Employee e ON lce.EmployeeID = e.EmployeeID
               WHERE sl.ShipmentID = ?
               ORDER BY lce.EventTime ASC";

$stmt = $conn->prepare($sql_events);
if (!$stmt) die("Custody query failed: " . h($conn->error));

$stmt->bind_param("s", $sid);
$stmt->execute();
$stmt->bind_result(
    $EventTime, $EventEmployeeID, $EventFirstName, $EventLastName, $EventRole,
    $FromLocation, $FromWarehouseID, $FromZoneCode, $FromVehicleID, $FromClinicID,
    $ToLocation, $ToWarehouseID, $ToZoneCode, $ToVehicleID, $ToClinicID,
    $ConditionConfirmed
);

$events = array();
while ($stmt->fetch()) {
    $from_display = $FromLocation;
    if ($FromLocation === 'Zone') $from_display = "Zone " . $FromWarehouseID . " / " . $FromZoneCode;
    if ($FromLocation === 'Vehicle') $from_display = "Vehicle " . $FromVehicleID;
    if ($FromLocation === 'Clinic') $from_display = "Clinic #" . $FromClinicID;

    $to_display = $ToLocation;
    if ($ToLocation === 'Zone') $to_display = "Zone " . $ToWarehouseID . " / " . $ToZoneCode;
    if ($ToLocation === 'Vehicle') $to_display = "Vehicle " . $ToVehicleID;
    if ($ToLocation === 'Clinic') $to_display = "Clinic #" . $ToClinicID;

    $events[] = array(
        "EventTime" => $EventTime,
        "Employee" => $EventFirstName . " " . $EventLastName,
        "Role" => $EventRole,
        "FromDisplay" => $from_display,
        "ToDisplay" => $to_display,
        "Condition" => $ConditionConfirmed
    );
}
$stmt->close();

// ---------------------------------------------------------
// Temperature breaches
// ---------------------------------------------------------
$sql_breaches = "SELECT StartTime, EndTime, MaxDeviation, ResolutionStatus
                 FROM ShipmentTempBreach
                 WHERE ShipmentID = ?
                 ORDER BY StartTime DESC";

$stmt = $conn->prepare($sql_breaches);
if (!$stmt) die("Breach query failed: " . h($conn->error));

$stmt->bind_param("s", $sid);
$stmt->execute();
$stmt->bind_result($BreachStart, $BreachEnd, $MaxDeviation, $ResolutionStatus);

$breaches = array();
while ($stmt->fetch()) {
    $breaches[] = array(
        "StartTime" => $BreachStart,
        "EndTime" => $BreachEnd,
        "MaxDeviation" => $MaxDeviation,
        "ResolutionStatus" => $ResolutionStatus
    );
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PharmaCool - Shipment Details</title>
    <link rel="stylesheet" href="../driver/driver-style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="warehouse-style.css?v=<?php echo time(); ?>">
    <script src="warehouse-scripts.js?v=<?php echo time(); ?>"></script>
</head>

<body class="erp-layout">
    <nav class="sidebar">
        <div class="logo">PharmaCool</div>
        <ul class="nav-links">
            <li><a href="whome.php">&laquo; Back to Dashboard</a></li>
            <li class="active">Shipment Details</li>
        </ul>
        <div class="user-profile">
            <div class="avatar"><?php echo h(substr($first_name, 0, 1)); ?></div>
            <div class="info">
                <strong><?php echo htmlspecialchars($first_name); ?></strong><br>
                <small><?php echo htmlspecialchars($username); ?>,</small>
                <small><?php echo htmlspecialchars($emp_id); ?></small><br>
                <small><?php echo htmlspecialchars($_SESSION['role']); ?></small>
            </div>
        </div>
    </nav>

    <main class="main-content">
        <header class="top-header" style="display: flex; justify-content: space-between; align-items: center; position: relative;">
            <div>
                <input type="text" id="globalSearch" size="80" placeholder="Search shipment, batch, vendor, clinic..." onkeyup="liveSearch(this.value)" autocomplete="off">
                <div id="searchResults" class="search-dropdown"></div>
            </div>

            <div style="position: relative;">
                <button id="settings-btn" class="icon-btn">
                    ⚙️
                </button>
                
                <div id="settings-dropdown" class="settings-dropdown">
                    <button id="theme-toggle" class="dropdown-item">
                        🌙 Dark Mode
                    </button>
                    
                    <div class="dropdown-divider"></div>
                    
                    <a href="../logout.php" class="dropdown-item text-danger">Logout</a>
                </div>
            </div>
        </header>

        <section class="dashboard">
            <p class="breadcrumb"><a href="wshipments.php">&larr; Back to Inbound / Outbound</a></p>

            <?php if ($msg !== ''): ?>
                <div class="flash flash-ok"><?php echo h($msg); ?></div>
            <?php endif; ?>
            <?php if ($err !== ''): ?>
                <div class="flash flash-err"><?php echo $err; ?></div>
            <?php endif; ?>

            <h2>Shipment <?php echo h($ShipmentID); ?></h2>

            <div class="stats-row">
                <div class="card">
                    <small>STATUS</small>
                    <h3><?php echo h(ucwords($Status)); ?></h3>
                    <p>Vehicle: <?php echo $VehicleID ? h($VehicleID) : '—'; ?></p>

                    <?php if (strtolower($Status) === 'scheduled'): ?>
                        <form method="post" style="margin-top:10px;">
                            <input type="hidden" name="action" value="advance_status">
                            <button type="submit" class="btn-save">Mark In Transit</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <small>ORIGIN</small>
                    <h3><?php echo h($OriginWarehouseID); ?></h3>
                    <p><?php echo h($OriginName); ?></p>
                </div>
                <div class="card">
                    <small>DESTINATION</small>
                    <h3><?php echo h($DestinationType); ?></h3>
                    <p><?php echo h($destination_display); ?></p>
                </div>
            </div>

            <div class="table-container">
                <h3>Add New Custody Event</h3>
                <p class="muted">Use this when a lot is loaded onto a vehicle or received into a warehouse/clinic.</p>

                <form method="post" class="filter-form">
                    <input type="hidden" name="action" value="add_custody_event">

                    <div class="filter-row">
                        <label>
                            Lot
                            <select name="lot_key" required>
                                <?php foreach ($lots as $lot): ?>
                                    <option value="<?php echo h($lot['VendorID'] . '|' . $lot['BatchNumber'] . '|' . $lot['LotSeq']); ?>">
                                        <?php echo h($lot['VendorID']); ?> /
                                        <?php echo h($lot['BatchNumber']); ?> /
                                        Lot <?php echo h($lot['LotSeq']); ?>
                                        <?php if ($lot['CurrentZoneCode']): ?>
                                            — currently <?php echo h($lot['CurrentWarehouseID'] . ' / ' . $lot['CurrentZoneCode']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Event Type
                            <select name="event_type" required>
                                <option value="load_out">Load out: Zone → Vehicle</option>
                                <option value="receive_in">Receive in: Vehicle → Destination</option>
                            </select>
                        </label>

                        <label>
                            Zone
                            <select name="zone_code" required>
                                <optgroup label="Origin warehouse zones">
                                    <?php foreach ($origin_zones as $z): ?>
                                        <option value="<?php echo h($z['ZoneCode']); ?>">
                                            <?php echo h($OriginWarehouseID . ' / ' . $z['ZoneCode'] . ' (' . $z['Classification'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>

                                <?php if (!empty($dest_zones)): ?>
                                    <optgroup label="Destination warehouse zones">
                                        <?php foreach ($dest_zones as $z): ?>
                                            <option value="<?php echo h($z['ZoneCode']); ?>">
                                                <?php echo h($DestinationWarehouseID . ' / ' . $z['ZoneCode'] . ' (' . $z['Classification'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </label>

                        <label>
                            Condition
                            <select name="condition" required>
                                <option value="Seal Intact">Seal Intact</option>
                                <option value="Packaging Damaged">Packaging Damaged</option>
                            </select>
                        </label>
                    </div>

                    <button type="submit" class="btn-save">Add Custody Event</button>
                </form>
            </div>

            <div class="table-container">
                <h3>Timeline</h3>
                <p><strong>Departure:</strong> <?php echo h($DepartureTime); ?></p>
                <p><strong>Arrival:</strong> <?php echo $ArrivalTime ? h($ArrivalTime) : '<span class="muted">Not arrived yet</span>'; ?></p>
            </div>

            <div class="table-container">
                <h3>Lot Manifest</h3>
                <table>
                    <thead>
                        <tr>
                            <th>VENDOR</th>
                            <th>BATCH</th>
                            <th>LOT</th>
                            <th>QTY</th>
                            <th>EXPIRY</th>
                            <th>CURRENT LOCATION</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($lots)): ?>
                        <?php foreach ($lots as $lot): ?>
                            <tr>
                                <td><?php echo h($lot['VendorName'] ? $lot['VendorName'] : $lot['VendorID']); ?></td>
                                <td><?php echo h($lot['BatchNumber']); ?></td>
                                <td><?php echo h($lot['LotSeq']); ?></td>
                                <td><?php echo h($lot['QuantityVolume']); ?></td>
                                <td><?php echo h($lot['ExpiryDate']); ?></td>
                                <td>
                                    <?php
                                        if ($lot['CurrentWarehouseID']) {
                                            echo h($lot['CurrentWarehouseID'] . ' / ' . $lot['CurrentZoneCode']);
                                        } else {
                                            echo '<span class="muted">Not currently stored</span>';
                                        }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="no-data">No lots found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-container">
                <h3>Chain of Custody</h3>
                <table>
                    <thead>
                        <tr>
                            <th>TIME</th>
                            <th>EMPLOYEE</th>
                            <th>FROM</th>
                            <th>TO</th>
                            <th>CONDITION</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($events)): ?>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?php echo h($event['EventTime']); ?></td>
                                <td>
                                    <?php echo h($event['Employee']); ?><br>
                                    <small class="muted"><?php echo h($event['Role']); ?></small>
                                </td>
                                <td><?php echo h($event['FromDisplay']); ?></td>
                                <td><?php echo h($event['ToDisplay']); ?></td>
                                <td><?php echo h($event['Condition']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="no-data">No custody events found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-container">
                <h3>Temperature Breaches</h3>
                <table>
                    <thead>
                        <tr>
                            <th>START</th>
                            <th>END</th>
                            <th>MAX DEVIATION</th>
                            <th>STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($breaches)): ?>
                        <?php foreach ($breaches as $b): ?>
                            <tr>
                                <td><?php echo h($b['StartTime']); ?></td>
                                <td><?php echo h($b['EndTime']); ?></td>
                                <td><?php echo h($b['MaxDeviation']); ?> °C</td>
                                <td><?php echo h($b['ResolutionStatus']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="no-data">No temperature breaches found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>