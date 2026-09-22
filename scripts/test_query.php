<?php
require_once __DIR__ . '/../public/includes/bootstrap.php';
$res = $conn->query("SELECT DISTINCT program FROM enrollments");
while ($row = $res->fetch_assoc()) {
    echo $row['program'] . "\n";
}
@unlink(__FILE__);
