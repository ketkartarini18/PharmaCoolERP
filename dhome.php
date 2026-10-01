<?php
session_start();

// Gatekeeper check
if (!isset($_SESSION['emp_id']) || $_SESSION['role'] !== 'driver') {
    header("Location: ../index.php?error=unauthorized");
    exit();
}

$driver_id = $_SESSION['emp_id'];
$username = $_SESSION['username'];
$first_name = $_SESSION['name'];

// Lock the entire page into the professor's simulated timeline
$today_sql = "'2026-03-16'";

// Connection
$conn = new mysqli("mydb.itap.purdue.edu", "g1154084", "Group1!", "g1154084");

//Determine which page number visitor is on
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

//Define how many results per page
$results_per_page = 5;
$offset = ($page - 1) * $results_per_page;

// ---------------------------------------------------------
// Determine Status Filter
// ---------------------------------------------------------
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : 'All';

// Safely append the status condition to our SQL if a specific status is chosen
$status_sql = "";
if ($status_filter !== 'All') {
    $safe_status = $conn->real_escape_string($status_filter);
    $status_sql = " AND s.Status = '$safe_status'";
}

// ---------------------------------------------------------
// Get total rows to calculate total pages (Updated)
// ---------------------------------------------------------
$sql_count = "SELECT COUNT(DISTINCT s.ShipmentID) 
              FROM LotCustodyEvent lce
              JOIN ShipmentLot sl ON (lce.VendorID = sl.VendorID AND lce.BatchNumber = sl.BatchNumber AND lce.LotSeq = sl.LotSeq)
              JOIN Shipment s ON sl.ShipmentID = s.ShipmentID
              WHERE lce.EmployeeID = ?" . $status_sql;
$stmt_ct = $conn->prepare($sql_count);
$stmt_ct->bind_param("s", $driver_id);
$stmt_ct->execute();
$stmt_ct->bind_result($total_rows);
$stmt_ct->fetch();
$stmt_ct->close();
$total_pages = ceil($total_rows / $results_per_page);


// ---------------------------------------------------------
// Shipments this month calculation
// ---------------------------------------------------------
// 1. Ensure your simulated date is defined at the top of the file
$today_sql = '2026-03-16'; 

// 2. The SQL Query: Hardcoding 3 (March) and 2026 to match the requirement
$sql_month_shipments = "SELECT COUNT(DISTINCT s.ShipmentID) 
    FROM LotCustodyEvent lce
    JOIN ShipmentLot sl ON lce.VendorID = sl.VendorID AND lce.BatchNumber = sl.BatchNumber AND lce.LotSeq = sl.LotSeq
    JOIN Shipment s ON sl.ShipmentID = s.ShipmentID
    WHERE lce.EmployeeID = ? 
      AND MONTH(s.DepartureTime) = MONTH(?) 
      AND YEAR(s.DepartureTime) = YEAR(?)";

$stmt_month = $conn->prepare($sql_month_shipments);

if ($stmt_month) {
    // We pass the simulated date twice to fill both the MONTH and YEAR placeholders
    $stmt_month->bind_param("sss", $_SESSION['emp_id'], $today_sql, $today_sql);
    $stmt_month->execute();
    $stmt_month->bind_result($res_total_shipments);
    $stmt_month->fetch();
    $stmt_month->close();
} else {
    $res_total_shipments = 0; // Fallback if query fails
}

// ---------------------------------------------------------
// Calculate D-KPI-1: On-Time Delivery Rate
// ---------------------------------------------------------
$sql_kpi1 = "SELECT 
    (COUNT(DISTINCT CASE 
        WHEN TIMESTAMPDIFF(MINUTE, s.DepartureTime, s.ArrivalTime) <= route_avg.AvgTransit 
        THEN s.ShipmentID 
        ELSE NULL 
    END) / COUNT(DISTINCT s.ShipmentID)) * 100 AS OnTimeRate
FROM LotCustodyEvent lce
JOIN ShipmentLot sl ON lce.VendorID = sl.VendorID 
    AND lce.BatchNumber = sl.BatchNumber 
    AND lce.LotSeq = sl.LotSeq
