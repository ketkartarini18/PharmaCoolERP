<?php
session_start();

// ---------------------------------------------------------
// Gatekeeper - only warehouse staff may view this page
// ---------------------------------------------------------
if (!isset($_SESSION['emp_id']) || $_SESSION['role'] !== 'warehouse staff') {
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

// ---------------------------------------------------------
// Snapshot date — pinned to the seed data's "current" date
// (2026-03-16, per gen_data.py SNAPSHOT_TIME).  Production
// builds should swap these back to CURRENT_DATE() / NOW().
// ---------------------------------------------------------
// $today_sql    = "CURRENT_DATE()";          // production
// $snapshot_sql = "NOW()";                   // production
$today_sql    = "'2026-03-16'";               // seed snapshot (date only)
$snapshot_sql = "'2026-03-16 12:00:00'";      // seed snapshot (datetime)
$snapshot_date_php = '2026-03-16';            // PHP-side mirror

// ---------------------------------------------------------
// Params
// ---------------------------------------------------------
$wid  = isset($_GET['wid'])  ? trim($_GET['wid'])  : '';
$zone = isset($_GET['zone']) ? trim($_GET['zone']) : '';

if ($wid === '') {
    header("Location: whome.php");
    exit();
}

$update_msg = '';
$update_err = '';

// ---------------------------------------------------------
// POST handler: update breach resolution status
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'update_breach') {

    $p_wid    = isset($_POST['wid'])        ? trim($_POST['wid'])        : '';
    $p_zone   = isset($_POST['zone'])       ? trim($_POST['zone'])       : '';
    $p_start  = isset($_POST['start_time']) ? trim($_POST['start_time']) : '';
    $p_status = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';

    $valid = array('Open', 'Under Review', 'Resolved');

    if (in_array($p_status, $valid, true) && $p_wid !== '' && $p_zone !== '' && $p_start !== '') {
        $sql_up = "UPDATE ZoneTempBreach
                   SET ResolutionStatus = ?
                   WHERE WarehouseID = ? AND ZoneCode = ? AND StartTime = ?";
        $stmt_up = $conn->prepare($sql_up);

        if ($stmt_up) {
            $stmt_up->bind_param("ssss", $p_status, $p_wid, $p_zone, $p_start);

            if ($stmt_up->execute()) {
                $stmt_up->close();
                header("Location: warehouse-details.php?wid=" . urlencode($p_wid)
                     . "&zone=" . urlencode($p_zone) . "&updated=1");
                exit();
            }

            $update_err = "Update failed: " . htmlspecialchars($stmt_up->error);
            $stmt_up->close();
        } else {
            $update_err = "Update failed: " . htmlspecialchars($conn->error);
        }
    } else {
        $update_err = "Invalid update request.";
    }
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $update_msg = "Breach resolution status updated.";
}

// ---------------------------------------------------------
// Warehouse header info
// ---------------------------------------------------------
$sql_wh = "SELECT WarehouseID, WarehouseName, StreetAddress, City, State, ZipCode, Type, Status
           FROM Warehouse
           WHERE WarehouseID = ?";
$stmt_wh = $conn->prepare($sql_wh);
if (!$stmt_wh) {
    die("Warehouse query failed: " . htmlspecialchars($conn->error));
}

$stmt_wh->bind_param("s", $wid);
$stmt_wh->execute();
$stmt_wh->bind_result($wh_id, $wh_name, $wh_street, $wh_city, $wh_state, $wh_zip, $wh_type, $wh_status);

$warehouse = null;
if ($stmt_wh->fetch()) {
    $warehouse = array(
        'WarehouseID'   => $wh_id,
        'WarehouseName' => $wh_name,
        'StreetAddress' => $wh_street,
        'City'          => $wh_city,
        'State'         => $wh_state,
        'ZipCode'       => $wh_zip,
        'Type'          => $wh_type,
        'Status'        => $wh_status
    );
}
$stmt_wh->close();

if (!$warehouse) {
    header("Location: whome.php?error=not_found");
    exit();
}

// ---------------------------------------------------------
// Zone table
// ---------------------------------------------------------
$sql_zones = "SELECT
    sz.ZoneCode,
    sz.Classification,
    sz.MinTemp,
    sz.MaxTemp,
    sz.CapacityVolume,
    COALESCE((SELECT SUM(bl.LotVolume)
              FROM StoredIn si
              JOIN BatchLot bl
                ON si.VendorID    = bl.VendorID
               AND si.BatchNumber = bl.BatchNumber
               AND si.LotSeq      = bl.LotSeq
              WHERE si.WarehouseID = sz.WarehouseID
                AND si.ZoneCode    = sz.ZoneCode
                AND si.EndTime IS NULL), 0) AS InUse,
    (SELECT COUNT(*)
     FROM ZoneTempBreach ztb
     WHERE ztb.WarehouseID = sz.WarehouseID
       AND ztb.ZoneCode = sz.ZoneCode
       AND ztb.ResolutionStatus = 'Open') AS OpenAlerts
