<?php
if (!defined('ABSPATH')) { exit; }

class MORES_Logger {
    /*
    public static function add($level, $context, $message, $data = null) {
        global $wpdb;
        if (!get_option('mores_debug_enabled', 1)) { return; }
        $tbl = $wpdb->prefix . 'mores_logs';
        $row = [
            'created_at' => current_time('mysql'),
            'level' => substr((string)$level,0,16),
            'context' => substr((string)$context,0,64),
            'message' => (string)$message,
            'data' => $data ? maybe_serialize($data) : null,
        ];
        $wpdb->insert($tbl, $row);
    }
    */
    
    public static function add($level, $context, $message, $data = null) {
        global $wpdb;
        //$tbl = $wpdb->prefix . 'mores_logs';

        $ctx = is_string($context) ? $context : maybe_serialize($context);
        if ($data !== null) {
            $ctx .= ' | ' . (is_scalar($data) ? (string)$data : maybe_serialize($data));
            // případně místo spojení použij wp_json_encode($data)
        }

        $wpdb->insert($wpdb->prefix . 'mores_logs', [
            'created_at' => current_time('mysql'),
            'level'      => substr((string)$level, 0, 16),
            'context'    => $ctx,
            'message'    => is_scalar($message) ? (string)$message : maybe_serialize($message),
        ]);
    }

}