JOIN Shipment s ON sl.ShipmentID = s.ShipmentID
JOIN (
    SELECT 
        OriginWarehouseID, 
        DestinationWarehouseID, 
        DestinationClinicID,
        AVG(TIMESTAMPDIFF(MINUTE, DepartureTime, ArrivalTime)) AS AvgTransit
    FROM Shipment
    WHERE Status = 'Delivered' AND ArrivalTime IS NOT NULL
    GROUP BY OriginWarehouseID, DestinationWarehouseID, DestinationClinicID
) route_avg 
    ON s.OriginWarehouseID = route_avg.OriginWarehouseID
    AND (s.DestinationWarehouseID <=> route_avg.DestinationWarehouseID)
    AND (s.DestinationClinicID <=> route_avg.DestinationClinicID)
WHERE lce.EmployeeID = ? 
    AND s.Status = 'Delivered'";

$stmt_kpi1 = $conn->prepare($sql_kpi1);
if ($stmt_kpi1) {
    $stmt_kpi1->bind_param("s", $driver_id);
    $stmt_kpi1->execute();
    $stmt_kpi1->bind_result($on_time_rate);
    $stmt_kpi1->fetch();
    $stmt_kpi1->close();
    $display_kpi1 = ($on_time_rate !== null) ? number_format($on_time_rate, 1) . '%' : 'N/A';
} else {
    $display_kpi1 = "SQL Error";
}

// ---------------------------------------------------------
// Calculate D-KPI-2: Temperature Excursion Rate
// ---------------------------------------------------------
// Uses correct MinTempRating and MaxTempRating columns
$sql_kpi2 = "SELECT 
    (COUNT(DISTINCT CASE WHEN sr.Temperature < v.MinTempRating OR sr.Temperature > v.MaxTempRating THEN s.ShipmentID ELSE NULL END) 
    / COUNT(DISTINCT s.ShipmentID)) * 100 AS ExcursionRate
FROM LotCustodyEvent lce
JOIN ShipmentLot sl ON lce.VendorID = sl.VendorID 
    AND lce.BatchNumber = sl.BatchNumber 
    AND lce.LotSeq = sl.LotSeq
JOIN Shipment s ON sl.ShipmentID = s.ShipmentID
JOIN Vehicle v ON s.VehicleID = v.VehicleID
JOIN Sensor sen ON v.VehicleID = sen.VehicleID
JOIN SensorReading sr ON sen.SensorID = sr.SensorID 
    AND sr.ShipmentID = s.ShipmentID
WHERE lce.EmployeeID = ?";

$stmt_kpi2 = $conn->prepare($sql_kpi2);
if ($stmt_kpi2) {
    $stmt_kpi2->bind_param("s", $driver_id);
    $stmt_kpi2->execute();
    $stmt_kpi2->bind_result($excursion_rate);
    $stmt_kpi2->fetch();
    $stmt_kpi2->close();
    $display_kpi2 = ($excursion_rate !== null) ? number_format($excursion_rate, 1) : '0.0';
} else {
    $display_kpi2 = "SQL Error";
}
// ---------------------------------------------------------
// Calculate D-KPI-3: Missing Reading Rate
// ---------------------------------------------------------
$sql_kpi3 = "SELECT
    (SUM(CASE WHEN sr.ReadingStatus = 'Missing' THEN 1 ELSE 0 END)
    / COUNT(sr.ReadingStatus)) * 100 AS MissingReadingRate
FROM LotCustodyEvent lce
JOIN ShipmentLot sl ON lce.VendorID = sl.VendorID
    AND lce.BatchNumber = sl.BatchNumber
    AND lce.LotSeq = sl.LotSeq
JOIN Shipment s ON sl.ShipmentID = s.ShipmentID
JOIN Vehicle v ON s.VehicleID = v.VehicleID
JOIN Sensor sen ON v.VehicleID = sen.VehicleID
JOIN SensorReading sr ON sen.SensorID = sr.SensorID
    AND sr.ShipmentID = s.ShipmentID
WHERE lce.EmployeeID = ?";

$stmt_kpi3 = $conn->prepare($sql_kpi3);

