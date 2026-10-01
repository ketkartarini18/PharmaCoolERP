<?php
session_start();

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

// Filters
$tab       = isset($_GET['tab']) && $_GET['tab'] === 'inbound' ? 'inbound' : 'outbound';
$wid       = isset($_GET['wid']) ? trim($_GET['wid']) : 'mine';
if ($wid === '') $wid = 'mine';
$status    = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to   = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$valid_statuses = array('all', 'scheduled', 'in transit', 'delivered', 'delayed');
if (!in_array($status, $valid_statuses, true)) {
    $status = 'all';
}

// Pagination
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$results_per_page = 10;
$offset = ($page - 1) * $results_per_page;

// POST handler is moved further down so it can validate the
// target shipment against the user's warehouse set.

// Warehouse filter list
$warehouses = array();
$res_wh = $conn->query("SELECT WarehouseID, WarehouseName FROM Warehouse ORDER BY WarehouseID");
if ($res_wh) {
    while ($r = $res_wh->fetch_assoc()) {
        $warehouses[] = $r;
    }
    $res_wh->free();
}

// ---------------------------------------------------------
// Heuristic "my warehouses" derivation (same approach as
// wcustody.php). Schema has no Employee->Warehouse table,
// so we infer from custody history.
// ---------------------------------------------------------
$my_warehouses = array();
$stmt_mw = $conn->prepare("
    SELECT DISTINCT COALESCE(FromWarehouseID, ToWarehouseID) AS WarehouseID
    FROM LotCustodyEvent
    WHERE EmployeeID = ?
      AND COALESCE(FromWarehouseID, ToWarehouseID) IS NOT NULL
");
if ($stmt_mw) {
    $stmt_mw->bind_param("s", $emp_id);
    $stmt_mw->execute();
    $stmt_mw->bind_result($mw_wid);
    while ($stmt_mw->fetch()) {
        $my_warehouses[] = $mw_wid;
    }
    $stmt_mw->close();
}

// Helper: build a SQL IN-list from the user's warehouses,
// suitable for inlining (already escaped). Returns NULL-safe
// '(NULL)' when the set is empty so the IN clause never errors.
function my_warehouse_in_list($conn, $my_warehouses) {
    if (empty($my_warehouses)) return "(NULL)";
    $list = array();
    foreach ($my_warehouses as $w) {
        $list[] = "'" . $conn->real_escape_string($w) . "'";
    }
    return '(' . implode(',', $list) . ')';
}

// ---------------------------------------------------------
// POST: advance outbound shipment (scheduled -> in transit)
// Now scoped: refuses the write if the shipment's origin
// warehouse isn't in the user's warehouse set. To allow
// cross-warehouse writes again, swap the ownership check
// for an unconditional UPDATE.
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'advance_shipment') {

    $p_sid       = isset($_POST['shipment_id']) ? trim($_POST['shipment_id']) : '';
    $advance_err = '';

    if ($p_sid !== '') {
        // Look up the shipment's origin to check ownership
        $stmt_chk = $conn->prepare("SELECT OriginWarehouseID, Status
                                    FROM Shipment
                                    WHERE ShipmentID = ?");
        $stmt_chk->bind_param("s", $p_sid);
        $stmt_chk->execute();
        $stmt_chk->bind_result($chk_origin, $chk_status);
        $found = $stmt_chk->fetch();
        $stmt_chk->close();

        if (!$found) {
            $advance_err = 'not_found';
        } elseif ($chk_status !== 'scheduled') {
            // Race or stale form — silently noop, same as before
            $advance_err = 'wrong_state';
        } elseif (!in_array($chk_origin, $my_warehouses, true)) {
            // Cross-warehouse write attempt — refuse
            $advance_err = 'forbidden';
        } else {
            $sql_adv = "UPDATE Shipment
                        SET Status = 'in transit'
                        WHERE ShipmentID = ?
                          AND Status = 'scheduled'";
            $stmt_adv = $conn->prepare($sql_adv);
            $stmt_adv->bind_param("s", $p_sid);
            $stmt_adv->execute();
            $stmt_adv->close();
        }

        $qs = http_build_query(array(
            'tab' => $tab,
            'wid' => $wid,
            'status' => $status,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'page' => $page,
            'updated' => $advance_err === '' ? '1' : '0',
            'err'     => $advance_err,
        ));
        header("Location: wshipments.php?" . $qs);
        exit();
    }
}

// Build shipment query
function build_shipment_query($conn, $direction, $wid, $status, $date_from, $date_to, $my_warehouses) {
    $my_in = my_warehouse_in_list($conn, $my_warehouses);

    if ($direction === 'outbound') {
        $main_date = 's.DepartureTime';
        $where = array("1=1");

        if ($wid === 'mine') {
            $where[] = "s.OriginWarehouseID IN $my_in";
        } elseif ($wid !== '' && $wid !== 'all') {
            $where[] = "s.OriginWarehouseID = '" . $conn->real_escape_string($wid) . "'";
        }

        $place_expr = "CASE
            WHEN s.DestinationType = 'Warehouse' THEN CONCAT(dw.WarehouseID, ' - ', dw.WarehouseName)
            ELSE CONCAT('Clinic ', c.ClinicName)
        END";

    } else {
        $main_date = 's.ArrivalTime';
        $where = array("s.DestinationType = 'Warehouse'");

        if ($wid === 'mine') {
            $where[] = "s.DestinationWarehouseID IN $my_in";
        } elseif ($wid !== '' && $wid !== 'all') {
            $where[] = "s.DestinationWarehouseID = '" . $conn->real_escape_string($wid) . "'";
        }

        $place_expr = "CONCAT(ow.WarehouseID, ' - ', ow.WarehouseName)";
    }

    if ($status !== '' && $status !== 'all') {
        $where[] = "s.Status = '" . $conn->real_escape_string($status) . "'";
    }

    if ($date_from !== '') {
        $where[] = "DATE($main_date) >= '" . $conn->real_escape_string($date_from) . "'";
    }

    if ($date_to !== '') {
        $where[] = "DATE($main_date) <= '" . $conn->real_escape_string($date_to) . "'";
    }

    $where_sql = implode(" AND ", $where);

    $sql = "SELECT
                s.ShipmentID,
                s.Status,
                s.OriginWarehouseID,
                s.DestinationType,
                s.DestinationWarehouseID,
                s.DestinationClinicID,
                s.VehicleID,
                s.DepartureTime,
                s.ArrivalTime,
                $place_expr AS OtherParty,
                COALESCE(lc.LotCount, 0) AS LotCount,
                COALESCE(tb.OpenAlerts, 0) AS OpenAlerts
            FROM Shipment s
            JOIN Warehouse ow
              ON s.OriginWarehouseID = ow.WarehouseID
            LEFT JOIN Warehouse dw
              ON s.DestinationWarehouseID = dw.WarehouseID
            LEFT JOIN Clinic c
              ON s.DestinationClinicID = c.ClinicID
            LEFT JOIN (
                SELECT ShipmentID, COUNT(*) AS LotCount
                FROM ShipmentLot
                GROUP BY ShipmentID
            ) lc
              ON s.ShipmentID = lc.ShipmentID
            LEFT JOIN (
                SELECT ShipmentID, COUNT(*) AS OpenAlerts
                FROM ShipmentTempBreach
                WHERE ResolutionStatus = 'Open'
                GROUP BY ShipmentID
            ) tb
              ON s.ShipmentID = tb.ShipmentID
            WHERE $where_sql
            ORDER BY $main_date DESC, s.ShipmentID ASC";

    return $sql;
}

$sql_outbound_base = build_shipment_query($conn, 'outbound', $wid, $status, $date_from, $date_to, $my_warehouses);
$sql_inbound_base  = build_shipment_query($conn, 'inbound',  $wid, $status, $date_from, $date_to, $my_warehouses);

// Count active tab rows before LIMIT
$count_sql = ($tab === 'inbound') ? $sql_inbound_base : $sql_outbound_base;
$count_res = $conn->query($count_sql);
$total_rows = $count_res ? $count_res->num_rows : 0;
$total_pages = max(1, ceil($total_rows / $results_per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $results_per_page;
}

// Count both tabs for summary cards
$res_out_count = $conn->query($sql_outbound_base);
$outbound_count = $res_out_count ? $res_out_count->num_rows : 0;

$res_in_count = $conn->query($sql_inbound_base);
$inbound_count = $res_in_count ? $res_in_count->num_rows : 0;

// Apply LIMIT only to active display queries
$sql_outbound = $sql_outbound_base . " LIMIT $offset, $results_per_page";
$sql_inbound  = $sql_inbound_base  . " LIMIT $offset, $results_per_page";

$outbound = array();
$res_out = $conn->query($sql_outbound);
if ($res_out) {
    while ($r = $res_out->fetch_assoc()) {
        $outbound[] = $r;
    }
    $res_out->free();
}

$inbound = array();
$res_in = $conn->query($sql_inbound);
if ($res_in) {
    while ($r = $res_in->fetch_assoc()) {
        $inbound[] = $r;
    }
    $res_in->free();
}

$conn->close();

$active_rows  = ($tab === 'inbound') ? $inbound : $outbound;
$active_label = ($tab === 'inbound') ? 'Origin' : 'Destination';

$page_params = array(
    'tab' => $tab,
    'wid' => $wid,
    'status' => $status,
    'date_from' => $date_from,
    'date_to' => $date_to
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PharmaCool - Inbound / Outbound Shipments</title>
    <link rel="stylesheet" href="../driver/driver-style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="warehouse-style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="wshipments-style.css?v=<?php echo time(); ?>">
    <script src="warehouse-scripts.js?v=<?php echo time(); ?>"></script>
</head>

<body class="erp-layout">
    <nav class="sidebar">
        <div class="logo">PharmaCool</div>
        <ul class="nav-links">
            <li><a href="whome.php">Overview</a></li>
            <li class="active">Inbound / Outbound</li>
            <li><a href="wbatchvendor.php">Batch &amp; Vendor Lookup</a></li>
            <li><a href="wcustody.php">Custody Log</a></li>
            <li><a href="../logout.php">Logout</a></li>
        </ul>
        <div class="user-profile">
            <div class="avatar"><?php echo htmlspecialchars(substr($first_name, 0, 1)); ?></div>
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
            <h2>Inbound / Outbound Shipments 📦</h2>
            <p class="muted">Track shipments leaving and arriving at warehouse facilities.</p>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
                <div class="alert-banner alert-success">
                    <span>✔</span> Shipment advanced to In Transit.
                </div>
            <?php elseif (isset($_GET['err']) && $_GET['err'] === 'forbidden'): ?>
                <div class="alert-banner alert-error" style="background:#fee2e2; color:#991b1b; padding:8px 12px; border-radius:4px; margin-bottom:12px;">
                    <span>✗</span> Cannot advance shipment: it originates at a warehouse outside your assignment.
                </div>
            <?php elseif (isset($_GET['err']) && $_GET['err'] === 'wrong_state'): ?>
                <div class="alert-banner" style="background:#fef3c7; color:#92400e; padding:8px 12px; border-radius:4px; margin-bottom:12px;">
                    <span>⚠</span> Shipment is no longer in 'scheduled' state — no change made.
                </div>
            <?php elseif (isset($_GET['err']) && $_GET['err'] === 'not_found'): ?>
                <div class="alert-banner" style="background:#fef3c7; color:#92400e; padding:8px 12px; border-radius:4px; margin-bottom:12px;">
                    <span>⚠</span> Shipment ID not found.
                </div>
            <?php endif; ?>

            <?php if ($wid === 'mine'): ?>
                <p class="muted" style="font-size: 0.82rem;">
                    <?php if (empty($my_warehouses)): ?>
                        <strong>Showing nothing:</strong> you have no custody history yet, so your "My warehouses" set is empty.
                        Pick <em>All warehouses</em> in the filter below to see system-wide shipments.
                    <?php else: ?>
                        Scope: your <?php echo count($my_warehouses); ?> warehouse<?php echo (count($my_warehouses) === 1 ? '' : 's'); ?>
                        (<?php echo htmlspecialchars(implode(', ', $my_warehouses)); ?>).
                        Pick <em>All warehouses</em> in the filter to widen.
                    <?php endif; ?>
                </p>
            <?php elseif ($wid === 'all'): ?>
                <p class="muted" style="font-size: 0.82rem;">
                    Scope: <strong>system-wide</strong> — every warehouse.
                </p>
            <?php endif; ?>

            <div class="stats-row">
                <div class="card">
                    <h3><?php echo number_format($outbound_count); ?></h3>
                    <p>Outbound matching filters</p>
                </div>
                <div class="card">
                    <h3><?php echo number_format($inbound_count); ?></h3>
                    <p>Inbound matching filters</p>
                </div>
                <div class="card <?php echo ($status !== 'all') ? 'warn' : ''; ?>">
                    <h3><?php echo htmlspecialchars(ucwords($status)); ?></h3>
                    <p>Status filter</p>
                </div>
            </div>

            <div class="table-container">
                <div class="shipment-tabs">
                    <a class="<?php echo ($tab === 'outbound') ? 'active' : ''; ?>"
                       href="wshipments.php?<?php echo http_build_query(array('tab'=>'outbound','wid'=>$wid,'status'=>$status,'date_from'=>$date_from,'date_to'=>$date_to,'page'=>1)); ?>">
                        Outbound
                    </a>
                    <a class="<?php echo ($tab === 'inbound') ? 'active' : ''; ?>"
                       href="wshipments.php?<?php echo http_build_query(array('tab'=>'inbound','wid'=>$wid,'status'=>$status,'date_from'=>$date_from,'date_to'=>$date_to,'page'=>1)); ?>">
                        Inbound
                    </a>
                </div>

                <form method="get" class="filter-bar">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                    <input type="hidden" name="page" value="1">

                    <label>
                        Warehouse
                        <select name="wid">
                            <option value="mine" <?php echo ($wid === 'mine') ? 'selected' : ''; ?>>
                                My warehouses (default) — <?php echo count($my_warehouses); ?> assigned
                            </option>
                            <option value="all" <?php echo ($wid === 'all') ? 'selected' : ''; ?>>
                                All warehouses (system-wide)
                            </option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?php echo htmlspecialchars($wh['WarehouseID']); ?>"
                                    <?php echo ($wid === $wh['WarehouseID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($wh['WarehouseID'] . ' - ' . $wh['WarehouseName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Status
                        <select name="status">
                            <?php foreach ($valid_statuses as $st): ?>
                                <option value="<?php echo htmlspecialchars($st); ?>"
                                    <?php echo ($status === $st) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucwords($st)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        From
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </label>

                    <label>
                        To
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </label>

                    <button type="submit" class="btn-save">Apply</button>
                    <a class="clear-link" href="wshipments.php?tab=<?php echo htmlspecialchars($tab); ?>">Clear</a>
                </form>

                <h3><?php echo ($tab === 'inbound') ? 'Inbound Shipments' : 'Outbound Shipments'; ?></h3>
                <p>
                    <?php if ($tab === 'inbound'): ?>
                        Shipments arriving at the selected warehouse. 📥
                    <?php else: ?>
                        Shipments leaving the selected warehouse. 📤
                    <?php endif; ?>
                </p>
                <div class="table-scroll">
                <table class="shipment-table">
                    <thead>
                        <tr>
                            <th>SHIPMENT #</th>
                            <th>STATUS</th>
                            <th><?php echo htmlspecialchars(strtoupper($active_label)); ?></th>
                            <th>DEPARTURE</th>
                            <th>ARRIVAL</th>
                            <th>TRUCK</th>
                            <th>LOTS</th>
                            <th>ALERTS</th>
                            <th>ACTION</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!empty($active_rows)): ?>
                        <?php foreach ($active_rows as $s):
                            $status_class = str_replace(' ', '-', strtolower($s['Status']));
                            $row_class = ($s['OpenAlerts'] > 0) ? 'row-alert' : '';
                        ?>
                            <tr class="<?php echo $row_class; ?>"
                                onclick="window.location='wshipment-details.php?id=<?php echo urlencode($s['ShipmentID']); ?>'">

                                <td>
                                    <a href="wshipment-details.php?id=<?php echo urlencode($s['ShipmentID']); ?>"
                                       class="shipment-link"
                                       onclick="event.stopPropagation();">
                                        <?php echo htmlspecialchars($s['ShipmentID']); ?>
                                    </a>
                                </td>

                                <td>
                                    <span class="status-text <?php echo htmlspecialchars($status_class); ?>">
                                        <?php echo htmlspecialchars(ucwords($s['Status'])); ?>
                                    </span>
                                </td>

                                <td><?php echo htmlspecialchars($s['OtherParty']); ?></td>

                                <td>
                                    <?php echo $s['DepartureTime']
                                        ? htmlspecialchars(date('m/d H:i', strtotime($s['DepartureTime'])))
                                        : '<span class="muted">&mdash;</span>'; ?>
                                </td>

                                <td>
                                    <?php echo $s['ArrivalTime']
                                        ? htmlspecialchars(date('m/d H:i', strtotime($s['ArrivalTime'])))
                                        : '<span class="muted">&mdash;</span>'; ?>
                                </td>

                                <td>
                                    <?php echo $s['VehicleID']
                                        ? htmlspecialchars($s['VehicleID'])
                                        : '<span class="muted">&mdash;</span>'; ?>
                                </td>

                                <td><?php echo number_format((int)$s['LotCount']); ?></td>

                                <td>
                                    <?php if ((int)$s['OpenAlerts'] > 0): ?>
                                        <span class="alert-badge"><?php echo (int)$s['OpenAlerts']; ?> OPEN</span>
                                    <?php else: ?>
                                        <span class="muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>

                                <td onclick="event.stopPropagation();">
                                    <?php if ($tab === 'outbound' && strtolower($s['Status']) === 'scheduled'): ?>
                                        <form method="post" class="inline-action">
                                            <input type="hidden" name="action" value="advance_shipment">
                                            <input type="hidden" name="shipment_id" value="<?php echo htmlspecialchars($s['ShipmentID']); ?>">
                                            <button type="submit" class="save-btn">Start</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">View</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="no-data">No shipments found for these filters.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <?php $page_params['page'] = $page - 1; ?>
                        <a href="wshipments.php?<?php echo http_build_query($page_params); ?>" class="page-btn">&laquo; Prev</a>
                    <?php else: ?>
                        <span class="page-btn disabled">&laquo; Prev</span>
                    <?php endif; ?>

                    <div class="page-selector">
                        <label for="pageJump">Page: </label>
                        <select id="pageJump" onchange="window.location.href=this.value;">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <?php $page_params['page'] = $i; ?>
                                <option value="wshipments.php?<?php echo htmlspecialchars(http_build_query($page_params)); ?>"
                                    <?php echo ($i == $page) ? 'selected' : ''; ?>>
                                    <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <span>of <?php echo $total_pages; ?></span>
                    </div>

                    <?php if ($page < $total_pages): ?>
                        <?php $page_params['page'] = $page + 1; ?>
                        <a href="wshipments.php?<?php echo http_build_query($page_params); ?>" class="page-btn">Next &raquo;</a>
                    <?php else: ?>
                        <span class="page-btn disabled">Next &raquo;</span>
                    <?php endif; ?>
                </div>

            </div>
        </section>
    </main>
</body>
</html>