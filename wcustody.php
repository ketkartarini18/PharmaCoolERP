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

$f_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$f_date_to   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';
$f_employee  = isset($_GET['employee'])  ? trim($_GET['employee'])  : '';
$f_condition = isset($_GET['condition']) ? trim($_GET['condition']) : '';
$f_direction = isset($_GET['direction']) ? trim($_GET['direction']) : '';
$f_warehouse = isset($_GET['warehouse']) ? trim($_GET['warehouse']) : 'mine';
if ($f_warehouse === '') $f_warehouse = 'mine';
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$export_mode = isset($_GET['export']) ? $_GET['export'] : '';
if (!in_array($export_mode, array('filtered', 'all'), true)) $export_mode = '';
$is_export = ($export_mode !== '');

$valid_conditions = array('', 'Seal Intact', 'Packaging Damaged');
if (!in_array($f_condition, $valid_conditions, true)) $f_condition = '';

$valid_directions = array('', 'out', 'in');
if (!in_array($f_direction, $valid_directions, true)) $f_direction = '';

if ($f_date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_from)) $f_date_from = '';
if ($f_date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_to)) $f_date_to = '';
if ($f_employee !== '' && !preg_match('/^[A-Za-z0-9\-]+$/', $f_employee)) $f_employee = '';
if ($f_warehouse !== 'mine' && $f_warehouse !== 'all'
    && !preg_match('/^[A-Za-z0-9\-]+$/', $f_warehouse)) {
    $f_warehouse = 'mine';
}

