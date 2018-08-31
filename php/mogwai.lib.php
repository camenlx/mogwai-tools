<?php

global $MOGWAI_CLI, $TESTNET, $block_headers, $ASSUMED_HASH;

// location of command line binary, for making RPC calls to
$MOGWAI_CLI = '~/mogwai-cli';


function get_block_time($blocknum) {
    // check if we already have block time for $blocknum
    global $block_headers;

    if ($blocknum <= 0) {
        return 0;
    }

    if (array_key_exists($blocknum, $block_headers)) {
        return $block_headers[$blocknum]['time'];
    }

    // else make call to mog client to get block time for this block
    $hash = cmd("getblockhash $blocknum");
    $header = cmd("getblockheader $hash");
    echo $header . PHP_EOL;
    $data = json_decode($header, true);

    if ($data) {
        $block_headers[$blocknum] = $data;
        return $block_headers[$blocknum]['time'];
    }

    echo "$blocknum Did not get valid data" . PHP_EOL;
    return false;

}

// get last 60 or fewer blocks
function get_blocks() {
    global $block_headers;

    $nexthash = false;

    $max = intval(cmd("getblockcount"));
    if (empty($max)) return;

    $max_block = @max(array_keys($block_headers));

    for ($i = $max - 60; $i <= $max; $i++) {
        if ($i < 0) {
            continue;
        }

        if ($i < $max_block) {
            continue;
        }

        if (empty($nexthash)) {
            $nexthash = cmd("getblockhash $i");
        }

        $header = cmd("getblockheader $nexthash");
        $nexthash = false;

        if ($header) {
            $block_headers[$i] = $header;
            $nexthash = @$header['nextblockhash'];

            if (@$block_headers[$i-1]['time']) {
                $block_headers[$i]['delta'] = $block_headers[$i]['time'] - $block_headers[$i-1]['time'];
            }
            else {
                $block_headers[$i]['delta'] = 0;
            }
        }

    }

    // get next diff (block does not exist yet)
    $next = get_next_diff();
    $i = $next[0];
    $diff = $next[1];

    $lapsed = (@$block_headers[$i-1]['time']) ? (time() - $block_headers[$i-1]['time']) : '*';
    $block_headers[$i] = array(
        'height' => $i,
        'time' => time(), 
        'delta' => $lapsed,
        'difficulty' => $diff,
    );
}

function get_next_diff() {
    $data = cmd('getblocktemplate');

    if ($data) {
        return array($data['height'], get_diff($data['bits']));
    }
}

function get_diff($bits) {
    $i = hexdec($bits);

    $numerator = 0xffff;
    $denominator = $i & 0x00ffffff;
    $quotient = $numerator / $denominator;

    $shift = ($i >> 24) & 0xff;
    while ($shift < 29) { $quotient *= 256.0; $shift++; }
    while ($shift > 29) { $quotient /= 256.0; $shift--; }

    return $quotient;

}

function get_payees_by_block_num($block_num) {
    if (!is_numeric($block_num)) {
        return false;
    }
    $block_num = intval($block_num);

    $block_hash = cmd("getblockhash $block_num");
    if (empty($block_hash)) {
        return false;
    }

    $block_header = cmd("getblock $block_hash");
    if (empty($block_header)) {
        return false;
    }

    $tx_hash = @$block_header['tx'][0];
    if (empty($tx_hash)) {
        return false;
    }

    return get_payees_by_tx_hash($tx_hash);

}

function get_payees_by_tx_hash($tx_hash) {

    $tx_raw = cmd("getrawtransaction $tx_hash");
    if (empty($tx_raw)) {
        return false;
    }

    $tx = cmd("decoderawtransaction $tx_raw");
    if (empty($tx)) {
        return false;
    }

    if (empty($tx['vout'])) {
        return false;
    }

    $payees = array();
    foreach ($tx['vout'] as $vout) {
        $addr = $vout['scriptPubKey']['addresses'][0];
        $payees[] = $addr;
    }

    return $payees;

}

