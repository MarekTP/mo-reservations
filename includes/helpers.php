<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mores_tz() {
    $tz = get_option('timezone_string');
    if (!$tz) $tz = 'UTC';
    return new DateTimeZone($tz);
}

function mores_random_token($len = 32) {
    return bin2hex(random_bytes($len));
}

function mores_parse_hhmm($hhmm) {
    // returns [h,m]
    $parts = explode(':', $hhmm);
    return [intval($parts[0]), intval($parts[1])];
}

function mores_bool($v) {
    return $v ? 1 : 0;
}