FROM StorageZone sz
WHERE sz.WarehouseID = ?
ORDER BY sz.ZoneCode";

$stmt_z = $conn->prepare($sql_zones);
if (!$stmt_z) {
    die("Zone query failed: " . htmlspecialchars($conn->error));
}

$stmt_z->bind_param("s", $wid);
$stmt_z->execute();
$stmt_z->bind_result($z_code, $z_class, $z_min, $z_max, $z_capacity, $z_inuse, $z_alerts);

$zones = array();
while ($stmt_z->fetch()) {
    $zones[] = array(
        'ZoneCode'       => $z_code,
        'Classification' => $z_class,
        'MinTemp'        => $z_min,
        'MaxTemp'        => $z_max,
        'CapacityVolume' => $z_capacity,
        'InUse'          => $z_inuse,
        'OpenAlerts'     => $z_alerts
    );
}
$stmt_z->close();

// ---------------------------------------------------------
// Selected zone detail
// ---------------------------------------------------------
$selected_zone = null;
$lots_in_zone  = array();
$temp_readings = array();
$breaches      = array();
$temp_source   = 'none';

if ($zone !== '') {
    foreach ($zones as $z) {
        if ($z['ZoneCode'] === $zone) {
            $selected_zone = $z;
            break;
        }
    }

    if ($selected_zone) {
        // Lots currently in the zone
        $sql_lots = "SELECT
            v.VendorName,
            si.VendorID,
            si.BatchNumber,
            si.LotSeq,
            bl.LotVolume,
            b.ExpiryDate,
            DATEDIFF(b.ExpiryDate, {$today_sql}) AS DaysToExpiry
        FROM StoredIn si
        JOIN BatchLot bl
          ON si.VendorID = bl.VendorID
         AND si.BatchNumber = bl.BatchNumber
         AND si.LotSeq = bl.LotSeq
        JOIN Batch b
          ON si.VendorID = b.VendorID
         AND si.BatchNumber = b.BatchNumber
        JOIN Vendor v
          ON si.VendorID = v.VendorID
        WHERE si.WarehouseID = ?
          AND si.ZoneCode = ?
          AND si.EndTime IS NULL
        ORDER BY b.ExpiryDate ASC";

        $stmt_l = $conn->prepare($sql_lots);
        if ($stmt_l) {
            $stmt_l->bind_param("ss", $wid, $zone);
            $stmt_l->execute();
            $stmt_l->bind_result($lot_vendor_name, $lot_vendor_id, $lot_batch, $lot_seq, $lot_volume, $lot_expiry, $lot_dte);

            while ($stmt_l->fetch()) {
                $lots_in_zone[] = array(
                    'VendorName'   => $lot_vendor_name,
                    'VendorID'     => $lot_vendor_id,
                    'BatchNumber'  => $lot_batch,
                    'LotSeq'       => $lot_seq,
                    'LotVolume'    => $lot_volume,
                    'ExpiryDate'   => $lot_expiry,
                    'DaysToExpiry' => $lot_dte
                );
            }
            $stmt_l->close();
        }

        // Primary temp query: last 7 calendar days
        $sql_temp = "SELECT ReadingTime, Temperature
                     FROM SensorReading
                     WHERE WarehouseID = ?
                       AND ZoneCode = ?
                       AND ReadingTime >= DATE_SUB({$today_sql}, INTERVAL 7 DAY)
                     ORDER BY ReadingTime ASC";

        $stmt_t = $conn->prepare($sql_temp);
        if ($stmt_t) {
            $stmt_t->bind_param("ss", $wid, $zone);
            $stmt_t->execute();
            $stmt_t->bind_result($t_reading_time, $t_temperature);

            while ($stmt_t->fetch()) {
                $temp_readings[] = array(
                    'ReadingTime'  => $t_reading_time,
                    'Temperature'  => $t_temperature
                );
            }
            $stmt_t->close();
        }

        if (!empty($temp_readings)) {
            $temp_source = 'primary';
        }

        // Fallback temp query: last 7 days ending at most recent reading
        if (empty($temp_readings)) {
            $sql_temp_fb = "SELECT ReadingTime, Temperature
                            FROM SensorReading
                            WHERE WarehouseID = ?
                              AND ZoneCode = ?
                              AND ReadingTime >= DATE_SUB(
                                  (SELECT MAX(ReadingTime)
                                   FROM SensorReading
                                   WHERE WarehouseID = ? AND ZoneCode = ?),
                                  INTERVAL 7 DAY)
                            ORDER BY ReadingTime ASC";

            $stmt_tfb = $conn->prepare($sql_temp_fb);
            if ($stmt_tfb) {
                $stmt_tfb->bind_param("ssss", $wid, $zone, $wid, $zone);
                $stmt_tfb->execute();
                $stmt_tfb->bind_result($fb_time, $fb_temp);

                while ($stmt_tfb->fetch()) {
                    $temp_readings[] = array(
                        'ReadingTime' => $fb_time,
                        'Temperature' => $fb_temp
                    );
                }
                $stmt_tfb->close();
            }

            if (!empty($temp_readings)) {
                $temp_source = 'fallback';
            }
        }

        // Breach history
        $sql_br = "SELECT StartTime, EndTime, MaxDeviation, ResolutionStatus
                   FROM ZoneTempBreach
                   WHERE WarehouseID = ? AND ZoneCode = ?
                   ORDER BY StartTime DESC";

        $stmt_b = $conn->prepare($sql_br);
        if ($stmt_b) {
            $stmt_b->bind_param("ss", $wid, $zone);
            $stmt_b->execute();
            $stmt_b->bind_result($br_start, $br_end, $br_dev, $br_status);

            while ($stmt_b->fetch()) {
                $breaches[] = array(
                    'StartTime'        => $br_start,
                    'EndTime'          => $br_end,
                    'MaxDeviation'     => $br_dev,
                    'ResolutionStatus' => $br_status
                );
            }
            $stmt_b->close();
        }
    }
}