// FAILSAFE: Only execute if the query is valid
if ($stmt_kpi3) {
    $stmt_kpi3->bind_param("s", $driver_id);
    $stmt_kpi3->execute();
    $stmt_kpi3->store_result();
    $stmt_kpi3->bind_result($missing_reading_rate);
    $stmt_kpi3->fetch();
    $stmt_kpi3->close();
    $display_kpi3 = ($missing_reading_rate !== null) ? number_format($missing_reading_rate, 1) . '%' : 'N/A';
} else {
    $display_kpi3 = "SQL Error";
}
// ---------------------------------------------------------
// OPT-D-1: Breach Frequency by Shipment over a Date Range
// ---------------------------------------------------------
$opt_d1_t1    = isset($_GET['opt_d1_t1'])    ? $_GET['opt_d1_t1']         : date('Y-m-01');       // default: first of this month
$opt_d1_t2    = isset($_GET['opt_d1_t2'])    ? $_GET['opt_d1_t2']         : date('Y-m-d');        // default: today
$opt_d1_delta = isset($_GET['opt_d1_delta']) ? (int)$_GET['opt_d1_delta'] : 5;                    // default threshold δ = 5

$sql_optd1 = "SELECT 
    s.ShipmentID, 
    s.DepartureTime, 
    COUNT(CASE 
        WHEN sr.Temperature < BOUNDS.BMin OR sr.Temperature > BOUNDS.BMax 
        THEN sr.ReadingID 
        ELSE NULL 
    END) AS OutOfRange
FROM LotCustodyEvent lce
JOIN ShipmentLot sl ON lce.VendorID = sl.VendorID 
                   AND lce.BatchNumber = sl.BatchNumber 
                   AND lce.LotSeq = sl.LotSeq
JOIN Shipment s    ON sl.ShipmentID = s.ShipmentID
JOIN Vehicle v     ON s.VehicleID = v.VehicleID
JOIN Sensor sen    ON v.VehicleID = sen.VehicleID
JOIN SensorReading sr ON sen.SensorID = sr.SensorID 
                      AND sr.ShipmentID = s.ShipmentID
JOIN (
    -- Get the TIGHTEST bounds across all batches on the shipment
    SELECT 
        sl_sub.ShipmentID,
        MAX(b.MinStorageTemp) AS BMin, 
        MIN(b.MaxStorageTemp) AS BMax
    FROM ShipmentLot sl_sub
    JOIN Batch b ON sl_sub.VendorID = b.VendorID 
                AND sl_sub.BatchNumber = b.BatchNumber
    GROUP BY sl_sub.ShipmentID
) AS BOUNDS ON s.ShipmentID = BOUNDS.ShipmentID
WHERE lce.EmployeeID = ? 
  AND s.DepartureTime >= ? 
  AND s.DepartureTime <= ?
GROUP BY s.ShipmentID, s.DepartureTime
ORDER BY s.DepartureTime ASC";

$opt_d1_results = array();
$stmt_optd1 = $conn->prepare($sql_optd1);

if ($stmt_optd1) {
    // Append time to the end date so it includes events up to 23:59:59 on that day
    $t2_end_of_day = $opt_d1_t2 . " 23:59:59";
    $stmt_optd1->bind_param("sss", $driver_id, $opt_d1_t1, $t2_end_of_day);
    $stmt_optd1->execute();
    $stmt_optd1->bind_result($res_d1_sid, $res_d1_dep, $res_d1_oor);
    while ($stmt_optd1->fetch()) {
        $opt_d1_results[] = array(
            'sid'          => $res_d1_sid,
            'dep_time'     => $res_d1_dep,
            'out_of_range' => $res_d1_oor
        );
    }
    $stmt_optd1->close();
}

// ---------------------------------------------------------
// OPT-D-2: Missing Reading Rate per Trip over a Date Range
// ---------------------------------------------------------
$opt_d2_t1        = isset($_GET['opt_d2_t1'])        ? $_GET['opt_d2_t1']          : date('Y-m-01');
$opt_d2_t2        = isset($_GET['opt_d2_t2'])        ? $_GET['opt_d2_t2']          : date('Y-m-d');
$opt_d2_threshold = isset($_GET['opt_d2_threshold']) ? (float)$_GET['opt_d2_threshold'] : 5.0;   // default 5%

$sql_optd2 = "SELECT
    s.ShipmentID,
    s.DepartureTime,
    (SUM(CASE WHEN sr.ReadingStatus = 'Missing' THEN 1 ELSE 0 END)
     / COUNT(sr.ReadingStatus)) * 100 AS MissingRate
FROM LotCustodyEvent lce
JOIN ShipmentLot sl ON lce.VendorID    = sl.VendorID
                   AND lce.BatchNumber = sl.BatchNumber
                   AND lce.LotSeq      = sl.LotSeq
