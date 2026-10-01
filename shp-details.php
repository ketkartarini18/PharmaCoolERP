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

// Connection
$conn = new mysqli("mydb.itap.purdue.edu", "g1154084", "Group1!", "g1154084");

$sid = isset($_GET['id']) ? $_GET['id'] : '';
$formatted_id = isset($_GET['fid']) ? $_GET['fid'] : $sid; 

if (empty($sid)) {
    die("Error: No shipment ID provided.");
}

// ---------------------------------------------------------
// Process Condition Updates in Custody Table
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_time'])) {
    $new_condition = $_POST['new_condition'];
    $event_time = $_POST['event_time'];
    
    $sql_update = "UPDATE LotCustodyEvent lce
                   JOIN ShipmentLot sl ON lce.VendorID = sl.VendorID AND lce.BatchNumber = sl.BatchNumber AND lce.LotSeq = sl.LotSeq
                   SET lce.ConditionConfirmed = ?
                   WHERE sl.ShipmentID = ? AND lce.EmployeeID = ? AND lce.EventTime = ?";
    
    $stmt_update = $conn->prepare($sql_update);
    if ($stmt_update) {
        $stmt_update->bind_param("ssss", $new_condition, $sid, $driver_id, $event_time);
        $stmt_update->execute();
        
        if ($stmt_update->affected_rows > 0) {
            $msg_code = "success";
        } else {
            $msg_code = "error_no_change";
        }
        $stmt_update->close();
    } else {
        $msg_code = "error_db";
    }
    
    // Restored the redirect so the banners will trigger properly
    header("Location: ?id=" . urlencode($sid) . "&fid=" . urlencode($formatted_id) . "&page_coc=" . $page_coc . "&msg=" . $msg_code);
    exit();
}

// ---------------------------------------------------------
// Fetch Shipment Card Details
// ---------------------------------------------------------
$sql_card = "SELECT s.*, 
               w_orig.WarehouseName AS o_name, w_orig.StreetAddress AS o_addr, w_orig.City AS o_city, w_orig.State AS o_state,
               w_dest.WarehouseName AS dw_name, w_dest.StreetAddress AS dw_addr, w_dest.City AS dw_city, w_dest.State AS dw_state,
               c.ClinicName AS dc_name, c.StreetAddress AS dc_addr, c.City AS dc_city, c.State AS dc_state
        FROM Shipment s
        INNER JOIN Warehouse w_orig ON s.OriginWarehouseID = w_orig.WarehouseID
        LEFT JOIN Warehouse w_dest ON s.DestinationWarehouseID = w_dest.WarehouseID
        LEFT JOIN Clinic c ON s.DestinationClinicID = c.ClinicID
        WHERE s.ShipmentID = ?";
$stmt_card = $conn->prepare($sql_card);
$stmt_card->bind_param("s", $sid);
$stmt_card->execute();
$stmt_card->store_result();
$stmt_card->bind_result(
    $r_sid, $r_orig_id, $r_type, $r_dest_w, $r_dest_c, $r_veh, $r_dep, $r_arr, $r_status,
    $o_name, $o_addr, $o_city, $o_state,
    $dw_name, $dw_addr, $dw_city, $dw_state,
    $dc_name, $dc_addr, $dc_city, $dc_state);

if (!$stmt_card->fetch()) {
    die("Error: Shipment details could not be retrieved.");
}

$destName = ($r_type === 'Warehouse') ? $dw_name : $dc_name;
$destAddr = ($r_type === 'Warehouse') ? ($dw_addr . ", " . $dw_city . ", " . $dw_state) : ($dc_addr . ", " . $dc_city . ", " . $dc_state);

$res = [
    'DepartureTime' => $r_dep,
    'ArrivalTime' => $r_arr 
];
$stmt_card->close();

