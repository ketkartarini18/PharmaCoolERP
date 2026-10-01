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

// Connection to SQL Database
$conn = new mysqli("mydb.itap.purdue.edu", "g1154084", "Group1!", "g1154084");

// Uses the vehicle ID already stored in session at login
$session_veh_id = isset($_SESSION['veh_assigned']) ? $_SESSION['veh_assigned'] : null;

// Grabbing vehicle info given the driver ID
$vehicle = null;
if ($session_veh_id && $session_veh_id !== 'None Assigned') {
    $sql_vleIn = "SELECT
                    v.VehicleID,
                    v.LicensePlate,
                    v.RefrigerationVolume,
                    v.MinTempRating,
                    v.MaxTempRating,
                    v.Status AS VehicleStatus
                  FROM Vehicle v
                  WHERE v.VehicleID = ?";

$stmt_vle = $conn->prepare($sql_vleIn);
$vehicle = null;
if ($stmt_vle) {
    $stmt_vle->bind_param("s", $session_veh_id);  
    $stmt_vle->execute();
    $stmt_vle->store_result();
    $stmt_vle->bind_result(
        $res_vehicle_id,
        $res_license_plate,
        $res_refrig_vol,
        $res_min_temp,
        $res_max_temp,
        $res_vehicle_status
    );
    if ($stmt_vle->fetch()) {
        $vehicle = array(
            'vehicle_id'    => $res_vehicle_id,
            'license_plate' => $res_license_plate,
            'refrig_vol'    => $res_refrig_vol,
            'min_temp'      => $res_min_temp,
            'max_temp'      => $res_max_temp,
            'status'        => $res_vehicle_status
        );
    }
    $stmt_vle->close();
}
}

//Grabbing vehicle sensor info if present
$sensors = array();
if ($vehicle) {
    $sql_senIn = "SELECT
                    sn.SensorID,
                    sn.SensorType,
                    sn.CalibrationDate,
                    sn.Status AS SensorStatus
                  FROM Sensor sn
                  WHERE sn.VehicleID = ?";

    $stmt_sen = $conn->prepare($sql_senIn);
    if ($stmt_sen) {
        $stmt_sen->bind_param("s", $vehicle['vehicle_id']);
        $stmt_sen->execute();
        $stmt_sen->store_result();
        $stmt_sen->bind_result(
            $res_sensor_id,
            $res_sensor_type,
            $res_cal_date,
            $res_sensor_status
        );
        while ($stmt_sen->fetch()) {
            $sensors[] = array(
                'sensor_id' => $res_sensor_id,
                'type'      => $res_sensor_type,
                'cal_date'  => $res_cal_date,
                'status'    => $res_sensor_status
            );
        }
        $stmt_sen->close();
    }
}

//Grabbing last 20 sensor readings for vehicle
$readings = array();
if ($vehicle) {
    $sql_senRead = "SELECT
                        sn.SensorID,
                        sr.ReadingTime,
                        sr.Temperature,
                        sr.Latitude,
                        sr.Longitude,
                        sr.ReadingStatus
                    FROM SensorReading sr
                    JOIN Sensor sn ON sn.SensorID = sr.SensorID
                    WHERE sn.VehicleID = ?
                    ORDER BY sr.ReadingTime DESC
                    LIMIT 20";

    $stmt_read = $conn->prepare($sql_senRead);
    if ($stmt_read) {
        $stmt_read->bind_param("s", $vehicle['vehicle_id']);
        $stmt_read->execute();
        $stmt_read->store_result();
        $stmt_read->bind_result(
            $res_r_sensor_id,
            $res_r_time,
            $res_r_temp,
            $res_r_lat,
            $res_r_lng,
            $res_r_status
        );
        while ($stmt_read->fetch()) {
            $readings[] = array(
                'sensor_id' => $res_r_sensor_id,
                'time'      => $res_r_time,
                'temp'      => $res_r_temp,
                'lat'       => $res_r_lat,
                'lng'       => $res_r_lng,
                'status'    => $res_r_status
            );
        }
        $stmt_read->close();
    }
}

//Faulty sensor collection
$faulty_sensors = array_filter($sensors, function($s) {
    return strtolower($s['status']) === 'faulty';
});
?>

<!DOCTYPE html>
 <!-- This is where the fun begins >;) -->
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="driver-style.css?v=<?php echo time(); ?>">
    <title>PharmaCool - My Vehicle</title>
    <script src="driver-scripts.js"></script>

</head>
<body class="erp-layout">
    <nav class="sidebar">
        <div class="logo">PharmaCool</div>
        <ul class="nav-links">
            <li><a href="dhome.php">My Deliveries</a></li>
            <li class="active">My Vehicle</li>
            <li><a href="../logout.php">Logout</a></li>
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
            <h2>My Vehicle</h2>

            <?php if (!$vehicle): ?>
                <div class="card">
                    <p class="no-data">No vehicle currently assigned to an active shipment.</p>
                </div>

            <?php else: ?>
