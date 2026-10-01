<?php
date_default_timezone_set('America/Indiana/Indianapolis');

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

$search    = isset($_GET['search']) ? trim($_GET['search']) : '';
$from      = isset($_GET['from']) ? trim($_GET['from']) : '';
$to        = isset($_GET['to']) ? trim($_GET['to']) : '';
$vendor_id = isset($_GET['vendor']) ? trim($_GET['vendor']) : '';
$batch_id  = isset($_GET['batch']) ? trim($_GET['batch']) : '';

// Scope: 'mine' (default) restricts to batches stored at the user's
// warehouses; 'all' shows the entire batch table.
$scope = isset($_GET['scope']) ? trim($_GET['scope']) : 'mine';
if ($scope !== 'mine' && $scope !== 'all') $scope = 'mine';

if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = '';

// ---------------------------------------------------------
// Heuristic "my warehouses" derivation (matches wcustody/wshipments)
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

// Build a SQL EXISTS predicate that scopes a batch to "stored
// at one of the user's warehouses". Returns empty string when
// scope=all so callers can concatenate unconditionally.
function my_storage_scope_clause($conn, $scope, $my_warehouses, $batch_alias = 'b') {
    if ($scope !== 'mine') return '';
    if (empty($my_warehouses)) {
        // User has no warehouses → show nothing
        return ' AND 1=0';
    }
    $list = array();
    foreach ($my_warehouses as $w) {
        $list[] = "'" . $conn->real_escape_string($w) . "'";
    }
    $list_sql = implode(',', $list);
    return " AND EXISTS (
                SELECT 1 FROM StoredIn si
                WHERE si.VendorID    = $batch_alias.VendorID
                  AND si.BatchNumber = $batch_alias.BatchNumber
                  AND si.WarehouseID IN ($list_sql)
            )";
}

$vendor = null;
$vendor_batches = array();
$batch = null;
$batch_lots = array();