// ---------------------------------------------------------
// ISOLATED PAGINATION: Chain of Custody (coc)
// ---------------------------------------------------------
$page_coc = isset($_GET['page_coc']) && is_numeric($_GET['page_coc']) ? (int)$_GET['page_coc'] : 1;
if ($page_coc < 1) $page_coc = 1;
$results_per_page_coc = 5;
$offset_coc = ($page_coc - 1) * $results_per_page_coc;

// Count total events for this specific table
$sql_count_coc = "SELECT COUNT(DISTINCT lce.EventTime, lce.EmployeeID) 
                  FROM LotCustodyEvent lce
                  JOIN ShipmentLot sl ON lce.VendorID = sl.VendorID AND lce.BatchNumber = sl.BatchNumber AND lce.LotSeq = sl.LotSeq
                  WHERE sl.ShipmentID = ?";
$stmt_ct = $conn->prepare($sql_count_coc);
$stmt_ct->bind_param("s", $sid);
$stmt_ct->execute();
$stmt_ct->bind_result($total_rows_coc);
$stmt_ct->fetch();
$stmt_ct->close();
$total_pages_coc = ceil($total_rows_coc / $results_per_page_coc);

// ---------------------------------------------------------
// Fetch Chain of Custody Timeline (With Pagination)
// ---------------------------------------------------------
$sql_timeline = "SELECT DISTINCT 
                    lce.EventTime, 
                    lce.EmployeeID, 
                    e.Role, 
                    CONCAT(
                        CASE lce.FromLocation
                            WHEN 'Zone' THEN CONCAT('Zone ', lce.FromZoneCode, ', ', lce.FromWarehouseID)
                            WHEN 'Vehicle' THEN lce.FromVehicleID
                            WHEN 'Clinic' THEN CONCAT('Clinic ', lce.FromClinicID)
                            ELSE 'Unknown Origin'
                        END,
                        ' &rarr; ',
                        CASE lce.ToLocation
                            WHEN 'Zone' THEN CONCAT('Zone ', lce.ToZoneCode, ', ', lce.ToWarehouseID)
                            WHEN 'Vehicle' THEN lce.ToVehicleID
                            WHEN 'Clinic' THEN CONCAT('Clinic ', lce.ToClinicID)
                            ELSE 'Unknown Destination'
                        END
                    ) AS Movement, 
                    lce.ConditionConfirmed AS ConditionStatus
                 FROM LotCustodyEvent lce
                 JOIN ShipmentLot sl ON lce.VendorID = sl.VendorID AND lce.BatchNumber = sl.BatchNumber AND lce.LotSeq = sl.LotSeq
                 JOIN Employee e ON lce.EmployeeID = e.EmployeeID
                 WHERE sl.ShipmentID = ?
                 ORDER BY lce.EventTime ASC
                 LIMIT ?, ?";

$stmt_timeline = $conn->prepare($sql_timeline);
$timeline_events = array();

if ($stmt_timeline) {
    $stmt_timeline->bind_param("sii", $sid, $offset_coc, $results_per_page_coc);
    $stmt_timeline->execute();
    $stmt_timeline->bind_result($t_time, $t_emp, $t_role, $t_move, $t_cond);   
    while ($stmt_timeline->fetch()) {
        $timeline_events[] = array(
            'time' => $t_time,
            'emp' => $t_emp,
            'role' => $t_role,
            'move' => $t_move,
            'cond' => $t_cond
        );
    }
    $stmt_timeline->close();
}

// ---------------------------------------------------------
// Fetch Lot Manifest Data 
// ---------------------------------------------------------
$sql_manifest = "SELECT 
                    COALESCE(v.VendorName, sl.VendorID) AS DisplayVendor, 
                    sl.BatchNumber, 
                    sl.LotSeq, 
                    sl.QuantityVolume, 
                    b.ExpiryDate
                 FROM ShipmentLot sl
                 LEFT JOIN Vendor v ON sl.VendorID = v.VendorID
                 LEFT JOIN Batch b ON sl.VendorID = b.VendorID AND sl.BatchNumber = b.BatchNumber
                 WHERE sl.ShipmentID = ?
                 ORDER BY sl.VendorID, sl.BatchNumber, sl.LotSeq ASC";

