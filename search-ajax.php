<?php
session_start();

// Gatekeeper check
if (!isset($_SESSION['emp_id'])) {
    die(json_encode(array())); // Return empty array if not logged in
}

$conn = new mysqli("mydb.itap.purdue.edu", "g1154084", "Group1!", "g1154084");
if ($conn->connect_error) {
    die(json_encode(array()));
}

$q = isset($_GET['q']) ? $conn->real_escape_string(trim($_GET['q'])) : '';

// Don't search until they've typed at least 2 characters
if (strlen($q) < 2) {
    echo json_encode(array());
    exit();
}

// UNION query to search all 4 required entities safely
// IMPORTANT: Verify that your Vendor table has a column exactly named 'VendorName'
$sql = "
    SELECT ShipmentID, 'Shipment' AS MatchType, ShipmentID AS MatchText 
    FROM Shipment 
    WHERE ShipmentID LIKE '%$q%'
    
    UNION
    
    SELECT sl.ShipmentID, 'Batch' AS MatchType, sl.BatchNumber AS MatchText 
    FROM ShipmentLot sl 
    WHERE sl.BatchNumber LIKE '%$q%'
    
    UNION
    
    SELECT sl.ShipmentID, 'Vendor' AS MatchType, v.VendorName AS MatchText 
    FROM ShipmentLot sl 
    JOIN Vendor v ON sl.VendorID = v.VendorID 
    WHERE v.VendorName LIKE '%$q%'
    
    UNION
    
    SELECT s.ShipmentID, 'Clinic' AS MatchType, c.ClinicName AS MatchText 
    FROM Shipment s 
    JOIN Clinic c ON s.DestinationClinicID = c.ClinicID 
    WHERE c.ClinicName LIKE '%$q%'
    
    LIMIT 8
";

$result = $conn->query($sql);
$data = array();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = array(
            'ShipmentID' => $row['ShipmentID'],
            'Type' => $row['MatchType'],
            'Text' => $row['MatchText']
        );
    }
    $result->free();
}

$conn->close();

// Send the data back to the browser as a JSON object
header('Content-Type: application/json');
echo json_encode($data);
?>