JOIN Shipment s    ON sl.ShipmentID    = s.ShipmentID
JOIN Vehicle v     ON s.VehicleID      = v.VehicleID
JOIN Sensor sen    ON v.VehicleID      = sen.VehicleID
JOIN SensorReading sr ON sen.SensorID  = sr.SensorID
                      AND sr.ShipmentID = s.ShipmentID
WHERE lce.EmployeeID   = ?
  AND s.DepartureTime >= ?
  AND s.DepartureTime <= ?
GROUP BY s.ShipmentID, s.DepartureTime
ORDER BY s.DepartureTime DESC";

$opt_d2_results = array();
$stmt_optd2 = $conn->prepare($sql_optd2);
// FAILSAFE: Only execute if the query is valid
if ($stmt_optd2) {
    $stmt_optd2->bind_param("sss", $driver_id, $opt_d2_t1, $opt_d2_t2);
    $stmt_optd2->execute();
    $stmt_optd2->store_result();
    $stmt_optd2->bind_result($res_d2_sid, $res_d2_dep, $res_d2_rate);
    while ($stmt_optd2->fetch()) {
        $opt_d2_results[] = array(
            'sid'          => $res_d2_sid,
            'dep_time'     => $res_d2_dep,
            'missing_rate' => $res_d2_rate
        );
    }
    $stmt_optd2->close();
}
// ---------------------------------------------------------
// Main Shipment table query (Updated)
// ---------------------------------------------------------
$sql_shpTb = "SELECT 
    s.ShipmentID, MAX(s.OriginWarehouseID), MAX(s.DepartureTime), MAX(s.ArrivalTime),
    MAX(s.Status), MAX(s.DestinationType),
    MAX(CASE WHEN s.DestinationType = 'Clinic' THEN c.ClinicName ELSE s.DestinationWarehouseID END) AS DestinationDisplay,
    COUNT(sl.LotSeq) AS LotCount
    FROM LotCustodyEvent lce
    JOIN ShipmentLot sl ON (lce.VendorID = sl.VendorID AND lce.BatchNumber = sl.BatchNumber AND lce.LotSeq = sl.LotSeq)
    JOIN Shipment s ON sl.ShipmentID = s.ShipmentID
    LEFT JOIN Clinic c ON s.DestinationClinicID = c.ClinicID
    WHERE lce.EmployeeID = ?" . $status_sql . "
    GROUP BY s.ShipmentID
    ORDER BY FIELD(MAX(s.Status), 'In Transit', 'Scheduled', 'Delivered', 'Delayed') ASC, MAX(s.DepartureTime) DESC
    LIMIT ?, ?";

$stmt_shpTb = $conn->prepare($sql_shpTb);
$stmt_shpTb->bind_param("sii", $driver_id, $offset, $results_per_page);
$stmt_shpTb->execute();
$stmt_shpTb->store_result();
$stmt_shpTb->bind_result($res_sid, $res_origin, $res_dep_time, $res_arr_time, $res_status, $res_dest_type, $res_dest_id, $res_lot_count);

// Store all main data in an array FIRST
$shipments = array();
while ($stmt_shpTb->fetch()) {
    $shipments[] = array(
        'sid' => $res_sid,
        'origin' => $res_origin,
        'dep_time' => $res_dep_time,
        'arr_time' => $res_arr_time,
        'status' => $res_status,
        'dest_id' => $res_dest_id,
        'lot_count' => $res_lot_count
    );
}
$stmt_shpTb->close(); 

