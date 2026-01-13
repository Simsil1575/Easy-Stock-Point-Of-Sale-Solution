<?php
header('Content-Type: application/json');
$db = new PDO('sqlite:../pos.db');
$creditors = $db->query("SELECT * FROM creditors WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($creditors); 