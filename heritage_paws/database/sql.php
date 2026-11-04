
<?php
// 1. Aiven Connection Details
$host = "mysql-2099e8fd-alvinmuchemi-05c2.a.aivencloud.com"; // Your Aiven Host
$port = 20336; // Your Aiven Port (NOT 3306)
$username = "avnadmin"; // Your Aiven Username
$password = "YOUR_AIVEN_PASSWORD"; // Your Aiven Password
$dbname = "heritage_paws"; // Your Database Name
$ssl_ca = "ca.pem"; // Ensure this file is in the same directory!

// 2. Initialize MySQLi
$conn = mysqli_init();
if (!$conn) {
    die("mysqli_init failed");
}

// 3. Set SSL Options (Critical for Aiven)
// Arguments: key, cert, ca_cert, ca_path, cipher_algos
$conn->ssl_set(NULL, NULL, $ssl_ca, NULL, NULL);

// 4. Connect with the Port and SSL Flag
// real_connect(host, user, pass, db, port, socket, flags)
$connected = $conn->real_connect($host, $username, $password, $dbname, $port, NULL, MYSQLI_CLIENT_SSL);

if (!$connected) {
    die("Connection failed: " . $conn->connect_error);
}

// Success!
// echo "Connected successfully"; 
?>
