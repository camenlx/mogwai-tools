<?php

/**
 * Get block times (in seconds, or other units if specified)
 *
 * Default to current top block, else go to block specified, or range of blocks
 */

$MOGWAI_CLI = '~/mogwaicore-0.12.2/bin/mogwai-cli';
$TESTNET = '';

if (@$argv[1] && ($argv[1] == "-t" || $argv[1] == "--t" || $argv[1] == "-testnet" || $argv[1] == "--testnet")) {
    $TESTNET = '--testnet';
}

main();


// fill in block time(s), indexed by block number

function main() {
    $last_line = '';

    // keep a list of all peers seen, not just current peers
    // keep indexed by ip addr, but store last_time_seen and version
    $versions_persistent = array();


    while(1) {
        $peers = cmd('getpeerinfo');

        // keep a list of versions found and the number found for each version
        $versions = array();
        $just_upgraded = 0;  // increment this as we detect an IP address changed from 70208 to 70209

        foreach ($peers as $peer) {
            $v = $peer['version'];
            $versions[$v] = @$versions[$v] + 1;

            // ignore version string "0" - I guess that peer hasn't fully communicated it's version yet ?
            if (!empty($v)) {
                $ip = $peer["addr"];

                // see if this node is already known or not
                if (array_key_exists($ip, $versions_persistent)) {
                    if ($v == "70209" && $versions_persistent[$ip]["version"] == "70208") {
                        $just_upgraded++;
                    }
                }

                $versions_persistent[$ip] = array(
                    "ip" => $ip,
                    "last_seen" => time(),
                    "version" => $v,
                );

            }
        }

        ksort($versions);

        // get percentages for each version in this last call to getpeerinfo
        $total = count($peers);
        $now = date("Y-m-d H:i:s");
        $line = "now: [";
        foreach ($versions as $ver => $ct) {
            $v = substr($ver, -1);
            $line .= "$v: $ct (" . round($ct / $total * 100, 2) . "%), ";
        }


        // get stats from persistent memory as well
        $counts = array(
            "all" => array(),   // all data
            "hour" => array(),  // just in past hour
        );

        foreach ($versions_persistent as $peer) {
            $version = $peer['version'];
            $counts["all"][$version] = @$counts["all"][$version] + 1;

            if ($peer['last_seen'] > time() - 3600) {
                $counts["hour"][$version] = @$counts["hour"][$version] + 1;
            }
        }

        $line .= "] hour: [";
        $total = $counts["hour"]["70208"] + $counts["hour"]["70209"];
        foreach ($counts["hour"] as $ver => $ct) {
            $v = substr($ver, -1);
            $line .= "$v: $ct (" . round($ct / $total * 100, 2) . "%), ";
        }

        $line .= "] all: [";
        $total = $counts["all"]["70208"] + $counts["all"]["70209"];
        foreach ($counts["all"] as $ver => $ct) {
            $v = substr($ver, -1);
            $line .= "$v: $ct (" . round($ct / $total * 100, 2) . "%), ";
        }

        $line .= "] just upgraded: $just_upgraded ";



        if ($line != $last_line) {
            echo "[$now] " . $line . PHP_EOL;
            $last_line = $line;
        }


        sleep(10);
    }
}



function cmd($cmd) {
    global $MOGWAI_CLI;
    global $TESTNET;

    $data = trim(`$MOGWAI_CLI $TESTNET $cmd`);

    if ($json = json_decode($data, true)) {
        $data = $json;
    }

    return $data;
}