$employee_options = array();
$res_emp = $conn->query("SELECT DISTINCT e.EmployeeID, e.FirstName, e.LastName, e.Role
                         FROM Employee e
                         JOIN LotCustodyEvent ce ON ce.EmployeeID = e.EmployeeID
                         ORDER BY e.LastName, e.FirstName");
if ($res_emp) {
    while ($r = $res_emp->fetch_assoc()) {
        $employee_options[] = $r;
    }
    $res_emp->free();
}

$warehouse_options = array();
$res_wh = $conn->query("SELECT WarehouseID, WarehouseName FROM Warehouse ORDER BY WarehouseID");
if ($res_wh) {
    while ($r = $res_wh->fetch_assoc()) {
        $warehouse_options[] = $r;
    }
    $res_wh->free();
}

// ---------------------------------------------------------
// Heuristic "my warehouses" derivation
// Schema has no Employee->Warehouse association table, so
// we infer the user's warehouses from their custody history.
// Drives the default 'mine' scope on the warehouse filter.
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

$where = array();

if ($f_date_from !== '') {
    $v = $conn->real_escape_string($f_date_from . ' 00:00:00');
    $where[] = "ce.EventTime >= '$v'";
}
if ($f_date_to !== '') {
    $v = $conn->real_escape_string($f_date_to . ' 23:59:59');
    $where[] = "ce.EventTime <= '$v'";
}
if ($f_employee !== '') {
    $v = $conn->real_escape_string($f_employee);
    $where[] = "ce.EmployeeID = '$v'";
}
if ($f_condition !== '') {
    $v = $conn->real_escape_string($f_condition);
    $where[] = "ce.ConditionConfirmed = '$v'";
}
if ($f_direction === 'out') {
    $where[] = "ce.FromLocation = 'Zone' AND ce.ToLocation = 'Vehicle'";
}
if ($f_direction === 'in') {
    $where[] = "ce.FromLocation = 'Vehicle' AND ce.ToLocation = 'Zone'";
}
if ($f_warehouse === 'mine') {
    if (empty($my_warehouses)) {
        // User has no custody history at any warehouse — return zero rows
        // rather than silently widening to system-wide.
        $where[] = "1=0";
    } else {
        $list = array();
        foreach ($my_warehouses as $w) {
            $list[] = "'" . $conn->real_escape_string($w) . "'";
        }
        $list_sql = implode(',', $list);
        $where[] = "(ce.FromWarehouseID IN ($list_sql) OR ce.ToWarehouseID IN ($list_sql))";
    }
} elseif ($f_warehouse !== 'all' && $f_warehouse !== '') {
    $v = $conn->real_escape_string($f_warehouse);
    $where[] = "(ce.FromWarehouseID = '$v' OR ce.ToWarehouseID = '$v')";
}
// else 'all' (or empty fallback) — no warehouse filter applied

$where_sql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

function format_location($row, $side) {
    $loc_type = $row[$side . 'Location'];

    switch ($loc_type) {
        case 'Zone':
            return 'Zone ' . htmlspecialchars($row[$side . 'WarehouseID'])
                 . ' / ' . htmlspecialchars($row[$side . 'ZoneCode']);
        case 'Vehicle':
            return 'Vehicle ' . htmlspecialchars($row[$side . 'VehicleID']);
        case 'Clinic':
            return 'Clinic #' . htmlspecialchars($row[$side . 'ClinicID']);
        default:
            return '—';
    }
}

if ($is_export) {
    $export_where = ($export_mode === 'all') ? '' : $where_sql;

    $sql_export = "SELECT ce.EventTime, ce.VendorID, ce.BatchNumber, ce.LotSeq,
                          ce.EmployeeID, e.FirstName, e.LastName, e.Role,
                          ce.FromLocation, ce.FromWarehouseID, ce.FromZoneCode,
                          ce.FromVehicleID, ce.FromClinicID,
                          ce.ToLocation, ce.ToWarehouseID, ce.ToZoneCode,
                          ce.ToVehicleID, ce.ToClinicID,
                          ce.ConditionConfirmed
                   FROM LotCustodyEvent ce
                   JOIN Employee e ON ce.EmployeeID = e.EmployeeID
                   $export_where
                   ORDER BY ce.EventTime DESC";
    $res_exp = $conn->query($sql_export);

    $fname_tag = ($export_mode === 'all') ? 'all' : 'filtered';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="custody_log_' . $fname_tag . '_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');

    fputcsv($out, array(
        'Event Time', 'Vendor ID', 'Batch', 'Lot Seq',
        'Employee ID', 'Employee Name', 'Role',
        'From', 'To', 'Condition'
    ));

    if ($res_exp) {
        while ($r = $res_exp->fetch_assoc()) {
            $from_str = $r['FromLocation'] === 'Zone' ? "Zone {$r['FromWarehouseID']}/{$r['FromZoneCode']}"
                      : ($r['FromLocation'] === 'Vehicle' ? "Vehicle {$r['FromVehicleID']}"
                      : ($r['FromLocation'] === 'Clinic' ? "Clinic #{$r['FromClinicID']}" : '—'));

            $to_str = $r['ToLocation'] === 'Zone' ? "Zone {$r['ToWarehouseID']}/{$r['ToZoneCode']}"
                    : ($r['ToLocation'] === 'Vehicle' ? "Vehicle {$r['ToVehicleID']}"
                    : ($r['ToLocation'] === 'Clinic' ? "Clinic #{$r['ToClinicID']}" : '—'));

            fputcsv($out, array(
                $r['EventTime'],
                $r['VendorID'],
                $r['BatchNumber'],
                $r['LotSeq'],
                $r['EmployeeID'],
                $r['FirstName'] . ' ' . $r['LastName'],
                $r['Role'],
                $from_str,
                $to_str,
                $r['ConditionConfirmed']
            ));
        }
        $res_exp->free();
    }

    fclose($out);
    $conn->close();
    exit();
}

// Pagination
$per_page = 20;
$offset = ($page - 1) * $per_page;

$sql_count = "SELECT COUNT(*) AS Cnt FROM LotCustodyEvent ce $where_sql";
$res_cnt = $conn->query($sql_count);

$total_rows = 0;
if ($res_cnt) {
    $row = $res_cnt->fetch_assoc();
    $total_rows = (int)$row['Cnt'];
    $res_cnt->free();
}

$total_pages = max(1, (int)ceil($total_rows / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

$sql_data = "SELECT ce.EventTime, ce.VendorID, ce.BatchNumber, ce.LotSeq,
                    ce.EmployeeID, e.FirstName, e.LastName, e.Role,
                    ce.FromLocation, ce.FromWarehouseID, ce.FromZoneCode,
                    ce.FromVehicleID, ce.FromClinicID,
                    ce.ToLocation, ce.ToWarehouseID, ce.ToZoneCode,
                    ce.ToVehicleID, ce.ToClinicID,
                    ce.ConditionConfirmed
             FROM LotCustodyEvent ce
             JOIN Employee e ON ce.EmployeeID = e.EmployeeID
             $where_sql
             ORDER BY ce.EventTime DESC
             LIMIT $per_page OFFSET $offset";

$rows = array();
$res_data = $conn->query($sql_data);
if ($res_data) {
    while ($r = $res_data->fetch_assoc()) {
        $rows[] = $r;
    }
    $res_data->free();
}

$conn->close();

$qs_filters = http_build_query(array(
    'date_from' => $f_date_from,
    'date_to'   => $f_date_to,
    'employee'  => $f_employee,
    'condition' => $f_condition,
    'direction' => $f_direction,
    'warehouse' => $f_warehouse
));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../driver/driver-style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="warehouse-style.css?v=<?php echo time(); ?>">
    <title>PharmaCool - Custody Log</title>
    <script src="warehouse-scripts.js"></script>
</head>

<body class="erp-layout">
    <nav class="sidebar">
        <div class="logo">PharmaCool</div>

        <ul class="nav-links">
            <li><a href="whome.php">Overview</a></li>
            <li><a href="wshipments.php">Inbound / Outbound</a></li>
            <li><a href="wbatchvendor.php">Batch &amp; Vendor Lookup</a></li>
            <li class="active">Custody Log</li>
            <li><a href="../logout.php">Logout</a></li>
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
            <h2>Custody Event Log</h2>
            <p class="muted">
                Append-only audit trail of every product handoff. Records cannot be edited or deleted.
            </p>

            <div class="table-container">
                <form method="GET" action="wcustody.php" class="filter-form">
                    <div class="filter-row">
                        <label>
                            From date
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($f_date_from); ?>">
                        </label>

                        <label>
                            To date
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($f_date_to); ?>">
                        </label>

                        <label>
                            Employee
                            <select name="employee">
                                <option value="">All employees</option>
                                <?php foreach ($employee_options as $eo): ?>
                                    <option value="<?php echo htmlspecialchars($eo['EmployeeID']); ?>"
                                        <?php echo ($f_employee === $eo['EmployeeID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($eo['LastName'] . ', ' . $eo['FirstName'] . ' (' . $eo['Role'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Condition
                            <select name="condition">
                                <option value="">All conditions</option>
                                <option value="Seal Intact" <?php echo ($f_condition === 'Seal Intact') ? 'selected' : ''; ?>>
                                    Seal Intact
                                </option>
                                <option value="Packaging Damaged" <?php echo ($f_condition === 'Packaging Damaged') ? 'selected' : ''; ?>>
                                    Packaging Damaged
                                </option>
                            </select>
                        </label>

                        <label>
                            Direction
                            <select name="direction">
                                <option value="">All directions</option>
                                <option value="out" <?php echo ($f_direction === 'out') ? 'selected' : ''; ?>>
                                    Loading out (Zone &rarr; Vehicle)
                                </option>
                                <option value="in" <?php echo ($f_direction === 'in') ? 'selected' : ''; ?>>
                                    Receiving in (Vehicle &rarr; Zone)
                                </option>
                            </select>
                        </label>

                        <label>
                            Warehouse
                            <select name="warehouse">
                                <option value="mine"
                                    <?php echo ($f_warehouse === 'mine') ? 'selected' : ''; ?>>
                                    My warehouses (default)<?php
                                        echo ' — ' . count($my_warehouses) . ' assigned';
                                    ?>
                                </option>
                                <option value="all"
                                    <?php echo ($f_warehouse === 'all') ? 'selected' : ''; ?>>
                                    All warehouses (system-wide)
                                </option>
                                <?php foreach ($warehouse_options as $wo): ?>
                                    <option value="<?php echo htmlspecialchars($wo['WarehouseID']); ?>"
                                        <?php echo ($f_warehouse === $wo['WarehouseID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($wo['WarehouseID'] . ' — ' . $wo['WarehouseName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-save">Apply filters</button>
                        <a href="wcustody.php" class="btn-clear">Clear</a>

                        <a href="wcustody.php?<?php echo $qs_filters; ?>&export=filtered"
                           class="btn-export"
                           title="Download a CSV of the rows matching the current filters.">
                            Export filtered (CSV)
                        </a>

                        <a href="wcustody.php?export=all"
                           class="btn-export btn-export-all"
                           title="Download a CSV of every custody event."
                           onclick="return confirm('Export ALL custody events? This will ignore current filters and may be large.');">
                            Export all (CSV)
                        </a>
                    </div>
                </form>
            </div>

            <div class="table-container">
                <h3>
                    Results
                    <span class="muted" style="font-weight: 400; font-size: 13px;">
                        — <?php echo number_format($total_rows); ?> event<?php echo ($total_rows === 1 ? '' : 's'); ?>
                    </span>
                </h3>

                <?php if ($f_warehouse === 'mine'): ?>
                    <p class="muted" style="font-size: 0.82rem; margin-top: -4px;">
                        <?php if (empty($my_warehouses)): ?>
                            <strong>Showing nothing:</strong> you have no custody history yet, so your "My warehouses" set is empty.
                            Pick <em>All warehouses</em> in the filter above to see system-wide events.
                        <?php else: ?>
                            Scope: your <?php echo count($my_warehouses); ?> warehouse<?php echo (count($my_warehouses) === 1 ? '' : 's'); ?>
                            (<?php echo htmlspecialchars(implode(', ', $my_warehouses)); ?>).
                            Pick <em>All warehouses</em> to widen.
                        <?php endif; ?>
                    </p>
                <?php elseif ($f_warehouse === 'all'): ?>
                    <p class="muted" style="font-size: 0.82rem; margin-top: -4px;">
                        Scope: <strong>system-wide</strong> — every warehouse, every employee.
                    </p>
                <?php endif; ?>

                <div class="table-scroll">
                    <table class="custody-table">
                        <thead>
                            <tr>
                                <th>EVENT TIME</th>
                                <th>LOT</th>
                                <th>EMPLOYEE</th>
                                <th>FROM</th>
                                <th>TO</th>
                                <th>CONDITION</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $r):
                                $cond_class = ($r['ConditionConfirmed'] === 'Packaging Damaged')
                                              ? 'cond-damaged' : 'cond-intact';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['EventTime']); ?></td>

                                <td>
                                    <strong><?php echo htmlspecialchars($r['VendorID']); ?></strong>
                                    / <?php echo htmlspecialchars($r['BatchNumber']); ?>
                                    / Lot <?php echo (int)$r['LotSeq']; ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($r['FirstName'] . ' ' . $r['LastName']); ?>
                                    <br>
                                    <small class="muted">
                                        <?php echo htmlspecialchars($r['EmployeeID']); ?>
                                        &middot;
                                        <?php echo htmlspecialchars(ucwords($r['Role'])); ?>
                                    </small>
                                </td>

                                <td><?php echo format_location($r, 'From'); ?></td>
                                <td><?php echo format_location($r, 'To'); ?></td>

                                <td>
                                    <span class="cond-pill <?php echo $cond_class; ?>">
                                        <?php echo htmlspecialchars($r['ConditionConfirmed']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding: 20px;">
                                    No custody events match the current filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="wcustody.php?<?php echo $qs_filters; ?>&page=<?php echo ($page - 1); ?>" class="page-btn">
                            &laquo; Prev
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled">&laquo; Prev</span>
                    <?php endif; ?>

                    <div class="page-selector">
                        <label for="pageJump">Page:</label>
                        <select id="pageJump" onchange="window.location.href=this.value;">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <option value="wcustody.php?<?php echo $qs_filters; ?>&page=<?php echo $i; ?>"
                                    <?php echo ($i == $page) ? 'selected' : ''; ?>>
                                    <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <span>of <?php echo $total_pages; ?></span>
                    </div>

                    <?php if ($page < $total_pages): ?>
                        <a href="wcustody.php?<?php echo $qs_filters; ?>&page=<?php echo ($page + 1); ?>" class="page-btn">
                            Next &raquo;
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled">Next &raquo;</span>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</body>
</html>