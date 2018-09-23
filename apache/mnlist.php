<?php
/**
 * Get masternode list from rpc, parse it into a nice json structure, and provide (as api)
 *
 * note: this requires proper setup of either daemon on this host or rpc connection to another daemon
 * note: make sure apache/httpd user has access to execute mogwai-cli
 */

require_once('rpcclient.php');

// PUT CREDENTIALS HERE
$username = '';
$password = '';
$host = 'localhost'; //'127.0.0.1';
$port = 17710;  // make sure to use RPC port, not the P2P port (17777)
$fist_letter = "M";   // first letter of coin addresses

$rpc = new RPCClient($username, $password, $host, $port) or die("Unable to instantiate RPCClient" . PHP_EOL);


// note:  recent addition to masternodelist api is "qualify" which will simplify figuring 
// out which masternodes are qualified for rewards.  this is not yet implemented in this script 
// as it has not been rolled into a formal release yet 
$getinfo = $rpc->getinfo();

if (empty($getinfo)) {
    // something wrong with rpc connection 
    echo json_encode(array("error"=>"rpc not responding"));
    exit(0);
}

$full = $rpc->masternodelist("full");
$rank = $rpc->masternodelist("rank");
$getblocktemplate = $rpc->getblocktemplate();
$getblocktemplate['difficulty'] = get_difficulty($getblocktemplate['bits']);

$next_10 = $rpc->masternode("winners", "0", $first_letter);
foreach ($next_10 as $key => $el) {
    $mns = preg_split('/(:|,| )/',$el,-1, PREG_SPLIT_NO_EMPTY);
    // if there are more than one winner, find the one with the highest vote count
    if (count($mns) > 2) {
        for ($i = 2; $i < count($mns); $i+=2) {
            if ($mns[$i+1] > $mns[1]) {
                $temp = $mns[0];  $mns[0] = $mns[$i]; $mns[$i] = $temp;
                $temp = $mns[1];  $mns[1] = $mns[$i+1]; $mns[$i+1] = $temp;
            }
        }
    }

    $next_10[$key] = $mns[0];
}

if (empty($full) || empty($rank)) {
    echo "[]";
    exit(1);
}

// refactor the 'rank' array (push the value ("rank") into an object as an attribute)
foreach ($rank as $key => $val) {
    $rank[$key] = array('rank' => $val);
}

// parse the 'full' results and put them into the $rank array
// the 'full' array's data is a string that can be imploded on white space (after trimming leading space)
// and placed into the following attributes:  status protocol payee lastseen activeseconds lastpaidtime lastpaidblock IP
// (plus the key)
$max_paid_block = 0;
$enabled_count = 0;
foreach ($full as $key => $val) {
    $parts = preg_split('/ +/', trim($val));
    $rank[$key]['key'] = $key;
    $rank[$key]['status'] = array_shift($parts);
    $rank[$key]['protocol'] = array_shift($parts);
    $rank[$key]['payee'] = array_shift($parts);
    $rank[$key]['lastseen'] = intval(array_shift($parts));
    $rank[$key]['activeseconds'] = intval(array_shift($parts));
    $rank[$key]['lastpaidtime'] = intval(array_shift($parts));
    $rank[$key]['lastpaidblock'] = intval(array_shift($parts));
    $rank[$key]['IP'] = array_shift($parts);

    // chamge WATCHDOG_EXPIRED to ENABLED_WD_EXP
    if ($rank[$key]['status'] == 'WATCHDOG_EXPIRED') {
        $rank[$key]['status'] = "ENABLED_WD_EXP";
        $enabled_count++;
    }
    elseif ($rank[$key]['status'] == 'ENABLED') {
        $enabled_count++;
    }

    $max_paid_block = max($max_paid_block, $rank[$key]['lastpaidblock']);

}

// reprocess the list to differentiate between:
// * invalid (expired/need restart)
// * started but not eligible
// * eligible bottom 90%
// * eligible top 10%
$count = count($rank);
$enabled_count = $rpc->masternode('count', 'qualify');


$count_ten_pct = $count / 10;
$estimate_ten_pct = floor($count_ten_pct) * 120;
$count_top_tier = 0;
$target_age = $count * (2.6 * 60);  // MNs are not eligible until this age (masternodeman.cpp 512)

// sort by last paid time, or active seconds
usort($rank, function($a, $b) {
    $cmp = $a['lastpaidtime'] <=> $b['lastpaidtime'];

    if ($cmp != 0) {
        return $cmp;
    }

    return $b['activeseconds'] <=> $a['activeseconds'];
});

// add tiers and estimated time to payment
foreach ($rank as $key => $mn) {

    if (in_array($rank[$key]['payee'], $next_10)) {
        $rank[$key]['tier'] = "000_PAYING";
        $rank[$key]['lastpaidblock'] = array_search($rank[$key]['payee'], $next_10);
        $rank[$key]['pos'] = -10 + ($max_paid_block - $rank[$key]['lastpaidblock'])*-1;
        $rank[$key]['estimate'] = time() + (10 + $rank[$key]['pos']) * 120;
    }
    elseif (strpos($mn['status'], 'ENABLED') === false) {
        $rank[$key]['tier'] = "004_INVALID";
        $rank[$key]['pos'] = 99999;
        $rank[$key]['estimate'] = 0;
    }
    elseif ($mn['activeseconds'] < $target_age) {
        $num_blocks_needed = ceil(($target_age - $mn['activeseconds']) / (2.6*60));
        $rank[$key]['tier'] = "003_NEW";
        $rank[$key]['pos'] = $count + $num_blocks_needed;
        $rank[$key]['estimate'] = ($target_age - $rank[$key]['activeseconds']) + time() + $estimate_ten_pct + 10 * 120;
    }
    else {
        $count_top_tier++;
        if ($count_top_tier <= $count_ten_pct) {
            $rank[$key]['tier'] = "001_10_PERCENT";
            $rank[$key]['pos'] = $count_top_tier;
            $rank[$key]['estimate'] = time() + $estimate_ten_pct + 10 * 120;
        }
        else {
            $rank[$key]['tier'] = "002_90_PERCENT";
            $rank[$key]['pos'] = $count_top_tier;
            $rank[$key]['estimate'] = time() + (10 + $rank[$key]['pos']) * 120;
        }
    }
}


// re-sort by pos
usort($rank, function($a, $b) {
    return $a['pos'] <=> $b['pos'];
});

// output to an ARRAY of objects with key "data" for datatables. ($rank is currently an OBJECT..?)
$output = array("data" => array());
$pos = 0;
foreach ($rank as $mn) {
    if ($mn['pos'] > 0) {
        $mn['pos'] = ++$pos;
    }
    $output["data"][] = $mn;
}

$output['meta']['target_age'] = $target_age * 1000;  // convert to javascript timestamp
$output['getinfo'] = $getinfo;
$output['getblocktemplate'] = $getblocktemplate;
$output['mn_stats'] = array(
    "count" => $count,
    "qualify" => $enabled_count,
);

print_r(json_encode($output));



////

function get_difficulty($bits) {
    $i = hexdec($bits);

    $numerator = 0xffff;
    $denominator = $i & 0x00ffffff;
    $quotient = $numerator / $denominator;

    $shift = ($i >> 24) & 0xff;
    while ($shift < 29) { $quotient *= 256.0; $shift++; }
    while ($shift > 29) { $quotient /= 256.0; $shift--; }

    return $quotient;

}
