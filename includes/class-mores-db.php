<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MORES_DB {
    const MORES_DB_VER = '2026-01-09';

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $cal = $wpdb->prefix . 'mores_calendars';
        $srv = $wpdb->prefix . 'mores_services';
        $bkg = $wpdb->prefix . 'mores_bookings';
        $blk = $wpdb->prefix . 'mores_blackouts';
        $log = $wpdb->prefix . 'mores_logs';

        $sql_cal = "CREATE TABLE $cal (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Prague',
            open_time VARCHAR(5) NOT NULL DEFAULT '08:00',
            close_time VARCHAR(5) NOT NULL DEFAULT '18:00',
            granularity INT NOT NULL DEFAULT 30,
            days_open VARCHAR(32) NOT NULL DEFAULT '1,2,3,4,5',
            ics_secret VARCHAR(64) NOT NULL DEFAULT '',
            buffer_after_minutes INT NOT NULL DEFAULT 0,
            break_start VARCHAR(5) NOT NULL DEFAULT '00:00',
            break_end   VARCHAR(5) NOT NULL DEFAULT '00:00',
            chaining VARCHAR(8) NOT NULL DEFAULT 'off',
            PRIMARY KEY (id)
        ) $charset;";

        $sql_srv = "CREATE TABLE $srv (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            calendar_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            duration_minutes INT NOT NULL DEFAULT 60,
            price_now DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            price_cash DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY cal (calendar_id)
        ) $charset;";

        $sql_bkg = "CREATE TABLE $bkg (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            calendar_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            customer_name VARCHAR(190) NULL,
            customer_email VARCHAR(190) NULL,
            customer_phone VARCHAR(64) NULL,
            customer_address TEXT NULL,
            start_utc DATETIME NOT NULL,
            end_utc   DATETIME NOT NULL,
            token VARCHAR(64) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'hold',
            order_id BIGINT NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY cal (calendar_id),
            KEY srv (service_id),
            KEY st (status),
            KEY s (start_utc),
            KEY e (end_utc)
        ) $charset;";

        $sql_blk = "CREATE TABLE $blk (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            calendar_id BIGINT UNSIGNED NOT NULL,
            date_from DATE NOT NULL,
            date_to   DATE NOT NULL,
            reason VARCHAR(190) NULL,
            PRIMARY KEY (id),
            KEY cal (calendar_id),
            KEY df (date_from),
            KEY dt (date_to)
        ) $charset;";

        $sql_log = "CREATE TABLE $log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(16) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lvl (level),
            KEY ts (created_at)
        ) $charset;";

        dbDelta($sql_cal);
        dbDelta($sql_srv);
        dbDelta($sql_bkg);
        dbDelta($sql_blk);
        // Drop any index on 'context' to avoid key-length issues
        try {
            $idx = $wpdb->get_results("SHOW INDEX FROM $log WHERE Column_name='context'");
            if (!empty($idx)) {
                foreach ($idx as $ix) {
                    $key = isset($ix->Key_name) ? $ix->Key_name : (is_array($ix)? $ix['Key_name'] : '');
                    if ($key) { $wpdb->query("ALTER TABLE $log DROP INDEX `$key`"); }
                }
            }
        } catch (Throwable $e) {}
        dbDelta($sql_log);
    }

    public static function maybe_update() {
        self::activate();
        $opt_key = 'mores_db_ver';
        $cur = get_option($opt_key, '');
        if ($cur !== self::MORES_DB_VER) {
            self::migrate();
            update_option($opt_key, self::MORES_DB_VER);
        }
    }

    public static function migrate() {
        // Drop leftover index on 'context'
        global $wpdb; $log = $wpdb->prefix.'mores_logs';
        try { $idx = $wpdb->get_results("SHOW INDEX FROM $log WHERE Column_name='context'"); if (!empty($idx)) { foreach ($idx as $ix) { $key = isset($ix->Key_name)?$ix->Key_name:(is_array($ix)?$ix['Key_name']:''); if ($key) $wpdb->query("ALTER TABLE $log DROP INDEX `$key`"); } } } catch (Throwable $e) {}
        global $wpdb;
        $cal = $wpdb->prefix . 'mores_calendars';
        $srv = $wpdb->prefix . 'mores_services';
        $log = $wpdb->prefix . 'mores_logs';

        if ( ! self::column_exists($srv, 'price_now') ) {
            $wpdb->query("ALTER TABLE $srv ADD COLUMN price_now DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }
        if ( ! self::column_exists($srv, 'price_cash') ) {
            $wpdb->query("ALTER TABLE $srv ADD COLUMN price_cash DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }
        if ( ! self::column_exists($cal, 'chaining') ) {
            $wpdb->query("ALTER TABLE $cal ADD COLUMN chaining VARCHAR(8) NOT NULL DEFAULT 'off'");
        }
        if ( ! self::column_exists($log, 'created_at') ) {
            $wpdb->query("ALTER TABLE $log ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }

    protected static function column_exists($table, $col) {
        global $wpdb;
        $r = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $col));
        return !empty($r);
    }

    public static function uninstall() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mores_logs");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mores_blackouts");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mores_bookings");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mores_services");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mores_calendars");
    }
}