$stmt_man = $conn->prepare($sql_manifest);
$manifest_lots = array();

if ($stmt_man) {
    $stmt_man->bind_param("s", $sid);
    $stmt_man->execute();
    
    $stmt_man->bind_result($m_vendor, $m_batch, $m_lot, $m_qty, $m_exp);
    
    while ($stmt_man->fetch()) {
        $manifest_lots[] = array(
            'vendor' => $m_vendor,
            'batch'  => $m_batch,
            'lot'    => $m_lot,
            'qty'    => $m_qty,
            'exp'    => $m_exp
        );
    }
    $stmt_man->close();
} else {
    die("<div style='margin: 20px; padding:20px; background:#fed7d7; color:#742a2a; border: 2px solid red; font-size: 18px; font-weight: bold;'>DATABASE SILENT CRASH: " . $conn->error . "</div>");
}

// ---------------------------------------------------------
// Fetch Temperature Chart Data
// ---------------------------------------------------------
// 1. Get vehicle, timeframe, and the overall control limits for this specific load
$sql_limits = "SELECT 
                s.VehicleID,
                s.DepartureTime,
                COALESCE(s.ArrivalTime, NOW()) as EndTime,
                MIN(b.MinStorageTemp) as OverallMinTemp,
                MAX(b.MaxStorageTemp) as OverallMaxTemp
             FROM Shipment s
             JOIN ShipmentLot sl ON s.ShipmentID = sl.ShipmentID
             JOIN Batch b ON sl.VendorID = b.VendorID AND sl.BatchNumber = b.BatchNumber
             WHERE s.ShipmentID = ?
             GROUP BY s.VehicleID, s.DepartureTime, s.ArrivalTime";

$stmt_lim = $conn->prepare($sql_limits);
$chart_labels = array();
$chart_data = array();
$chart_colors = array();
$sys_min = 2; // Fallback defaults just in case
$sys_max = 8; 

