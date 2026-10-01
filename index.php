<?php
session_start();
//Group Database Credentials
$servername = "mydb.itap.purdue.edu" ;
$db_user = "g1154084"; // Your group login 
$db_pass = 'Group1!'; // Your group password 
$dbname = "g1154084";

//Create connection
$conn = new mysqli($servername, $db_user, $db_pass, $dbname);

// Check connection - if this fails, it might cause the 500 error
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 2. Employee Credentials from Form (Level 2)
    $form_user = $_POST['username'];
    $form_pass = $_POST['password'];

    // Query the Employee table specifically
    // Note: Use backticks for table/column names if they match SQL keywords
    $sql = "SELECT Username, EmployeeID, EmploymentStatus, FirstName, Role FROM Employee WHERE Username = ? AND PasswordHash = ?";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Error preparing statement: " . $conn->error);
    }

    $stmt->bind_param("ss", $form_user, $form_pass);
    $stmt->execute();
    
    // Instead of get_result(), we "bind" the columns to variables
    // These must match the ORDER in your SELECT statement (EmployeeID, FirstName, Role)
    $stmt->store_result();
    $stmt->bind_result($res_username, $res_emp_id, $res_status, $res_first_name, $res_role);

    if ($stmt->num_rows === 1) {
        $stmt->fetch(); // This actually loads the data into the variables above
        
        $_SESSION['username'] = $res_username;
        $_SESSION['emp_id'] = $res_emp_id;
        $_SESSION['status'] = $res_status;
        $_SESSION['role'] = $res_role; 
        $_SESSION['name'] = $res_first_name;

        if($res_status == 'active') {
            $clean_role = strtolower(trim($res_role));
            if ($res_role == 'driver') {
                if ($res_role == 'driver') {
                    $sql_veh = "SELECT 
                                    v.VehicleID, 
                                    v.Status 
                                FROM LotCustodyEvent lce
                                JOIN Vehicle v ON v.VehicleID = COALESCE(lce.ToVehicleID, lce.FromVehicleID)
                                WHERE lce.EmployeeID = ? 
                                AND (lce.ToVehicleID IS NOT NULL OR lce.FromVehicleID IS NOT NULL)
                                ORDER BY lce.EventTime DESC 
                                LIMIT 1";
                    $st = $conn->prepare($sql_veh);
                    $st->bind_param("s", $res_emp_id);
                    $st->execute();
                    $st->store_result();
                    $st->bind_result($last_known_veh, $veh_status);
                    if ($st->fetch()) {
                        $_SESSION['veh_assigned'] = $last_known_veh;
                        $_SESSION['veh_status'] = $veh_status; 
                    } else {
                        $_SESSION['veh_assigned'] = "None Assigned";
                        $_SESSION['veh_status'] = "unknown";
                    }
                    $st->close();
                    header("Location: driver/dhome.php");
                    exit();
}
                exit();
            } elseif ($res_role == 'warehouse staff') {
                header("Location: warehouse/whome.php");
                exit();
            } else {
                echo "Role not recognized: " . $res_role;
            }
        } else {
            echo "Invalid login, role not active";
        }
    } else {
        header("Location: index.php?error=invalid_login");
        exit();
    }
}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN">
<link rel="stylesheet" href="index_style.css">
<div class="logo">
<h1>PharmaCool</h1>
</div>
<h2>Welcome to the ERP system</h2>

<div class="team-container">
    <h3>Meet our team!</h3> <br>
    <div class="member-card">
        <img src="Cora.jpg" alt="Member Name">
        <p>Cora Pfister</p>
    </div>
    <div class="member-card">
        <img src="Daniel.jpeg" alt="Member Name">
        <p>Daniel Wu</p>
    </div>
    <div class="member-card">
        <img src="Drew.jpg" alt="Member Name">
        <p>Drew Samis</p>
    </div>
    <div class="member-card">
        <img src="Nick.png" alt="Member Name">
        <p>Nicholas Geren</p>
    </div>
    <div class="member-card">
        <img src="Tarini.jpeg" alt="Member Name">
        <p>Tarini Ketkar</p>
    </div>
    <div class="member-card">
        <img src="Tiger.jpg" alt="Member Name">
        <p>Tiger Liu</p>
    </div>
</div>

<div class="login-box">
    <h2>Enter your credentials here</h2>
    
    <?php if(isset($_GET['error'])) echo '<div class="error-msg">Invalid Credentials</div>'; ?>

    <form action="index.php" method="POST">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit" class="login-button">Login</button>
    </form>
</div>
</html>



