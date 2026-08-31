<?php
chdir('/opt/observium');
$init_modules = array('discovery', 'poller');
include('includes/init.inc.php');
if (!function_exists('dbFetchRow')) { include('includes/sql.inc.php'); }
$device = dbFetchRow("SELECT * FROM devices WHERE hostname = '10.25.0.2'");
if (!$device) die("Device not found.\n");
echo "\n[DEBUG] ====== RAISECOM CUSTOM SCRIPT LOADED ======\n";
include('/opt/observium/includes/discovery/sensors/raisecom-optical-transceiver-mib.inc.php');
echo "\nDone.\n";