<!-- Truck Details Layout -->
            <h3 class="section-title">Truck details</h3>
            <div class="card">
                <div class="kv-grid">
                    <div class="kv">
                        <span class="kv-label">Vehicle ID</span>
                        <span class="kv-val"><?php echo htmlspecialchars($vehicle['vehicle_id']); ?></span>
                    </div>
                    <div class="kv">
                        <span class="kv-label">License plate</span>
                        <span class="kv-val"><?php echo htmlspecialchars($vehicle['license_plate']); ?></span>
                    </div>
                    <div class="kv">
                        <span class="kv-label">Refrigeration volume</span>
                        <span class="kv-val"><?php echo htmlspecialchars($vehicle['refrig_vol']); ?> L</span>
                    </div>
                    <div class="kv">
                        <span class="kv-label">Min temp rating</span>
                        <span class="kv-val"><?php echo htmlspecialchars($vehicle['min_temp']); ?> °C</span>
                    </div>
                    <div class="kv">
                        <span class="kv-label">Max temp rating</span>
                        <span class="kv-val"><?php echo htmlspecialchars($vehicle['max_temp']); ?> °C</span>
                    </div>
                    <div class="kv">
                        <span class="kv-label">Status</span>
                        <span class="kv-val">
                            <span class="badge badge-active"><?php echo htmlspecialchars($vehicle['status']); ?></span>
                        </span>
                    </div>
                </div>
            </div>  
            <!-- Sensor Panel Layout -->
            <h3 class="section-title">Sensor panel</h3>
            <div class="card">

                <?php if (!empty($faulty_sensors)): ?>
                    <?php foreach ($faulty_sensors as $fs): ?>
                        <div class="banner-faulty">
                            <span class="warn-icon">!</span>
                            Sensor <?php echo htmlspecialchars($fs['sensor_id']); ?> is flagged as faulty. Inspect before next dispatch.
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (empty($sensors)): ?>
                    <p class="no-data">No sensors found for this vehicle.</p>
                <?php else: ?>
                    <div class="sensor-row">
                        <?php foreach ($sensors as $sen): ?>
                            <?php
                                $s_status_lower = strtolower($sen['status']);
                                $s_badge = ($s_status_lower === 'active') ? 'badge-active'
                                         : (($s_status_lower === 'faulty') ? 'badge-faulty' : 'badge-inactive');
                            ?>
                            <div class="sensor-card">
                                <div class="sensor-name"><?php echo htmlspecialchars($sen['sensor_id']); ?></div>
                                <div class="sensor-kv">
                                    <span class="sensor-kv-label">Type</span>
                                    <span class="sensor-kv-val"><?php echo htmlspecialchars($sen['type']); ?></span>
                                </div>
                                <div class="sensor-kv">
                                    <span class="sensor-kv-label">Last calibration</span>
                                    <span class="sensor-kv-val"><?php echo htmlspecialchars($sen['cal_date']); ?></span>
                                </div>
                                <div class="sensor-kv">
                                    <span class="sensor-kv-label">Status</span>
                                    <span class="badge <?php echo $s_badge; ?>"><?php echo htmlspecialchars($sen['status']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
           <!-- Sensor Readings Layout -->
            <h3 class="section-title">Last 20 sensor readings</h3>
            <div class="table-container" style="padding: 0;">
                <?php if (empty($readings)): ?>
                    <p style="padding: 20px;" class="no-data">No readings available.</p>
                <?php else: ?>
                    <div style="max-height: 320px; overflow-y: auto; border-radius: 12px;">
                    <table style="margin: 0;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>SENSOR ID</th>
                                <th>READING TIME</th>
                                <th>TEMPERATURE (°C)</th>
                                <th>LATITUDE</th>
                                <th>LONGITUDE</th>
                                <th>STATUS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($readings as $i => $r): ?>
                                <?php
                                    $r_status_lower = strtolower($r['status']);
                                    $row_class  = ($r_status_lower === 'suspect') ? 'row-suspect'
                                                : (($r_status_lower === 'missing') ? 'row-missing' : '');
                                    $badge_class = ($r_status_lower === 'valid')   ? 'badge-active'
                                                 : (($r_status_lower === 'suspect') ? 'badge-suspect' : 'badge-faulty');
                                ?>
                                <tr<?php echo $row_class ? " class=\"{$row_class}\"" : ''; ?>>
                                    <td style="color:#a0aec0"><?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars($r['sensor_id']); ?></td>
                                    <td style="white-space:nowrap"><?php echo htmlspecialchars($r['time']); ?></td>
                                    <td><?php echo ($r['temp'] !== null) ? htmlspecialchars($r['temp']) : '—'; ?></td>
                                    <td class="mono"><?php echo ($r['lat'] !== null) ? htmlspecialchars($r['lat']) : '—'; ?></td>
                                    <td class="mono"><?php echo ($r['lng'] !== null) ? htmlspecialchars($r['lng']) : '—'; ?></td>
                                    <td>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo htmlspecialchars($r['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div><!-- end scroll wrapper -->
                <?php endif; ?>
            </div>

            <?php endif; // end $vehicle check ?>

        </section>
    </main>
</body>
</html>
