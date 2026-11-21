<?php

$host = "sqlXXX.infinityfree.com";
$dbname = "if0_43443966_kalakal";
$username = "if0_43443966";
$password = "qI2fghq6mVB4";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

} catch (PDOException $e) {
    error_log($e->getMessage());
    die("Database connection failed.");
}
?>