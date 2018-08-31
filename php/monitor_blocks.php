<?php

/**
 * Get block times (in seconds, or other units if specified)
 *
 * Default to current top block, else go to block specified, or range of blocks
 */

global $MOGWAI_CLI, $TESTNET, $block_headers, $ASSUMED_HASH;

$TESTNET = '';

if (@$argv[1] && ($argv[1] == "-t" || $argv[1] == "--t" || $argv[1] == "-testnet" || $argv[1] == "--testnet")) {
    $TESTNET = '-testnet';
}
elseif (@$argv[1] && ($argv[1] == "-r" || $argv[1] == "--r" || $argv[1] == "-regtest" || $argv[1] == "--regtest")) {
    $TESTNET = '-regtest';
}

$block_headers = array();
$ASSUMED_HASH = 40;  // in megahash (used to make projections about time-to-find-block)


require_once(__DIR__ . '/mogwai.lib.php');
date_default_timezone_set('UTC');

main();


// fill in block time(s), indexed by block number

function main() {
    global $block_headers;
    global $argv;
    global $ASSUMED_HASH;
    global $TESTNET;
    global $MOGWAI_CLI;

    // test MOGWAI_CLI 
    if (!file_exists($MOGWAI_CLI)) {
        echo "$MOGWAI_CLI does not exist. aborting." . PHP_EOL;
    }

    $last_block = 0;
    $last_str = '';
    $first_sync_completed = false;
    $offset = '';

    echo "block,duration,difficulty,time,hist" . PHP_EOL;
    while(1) {
        get_blocks($last_block);
        $max_block = 0;
        if ($block_headers) $max_block = max(array_keys($block_headers));
        if ($last_str) {
            echo str_repeat(chr(8), strlen($last_str));  // erase any partial written line
        }

        // get timing for all blocks
        $prevtime = false;
        $prevdiff = false;
        foreach ($block_headers as $key => $val) {
            if ($key < $last_block - 1) {
                unset($block_headers[$key]);
                continue;
            }
            if ($key < $last_block) {
                continue;
            }
            $last_block = $key;

            $diff = round($val['difficulty'], 6);
            $duration = intval($val['delta']);
            $duration_str = $duration;
            if ($duration > 0) {
                $h = date("H", $duration);
                $m = date("i", $duration);
                $s = date("s", $duration);

                $h = preg_replace('/^00/', '__', $h);
                $h = preg_replace('/^0/',  '_',  $h);
                if ($h == '__') {
                    $m = preg_replace('/^00/', '__', $m);
                    $m = preg_replace('/^0/',  '_',  $m);
                }
                if ($m == '__') {
                    $s = preg_replace('/^00/', '__', $s);
                    $s = preg_replace('/^0/',  '_',  $s);
                }

                $duration_str = "$h:$m:$s";
            }
            else {
                $duration_str = str_pad($duration, 8, " ", STR_PAD_LEFT);
            }

            // make a graphical histogram of * chars
            $hist = str_repeat('*', max(1, log($diff) / log(2)));
            if ($duration < 15) {
                $hist = str_replace('*', '0', $hist);
            }
            $offset = "-";
            if ($first_sync_completed) {
                $offset = $val['time'] - time();
                if ($offset > 0) {
                    $offset = "+" . $offset;
                }
            }

            $time = gmdate('H:i:s', $val['time']);
            $time .= str_pad(" ($offset)", 7, " ", STR_PAD_LEFT);

            // we can generate an estimate time to solve
            // $est = $diff / $ASSUMED_HASH * pow(2, 12);
            $est = $diff / $ASSUMED_HASH * pow(2, 32) / pow(10, 6);
            $days = floor($est / (3600*24));
            $time_est = gmdate('H:i:s', $est % (3600*24));
            if ($days) {
                $time_est = "$days d, " . $time_est;
            }
            if ($est <= 0) {
                $est = 1;
            }

            $last_str = str_pad("$key, $duration_str, $diff,", 30, " ", STR_PAD_RIGHT) . str_pad("$time [$time_est ; " . round($duration/$est, 2) . "x]", 40, " ") . str_pad(" $hist", 8);
            echo $last_str;

            if ($key != $max_block) {
                // gets array of amounts, but for mining block, should be split among multiple
                // addresses. just get the 1st one
                $amounts = get_amount_by_block_num($key);
                if (!empty($amounts)) {
                    $amount = number_format($amounts[0], 2);
                    echo "  [$amount]";
                }

                $payees = get_payees_by_block_num($key);
                if (!empty($payees)) {
                    foreach ($payees as &$payee) {
                        // you can put any address-to-name mappings you want in get_address_name() in mogwai.lib.php
                        $payee = get_address_name($payee);
                    }
                    if (@$payees[0] == 'mystery swapper') {
                        // unlike everyone else, this miner puts his address first, masternode address second.  pop off this swapper guy and put him on the end
                        array_push($payees, array_shift($payees));
                    }
                    if (!empty($payees)) {
                        echo "  (" . implode(', ', $payees) . ")";
                    }
                }
                $last_str = '';
                echo PHP_EOL;
            }

        }


        $first_sync_completed = true;
        // if desired, change this sleep timeout to reduce load on your system
        sleep(1);
    }
}

