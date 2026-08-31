<?php
// =====================================================================
// RAISECOM RAX721 - SENSOR MONITORING FULL (Clean Sensor Names)
// =====================================================================
echo "\n[DEBUG] ====== RAISECOM CUSTOM SCRIPT LOADED ======\n";
$rax721_options = snmp_gen_options('snmpwalk');
$rax721_data    = snmp_walk($device, '.1.3.6.1.4.1.8886.60.18.1.2', $rax721_options);

if (!empty($rax721_data)) {
    $rax721_lines    = explode("\n", $rax721_data);
    $inventory_cache = [];
    $sensor_seen     = [];
    $port_cache      = [];

    // TAHAP 1: Metadata (Vendor, SN, PN, Wavelength)
    foreach ($rax721_lines as $line) {
        if (preg_match('/\.60\.18\.1\.2\.1\.1\.1\.3\.([0-9]+)\s*=\s*(.*)$/', trim($line), $m)) {
            $inventory_cache[$m[1]]['vendor'] = trim(str_replace(['STRING:', '"', "'"], '', $m[2]));
        }
        if (preg_match('/\.60\.18\.1\.2\.1\.1\.1\.4\.([0-9]+)\s*=\s*(.*)$/', trim($line), $m)) {
            $inventory_cache[$m[1]]['pn'] = trim(str_replace(['STRING:', '"', "'"], '', $m[2]));
        }
        if (preg_match('/\.60\.18\.1\.2\.1\.1\.1\.5\.([0-9]+)\s*=\s*(.*)$/', trim($line), $m)) {
            $inventory_cache[$m[1]]['sn'] = trim(str_replace(['STRING:', '"', "'"], '', $m[2]));
        }
        if (preg_match('/\.60\.18\.1\.2\.1\.1\.1\.16\.([0-9]+)\s*=\s*(?:INTEGER:\s*)?([0-9]+)/', trim($line), $m)) {
            if ($m[2] > 0) $inventory_cache[$m[1]]['wave'] = $m[2];
        }
    }

    // TAHAP 2: Inventory Module (Membangun Ulang Hierarki SFP)
    foreach ($inventory_cache as $ifIndex => $data) {
        if (!isset($port_cache[$ifIndex])) {
            $port_cache[$ifIndex] = dbFetchRow(
                "SELECT `port_id`, `ifDescr` FROM `ports` WHERE `device_id` = ? AND `ifIndex` = ?",
                array($device['device_id'], $ifIndex)
            );
        }
        if (empty($port_cache[$ifIndex])) continue;

        $port_name = $port_cache[$ifIndex]['ifDescr'];
        $vendor    = !empty($data['vendor']) ? trim($data['vendor']) : '';
        $model     = !empty($data['pn'])     ? trim($data['pn'])     : '';
        $serial    = !empty($data['sn'])     ? trim($data['sn'])     : '';

        if ($vendor || $model || $serial) {
            $module_index = (int)$ifIndex + 1000000;
            
            $ent_data = [
                'inventory_mib'          => 'RAISECOM-DIGITAL-DIAGNOSTIC-MONITOR-MIB',
                'entPhysicalDescr'       => $model ?: $port_name . ' Transceiver',
                'entPhysicalName'        => $port_name . ' Transceiver',
                'entPhysicalClass'       => 'module',
                'entPhysicalMfgName'     => $vendor,
                'entPhysicalModelName'   => $model,
                'entPhysicalSerialNum'   => $serial,
                'entPhysicalIsFRU'       => 'true',
                'ifIndex'                => (int)$ifIndex,
                'deleted'                => array('NULL'),
            ];

            $existing = dbFetchCell("SELECT COUNT(*) FROM `entPhysical` WHERE `device_id` = ? AND `entPhysicalIndex` = ?", [$device['device_id'], $module_index]);
            if ($existing > 0) {
                dbUpdate($ent_data, 'entPhysical', '`device_id` = ? AND `entPhysicalIndex` = ?', [$device['device_id'], $module_index]);
            } else {
                dbInsert(array_merge(['device_id' => $device['device_id'], 'entPhysicalIndex' => $module_index], $ent_data), 'entPhysical');
            }
        }
    }

    // TAHAP 3: Sensor Global & Channel
    foreach ($rax721_lines as $line) {
        if (!preg_match('/\.60\.18\.1\.2\.(2|3)\.1\.1\.(2|3)\.([0-9]+)\.([0-9]+)(\.([0-9]+))?\s*=\s*(?:INTEGER:\s*)?(-?[0-9]+)$/', trim($line), $m)) continue;

        $ifIndex = $m[3];
        $value   = (int)$m[7];

        if (empty($port_cache[$ifIndex])) continue;
        $port_data = $port_cache[$ifIndex];

        $module_index = (int)$ifIndex + 1000000;

        $opts = [
            'entPhysicalIndex'          => $module_index, 
            'measured_class'            => 'port',   
            'measured_entity'           => $port_data['port_id'],
            'entPhysicalIndex_measured' => $ifIndex
        ];

        $ifDescr  = strtolower($port_data['ifDescr']);
        $is_multi = strpos($ifDescr, 'hundred') !== false || strpos($ifDescr, 'forty') !== false;

        // NAMA SENSOR BERSIH (Tanpa embel-embel vendor/tipe)
        if ($m[1] == 2) {
            $prop = $m[4];
            if ($prop == 1)     { $d = "Temperature"; $c = "temperature"; $s = 0.001; }
            elseif ($prop == 5) { $d = "Voltage";     $c = "voltage";     $s = 0.001; }
            elseif ($prop == 2) { $d = "Bias Current";$c = "current";     $s = 0.000001; }
            else continue;
        } else {
            $chan = $m[4]; $prop = $m[6];
            $prefix = $is_multi ? "Channel $chan " : "";
            if ($prop == 4)     { $d = $prefix . "Rx Power"; $c = "dbm"; $s = 0.001; }
            elseif ($prop == 3) { $d = $prefix . "Tx Power"; $c = "dbm"; $s = 0.001; }
            else continue;
        }

        $dedup_key = "{$ifIndex}|{$d}";
        if (isset($sensor_seen[$dedup_key])) continue;
        $sensor_seen[$dedup_key] = true;

        $oid_suffix = $m[1] . '.1.1.' . ($m[1] == 2 ? '2' : '3') . '.' . $ifIndex . '.'
                    . ($m[1] == 2 ? $prop : $chan . '.' . $prop);
        $idx        = $m[1] == 2 ? "{$ifIndex}.{$prop}" : "{$ifIndex}.{$chan}.{$prop}";

        discover_sensor($c, $device, '.1.3.6.1.4.1.8886.60.18.1.2.' . $oid_suffix, $idx,
            'RAISECOM-DIGITAL-DIAGNOSTIC-MONITOR-MIB', $d, $s, $value, $opts);
    }

    // TAHAP 4: Wavelength
    foreach ($inventory_cache as $ifIndex => $data) {
        if (isset($data['wave']) && !empty($port_cache[$ifIndex])) {
            $port_id = $port_cache[$ifIndex]['port_id'];
            $module_index = (int)$ifIndex + 1000000;
            
            $opts_wave = [
                'entPhysicalIndex'          => $module_index,
                'measured_class'            => 'port',
                'measured_entity'           => $port_id,
                'entPhysicalIndex_measured' => $ifIndex,
                'graph'                     => false
            ];

            // NAMA SENSOR BERSIH
            $d_wave = "Wavelength";

            discover_sensor('wavelength', $device, '.1.3.6.1.4.1.8886.60.18.1.2.1.1.1.16.' . $ifIndex, "{$ifIndex}.16", 'RAISECOM-DIGITAL-DIAGNOSTIC-MONITOR-MIB', $d_wave, 1, $data['wave'], $opts_wave);
        }
    }
}
?>