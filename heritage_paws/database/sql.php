<?php
//error_reporting(0);

$localhost = "localhost";
$username = "root";
$password = "Zxcvbnm.5";
$dbname = "heritage_paws";

$conn =  new mysqli($localhost, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


?>