if ($stmt_lim) {
    $stmt_lim->bind_param("s", $sid);
    $stmt_lim->execute();
    $stmt_lim->bind_result($v_id, $v_dep, $v_end, $sys_min, $sys_max);
    
    if ($stmt_lim->fetch()) {
        $stmt_lim->close();
        
        // 2. Fetch the sensor readings for this vehicle during this shipment
        $sql_readings = "SELECT sr.ReadingTime, sr.Temperature
                         FROM SensorReading sr
                         JOIN Sensor sen ON sr.SensorID = sen.SensorID
                         WHERE sen.VehicleID = ? 
                           AND sr.ReadingTime >= ? 
                           AND sr.ReadingTime <= ?
                         ORDER BY sr.ReadingTime ASC";
                         
        $stmt_read = $conn->prepare($sql_readings);
        if ($stmt_read) {
            $stmt_read->bind_param("sss", $v_id, $v_dep, $v_end);
            $stmt_read->execute();
            $stmt_read->bind_result($r_time, $r_temp);
            
            while ($stmt_read->fetch()) {
                $chart_labels[] = date('H:i', strtotime($r_time));
                $chart_data[] = $r_temp;
                
                // Color logic: Red if outside control limits, Blue if safe
                if ($r_temp < $sys_min || $r_temp > $sys_max) {
                    $chart_colors[] = 'red';
                } else {
                    $chart_colors[] = '#3182ce';
                }
            }
            $stmt_read->close();
        }
    } else {
         $stmt_lim->close();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="driver-style.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="driver-scripts.js?v=<?php echo time(); ?>" defer></script>
    <title>Shipment Details</title>
    <script>
                            window.PharmaCoolChartData = {
                                labels: <?php echo json_encode($chart_labels); ?>,
                                dataPoints: <?php echo json_encode($chart_data); ?>,
                                pointColors: <?php echo json_encode($chart_colors); ?>,
                                minTemp: <?php echo (float)$sys_min; ?>,
                                maxTemp: <?php echo (float)$sys_max; ?>
                            };
    </script>
</head>

<body class="erp-layout">
    <nav class="sidebar">
        <div class="logo">PharmaCool</div>
        <ul class="nav-links">
            <li><a href="dhome.php">&laquo; Back to Dashboard</a></li>
            <li class="active">Shipment Details</li>
        </ul>
        <div class="user-profile">
            <div class="avatar"><?php echo substr($first_name, 0, 1); ?></div>
            <div class="info">
                <strong><?php echo htmlspecialchars($first_name); ?></strong><br>
                <small><?php echo htmlspecialchars($username); ?></small>
                <small><?php echo htmlspecialchars($driver_id); ?></small>
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
            
            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'success'): ?>
                    <div class="alert-banner alert-success">
                        <span>✔️</span> Condition successfully updated.
                    </div>
                <?php elseif ($_GET['msg'] === 'error_no_change'): ?>
                    <div class="alert-banner alert-error">
                        <span>⚠️</span> Update failed: You cannot modify records assigned to other drivers, or the condition was already set to this value.
                    </div>
                <?php elseif ($_GET['msg'] === 'error_db'): ?>
                    <div class="alert-banner alert-error">
                        <span>⚠️</span> Database error: Could not process the update.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <h2>Hello <?php echo htmlspecialchars($first_name); ?>, here are the details for <?php echo htmlspecialchars($formatted_id); ?> (<?php echo htmlspecialchars($r_status); ?>)</h2>
            <div class="stats-row">
                <div class="card">
                    <small>ORIGIN</small>
                    <p><strong><?php echo htmlspecialchars($o_name); ?></strong></p>
                    <p><?php echo htmlspecialchars($o_addr . ", " . $o_city . ", " . $o_state); ?></p>
                </div>
                <div class="card">
                    <small>DESTINATION (<?php echo htmlspecialchars($r_type); ?>)</small>
                    <p><strong><?php echo htmlspecialchars($destName); ?></strong></p>
                    <p><?php echo htmlspecialchars($destAddr); ?></p>
                </div>
                <div class="card">
                    <small>TIMELINE</small>
                    <p><strong>Departed:</strong> <?php echo date('M d, H:i', strtotime($res['DepartureTime'])); ?></p>
                    <p><strong>Arrived:</strong> 
                        <?php echo $res['ArrivalTime'] ? date('M d, H:i', strtotime($res['ArrivalTime'])) : '<span style="color: #d69e2e; font-weight: bold;">In Transit</span>'; ?>
                    </p>
                </div>
            </div>

            <div class="table-container" style="margin-top: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Chain of Custody Timeline</h3>
                    <a href="print-coc.php?sid=<?php echo urlencode($sid); ?>" target="_blank" class="ok-btn" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px; background-color: #4a5568;">
                        📄 Save to PDF
                    </a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Person (role)</th>
                            <th>Movement</th>
                            <th>Condition</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($timeline_events)): ?>
                            <?php foreach($timeline_events as $event): ?>
                                <tr>
                                    <td><?php echo date('M d, Y - H:i:s', strtotime($event['time'])); ?></td>
                                    
                                    <td><?php echo htmlspecialchars($event['emp'] . " (" . $event['role'] . ")"); ?></td>
                                    <td><?php echo $event['move']; // Contains HTML arrows, do not escape ?></td>
                                    <td>
                                        <?php 
                                        // Gatekeeper: Only the exact driver who owns this event can change it
                                        if ($event['emp'] === $driver_id) { 
                                        ?>
                                        <form method="POST" action="" style="margin: 0;">
                                            <input type="hidden" name="event_time" value="<?php echo htmlspecialchars($event['time']); ?>">
                                            
                                            <select name="new_condition" class="edit-select" onchange="if(confirm('Are you sure you want to permanently update this condition?')) { this.form.submit(); } else { window.location.reload(); }">
                                                <option value="Seal Intact" <?php echo ($event['cond'] === 'Seal Intact') ? 'selected' : ''; ?>>Seal Intact</option>
                                                <option value="Packaging Damaged" <?php echo ($event['cond'] === 'Packaging Damaged') ? 'selected' : ''; ?>>Packaging Damaged</option>
                                            </select>
                                        </form>
                                        <?php 
                                        } else { 
                                            // Everyone else just sees the uneditable text
                                            $cond_class = ($event['cond'] === 'Packaging Damaged') ? 'cond-damaged' : 'cond-intact';
                                            echo "<span class='{$cond_class}'>" . htmlspecialchars($event['cond']) . "</span>";
                                        } 
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align:center; padding: 20px;">No chain of custody events recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($total_pages_coc > 1): ?>
                    <div class="pagination" style="margin-top: 15px; display: flex; justify-content: center; gap: 10px; align-items: center;">
                        <?php if ($page_coc > 1): ?>
                            <a href="shp-details.php?id=<?php echo urlencode($sid); ?>&fid=<?php echo urlencode($formatted_id); ?>&page_coc=<?php echo $page_coc - 1; ?>" class="page-btn">&laquo; Prev</a>
                        <?php else: ?>
                            <span class="page-btn disabled" style="color: #a0aec0; cursor: not-allowed;">&laquo; Prev</span>
                        <?php endif; ?>
                        
                        <div class="page-selector" style="display: flex; align-items: center; gap: 5px;">
                            <label for="pageJump">Page: </label>
                            <select id="pageJump" onchange="window.location.href='shp-details.php?id=<?php echo urlencode($sid); ?>&fid=<?php echo urlencode($formatted_id); ?>&page_coc=' + this.value;">
                                <?php for ($i = 1; $i <= $total_pages_coc; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($i == $page_coc) ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <span>of <?php echo max(1, $total_pages_coc); ?></span>
                        </div>

                        <?php if ($page_coc < $total_pages_coc): ?>
                            <a href="shp-details.php?id=<?php echo urlencode($sid); ?>&fid=<?php echo urlencode($formatted_id); ?>&page_coc=<?php echo $page_coc + 1; ?>" class="page-btn">Next &raquo;</a>
                        <?php else: ?>
                            <span class="page-btn disabled" style="color: #a0aec0; cursor: not-allowed;">Next &raquo;</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
                <div class="table-container" style="margin-top: 30px;">
                    <h3>Lot Manifest</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Vendor Name</th>
                                <th>Batch Number</th>
                                <th>Lot Number</th>
                                <th>Quantity Loaded</th>
                                <th>Expiry Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($manifest_lots)): ?>
                                <?php foreach($manifest_lots as $lot): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($lot['vendor']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($lot['batch']); ?></td>
                                        <td>Lot <?php echo htmlspecialchars($lot['lot']); ?></td>
                                        <td><?php echo htmlspecialchars($lot['qty']); ?> Units</td>
                                        <td><?php echo $lot['exp'] ? date('M d, Y', strtotime($lot['exp'])) : '<span style="color: #a0aec0; font-style: italic;">N/A</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding: 20px;">No lot records found for this shipment.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-container" style="margin-top: 30px; margin-bottom: 50px; padding: 20px;">
                    <h3>Live Temperature Log</h3>
                    <p style="font-size: 14px; color: #718096; margin-bottom: 15px;">
                        Control Limits: <strong><?php echo number_format((float)$sys_min, 1); ?>&deg;C</strong> to <strong><?php echo number_format((float)$sys_max, 1); ?>&deg;C</strong>. Readings outside this range indicate a temperature excursion.
                    </p>
                    
                    <?php if(empty($chart_data)): ?>
                        <div style="text-align:center; padding: 40px; background: #f7fafc; color: #a0aec0; border-radius: 8px;">
                            No sensor data available for this vehicle during this timeframe.
                        </div>
                    <?php else: ?>
                        <div style="position: relative; height:400px; width:100%">
                            <canvas id="tempChart"></canvas>
                        </div>

                        
                    <?php endif; ?>
                </div>



            </section>
    </main>
</body>
</html>