// ---------------------------------------------------------
// OPT-W-5: Stale Inventory Report
// Per-warehouse report scoped to this page's $wid.
// User-controlled filters:
//   - t1, t2 : arrival window (StoredIn.StartTime ∈ [t1, t2])
//   - N      : staleness threshold in days
// All date math runs in SQL against the snapshot constant
// (not wall-clock), to stay consistent with the seed data.
// ---------------------------------------------------------

// Filter inputs with sane defaults: 180-day window ending at snapshot.
if (isset($_GET['t1']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['t1'])) {
    $stale_t1 = $_GET['t1'];
} else {
    $stale_t1 = date('Y-m-d', strtotime($snapshot_date_php . ' -180 days'));
}
if (isset($_GET['t2']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['t2'])) {
    $stale_t2 = $_GET['t2'];
} else {
    $stale_t2 = $snapshot_date_php;
}
$stale_N = isset($_GET['n']) ? max(0, (int)$_GET['n']) : 30;

$stale_t1_sql = $stale_t1 . ' 00:00:00';
$stale_t2_sql = $stale_t2 . ' 23:59:59';

$stale_rows  = array();
$stale_bins  = array();   // histogram: BinStart -> LotCount
$stale_error = '';

// --- Query 1: stale lot table ---
$sql_stale = "SELECT
    v.VendorName,
    bl.VendorID,
    bl.BatchNumber,
    bl.LotSeq,
    si.ZoneCode,
    sz.Classification,
    bl.LotVolume,
    si.StartTime,
    DATEDIFF({$today_sql}, si.StartTime)  AS StaleDays,
    b.ExpiryDate,
    DATEDIFF(b.ExpiryDate, {$today_sql})  AS DaysToExpiry
FROM StoredIn si
JOIN BatchLot bl
  ON si.VendorID    = bl.VendorID
 AND si.BatchNumber = bl.BatchNumber
 AND si.LotSeq      = bl.LotSeq
JOIN Batch b
  ON bl.VendorID    = b.VendorID
 AND bl.BatchNumber = b.BatchNumber
JOIN Vendor v
  ON bl.VendorID    = v.VendorID
JOIN StorageZone sz
  ON si.WarehouseID = sz.WarehouseID
 AND si.ZoneCode    = sz.ZoneCode
WHERE si.WarehouseID = ?
  AND si.EndTime IS NULL
  AND si.StartTime BETWEEN ? AND ?
  AND DATEDIFF({$today_sql}, si.StartTime) > ?
ORDER BY StaleDays DESC";