function get_amount_by_block_num($block_num) {
    if (!is_numeric($block_num)) {
        return false;
    }
    $block_num = intval($block_num);

    $block_hash = cmd("getblockhash $block_num");
    if (empty($block_hash)) {
        return false;
    }

    $block_header = cmd("getblock $block_hash");
    if (empty($block_header)) {
        return false;
    }

    $tx_hash = @$block_header['tx'][0];
    if (empty($tx_hash)) {
        return false;
    }

    return get_amount_by_tx_hash($tx_hash);

}

function get_amount_by_tx_hash($tx_hash) {

    $tx_raw = cmd("getrawtransaction $tx_hash");
    if (empty($tx_raw)) {
        return false;
    }

    $tx = cmd("decoderawtransaction $tx_raw");
    if (empty($tx)) {
        return false;
    }

    if (empty($tx['vout'])) {
        return false;
    }

    $amounts = array();
    foreach ($tx['vout'] as $vout) {
        $amounts[] = $vout['value'];
    }

    return $amounts;

}

function cmd($cmd) {
    global $MOGWAI_CLI;
    global $TESTNET;

    // sanitize
    $cmd = escapeshellcmd($cmd);
    $cmd = str_replace('!', '', $cmd);

    $data = trim(`$MOGWAI_CLI $TESTNET $cmd  2>/dev/null`);

    if ($json = json_decode($data, true)) {
        $data = $json;
    }

    return $data;
}

// put Address to name mappings here
function get_address_name($address) {
    $known_addresses = array(
        // masternodes

        // pools
        'MV9qzvTfu1HRQ8hCee8iuikZr7BK2MQk9U' => 'altcoinpool.club',
        'MPpGEey9uWDTAKm8mdSr1CunQEBDY9rogv' => 'pool.mogwaicoin.net',
        'MCSKkYu8Fz7nRxzEQ5BSBSRmDrm8pfdo88' => 'bsod.pw',
        'MJbjyPaS1ze34ehaeEDqeofwz2BLw3JMsi' => 'evil.ru',
        'MWNVSDHpmqfJZe9Pw9Zto71zrxiFpN2nnG' => 'nlpool.nl',
        'MGbnVz5yiPDgCQVHMAB6MeMWCZaN1HhFFJ' => 'cryptohashtank.net',
        'MMYkYBR2XM2oTBQFQEkg1WqzYWeqKDdcKJ' => 'arcpool.com',
        'MT4Tn8zaZ6AE9wpdV3N2YKbkfpgg6YM12u' => 'mktechpools.xyz',
        'MECXcgoxDkoJPVKPjTpWBsFHKNuQ7ap5qe' => 'who da fuk is alice??',
        'MNPT39JDrTMqkGcEisULxvxwVyE4misNkS' => 'mystery swapper',
        // '' => 'mktechpools.xyz',

        // testnet nodes
        'tEcjjwjzixVKSJw96sRJMsxwmH2QN5BBt5' => 'seed 1',
        'tPfTXAX9DV6RNEVGfCpqpfLotB7CDtqgJZ' => 'seed 2',
        'tEHmoww2H4VPzDwQrpBUPfTmT6mgFkitwb' => 'testnet pool',
    );

    // return the name, if known, else return the address back out
    return @$known_addresses[$address] ?: $address;
}

// get number of hex digits excluding leading numbers less than f
// this is primarily used for calculating the likelihood to get a "FEED" event (or other event)
// which is triggered by the event of finding "FEED" in the output hash of a block
function get_hex_bits($hex, $test_char = "f") {
    $hex = strtolower($hex);
    $len = strlen($hex);
    $i = 0;
    $prev_n = 0;
    $test = hexdec($test_char);

    foreach (str_split($hex) as $digit) {
        $n = hexdec($digit);
        if ($n >= $test || $prev_n > 0)  {
            break;
        }
        $i++;
        $prev_n = $n;
    }

    return $len - $i;
}
