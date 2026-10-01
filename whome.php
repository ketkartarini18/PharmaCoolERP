<?php
session_start();
date_default_timezone_set('America/Indiana/Indianapolis');

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ---------------------------------------------------------
// Gatekeeper - only warehouse staff may view this page
// ---------------------------------------------------------
if (!isset($_SESSION['emp_id']) || strtolower(trim($_SESSION['role'])) !== 'warehouse staff') {
    header("Location: ../index.php?error=unauthorized");
    exit();
}

$emp_id     = $_SESSION['emp_id'];
$username   = $_SESSION['username'];
$first_name = $_SESSION['name'];

$conn = new mysqli("mydb.itap.purdue.edu", "g1154084", "Group1!", "g1154084");
if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}

$today_sql = "'2026-03-16'";

// ---------------------------------------------------------
// Top summary KPIs
// ---------------------------------------------------------
// Count distinct lots currently in storage (a lot may have multiple StoredIn
// history rows; we only care that at least one is open).
$res = $conn->query("
    SELECT COUNT(DISTINCT VendorID, BatchNumber, LotSeq)
    FROM StoredIn
    WHERE EndTime IS NULL
");
$lots_in_storage = $res ? (int)$res->fetch_row()[0] : 0;

$res = $conn->query("
    SELECT COUNT(DISTINCT CONCAT(WarehouseID,'-',ZoneCode))
    FROM ZoneTempBreach
    WHERE ResolutionStatus = 'Open'
");
$zones_with_alerts = $res ? (int)$res->fetch_row()[0] : 0;

$res = $conn->query("
    SELECT COUNT(*)
    FROM Shipment
    WHERE DATE(DepartureTime) = {$today_sql}
");
$departing_today = $res ? (int)$res->fetch_row()[0] : 0;

$res = $conn->query("
    SELECT COUNT(*)
    FROM Shipment
    WHERE DATE(ArrivalTime) = {$today_sql}
");
$arriving_today = $res ? (int)$res->fetch_row()[0] : 0;

$res = $conn->query("
    SELECT COUNT(DISTINCT si.VendorID, si.BatchNumber, si.LotSeq)
    FROM StoredIn si
    JOIN Batch b
      ON si.VendorID = b.VendorID
     AND si.BatchNumber = b.BatchNumber
    WHERE si.EndTime IS NULL
      AND b.ExpiryDate BETWEEN {$today_sql}
      AND DATE_ADD({$today_sql}, INTERVAL 30 DAY)
");
$expiring_30 = $res ? (int)$res->fetch_row()[0] : 0;

// ---------------------------------------------------------
// W-KPI-4: Average Excursion Resolution Time
// Mean time (in hours) from breach start to resolution,
// across resolved zone breaches in the past 90 days.
// Spec is silent on which excursion source; we use
// ZoneTempBreach because warehouse staff own those.
// ---------------------------------------------------------
$wkpi4_avg_hours   = null;
$wkpi4_sample_size = 0;

$res = $conn->query("
    SELECT
        COUNT(*)                                              AS n_resolved,
        AVG(TIMESTAMPDIFF(MINUTE, StartTime, EndTime)) / 60.0 AS avg_hours
    FROM ZoneTempBreach
    WHERE ResolutionStatus = 'Resolved'
      AND StartTime >= DATE_SUB({$today_sql}, INTERVAL 90 DAY)
");
if ($res) {
    $row = $res->fetch_assoc();
    $wkpi4_sample_size = (int)$row['n_resolved'];
    if ($wkpi4_sample_size > 0 && $row['avg_hours'] !== null) {
        $wkpi4_avg_hours = (float)$row['avg_hours'];
    }
    $res->free();
}

// Format the display value once so the tile markup stays clean
if ($wkpi4_avg_hours === null) {
    $wkpi4_display = '—';
} elseif ($wkpi4_avg_hours < 1) {
    $wkpi4_display = round($wkpi4_avg_hours * 60) . 'm';
} elseif ($wkpi4_avg_hours < 24) {
    $wkpi4_display = number_format($wkpi4_avg_hours, 1) . 'h';
} else {
    $wkpi4_display = number_format($wkpi4_avg_hours / 24, 1) . 'd';
}

// ---------------------------------------------------------
// System-wide occupancy by zone classification
// Drives the large header donut. Always system-wide;
// ignores the warehouse pagination on this page.
// Sums LotVolume of currently-stored lots, grouped by the
// classification of the zone they sit in.
// ---------------------------------------------------------
// ---------------------------------------------------------
// System occupancy by zone classification
// Supports two scopes via ?occ_scope=:
//   - 'all'  (default) -> every warehouse in the system
//   - 'mine'           -> only warehouses where the current
//                         employee has logged a custody event
// "Mine" is heuristic because the schema has no Employee
// to-Warehouse association table; we can't change the schema
// per the assignment, so this is the closest defensible
// approximation of "where does this person work".
// ---------------------------------------------------------
$allowed_scopes = array('all', 'mine');
$occ_scope = (isset($_GET['occ_scope']) && in_array($_GET['occ_scope'], $allowed_scopes, true))
    ? $_GET['occ_scope']
    : 'all';

$occ_by_class = array(
    'refrigerated' => 0,
    'freezer'      => 0,
    'ambient'      => 0,
);

if ($occ_scope === 'mine') {
    // Filter: lots stored in warehouses where this employee
    // has at least one custody event (either as origin or destination).
    $sql_occ = "SELECT sz.Classification,
                       COALESCE(SUM(bl.LotVolume), 0) AS OccupiedVolume
                FROM StoredIn si
                JOIN BatchLot bl
                  ON si.VendorID    = bl.VendorID
                 AND si.BatchNumber = bl.BatchNumber
                 AND si.LotSeq      = bl.LotSeq
                JOIN StorageZone sz
                  ON si.WarehouseID = sz.WarehouseID
                 AND si.ZoneCode    = sz.ZoneCode
                WHERE si.EndTime IS NULL
                  AND si.WarehouseID IN (
                      SELECT DISTINCT
                          COALESCE(lce.FromWarehouseID, lce.ToWarehouseID) AS wh
                      FROM LotCustodyEvent lce
                      WHERE lce.EmployeeID = ?
                        AND COALESCE(lce.FromWarehouseID, lce.ToWarehouseID) IS NOT NULL
                  )
                GROUP BY sz.Classification";

    $stmt_occ = $conn->prepare($sql_occ);
    if ($stmt_occ) {
        $stmt_occ->bind_param("s", $emp_id);
        $stmt_occ->execute();
        $stmt_occ->bind_result($occ_cls, $occ_vol);
        while ($stmt_occ->fetch()) {
            if (isset($occ_by_class[$occ_cls])) {
                $occ_by_class[$occ_cls] = (int)$occ_vol;
            }
        }
        $stmt_occ->close();
    }
} else {
    // System-wide
    $res = $conn->query("
        SELECT sz.Classification, COALESCE(SUM(bl.LotVolume), 0) AS OccupiedVolume
        FROM StoredIn si
        JOIN BatchLot bl
          ON si.VendorID    = bl.VendorID
         AND si.BatchNumber = bl.BatchNumber
         AND si.LotSeq      = bl.LotSeq
        JOIN StorageZone sz
          ON si.WarehouseID = sz.WarehouseID
         AND si.ZoneCode    = sz.ZoneCode
        WHERE si.EndTime IS NULL
        GROUP BY sz.Classification
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cls = $row['Classification'];
            if (isset($occ_by_class[$cls])) {
                $occ_by_class[$cls] = (int)$row['OccupiedVolume'];
            }
        }
        $res->free();
    }
}

$occ_total = $occ_by_class['refrigerated']
           + $occ_by_class['freezer']
           + $occ_by_class['ambient'];

// Build query string for the toggle links so we don't lose
// other URL params (e.g. ?page=) when switching scope.
function build_occ_toggle_url($target_scope) {
    $qs = $_GET;
    $qs['occ_scope'] = $target_scope;
    return 'whome.php?' . http_build_query($qs) . '#occ-mix-card';
}

// ---------------------------------------------------------
// 1. Find the Employee's Primary Warehouse ($wh)
// ---------------------------------------------------------
$sql_wh = "SELECT
    w.WarehouseID,
    w.WarehouseName,
    w.City,
    w.State,
    w.Type,
    w.Status,
    COALESCE((SELECT SUM(sz.CapacityVolume)
              FROM StorageZone sz
              WHERE sz.WarehouseID = w.WarehouseID), 0) AS TotalCapacity,
    COALESCE((SELECT SUM(sz.CapacityVolume)
              FROM StorageZone sz
              WHERE sz.WarehouseID = w.WarehouseID
                AND LOWER(sz.Classification) = 'refrigerated'), 0) AS CapRefrig,
    COALESCE((SELECT SUM(sz.CapacityVolume)
              FROM StorageZone sz
              WHERE sz.WarehouseID = w.WarehouseID
                AND LOWER(sz.Classification) = 'freezer'), 0) AS CapFreezer,
    COALESCE((SELECT SUM(sz.CapacityVolume)
              FROM StorageZone sz
              WHERE sz.WarehouseID = w.WarehouseID
                AND LOWER(sz.Classification) = 'ambient'), 0) AS CapAmbient,
    COALESCE((SELECT SUM(bl.LotVolume)
              FROM StoredIn si
              JOIN BatchLot bl
                ON si.VendorID = bl.VendorID
               AND si.BatchNumber = bl.BatchNumber
               AND si.LotSeq = bl.LotSeq
              WHERE si.WarehouseID = w.WarehouseID
                AND si.EndTime IS NULL), 0) AS OccupiedVolume,
    COALESCE((SELECT COUNT(DISTINCT si.VendorID, si.BatchNumber, si.LotSeq)
              FROM StoredIn si
              WHERE si.WarehouseID = w.WarehouseID
                AND si.EndTime IS NULL), 0) AS LotCount,
    COALESCE((SELECT COUNT(DISTINCT ztb.ZoneCode)
              FROM ZoneTempBreach ztb
              WHERE ztb.WarehouseID = w.WarehouseID
                AND ztb.ResolutionStatus = 'Open'), 0) AS OpenAlerts,
    COALESCE((SELECT COUNT(*)
              FROM Shipment s
              WHERE s.OriginWarehouseID = w.WarehouseID
                AND DATE(s.DepartureTime) = {$today_sql}), 0) AS DepartingToday
FROM Warehouse w
WHERE w.WarehouseID = (
    SELECT COALESCE(FromWarehouseID, ToWarehouseID)
    FROM LotCustodyEvent
    WHERE EmployeeID = ?
      AND (FromWarehouseID IS NOT NULL OR ToWarehouseID IS NOT NULL)
    ORDER BY EventTime DESC
    LIMIT 1
)";

$stmt_wh = $conn->prepare($sql_wh);
$stmt_wh->bind_param("s", $emp_id);
$stmt_wh->execute();

$stmt_wh->bind_result(
    $WarehouseID, $WarehouseName, $City, $State, $Type, $Status,
    $TotalCapacity, $CapRefrig, $CapFreezer, $CapAmbient,
    $OccupiedVolume, $LotCount, $OpenAlerts, $DepartingToday
);

$wh = null;
if ($stmt_wh->fetch()) {
    $wh = array(
        'WarehouseID' => $WarehouseID, 'WarehouseName' => $WarehouseName, 
        'City' => $City, 'State' => $State, 'Type' => $Type, 'Status' => $Status, 
        'TotalCapacity' => $TotalCapacity, 'CapRefrig' => $CapRefrig, 
        'CapFreezer' => $CapFreezer, 'CapAmbient' => $CapAmbient, 
        'OccupiedVolume' => $OccupiedVolume, 'LotCount' => $LotCount, 
        'OpenAlerts' => $OpenAlerts, 'DepartingToday' => $DepartingToday
    );
}
$stmt_wh->close();

// ---------------------------------------------------------
// 2. Per-zone breakdown (Single Warehouse Donut Data)
// ---------------------------------------------------------
$zones_by_wh = array();

if ($wh) {
    $safe_wh_id = "'" . $conn->real_escape_string($wh['WarehouseID']) . "'";

    $sql_zones = "
        SELECT
            sz.WarehouseID, sz.ZoneCode, sz.Classification, sz.CapacityVolume,
            COALESCE((
                SELECT SUM(bl.LotVolume)
                FROM StoredIn si
                JOIN BatchLot bl ON si.VendorID = bl.VendorID
                                AND si.BatchNumber = bl.BatchNumber
                                AND si.LotSeq = bl.LotSeq
                WHERE si.WarehouseID = sz.WarehouseID
                  AND si.ZoneCode = sz.ZoneCode
                  AND si.EndTime IS NULL
            ), 0) AS OccupiedVolume
        FROM StorageZone sz
        WHERE sz.WarehouseID = $safe_wh_id
        ORDER BY FIELD(LOWER(sz.Classification), 'refrigerated', 'freezer', 'ambient'), sz.ZoneCode
    ";

    $res_z = $conn->query($sql_zones);
    if ($res_z) {
        while ($row = $res_z->fetch_assoc()) {
            $zones_by_wh[$row['WarehouseID']][] = array(
                'ZoneCode'       => $row['ZoneCode'],
                'Classification' => $row['Classification'],
                'CapacityVolume' => (int)$row['CapacityVolume'],
                'OccupiedVolume' => (int)$row['OccupiedVolume'],
            );
        }
        $res_z->free();
    }
}

// ---------------------------------------------------------
// OPT-W-1: Zone Breach Frequency Ranking
// ---------------------------------------------------------
$opt_w1_t1  = isset($_GET['opt_w1_t1'])  ? $_GET['opt_w1_t1']  : '2026-03-01'; // Simulated 1st of the month
$opt_w1_t2  = isset($_GET['opt_w1_t2'])  ? $_GET['opt_w1_t2']  : '2026-03-16'; // Simulated "Today"
$opt_w1_phi = isset($_GET['opt_w1_phi']) ? (int)$_GET['opt_w1_phi'] : 5;         // Default threshold: 5

$sql_opt_w1 = "SELECT 
        ztb.WarehouseID, 
        ztb.ZoneCode, 
        sz.Classification, 
        COUNT(ztb.StartTime) AS BreachCount
    FROM ZoneTempBreach ztb
    JOIN StorageZone sz ON ztb.WarehouseID = sz.WarehouseID AND ztb.ZoneCode = sz.ZoneCode
    WHERE ztb.StartTime >= ? AND ztb.StartTime <= ?
    GROUP BY ztb.WarehouseID, ztb.ZoneCode, sz.Classification
    ORDER BY BreachCount DESC";

$opt_w1_results = array();
$stmt_optw1 = $conn->prepare($sql_opt_w1);

if ($stmt_optw1) {
    // Append time to end date to cover the full 24 hours of t2
    $t2_end_of_day = $opt_w1_t2 . " 23:59:59";
    $stmt_optw1->bind_param("ss", $opt_w1_t1, $t2_end_of_day);
    $stmt_optw1->execute();
    $stmt_optw1->bind_result($res_w1_wh, $res_w1_zc, $res_w1_class, $res_w1_count);
    
    while ($stmt_optw1->fetch()) {
        $opt_w1_results[] = array(
            'label' => $res_w1_wh . ' / ' . $res_w1_zc . ' / ' . ucfirst($res_w1_class),
            'count' => $res_w1_count,
            'class' => strtolower($res_w1_class)
        );
    }
    $stmt_optw1->close();
}

// ---------------------------------------------------------
// OPT-W-2: Average Lot Dwell Time by Zone Classification
// ---------------------------------------------------------
$opt_w2_t1         = isset($_GET['opt_w2_t1'])         ? $_GET['opt_w2_t1']             : date('Y-m-01');
$opt_w2_t2         = isset($_GET['opt_w2_t2'])         ? $_GET['opt_w2_t2']             : date('Y-m-d');
$opt_w2_threshold  = isset($_GET['opt_w2_threshold'])  ? (float)$_GET['opt_w2_threshold'] : 60.0; // default 60 days

$sql_optw2 = "SELECT
    sz.Classification                                           AS zone_class,
    AVG(
        TIMESTAMPDIFF(SECOND,
            si.StartTime,
            COALESCE(si.EndTime, ?)
        )
    ) / 86400.0                                                 AS avg_dwell_days,
    STDDEV(
        TIMESTAMPDIFF(SECOND,
            si.StartTime,
            COALESCE(si.EndTime, ?)
        )
    ) / 86400.0                                                 AS stddev_dwell_days,
    COUNT(*)                                                    AS lot_count
FROM StoredIn si
JOIN StorageZone sz
    ON si.WarehouseID = sz.WarehouseID
   AND si.ZoneCode    = sz.ZoneCode
WHERE si.StartTime >= ?
  AND si.StartTime <= ?
  AND sz.Classification IN ('refrigerated', 'freezer', 'ambient')
GROUP BY sz.Classification
ORDER BY FIELD(sz.Classification, 'refrigerated', 'freezer', 'ambient')";

$opt_w2_results = array();
$stmt_optw2 = $conn->prepare($sql_optw2);
// FAILSAFE: Only execute if the query is valid
if ($stmt_optw2) {
    // Bind: t2 twice (for COALESCE), then t1, t2 for the WHERE range
    $stmt_optw2->bind_param("ssss", $opt_w2_t2, $opt_w2_t2, $opt_w2_t1, $opt_w2_t2);
    $stmt_optw2->execute();
    $stmt_optw2->store_result();
    $stmt_optw2->bind_result(
        $res_w2_class,
        $res_w2_avg,
        $res_w2_stddev,
        $res_w2_count
    );
    while ($stmt_optw2->fetch()) {
        $opt_w2_results[] = array(
            'class'         => $res_w2_class,
            'avg_days'      => round((float)$res_w2_avg,    2),
            'stddev_days'   => round((float)$res_w2_stddev, 2),
            'lot_count'     => (int)$res_w2_count
        );
    }
    $stmt_optw2->close();
}

// ---------------------------------------------------------
// W-KPI-5: Weekly Shipment Volume (last 13 weeks)
// ---------------------------------------------------------
$wkpi5_outbound = array(); 
$wkpi5_inbound  = array();
$wkpi5_weeks    = array(); 

// Create a 13-week scaffold ending at the snapshot date
$res = $conn->query("
    SELECT DATE_FORMAT(DATE_SUB($today_sql, INTERVAL (7 * n) DAY), '%Y-%m-%d') AS week_start
    FROM (
        SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
        UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
        UNION ALL SELECT 12
    ) AS weeks
    ORDER BY week_start ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $wkpi5_weeks[] = $row['week_start'];
        $wkpi5_outbound[$row['week_start']] = 0;
        $wkpi5_inbound[$row['week_start']]  = 0;
    }
}

// Outbound Volume (Sum of Lot volumes by Departure week)
$sql_out = "SELECT DATE_FORMAT(DATE_SUB(s.DepartureTime, INTERVAL WEEKDAY(s.DepartureTime) DAY), '%Y-%m-%d') AS week_start,
                   SUM(sl.QuantityVolume) AS total_vol
            FROM Shipment s
            JOIN ShipmentLot sl ON s.ShipmentID = sl.ShipmentID
            WHERE s.DepartureTime BETWEEN DATE_SUB($today_sql, INTERVAL 13 WEEK) AND $today_sql
            GROUP BY week_start";
$res_out = $conn->query($sql_out);
if ($res_out) {
    while ($row = $res_out->fetch_assoc()) {
        if (isset($wkpi5_outbound[$row['week_start']])) $wkpi5_outbound[$row['week_start']] = (int)$row['total_vol'];
    }
}

// Inbound Volume (Sum of Lot volumes by Arrival week)
$sql_in = "SELECT DATE_FORMAT(DATE_SUB(s.ArrivalTime, INTERVAL WEEKDAY(s.ArrivalTime) DAY), '%Y-%m-%d') AS week_start,
                  SUM(sl.QuantityVolume) AS total_vol
           FROM Shipment s
           JOIN ShipmentLot sl ON s.ShipmentID = sl.ShipmentID
           WHERE s.ArrivalTime IS NOT NULL 
             AND s.ArrivalTime BETWEEN DATE_SUB($today_sql, INTERVAL 13 WEEK) AND $today_sql
           GROUP BY week_start";
$res_in = $conn->query($sql_in);
if ($res_in) {
    while ($row = $res_in->fetch_assoc()) {
        if (isset($wkpi5_inbound[$row['week_start']])) $wkpi5_inbound[$row['week_start']] = (int)$row['total_vol'];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../driver/driver-style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="warehouse-style.css?v=<?php echo time(); ?>">
    <title>PharmaCool - Warehouse Overview</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="auto-charts.js?v=<?php echo time(); ?>"></script>
    <script src="warehouse-scripts.js?v=<?php echo time(); ?>"></script>
</head>

<body class="erp-layout">
    <nav class="sidebar">
        <div class="logo">PharmaCool</div>
        <ul class="nav-links">
            <li class="active">Overview</li>
            <li><a href="wshipments.php">Inbound / Outbound</a></li>
            <li><a href="wbatchvendor.php">Batch &amp; Vendor Lookup</a></li>
            <li><a href="wcustody.php">Custody Log</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
        <div class="user-profile">
            <div class="avatar"><?php echo htmlspecialchars(substr($first_name, 0, 1)); ?></div>
            <div class="info">
                <strong><?php echo htmlspecialchars($first_name); ?></strong><br>
                <small><?php echo htmlspecialchars($username); ?></small><br>
                <small><?php echo htmlspecialchars($emp_id); ?></small>
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
            <h2>Warehouse overview, <?php echo htmlspecialchars($first_name); ?></h2>

            <div class="stats-row">
                <div class="card">
                    <h3><?php echo number_format($lots_in_storage); ?></h3>
                    <p>Lots in storage</p>
                </div>
                <div class="card <?php echo ($zones_with_alerts > 0) ? 'alert' : ''; ?>">
                    <h3><?php echo number_format($zones_with_alerts); ?></h3>
                    <p>Zones with open alerts</p>
                </div>
                <div class="card">
                    <h3><?php echo number_format($departing_today); ?></h3>
                    <p>Departing today</p>
                </div>
                <div class="card">
                    <h3><?php echo number_format($arriving_today); ?></h3>
                    <p>Arriving today</p>
                </div>
                <div class="card <?php echo ($expiring_30 > 0) ? 'warn' : ''; ?>">
                    <h3><?php echo number_format($expiring_30); ?></h3>
                    <p>Expiring &le; 30 days</p>
                </div>
                <div class="card"
                     title="W-KPI-4: average time from breach start to resolution across the past 90 days of zone temperature breaches. Computed from <?php echo $wkpi4_sample_size; ?> resolved breach<?php echo ($wkpi4_sample_size === 1 ? '' : 'es'); ?>.">
                    <h3><?php echo htmlspecialchars($wkpi4_display); ?></h3>
                    <p>Avg breach resolution
                       <small style="opacity:0.6;">(<?php echo $wkpi4_sample_size; ?>&nbsp;in&nbsp;90d)</small>
                    </p>
                </div>
                <div class="card card-wide" id="occ-mix-card">
                    <div class="occ-mix-layout">
                        <div class="occ-mix-chart">
                            <canvas id="occ-mix-donut" 
                                    class="auto-chart" 
                                    data-chart-type="occ-mix"
                                    data-refrig="<?php echo $occ_by_class['refrigerated']; ?>"
                                    data-freezer="<?php echo $occ_by_class['freezer']; ?>"
                                    data-ambient="<?php echo $occ_by_class['ambient']; ?>">
                            </canvas>                        
                        </div>
                        <div class="occ-mix-meta">
                            <div class="occ-mix-header" style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:4px;">
                                <p class="occ-mix-title" style="margin:0;">
                                    <?php echo ($occ_scope === 'mine') ? 'My Occupancy Mix' : 'System Occupancy Mix'; ?>
                                </p>
                                <div class="occ-scope-toggle" role="group" aria-label="Occupancy scope" style="display:inline-flex; border:1px solid #d1d5db; border-radius:4px; overflow:hidden; font-size:0.7rem;">
                                    <a href="<?php echo htmlspecialchars(build_occ_toggle_url('mine')); ?>"
                                       title="Show only warehouses where you've logged custody events"
                                       style="padding:2px 8px; text-decoration:none; <?php echo ($occ_scope === 'mine') ? 'background:#0d9488; color:#fff;' : 'background:#fff; color:#374151;'; ?>">
                                        Mine
                                    </a>
                                    <a href="<?php echo htmlspecialchars(build_occ_toggle_url('all')); ?>"
                                       title="Show all warehouses in the system"
                                       style="padding:2px 8px; text-decoration:none; border-left:1px solid #d1d5db; <?php echo ($occ_scope === 'all') ? 'background:#0d9488; color:#fff;' : 'background:#fff; color:#374151;'; ?>">
                                        All
                                    </a>
                                </div>
                            </div>
                            <p class="occ-mix-total">
                                <strong><?php echo number_format($occ_total); ?></strong>
                                <span>units stored</span>
                            </p>
                            <ul class="occ-mix-legend">
                                <li><span class="sw" style="background:#0d9488;"></span>
                                    Refrigerated
                                    <em><?php echo number_format($occ_by_class['refrigerated']); ?></em></li>
                                <li><span class="sw" style="background:#2563eb;"></span>
                                    Freezer
                                    <em><?php echo number_format($occ_by_class['freezer']); ?></em></li>
                                <li><span class="sw" style="background:#6b7280;"></span>
                                    Ambient
                                    <em><?php echo number_format($occ_by_class['ambient']); ?></em></li>
                            </ul>
                            <?php if ($occ_scope === 'mine' && $occ_total === 0): ?>
                                <p class="muted" style="font-size:0.7rem; margin:4px 0 0;">
                                    No custody history found for your account.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="wkpi5-container" style="margin-top: 40px;">
                <h3 style="margin-bottom: 6px;">Weekly Shipment Volume</h3>
                <p class="muted" style="font-size: 0.82rem; margin-top: 0;">
                    W-KPI-5 — outbound vs inbound volume across the past 13 weeks (system-wide).
                </p>
            <div class="chart-card">
                <div class="chart-header">
                    <h4>Weekly Shipment Volume (W-KPI-5)</h4>
                </div>
                <div class="chart-body" style="height: 300px; position: relative; padding: 10px;">
                    <canvas id="emergency-kpi5-chart"></canvas>
                </div>
            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // 1. Grab the data directly from PHP
                const weeks = <?php echo json_encode(array_values($wkpi5_weeks)); ?>;
                const outbound = <?php echo json_encode(array_values($wkpi5_outbound)); ?>;
                const inbound = <?php echo json_encode(array_values($wkpi5_inbound)); ?>;

                // 2. Debug Check: If this shows 0s in your F12 console, the SQL logic above is the issue
                console.log("W-KPI-5 Debug:", {weeks, outbound, inbound});

                const ctx = document.getElementById('emergency-kpi5-chart').getContext('2d');
                
                // 3. Force render bypassing auto-charts.js
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: weeks.map(w => 'Wk ' + w.substring(5, 10)),
                        datasets: [
                            {
                                label: 'Outbound Vol (L)',
                                data: outbound,
                                backgroundColor: '#0d9488',
                                borderRadius: 4
                            },
                            {
                                label: 'Inbound Vol (L)',
                                data: inbound,
                                backgroundColor: '#2563eb',
                                borderRadius: 4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true,
                                title: { display: true, text: 'Liters' }
                            }
                        }
                    }
                });
            });
            </script>
                </div>
            </div>

            <div class="my-warehouse-container" style="margin-top: 40px;">
                <h3 style="margin-bottom: 15px;">My Assigned Facility</h3>
                
                <?php if ($wh): 
                    $util_pct = ($wh['TotalCapacity'] > 0) ? round(($wh['OccupiedVolume'] / $wh['TotalCapacity']) * 100) : 0;
                    $status_class = str_replace(' ', '-', strtolower($wh['Status']));
                ?>
                    <div class="wh-primary-card" onclick="window.location='warehouse-details.php?wid=<?php echo urlencode($wh['WarehouseID']); ?>'">
                        
                        <div class="wh-card-header">
                            <div class="wh-card-meta">
                                <span class="mono"><?php echo htmlspecialchars($wh['WarehouseID']); ?></span> &bull; 
                                <span class="muted"><?php echo htmlspecialchars($wh['City'] . ', ' . $wh['State']); ?></span>
                            </div>
                            <span class="status-text <?php echo htmlspecialchars($status_class); ?>">
                                <?php echo htmlspecialchars(ucwords($wh['Status'])); ?>
                            </span>
                        </div>
                        
                        <div class="wh-card-title">
                            <h2><?php echo htmlspecialchars($wh['WarehouseName']); ?></h2>
                            <p class="muted"><?php echo htmlspecialchars(ucwords($wh['Type'])); ?></p>
                        </div>

                        <?php if ((int)$wh['OpenAlerts'] > 0): ?>
                            <div class="banner-faulty wh-alert-banner">
                                <span class="warn-icon">⚠️</span> 
                                Zone temperature excursion in progress
                            </div>
                        <?php endif; ?>

                        <div class="wh-card-body" style="display: flex; align-items: center; gap: 25px; padding: 20px;">
                            <div style="position: relative; width: 120px; height: 120px; flex-shrink: 0;">
                                <canvas id="donut-single-wh" width="120" height="120" 
                                        data-zones='<?php echo htmlspecialchars(json_encode(isset($zones_by_wh[$wh['WarehouseID']]) ? $zones_by_wh[$wh['WarehouseID']] : array()), ENT_QUOTES, 'UTF-8'); ?>'>
                                </canvas>
                                <div class="donut-center-text" style="font-size: 20px;"><?php echo $util_pct; ?>%</div>
                            </div>
                            
                            <div class="wh-card-legend" style="flex-grow: 1;">
                                <?php 
                                $cap_r = ($wh['TotalCapacity'] > 0) ? round(($wh['CapRefrig'] / $wh['TotalCapacity']) * 100) : 0;
                                $cap_f = ($wh['TotalCapacity'] > 0) ? round(($wh['CapFreezer'] / $wh['TotalCapacity']) * 100) : 0;
                                $cap_a = ($wh['TotalCapacity'] > 0) ? round(($wh['CapAmbient'] / $wh['TotalCapacity']) * 100) : 0;
                                ?>
                                <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px; color: #4a5568;">
                                    <?php if ($wh['CapRefrig'] > 0): ?>
                                        <li style="margin-bottom: 8px; display: flex; align-items: center;">
                                            <span style="display: inline-block; width: 12px; height: 12px; background-color: #0d9488; margin-right: 10px; border-radius: 2px;"></span>
                                            Refrigerated (<?php echo $cap_r; ?>%)
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($wh['CapFreezer'] > 0): ?>
                                        <li style="margin-bottom: 8px; display: flex; align-items: center;">
                                            <span style="display: inline-block; width: 12px; height: 12px; background-color: #2563eb; margin-right: 10px; border-radius: 2px;"></span>
                                            Freezer (<?php echo $cap_f; ?>%)
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($wh['CapAmbient'] > 0): ?>
                                        <li style="display: flex; align-items: center;">
                                            <span style="display: inline-block; width: 12px; height: 12px; background-color: #6b7280; margin-right: 10px; border-radius: 2px;"></span>
                                            Ambient (<?php echo $cap_a; ?>%)
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>

                        <div class="wh-card-footer">
                            <div class="wh-stat">
                                <h4><?php echo number_format((int)$wh['LotCount']); ?></h4>
                                <p>LOTS STORED</p>
                            </div>
                            <div class="wh-stat <?php echo ((int)$wh['OpenAlerts'] > 0) ? 'stat-danger' : ''; ?>">
                                <h4><?php echo (int)$wh['OpenAlerts']; ?></h4>
                                <p>OPEN ALERTS</p>
                            </div>
                            <div class="wh-stat">
                                <h4><?php echo number_format((int)$wh['DepartingToday']); ?></h4>
                                <p>DEPARTING TODAY</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </div>
                    
                <?php else: ?>
                    <div class="card no-data" style="padding: 40px; text-align: center;">
                        You are not currently associated with any warehouses.
                    </div>
                <?php endif; ?>
            </div>
            <details class="table-container" style="margin-top: 40px;" <?php echo isset($_GET['opt_w1_t1']) ? 'open' : ''; ?>>
                <summary>
                    Zone Breach Frequency Ranking ▼
                    <i class="fa-solid fa-chevron-down"></i>
                </summary>
                        
                <p style="margin-top: 0;">Rank of zones by the number of temperature excursions over a selected date range.</p>            
                <form method="GET" action="whome.php" style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 20px; background: #f7fafc; padding: 15px; border-radius: 8px;">
                    <label style="display: flex; flex-direction: column; font-size: 14px; font-weight: bold;">
                        Start Date (t1):
                        <input type="date" name="opt_w1_t1" value="<?php echo htmlspecialchars($opt_w1_t1); ?>" style="padding: 6px; margin-top: 5px;">
                    </label>
                    
                    <label style="display: flex; flex-direction: column; font-size: 14px; font-weight: bold;">
                        End Date (t2):
                        <input type="date" name="opt_w1_t2" value="<?php echo htmlspecialchars($opt_w1_t2); ?>" style="padding: 6px; margin-top: 5px;">
                    </label>
                    
                    <label style="display: flex; flex-direction: column; font-size: 14px; font-weight: bold;">
                        Threshold (&phi;):
                        <input type="number" name="opt_w1_phi" value="<?php echo htmlspecialchars($opt_w1_phi); ?>" min="0" style="padding: 6px; margin-top: 5px; width: 80px;">
                    </label>
                    
                    <button type="submit" class="ok-btn" style="background: #1a237e;">Apply Filters</button>
                </form>
                <?php if(empty($opt_w1_results)): ?>
                    <div style="text-align:center; padding: 40px; background: #edf2f7; color: #a0aec0; border-radius: 8px;">
                        No zone breaches found in this date range.
                    </div>
                <?php else: ?>
                    <div style="position: relative; height: 400px; width: 100%;">
                        <canvas id="zoneBreachChart" 
                                data-breaches='<?php echo htmlspecialchars(json_encode($opt_w1_results), ENT_QUOTES, 'UTF-8'); ?>'
                                data-phi='<?php echo (int)$opt_w1_phi; ?>'>
                        </canvas>
                    </div>
                <?php endif; ?>
            </details>
            <details class="table-container" style="margin-top: 40px;" <?php echo isset($_GET['opt_w2_t1']) ? 'open' : ''; ?>>
                <summary>
                    Average Lot Dwell Time by Zone ▼
                    <i class="fa-solid fa-chevron-down"></i>
                </summary>

                <p style="margin-top: 0; color: #718096;">Average time lots spend in each zone classification over the selected date range. Error bars show &plusmn;1 standard deviation.</p>

                <form method="GET" action="whome.php" style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 20px; background: #f7fafc; padding: 15px; border-radius: 8px;">
                    <label style="display: flex; flex-direction: column; font-size: 14px; font-weight: bold;">
                        Start Date (t1):
                        <input type="date" name="opt_w2_t1" value="<?php echo htmlspecialchars($opt_w2_t1); ?>" style="padding: 6px; margin-top: 5px;">
                    </label>
                    <label style="display: flex; flex-direction: column; font-size: 14px; font-weight: bold;">
                        End Date (t2):
                        <input type="date" name="opt_w2_t2" value="<?php echo htmlspecialchars($opt_w2_t2); ?>" style="padding: 6px; margin-top: 5px;">
                    </label>
                    <label style="display: flex; flex-direction: column; font-size: 14px; font-weight: bold;">
                        Threshold (days):
                        <input type="number" name="opt_w2_threshold" value="<?php echo htmlspecialchars($opt_w2_threshold); ?>" min="0" step="1" style="padding: 6px; margin-top: 5px; width: 80px;">
                    </label>
                    <button type="submit" class="ok-btn" style="background: #1a237e;">Apply Filters</button>
                </form>

                <?php if (empty($opt_w2_results)): ?>
                    <div style="text-align:center; padding: 40px; background: #edf2f7; color: #a0aec0; border-radius: 8px;">
                        No zone dwell data found in this date range.
                    </div>
                <?php else: ?>
                    <div style="position: relative; height: 400px; width: 100%;">
                        <canvas id="dwellChart"
                                data-results='<?php echo htmlspecialchars(json_encode($opt_w2_results), ENT_QUOTES, "UTF-8"); ?>'
                                data-threshold='<?php echo (float)$opt_w2_threshold; ?>'>
                        </canvas>
                    </div>
                    <table style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>ZONE CLASS</th>
                                <th>AVG DWELL (DAYS)</th>
                                <th>STD DEV (DAYS)</th>
                                <th>LOT COUNT</th>
                                <th>vs THRESHOLD (<?php echo $opt_w2_threshold; ?> days)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($opt_w2_results as $row):
                                $exceeded     = $row['avg_days'] > $opt_w2_threshold;
                                $status_class = $exceeded ? 'open'               : 'resolved';
                                $status_label = $exceeded ? 'Exceeds threshold'  : 'Within threshold';
                                $zone_colour  = strtolower($row['class']) === 'refrigerated' ? '#0d9488'
                                              : (strtolower($row['class']) === 'freezer'     ? '#2563eb' : '#6b7280');
                            ?>
                                <tr class="<?php echo $exceeded ? 'row-alert' : ''; ?>">
                                    <td>
                                        <span style="display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:8px; background:<?php echo $zone_colour; ?>;"></span>
                                        <strong><?php echo htmlspecialchars(ucfirst($row['class'])); ?></strong>
                                    </td>
                                    <td><?php echo $row['avg_days']; ?></td>
                                    <td><?php echo $row['stddev_days']; ?></td>
                                    <td><?php echo $row['lot_count']; ?></td>
                                    <td><span class="status-text <?php echo $status_class; ?>"><?php echo $status_label; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </details>
        </section>
    </main>
</body>
</html>