$stmt_st = $conn->prepare($sql_stale);
if ($stmt_st) {
    $stmt_st->bind_param("sssi", $wid, $stale_t1_sql, $stale_t2_sql, $stale_N);
    if ($stmt_st->execute()) {
        $stmt_st->bind_result(
            $st_vendor, $st_vid, $st_batch, $st_seq, $st_zone, $st_class,
            $st_volume, $st_start, $st_days, $st_expiry, $st_dte
        );
        while ($stmt_st->fetch()) {
            $stale_rows[] = array(
                'VendorName'     => $st_vendor,
                'VendorID'       => $st_vid,
                'BatchNumber'    => $st_batch,
                'LotSeq'         => $st_seq,
                'ZoneCode'       => $st_zone,
                'Classification' => $st_class,
                'LotVolume'      => $st_volume,
                'StartTime'      => $st_start,
                'StaleDays'      => (int)$st_days,
                'ExpiryDate'     => $st_expiry,
                'DaysToExpiry'   => ($st_dte === null ? null : (int)$st_dte),
            );
        }
    } else {
        $stale_error = "Stale inventory query failed: " . htmlspecialchars($stmt_st->error);
    }
    $stmt_st->close();
} else {
    $stale_error = "Stale inventory query prep failed: " . htmlspecialchars($conn->error);
}

// --- Query 2: histogram aggregation (10-day bins) ---
$sql_hist = "SELECT
    FLOOR(DATEDIFF({$today_sql}, si.StartTime) / 10) * 10 AS BinStart,
    COUNT(*) AS LotCount
FROM StoredIn si
WHERE si.WarehouseID = ?
  AND si.EndTime IS NULL
  AND si.StartTime BETWEEN ? AND ?
  AND DATEDIFF({$today_sql}, si.StartTime) > ?
GROUP BY FLOOR(DATEDIFF({$today_sql}, si.StartTime) / 10)
ORDER BY BinStart";

$stmt_h = $conn->prepare($sql_hist);
if ($stmt_h) {
    $stmt_h->bind_param("sssi", $wid, $stale_t1_sql, $stale_t2_sql, $stale_N);
    if ($stmt_h->execute()) {
        $stmt_h->bind_result($h_bin, $h_count);
        while ($stmt_h->fetch()) {
            $stale_bins[] = array(
                'BinStart' => (int)$h_bin,
                'LotCount' => (int)$h_count,
            );
        }
    }
    $stmt_h->close();
}

$conn->close();