// Loop through the array atomically to get temperatures
foreach ($shipments as $key => $shipment) {
    $safe_sid = $conn->real_escape_string(trim($shipment['sid']));
    
    // Uses correct MinTempRating and MaxTempRating
    $sql_temp = "SELECT sr.Temperature, v.MinTempRating, v.MaxTempRating 
                 FROM SensorReading sr
                 JOIN Sensor sen ON sr.SensorID = sen.SensorID
                 JOIN Vehicle v ON sen.VehicleID = v.VehicleID
                 WHERE sr.ShipmentID = '$safe_sid' 
                 ORDER BY sr.ReadingTime ASC"; 

    $res_temp = $conn->query($sql_temp);

    $temp_array = array();
    $alerts = 0;
    $min_safe = 0; 
    $max_safe = 0;
    $in_excursion = false; // State tracking flag for events

    if ($res_temp) {
        while ($row = $res_temp->fetch_assoc()) {
            $t = isset($row['Temperature']) ? (float)$row['Temperature'] : 0;
            $min_t = isset($row['MinTempRating']) ? (float)$row['MinTempRating'] : 0;
            $max_t = isset($row['MaxTempRating']) ? (float)$row['MaxTempRating'] : 0;

            $temp_array[] = $t;
            $min_safe = $min_t;
            $max_safe = $max_t;

            // Determine if the CURRENT ping is out of bounds
            $is_bad = ($t < $min_t || $t > $max_t);

            if ($is_bad && !$in_excursion) {
                // A new event just started
                $alerts++;
                $in_excursion = true;
            } elseif (!$is_bad) {
                // Temperature returned to normal, reset flag
                $in_excursion = false;
            }
        }
        $res_temp->free();
    }

    $shipments[$key]['temps'] = json_encode($temp_array);
    $shipments[$key]['alerts'] = $alerts;
    $shipments[$key]['min_safe'] = $min_safe;
    $shipments[$key]['max_safe'] = $max_safe;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="driver-style.css?v=<hp?p echo time(); ?>">
    <title>PharmaCool - My Deliveries</title>
    <script src="driver-scripts.js"></script>
    <script src="opt-d-1.js"></script>

</head>
<body class="erp-layout">
    <nav class="sidebar">
        <div class="logo">PharmaCool</div>
        <ul class="nav-links">
            <li class="active">My Deliveries</li>
            <li><a href="dmyvehicle.php">My Vehicle</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
        <div class="user-profile">
            <div class="avatar"><?php echo substr($first_name, 0, 1); ?></div>
            <div class="info">
                <strong><?php echo htmlspecialchars($first_name); ?></strong><br>
                <small><?php echo htmlspecialchars($username); ?></small>
                <small><?php echo htmlspecialchars($driver_id); ?></small>
                <small><?php echo htmlspecialchars($_SESSION['role']); ?></small>
            </div>
        </div>
    </nav>

    <main class="main-content">
        <header class="top-header" style="display: flex; justify-content: space-between; align-items: center; position: relative;">
            <div>
                <input type="text" id="globalSearch" size="80" placeholder="🔍 Search shipment, batch, vendor, clinic..." onkeyup="liveSearch(this.value)" autocomplete="off">
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
            <h2>Hello <?php echo htmlspecialchars($first_name); ?>, here's your overview.</h2>

        <div class="stats-row">
            <div class="card">
                <h3>📦<br><?php echo htmlspecialchars($res_total_shipments); ?></h3>
                <p>Shipments this month</p>
            </div>
            <div class="card">
                <h3>⏱️<br><?php echo htmlspecialchars($display_kpi1); ?></h3>
                <p>On-time delivery rate</p>
            </div>
            <div class="card <?php echo ($excursion_rate > 0) ? 'alert' : ''; ?>">
                <h3>❗<br><?php echo htmlspecialchars($display_kpi2); ?></h3>
                <p>Excursions per 100 deliveries</p>
            </div>
            <div class="card">
                <h3>❌<br><?php echo htmlspecialchars($display_kpi3); ?></h3>
                <p>Missing Readings Rate</p>
            </div>
            <div class="card">
                <h3>🚚<br><?php echo isset($_SESSION['veh_assigned']) ? htmlspecialchars($_SESSION['veh_assigned']) : 'N/A'; ?></h3>
                <p><?php echo isset($_SESSION['veh_status']) ? htmlspecialchars($_SESSION['veh_status']) : 'N/A'; ?></p>
            </div>
        </div>
        <!-- OPT-KPI-D-1: Breach Frequency by Shipment -->
        <details class="table-container" <?php echo isset($_GET['opt_d1_t1']) ? 'open' : ''; ?>>
            <summary>
                Breach Frequency by Shipment ▼
            </summary>
            
            <p style="margin-top: 0;">Count of out-of-range readings per shipment over a selected date range.</p>            
            <form method="GET" action="" style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 20px; background: #f7fafc; padding: 15px; border-radius: 8px;">
                <label style="display: flex; flex-direction: column; font-size: 14px; font-weight: bold;">
                    Start Date (t1):
                    <input type="date" name="opt_d1_t1" value="<?php echo htmlspecialchars($opt_d1_t1); ?>" style="padding: 6px; margin-top: 5px;">
                </label>
              
                <label style="display: flex; flex-direction: column; font-size: 14px; font-weight: bold;">
                    End Date (t2):
                    <input type="date" name="opt_d1_t2" value="<?php echo htmlspecialchars($opt_d1_t2); ?>" style="padding: 6px; margin-top: 5px;">
                </label>
                
                <label style="display: flex; flex-direction: column; font-size: 14px; font-weight: bold;">
                    Threshold (&delta;):
                    <input type="number" name="opt_d1_delta" value="<?php echo htmlspecialchars($opt_d1_delta); ?>" min="0" style="padding: 6px; margin-top: 5px; width: 80px;">
                </label>
                
                <button type="submit" class="ok-btn">Generate Chart</button>
            </form>

            <?php if(empty($opt_d1_results)): ?>
                <div style="text-align:center; padding: 40px; background: #edf2f7; color: #a0aec0; border-radius: 8px;">
                    No shipments found in this date range.
                </div>
            <?php else: ?>
                <div style="display: flex; justify-content: center; width: 100%;">
                    <canvas id="breachChart" width="800" height="400" style="max-width: 100%;"></canvas>
                </div>
                
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        const rawData = <?php echo json_encode($opt_d1_results); ?>;
                        const delta = <?php echo (int)$opt_d1_delta; ?>;
                        
                        // Map the PHP array into the two flat arrays your function expects
                        const labels = rawData.map(row => row.sid);
                        const values = rawData.map(row => row.out_of_range);
                        
                        // Ensure your custom function is available in driver-scripts.js
                        if (typeof drawBreachChart === 'function') {
                            drawBreachChart('breachChart', labels, values, delta);
                        } else {
                            console.error("drawBreachChart function is missing or not loaded.");
                        }
                    });
                </script>
            <?php endif; ?>
        </details>

        <details class="table-container" <?php echo isset($_GET['opt_d2_t1']) ? 'open' : ''; ?>>
            <summary>
                Missing Reading Rate per Trip ▼
            </summary>
            
            <p style="margin-top: 0;">Percentage of sensor readings with status "Missing" broken down per shipment.</p>

            <form method="GET" action="dhome.php" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:16px;">
                <input type="hidden" name="page" value="<?php echo $page; ?>">
                <label>From: <input type="date" name="opt_d2_t1" value="<?php echo htmlspecialchars($opt_d2_t1); ?>"></label>
                <label>To: <input type="date" name="opt_d2_t2" value="<?php echo htmlspecialchars($opt_d2_t2); ?>"></label>
                <label>Threshold (%): <input type="number" name="opt_d2_threshold" value="<?php echo htmlspecialchars($opt_d2_threshold); ?>" min="0" max="100" step="0.1" style="width:70px;"></label>
                <button type="submit" class="ok-btn">Apply</button>
            </form>

            <?php if (empty($opt_d2_results)): ?>
                <p style="text-align:center; color:#a0aec0; padding:20px;">No shipments found in this date range.</p>
            <?php else: ?>
                <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>SHIPMENT ID</th>
                            <th>DEPARTED</th>
                            <th>MISSING RATE (%)</th>
                            <th>vs THRESHOLD (<?php echo $opt_d2_threshold; ?>%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($opt_d2_results as $row):
                            $ship_year = date('Y', strtotime($row['dep_time']));
                            $ship_seq  = substr($row['sid'], -4);
                            $formatted_sid = "SHP-" . $ship_year . "-" . $ship_seq;
                            $exceeded = $row['missing_rate'] > $opt_d2_threshold;
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($formatted_sid); ?></strong></td>
                                <td><?php echo date('M d, H:i', strtotime($row['dep_time'])); ?></td>
                                <td><?php echo number_format($row['missing_rate'], 1); ?>%</td>
                                <td>
                                    <span class="status-text <?php echo $exceeded ? 'delayed' : 'delivered'; ?>">
                                        <?php echo $exceeded ? 'Exceeds threshold' : 'Within threshold'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </details>
        
        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h3 style="margin: 0;"><b>Shipment History</b></h3>
                    <p style="margin: 5px 0 0 0; color: #718096; font-size: 13px;"><?php echo $total_rows; ?> shipments found · Click any row to view detail</p>
                </div>
                
                <div class="filter-group">
                    <a href="dhome.php?status_filter=All" class="filter-pill <?php echo ($status_filter === 'All') ? 'active' : ''; ?>">All</a>
                    <a href="dhome.php?status_filter=In Transit" class="filter-pill <?php echo ($status_filter === 'In Transit') ? 'active' : ''; ?>">In Transit</a>
                    <a href="dhome.php?status_filter=Delivered" class="filter-pill <?php echo ($status_filter === 'Delivered') ? 'active' : ''; ?>">Delivered</a>
                    <a href="dhome.php?status_filter=Delayed" class="filter-pill <?php echo ($status_filter === 'Delayed') ? 'active' : ''; ?>">Delayed</a>
                    <a href="dhome.php?status_filter=Scheduled" class="filter-pill <?php echo ($status_filter === 'Scheduled') ? 'active' : ''; ?>">Scheduled</a>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>SHIPMENT ID</th>
                        <th>FROM</th>
                        <th>TO</th>
                        <th>DEPARTED</th>
                        <th>ARRIVED</th>
                        <th>STATUS</th>
                        <th>LOTS</th>
                        <th>TEMP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($shipments)): ?>
                        <?php foreach($shipments as $shp): 
                            $ship_year = date('Y', strtotime($shp['dep_time'])); 
                            $ship_seq = substr($shp['sid'], -4);
                            $formatted_sid = "SHP-" . $ship_year . "-" . $ship_seq;
                        ?>
                            <tr>
                                <td>
                                    <a href="shp-details.php?id=<?php echo urlencode($shp['sid']); ?>&fid=<?php echo urlencode($formatted_sid); ?>" class="shipment-link">
                                        <strong><?php echo htmlspecialchars($formatted_sid); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($shp['origin']); ?></td>
                                <td><?php echo htmlspecialchars($shp['dest_id']); ?></td>
                                <td><?php echo date('M d, H:i', strtotime($shp['dep_time'])); ?></td>
                                <td><?php echo ($shp['arr_time']) ? date('M d, H:i', strtotime($shp['arr_time'])) : '--'; ?></td>
                                <td>
                                    <span class="status-text <?php echo str_replace(' ', '-', strtolower($shp['status'])); ?>">
                                        <?php echo htmlspecialchars($shp['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $shp['lot_count']; ?> Lot/s</td>
                                <td>
                                    <div class="sparkline-container">
                                        <canvas id="spark-<?php echo $shp['sid']; ?>" width="100" height="30"></canvas>
                                        <?php if ($shp['alerts'] > 0): ?>
                                            <div class="alert-badge">
                                                <span class="alert-icon">⚠️</span> <?php echo $shp['alerts']; ?> Alert<?php echo ($shp['alerts'] > 1 ? 's' : ''); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <script>
                                        (function() {
                                            const data = <?php echo !empty($shp['temps']) ? $shp['temps'] : '[]'; ?>;
                                            const minT = <?php echo isset($shp['min_safe']) ? $shp['min_safe'] : 0; ?>;
                                            const maxT = <?php echo isset($shp['max_safe']) ? $shp['max_safe'] : 0; ?>;
                                            const canvasId = "spark-<?php echo $shp['sid']; ?>";
                                            if (typeof drawSparkline === 'function') {
                                                drawSparkline(canvasId, data, minT, maxT);
                                            }
                                        })();
                                    </script>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding: 30px; color: #a0aec0;">
                                No <strong><?php echo htmlspecialchars($status_filter); ?></strong> shipments found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="pagination">
                <?php 
                // Build the base URL so pagination keeps the filter
                $base_url = "dhome.php?status_filter=" . urlencode($status_filter) . "&page="; 
                ?>
                
                <?php if ($page > 1): ?>
                    <a href="<?php echo $base_url . ($page - 1); ?>" class="page-btn">&laquo; Prev</a>
                <?php else: ?>
                    <span class="page-btn disabled">&laquo; Prev</span>
                <?php endif; ?>
                
                <div class="page-selector">
                    <label for="pageJump">Page: </label>
                    <select id="pageJump" onchange="window.location.href='<?php echo $base_url; ?>' + this.value;">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($i == $page) ? 'selected' : ''; ?>>
                                <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <span>of <?php echo max(1, $total_pages); ?></span>
                </div>
                
                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo $base_url . ($page + 1); ?>" class="page-btn">Next &raquo;</a>
                <?php else: ?>
                    <span class="page-btn disabled">Next &raquo;</span>
                <?php endif; ?>
            </div>
        </div>
        </section>
    </main>
</body>
</html>