if ($vendor_id !== '') {
    $stmt = $conn->prepare("SELECT VendorID, VendorName FROM Vendor WHERE VendorID = ?");
    $stmt->bind_param("s", $vendor_id);
    $stmt->execute();
    $stmt->bind_result($v_id, $v_name);

    if ($stmt->fetch()) {
        $vendor = array(
            'VendorID' => $v_id,
            'VendorName' => $v_name
        );
    }
    $stmt->close();

    if ($vendor) {
        // Scope: when in 'mine' mode, only show batches that have been
        // stored at one of the user's warehouses. The helper returns
        // an empty string when scope='all', so it's safe to concat.
        $vb_scope = my_storage_scope_clause($conn, $scope, $my_warehouses, 'b');
        $stmt = $conn->prepare("
            SELECT b.BatchNumber, b.ManufactureDate, b.ExpiryDate, b.TotalVolume,
                   b.MinStorageTemp, b.MaxStorageTemp
            FROM Batch b
            WHERE b.VendorID = ?
            $vb_scope
            ORDER BY b.ExpiryDate ASC
        ");
        $stmt->bind_param("s", $vendor_id);
        $stmt->execute();
        $stmt->bind_result($b_num, $b_mfg, $b_exp, $b_vol, $b_min, $b_max);

        while ($stmt->fetch()) {
            $vendor_batches[] = array(
                'BatchNumber' => $b_num,
                'ManufactureDate' => $b_mfg,
                'ExpiryDate' => $b_exp,
                'TotalVolume' => $b_vol,
                'MinStorageTemp' => $b_min,
                'MaxStorageTemp' => $b_max
            );
        }
        $stmt->close();
    }
}

if ($vendor_id !== '' && $batch_id !== '') {
    $stmt = $conn->prepare("
        SELECT b.BatchNumber, b.ManufactureDate, b.ExpiryDate, b.TotalVolume,
               b.MinStorageTemp, b.MaxStorageTemp, v.VendorName
        FROM Batch b
        JOIN Vendor v ON b.VendorID = v.VendorID
        WHERE b.VendorID = ?
          AND b.BatchNumber = ?
    ");
    $stmt->bind_param("ss", $vendor_id, $batch_id);
    $stmt->execute();
    $stmt->bind_result($bb_num, $bb_mfg, $bb_exp, $bb_vol, $bb_min, $bb_max, $bb_vendor_name);

    if ($stmt->fetch()) {
        $batch = array(
            'BatchNumber' => $bb_num,
            'ManufactureDate' => $bb_mfg,
            'ExpiryDate' => $bb_exp,
            'TotalVolume' => $bb_vol,
            'MinStorageTemp' => $bb_min,
            'MaxStorageTemp' => $bb_max,
            'VendorName' => $bb_vendor_name
        );
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT LotSeq, LotVolume, CreatedTime
        FROM BatchLot
        WHERE VendorID = ?
          AND BatchNumber = ?
        ORDER BY LotSeq ASC
    ");
    $stmt->bind_param("ss", $vendor_id, $batch_id);
    $stmt->execute();
    $stmt->bind_result($lot_seq, $lot_volume, $created_time);

    while ($stmt->fetch()) {
        $batch_lots[] = array(
            'LotSeq' => $lot_seq,
            'LotVolume' => $lot_volume,
            'CreatedTime' => $created_time
        );
    }
    $stmt->close();
}

// ---------------------------------------------------------
// Search-results pagination
// ---------------------------------------------------------
$results_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

// Build the WHERE clause once — reused by COUNT and SELECT
$where = " WHERE 1=1";
if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where .= " AND (v.VendorName LIKE '%$safe_search%'
                   OR b.BatchNumber LIKE '%$safe_search%'
                   OR b.VendorID LIKE '%$safe_search%')";
}
if ($from !== '') {
    $safe_from = $conn->real_escape_string($from);
    $where .= " AND b.ExpiryDate >= '$safe_from'";
}
if ($to !== '') {
    $safe_to = $conn->real_escape_string($to);
    $where .= " AND b.ExpiryDate <= '$safe_to'";
}
// Apply mine/all scope (helper appends ' AND ...' or empty string)
$where .= my_storage_scope_clause($conn, $scope, $my_warehouses, 'b');

// Total matching rows — drives the page count
$count_sql = "SELECT COUNT(*)
              FROM Batch b
              JOIN Vendor v ON b.VendorID = v.VendorID"
           . $where;
$count_res = $conn->query($count_sql);
$total_rows = $count_res ? (int)$count_res->fetch_row()[0] : 0;
$total_pages = max(1, (int)ceil($total_rows / $results_per_page));

// Clamp page to valid range now that we know the total
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $results_per_page;

// Page query — ORDER, LIMIT, OFFSET inlined (integers, safe)
$sql = "SELECT
            v.VendorName,
            b.VendorID,
            b.BatchNumber,
            b.ManufactureDate,
            b.ExpiryDate,
            b.TotalVolume,
            b.MinStorageTemp,
            b.MaxStorageTemp
        FROM Batch b
        JOIN Vendor v ON b.VendorID = v.VendorID"
     . $where
     . " ORDER BY b.ExpiryDate ASC
         LIMIT $results_per_page OFFSET $offset";

$result = $conn->query($sql);

// Build the query string used by pagination links so the
// search/from/to filters survive page navigation.
$qs_parts = array();
if ($search !== '')    $qs_parts[] = 'search=' . urlencode($search);
if ($from !== '')      $qs_parts[] = 'from='   . urlencode($from);
if ($to !== '')        $qs_parts[] = 'to='     . urlencode($to);
if ($vendor_id !== '') $qs_parts[] = 'vendor=' . urlencode($vendor_id);
if ($batch_id !== '')  $qs_parts[] = 'batch='  . urlencode($batch_id);
if ($scope !== 'mine') $qs_parts[] = 'scope='  . urlencode($scope);  // 'mine' is default, omit
$pager_qs = empty($qs_parts) ? '' : (implode('&', $qs_parts) . '&');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Batch & Vendor Lookup</title>

    <link rel="stylesheet" href="../driver/driver-style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="warehouse-style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="wbatchvendor-style.css?v=<?php echo time(); ?>">
    <script src="warehouse-scripts.js?v=<?php echo time(); ?>"></script>
</head>

<body class="erp-layout">

<nav class="sidebar">
    <div class="logo">PharmaCool</div>
    <ul class="nav-links">
        <li><a href="whome.php">Overview</a></li>
        <li><a href="wshipments.php">Inbound / Outbound</a></li>
        <li class="active">Batch &amp; Vendor Lookup</li>
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
        <h2>Batch & Vendor Lookup</h2>
        <p class="muted">Search batches, view vendor details, and inspect lots inside a batch.</p>

        <?php if ($vendor_id !== '' && $vendor): ?>
            <p class="breadcrumb">
                <a href="wbatchvendor.php">&larr; Back to batch search</a>
            </p>

            <div class="table-container">
                <h3>Vendor Details</h3>
                <p><strong>Vendor ID:</strong> <?php echo htmlspecialchars($vendor['VendorID']); ?></p>
                <p><strong>Vendor Name:</strong> <?php echo htmlspecialchars($vendor['VendorName']); ?></p>
            </div>

            <div class="table-container">
                <h3>Batches for <?php echo htmlspecialchars($vendor['VendorName']); ?></h3>
                <div class="table-scroll"> 
                <table>
                    <thead>
                        <tr>
                            <th>BATCH</th>
                            <th>MANUFACTURED</th>
                            <th>EXPIRY</th>
                            <th>VOLUME</th>
                            <th>TEMP RANGE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($vendor_batches)): ?>
                            <?php foreach ($vendor_batches as $b): ?>
                                <tr onclick="window.location='wbatchvendor.php?vendor=<?php echo urlencode($vendor_id); ?>&batch=<?php echo urlencode($b['BatchNumber']); ?>'">
                                    <td>
                                        <a class="shipment-link"
                                           onclick="event.stopPropagation();"
                                           href="wbatchvendor.php?vendor=<?php echo urlencode($vendor_id); ?>&batch=<?php echo urlencode($b['BatchNumber']); ?>">
                                            <?php echo htmlspecialchars($b['BatchNumber']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($b['ManufactureDate']); ?></td>
                                    <td><?php echo htmlspecialchars($b['ExpiryDate']); ?></td>
                                    <td><?php echo number_format((float)$b['TotalVolume']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($b['MinStorageTemp']); ?> –
                                        <?php echo htmlspecialchars($b['MaxStorageTemp']); ?> °C
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="no-data">No batches found for this vendor.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php elseif ($vendor_id !== ''): ?>
            <div class="table-container">
                <h3>Vendor Not Found</h3>
                <p class="muted">No vendor found for ID: <?php echo htmlspecialchars($vendor_id); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($vendor_id !== '' && $batch_id !== '' && $batch): ?>
            <div class="table-container">
                <h3>Batch Details</h3>
                <p><strong>Vendor:</strong> <?php echo htmlspecialchars($batch['VendorName']); ?></p>
                <p><strong>Batch Number:</strong> <?php echo htmlspecialchars($batch['BatchNumber']); ?></p>
                <p><strong>Manufacture Date:</strong> <?php echo htmlspecialchars($batch['ManufactureDate']); ?></p>
                <p><strong>Expiry Date:</strong> <?php echo htmlspecialchars($batch['ExpiryDate']); ?></p>
                <p><strong>Total Volume:</strong> <?php echo number_format((float)$batch['TotalVolume']); ?></p>
                <p>
                    <strong>Storage Temp Range:</strong>
                    <?php echo htmlspecialchars($batch['MinStorageTemp']); ?> –
                    <?php echo htmlspecialchars($batch['MaxStorageTemp']); ?> °C
                </p>
            </div>

            <div class="table-container">
                <h3>Lots in Batch</h3>

                <table>
                    <thead>
                        <tr>
                            <th>LOT</th>
                            <th>VOLUME</th>
                            <th>CREATED</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($batch_lots)): ?>
                            <?php foreach ($batch_lots as $lot): ?>
                                <tr>
                                    <td>Lot <?php echo htmlspecialchars($lot['LotSeq']); ?></td>
                                    <td><?php echo number_format((float)$lot['LotVolume']); ?></td>
                                    <td><?php echo htmlspecialchars($lot['CreatedTime']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="no-data">No lots found for this batch.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($vendor_id !== '' && $batch_id !== ''): ?>
            <div class="table-container">
                <h3>Batch Not Found</h3>
                <p class="muted">No batch found for this vendor and batch number.</p>
            </div>
        <?php endif; ?>

        <div class="table-container">
            <h3>Search Batches</h3>

            <form method="GET">
                <input type="text" name="search" placeholder="Search vendor, batch, ID..."
                       value="<?php echo htmlspecialchars($search); ?>">

                <label>From:</label>
                <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>">

                <label>To:</label>
                <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>">

                <label>Scope:</label>
                <select name="scope">
                    <option value="mine" <?php echo ($scope === 'mine') ? 'selected' : ''; ?>>
                        My warehouses (default) — <?php echo count($my_warehouses); ?> assigned
                    </option>
                    <option value="all" <?php echo ($scope === 'all') ? 'selected' : ''; ?>>
                        All warehouses (system-wide)
                    </option>
                </select>

                <button type="submit">Search</button>
                <a class="clear-link" href="wbatchvendor.php">Clear</a>
            </form>

            <?php if ($scope === 'mine'): ?>
                <p class="muted" style="font-size: 0.82rem; margin-top: 8px;">
                    <?php if (empty($my_warehouses)): ?>
                        <strong>Showing nothing:</strong> you have no custody history yet, so your "My warehouses" set is empty.
                        Switch <em>Scope</em> to <em>All warehouses</em> to see system-wide batches.
                    <?php else: ?>
                        Scope: batches stored at your <?php echo count($my_warehouses); ?>
                        warehouse<?php echo (count($my_warehouses) === 1 ? '' : 's'); ?>
                        (<?php echo htmlspecialchars(implode(', ', $my_warehouses)); ?>).
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="muted" style="font-size: 0.82rem; margin-top: 8px;">
                    Scope: <strong>system-wide</strong> — every batch in every warehouse.
                </p>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <h3>Batch Search Results</h3>
            <p class="muted" style="font-size:0.82rem; margin-top:0;">
                <?php if ($total_rows === 0): ?>
                    No matching batches.
                <?php else:
                    $shown_from = $offset + 1;
                    $shown_to   = min($offset + $results_per_page, $total_rows);
                ?>
                    Showing <strong><?php echo $shown_from; ?>&ndash;<?php echo $shown_to; ?></strong>
                    of <strong><?php echo number_format($total_rows); ?></strong> matching batches.
                <?php endif; ?>
            </p>
            <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>VENDOR</th>
                        <th>VENDOR ID</th>
                        <th>BATCH</th>
                        <th>MANUFACTURED</th>
                        <th>EXPIRY</th>
                        <th>VOLUME</th>
                        <th>TEMP RANGE</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <a class="shipment-link"
                                       href="wbatchvendor.php?vendor=<?php echo urlencode($row['VendorID']); ?>">
                                        <?php echo htmlspecialchars($row['VendorName']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($row['VendorID']); ?></td>
                                <td>
                                    <a class="shipment-link"
                                       href="wbatchvendor.php?vendor=<?php echo urlencode($row['VendorID']); ?>&batch=<?php echo urlencode($row['BatchNumber']); ?>">
                                        <?php echo htmlspecialchars($row['BatchNumber']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($row['ManufactureDate']); ?></td>
                                <td><?php echo htmlspecialchars($row['ExpiryDate']); ?></td>
                                <td><?php echo number_format((float)$row['TotalVolume']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($row['MinStorageTemp']); ?> &ndash;
                                    <?php echo htmlspecialchars($row['MaxStorageTemp']); ?> &deg;C
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="no-data">No results found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="wbatchvendor.php?<?php echo $pager_qs; ?>page=<?php echo $page - 1; ?>"
                           class="page-btn">&laquo; Prev</a>
                    <?php else: ?>
                        <span class="page-btn disabled">&laquo; Prev</span>
                    <?php endif; ?>

                    <div class="page-selector">
                        <label for="pageJump">Page:</label>
                        <select id="pageJump"
                                onchange="window.location.href='wbatchvendor.php?<?php echo $pager_qs; ?>page=' + this.value;">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo ($i == $page) ? 'selected' : ''; ?>>
                                    <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <span>of <?php echo $total_pages; ?></span>
                    </div>

                    <?php if ($page < $total_pages): ?>
                        <a href="wbatchvendor.php?<?php echo $pager_qs; ?>page=<?php echo $page + 1; ?>"
                           class="page-btn">Next &raquo;</a>
                    <?php else: ?>
                        <span class="page-btn disabled">Next &raquo;</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

</body>
</html>