$chart_labels = array();
$chart_values = array();
foreach ($temp_readings as $r) {
    $chart_labels[] = date('M d H:i', strtotime($r['ReadingTime']));
    $chart_values[] = (float)$r['Temperature'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- <meta name="viewport" content="width=device-width, initial-scale=1.0"> -->
    <link rel="stylesheet" href="../driver/driver-style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="warehouse-style.css?v=<?php echo time(); ?>">
    <script src="warehouse-scripts.js?v=<?php echo time(); ?>"></script>
    <!-- <title>PharmaCool - Warehouse Details</title> -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- <title>PharmaCool - <?php echo htmlspecialchars($warehouse['WarehouseID']); ?> Detail</title> -->
</head>
<body class="erp-layout">
    <nav class="sidebar">
        <div class="logo">PharmaCool</div>
        <ul class="nav-links">
            <li><a href="whome.php">&laquo; Back to Dashboard</a></li>
            <li class="active">Warehouse Details</li>
        </ul>
        <div class="user-profile">
            <div class="avatar"><?php echo substr($first_name, 0, 1); ?></div>
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
            <p class="breadcrumb">
                <a href="whome.php">&larr; Back to overview</a>
            </p>

            <?php if ($update_msg !== ''): ?>
                <div class="flash flash-ok"><?php echo htmlspecialchars($update_msg); ?></div>
            <?php endif; ?>
            <?php if ($update_err !== ''): ?>
                <div class="flash flash-err"><?php echo $update_err; ?></div>
            <?php endif; ?>

            <div class="wh-header">
                <div>
                    <h2><?php echo htmlspecialchars($warehouse['WarehouseName']); ?></h2>
                    <p class="wh-meta">
                        <strong><?php echo htmlspecialchars($warehouse['WarehouseID']); ?></strong>
                        &middot; <?php echo htmlspecialchars(ucwords($warehouse['Type'])); ?>
                        &middot;
                        <span class="status-text <?php echo str_replace(' ', '-', strtolower($warehouse['Status'])); ?>">
                            <?php echo htmlspecialchars(ucwords($warehouse['Status'])); ?>
                        </span>
                    </p>
                    <p class="wh-meta">
                        <?php echo htmlspecialchars($warehouse['StreetAddress'] . ', ' . $warehouse['City'] . ', ' . $warehouse['State'] . ' ' . $warehouse['ZipCode']); ?>
                    </p>
                </div>
            </div>

            <div class="table-container">
                <h3>Storage Zones</h3>
                <p>Click a zone to see its lots, recent temperature history, and breach log.</p>
                <table class="zone-table">
                    <thead>
                        <tr>
                            <th>ZONE</th>
                            <th>TYPE</th>
                            <th>TEMP RANGE</th>
                            <th>CAPACITY</th>
                            <th>IN USE</th>
                            <th>UTIL.%</th>
                            <th>ALERT</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($zones)): ?>
                        <?php foreach ($zones as $z):
                            $util_pct = ($z['CapacityVolume'] > 0)
                                ? round(($z['InUse'] / $z['CapacityVolume']) * 100)
                                : 0;
                            $row_cls = '';
                            if ($z['OpenAlerts'] > 0) $row_cls .= ' row-alert';
                            if ($selected_zone && $z['ZoneCode'] === $selected_zone['ZoneCode']) $row_cls .= ' row-selected';
                        ?>
                        <tr class="<?php echo trim($row_cls); ?>"
                            onclick="window.location='warehouse-details.php?wid=<?php echo urlencode($wid); ?>&zone=<?php echo urlencode($z['ZoneCode']); ?>#zone-detail'">
                            <td><strong><?php echo htmlspecialchars($z['ZoneCode']); ?></strong></td>
                            <td><?php echo htmlspecialchars(ucwords($z['Classification'])); ?></td>
                            <td>
                                <?php echo number_format((float)$z['MinTemp'], 1); ?> &ndash;
                                <?php echo number_format((float)$z['MaxTemp'], 1); ?> &deg;C
                            </td>
                            <td><?php echo number_format((float)$z['CapacityVolume']); ?></td>
                            <td><?php echo number_format((float)$z['InUse']); ?></td>
                            <td><strong><?php echo $util_pct; ?>%</strong></td>
                            <td>
                                <?php if ($z['OpenAlerts'] > 0): ?>
                                    <div class="alert-badge"><?php echo (int)$z['OpenAlerts']; ?> OPEN</div>
                                <?php else: ?>
                                    <span class="muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align:center;">No zones found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($selected_zone): ?>
            <div id="zone-detail" class="zone-detail">
                <h2>
                    Zone <?php echo htmlspecialchars($selected_zone['ZoneCode']); ?>
                    &mdash; <?php echo htmlspecialchars(ucwords($selected_zone['Classification'])); ?>
                </h2>

                <div class="table-container">
                    <h3>Lots in This Zone</h3>
                    <p>Sorted by earliest expiry. Lots within 30 days highlighted yellow; within 7 days red.</p>
                    <table class="lots-table">
                        <thead>
                            <tr>
                                <th>VENDOR</th>
                                <th>VENDOR ID</th>
                                <th>BATCH</th>
                                <th>LOT</th>
                                <th>VOLUME</th>
                                <th>EXPIRY DATE</th>
                                <th>DAYS TO EXPIRY</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($lots_in_zone)): ?>
                            <?php foreach ($lots_in_zone as $lot):
                                $dte = (int)$lot['DaysToExpiry'];
                                $row_cls = '';
                                if ($dte < 0) $row_cls = 'expired';
                                elseif ($dte <= 7) $row_cls = 'expiring-urgent';
                                elseif ($dte <= 30) $row_cls = 'expiring-soon';
                            ?>
                            <tr class="<?php echo $row_cls; ?>">
                                <td><?php echo htmlspecialchars($lot['VendorName']); ?></td>
                                <td><?php echo htmlspecialchars($lot['VendorID']); ?></td>
                                <td><?php echo htmlspecialchars($lot['BatchNumber']); ?></td>
                                <td><?php echo (int)$lot['LotSeq']; ?></td>
                                <td><?php echo number_format((float)$lot['LotVolume']); ?></td>
                                <td><?php echo htmlspecialchars($lot['ExpiryDate']); ?></td>
                                <td>
                                    <?php if ($dte < 0): ?>
                                        <strong>Expired (<?php echo abs($dte); ?>d ago)</strong>
                                    <?php else: ?>
                                        <?php echo $dte; ?> days
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align:center;">No lots currently stored in this zone.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-container">
                    <h3>
                        Temperature History (Last 7 Days)
                        <?php
                            if ($temp_source === 'primary') {
                                $first_ts = $temp_readings[0]['ReadingTime'];
                                $last_ts  = $temp_readings[count($temp_readings) - 1]['ReadingTime'];
                                echo '<span class="data-badge data-badge-live" title="Primary query: readings from the last 7 calendar days.">LIVE</span>';
                        ?>
                            <span class="data-badge-note">
                                <?php echo htmlspecialchars(date('M d', strtotime($first_ts))); ?>
                                &ndash;
                                <?php echo htmlspecialchars(date('M d', strtotime($last_ts))); ?>
                            </span>
                        <?php
                            } elseif ($temp_source === 'fallback') {
                                $last_ts = $temp_readings[count($temp_readings) - 1]['ReadingTime'];
                                echo '<span class="data-badge data-badge-fallback" title="Primary 7-day query returned no rows. Showing the 7 days ending at this zone&#39;s most recent sensor reading.">FALLBACK</span>';
                        ?>
                            <span class="data-badge-note">window ending <?php echo htmlspecialchars(date('M d, Y', strtotime($last_ts))); ?></span>
                        <?php
                            } else {
                                echo '<span class="data-badge data-badge-none" title="Neither query returned any readings.">NO DATA</span>';
                            }
                        ?>
                    </h3>
                    <p>
                        Zone bounds:
                        <?php echo number_format((float)$selected_zone['MinTemp'], 1); ?>&deg;C to
                        <?php echo number_format((float)$selected_zone['MaxTemp'], 1); ?>&deg;C.
                        Out-of-range segments drawn in red.
                        <?php if ($temp_source === 'fallback'): ?>
                            <br>
                            <span class="muted"><strong>Note:</strong> the live-7-day window for this zone contained no readings, so the chart is showing the most recent 7 days of data available.</span>
                        <?php endif; ?>
                    </p>
                    <?php if (empty($temp_readings)): ?>
                        <p class="muted" style="text-align:center; padding: 20px;">No sensor readings available for this zone.</p>
                    <?php else: ?>
                        <div class="chart-wrap">
                            <canvas id="tempChart"></canvas>
                        </div>
                        <script>
                            (function () {
                                var labels = <?php echo json_encode($chart_labels); ?>;
                                var values = <?php echo json_encode($chart_values); ?>;
                                var minT = <?php echo (float)$selected_zone['MinTemp']; ?>;
                                var maxT = <?php echo (float)$selected_zone['MaxTemp']; ?>;
                                var ctx = document.getElementById('tempChart');
                                if (!ctx || typeof Chart === 'undefined') return;

                                new Chart(ctx, {
                                    type: 'line',
                                    data: {
                                        labels: labels,
                                        datasets: [
                                            {
                                                label: 'Temperature (°C)',
                                                data: values,
                                                borderColor: '#3182ce',
                                                backgroundColor: 'rgba(49,130,206,0.08)',
                                                tension: 0.2,
                                                pointRadius: 2,
                                                pointBackgroundColor: values.map(function (v) {
                                                    return (v < minT || v > maxT) ? '#c53030' : '#3182ce';
                                                }),
                                                segment: {
                                                    borderColor: function (c) {
                                                        var y = c.p1.parsed.y;
                                                        return (y < minT || y > maxT) ? '#c53030' : '#3182ce';
                                                    }
                                                }
                                            },
                                            {
                                                label: 'Min (' + minT + '°C)',
                                                data: labels.map(function () { return minT; }),
                                                borderColor: '#dd6b20',
                                                borderDash: [5, 5],
                                                borderWidth: 1.5,
                                                pointRadius: 0,
                                                fill: false
                                            },
                                            {
                                                label: 'Max (' + maxT + '°C)',
                                                data: labels.map(function () { return maxT; }),
                                                borderColor: '#dd6b20',
                                                borderDash: [5, 5],
                                                borderWidth: 1.5,
                                                pointRadius: 0,
                                                fill: false
                                            }
                                        ]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        plugins: { legend: { position: 'bottom' } },
                                        scales: {
                                            x: { ticks: { maxTicksLimit: 10, autoSkip: true } },
                                            y: { title: { display: true, text: '°C' } }
                                        }
                                    }
                                });
                            })();
                        </script>
                    <?php endif; ?>
                </div>

                <div class="table-container">
                    <h3>Breach History</h3>
                    <p>Every temperature excursion ever logged against this zone. Use the dropdown to update a breach's resolution status.</p>
                    <table class="breach-table">
                        <thead>
                            <tr>
                                <th>START TIME</th>
                                <th>END TIME</th>
                                <th>MAX DEVIATION (°C)</th>
                                <th>CURRENT STATUS</th>
                                <th>UPDATE STATUS</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($breaches)): ?>
                            <?php foreach ($breaches as $br):
                                $status_cls = str_replace(' ', '-', strtolower($br['ResolutionStatus']));
                            ?>
                            <tr class="<?php echo ($br['ResolutionStatus'] === 'Open') ? 'row-alert' : ''; ?>">
                                <td><?php echo htmlspecialchars($br['StartTime']); ?></td>
                                <td><?php echo htmlspecialchars($br['EndTime']); ?></td>
                                <td><?php echo number_format((float)$br['MaxDeviation'], 2); ?></td>
                                <td>
                                    <span class="status-text <?php echo $status_cls; ?>">
                                        <?php echo htmlspecialchars($br['ResolutionStatus']); ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST"
                                          action="warehouse-details.php?wid=<?php echo urlencode($wid); ?>&zone=<?php echo urlencode($zone); ?>"
                                          class="breach-form">
                                        <input type="hidden" name="action" value="update_breach">
                                        <input type="hidden" name="wid" value="<?php echo htmlspecialchars($wid); ?>">
                                        <input type="hidden" name="zone" value="<?php echo htmlspecialchars($zone); ?>">
                                        <input type="hidden" name="start_time" value="<?php echo htmlspecialchars($br['StartTime']); ?>">
                                        <select name="new_status">
                                            <?php foreach (array('Open', 'Under Review', 'Resolved') as $opt): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($opt === $br['ResolutionStatus']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($opt); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-save">Save</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;">No breaches recorded for this zone.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============================================================ -->
            <!-- OPT-W-5: Stale Inventory Report                              -->
            <!-- Per-warehouse, snapshot-pinned, user-filtered.               -->
            <!-- ============================================================ -->
            <div id="stale-inventory" class="table-container">
                <h3>Stale Inventory Report
                    <span class="data-badge data-badge-fallback"
                          title="All date math runs against the seed-data snapshot date (2026-03-16), not the live wall clock.">
                        SNAPSHOT 2026-03-16
                    </span>
                </h3>
                <p>Lots that arrived in the selected window, are still in storage, and have been sitting longer than the threshold N. Yellow rows are between N and 2N days old; red rows exceed 2N days <em>or</em> are within 30 days of expiry.</p>

                <form method="get" action="warehouse-details.php" class="stale-filters" style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; margin: 10px 0 16px;">
                    <input type="hidden" name="wid"  value="<?php echo htmlspecialchars($wid); ?>">
                    <?php if ($zone !== ''): ?>
                        <input type="hidden" name="zone" value="<?php echo htmlspecialchars($zone); ?>">
                    <?php endif; ?>
                    <label style="display:flex; flex-direction:column; font-size:0.8rem;">
                        Arrival window from
                        <input type="date" name="t1" value="<?php echo htmlspecialchars($stale_t1); ?>">
                    </label>
                    <label style="display:flex; flex-direction:column; font-size:0.8rem;">
                        to
                        <input type="date" name="t2" value="<?php echo htmlspecialchars($stale_t2); ?>">
                    </label>
                    <label style="display:flex; flex-direction:column; font-size:0.8rem;">
                        Stale after N days
                        <input type="number" name="n" min="0" max="3650" value="<?php echo (int)$stale_N; ?>" style="width:90px;">
                    </label>
                    <button type="submit" class="btn-save" style="height:32px;">Run report</button>
                    <a href="warehouse-details.php?wid=<?php echo urlencode($wid); ?><?php echo ($zone !== '') ? '&zone=' . urlencode($zone) : ''; ?>#stale-inventory"
                       style="font-size:0.8rem; align-self:center;">Reset</a>
                </form>

                <?php if ($stale_error !== ''): ?>
                    <div class="flash flash-err"><?php echo $stale_error; ?></div>
                <?php endif; ?>

                <p class="muted" style="font-size:0.82rem;">
                    Window: <strong><?php echo htmlspecialchars($stale_t1); ?></strong> to <strong><?php echo htmlspecialchars($stale_t2); ?></strong>
                    &middot; Threshold: <strong><?php echo (int)$stale_N; ?> days</strong>
                    &middot; Matching lots: <strong><?php echo number_format(count($stale_rows)); ?></strong>
                </p>

                <table class="stale-table is-collapsed" id="staleTable">
                    <thead>
                        <tr>
                            <th>VENDOR</th>
                            <th>BATCH</th>
                            <th>LOT</th>
                            <th>ZONE</th>
                            <th>VOLUME</th>
                            <th>ARRIVED</th>
                            <th>STALE DAYS</th>
                            <th>EXPIRY</th>
                            <th>DAYS TO EXPIRY</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($stale_rows)): ?>
                        <?php foreach ($stale_rows as $r):
                            $sd  = (int)$r['StaleDays'];
                            $dte = $r['DaysToExpiry'];

                            // Spec coloring:
                            //   red    if StaleDays > 2N OR DaysToExpiry < 30
                            //   yellow if N < StaleDays <= 2N
                            $row_cls = '';
                            if ($sd > 2 * $stale_N || ($dte !== null && $dte < 30)) {
                                $row_cls = 'expiring-urgent';   // reuse existing red style
                            } elseif ($sd > $stale_N) {
                                $row_cls = 'expiring-soon';     // reuse existing yellow style
                            }
                        ?>
                        <tr class="<?php echo $row_cls; ?>">
                            <td>
                                <?php echo htmlspecialchars($r['VendorName']); ?>
                                <small class="muted">(<?php echo htmlspecialchars($r['VendorID']); ?>)</small>
                            </td>
                            <td><?php echo htmlspecialchars($r['BatchNumber']); ?></td>
                            <td><?php echo (int)$r['LotSeq']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($r['ZoneCode']); ?>
                                <small class="muted"><?php echo htmlspecialchars(ucwords($r['Classification'])); ?></small>
                            </td>
                            <td><?php echo number_format((float)$r['LotVolume']); ?></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($r['StartTime']))); ?></td>
                            <td><strong><?php echo $sd; ?></strong></td>
                            <td><?php echo htmlspecialchars($r['ExpiryDate']); ?></td>
                            <td>
                                <?php
                                if ($dte === null) {
                                    echo '<span class="muted">&mdash;</span>';
                                } elseif ($dte < 0) {
                                    echo '<strong>Expired (' . abs($dte) . 'd ago)</strong>';
                                } else {
                                    echo $dte . ' days';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9" style="text-align:center;">No stale lots found in the selected window.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if (count($stale_rows) > 5): ?>
                    <div class="stale-toggle-wrap" style="text-align:center; margin-top:8px;">
                        <button type="button" id="staleToggle" class="btn-save"
                                data-shown="5"
                                data-total="<?php echo (int)count($stale_rows); ?>"
                                style="background:#4a5568;">
                            Show all <?php echo (int)count($stale_rows); ?> rows
                        </button>
                    </div>
                    <style>
                        /* Collapse to first 5 data rows by default. nth-child(n+6)
                           targets every <tr> from the 6th onward. Scoped to the
                           is-collapsed class so the JS toggle can flip it off. */
                        table.stale-table.is-collapsed tbody tr:nth-child(n+6) {
                            display: none;
                        }
                    </style>
                    <script>
                        (function () {
                            var btn = document.getElementById('staleToggle');
                            var tbl = document.getElementById('staleTable');
                            if (!btn || !tbl) return;
                            btn.addEventListener('click', function () {
                                var collapsed = tbl.classList.toggle('is-collapsed');
                                if (collapsed) {
                                    btn.textContent = 'Show all ' + btn.dataset.total + ' rows';
                                } else {
                                    btn.textContent = 'Show first ' + btn.dataset.shown + ' only';
                                }
                            });
                        })();
                    </script>
                <?php endif; ?>

                <!-- StaleDays histogram (10-day bins) -->
                <h3 style="margin-top:24px;">StaleDays Distribution</h3>
                <p>Lot count by 10-day stale bin. Same filters as above.</p>
                <?php if (empty($stale_bins)): ?>
                    <p class="muted" style="text-align:center; padding: 20px;">Nothing to plot — no stale lots in the selected window.</p>
                <?php else: ?>
                    <div class="chart-wrap">
                        <canvas id="staleHistChart"></canvas>
                    </div>
                    <script>
                        (function () {
                            var bins = <?php echo json_encode($stale_bins); ?>;
                            var labels = bins.map(function (b) {
                                return b.BinStart + '\u2013' + (b.BinStart + 9) + 'd';
                            });
                            var counts = bins.map(function (b) { return b.LotCount; });
                            var ctx = document.getElementById('staleHistChart');
                            if (!ctx || typeof Chart === 'undefined') return;

                            new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: labels,
                                    datasets: [{
                                        label: 'Stale lots',
                                        data: counts,
                                        backgroundColor: '#dd6b20',
                                        borderWidth: 0
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    animation: false,
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: {
                                            callbacks: {
                                                label: function (c) {
                                                    return c.parsed.y + ' lots in ' + c.label + ' bin';
                                                }
                                            }
                                        }
                                    },
                                    scales: {
                                        x: { title: { display: true, text: 'StaleDays bin' } },
                                        y: {
                                            title: { display: true, text: 'Lot count' },
                                            beginAtZero: true,
                                            ticks: { precision: 0 }
                                        }
                                    }
                                }
                            });
                        })();
                    </script>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
