<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MORES_ICS {

    public function __construct() {
        add_action('init', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'handle_requests']);
    }

    public function register_query_vars() {
        add_rewrite_tag('%mores_cancel%', '([^&]+)');
        add_rewrite_tag('%mo_res_ics%', '([0-9]+)');
        add_rewrite_tag('%mo_res_key%', '([^&]+)');
    }

    public function handle_requests() {
        if (isset($_GET['mo_res_ics']) && isset($_GET['mo_res_key'])) {
            $this->serve_calendar_ics(intval($_GET['mo_res_ics']), sanitize_text_field($_GET['mo_res_key']));
            exit;
        }
    }

    public static function generate_booking_ics($booking_id) {
        global $wpdb;
        $b_tbl = $wpdb->prefix . 'mores_bookings';
        $s_tbl = $wpdb->prefix . 'mores_services';
        $c_tbl = $wpdb->prefix . 'mores_calendars';

        $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM $b_tbl WHERE id=%d", $booking_id));
        if (!$b) return '';

        $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM $s_tbl WHERE id=%d", $b->service_id));
        $c = $wpdb->get_row($wpdb->prepare("SELECT * FROM $c_tbl WHERE id=%d", $b->calendar_id));

        $uid = 'mores-' . $b->id . '@' . parse_url(home_url(), PHP_URL_HOST);
        $dtstart = gmdate('Ymd\THis\Z', strtotime($b->start_utc));
        $dtend = gmdate('Ymd\THis\Z', strtotime($b->end_utc));
        $summary = ($s ? $s->name : __('Rezervace', 'mores')) . ' – ' . ($c ? $c->name : get_bloginfo('name'));
        $desc = 'Rezervace vytvořena v ' . home_url('/');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MORES//CZ',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $dtstart,
            'DTEND:' . $dtend,
            'SUMMARY:' . self::escape($summary),
            'DESCRIPTION:' . self::escape($desc),
            'END:VEVENT',
            'END:VCALENDAR'
        ];
        return implode("\r\n", $lines) . "\r\n";
    }

    public function serve_calendar_ics($calendar_id, $key) {
        global $wpdb;
        $c_tbl = $wpdb->prefix . 'mores_calendars';
        $b_tbl = $wpdb->prefix . 'mores_bookings';

        $cal = $wpdb->get_row($wpdb->prepare("SELECT * FROM $c_tbl WHERE id=%d", $calendar_id));
        if (!$cal || $cal->ics_secret !== $key) {
            status_header(403);
            echo 'Forbidden';
            return;
        }

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="calendar-' . $calendar_id . '.ics"');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MORES//CZ',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH'
        ];

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $b_tbl WHERE calendar_id=%d AND status='confirmed' ORDER BY start_utc ASC", $calendar_id));
        foreach ($rows as $b) {
            $uid = 'mores-' . $b->id . '@' . parse_url(home_url(), PHP_URL_HOST);
            $dtstart = gmdate('Ymd\THis\Z', strtotime($b->start_utc));
            $dtend = gmdate('Ymd\THis\Z', strtotime($b->end_utc));
            $summary = 'Rezervace #' . $b->id;
            $desc = 'Zákazník: ' . $b->customer_name . ' <' . $b->customer_email . '>';
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $dtstart;
            $lines[] = 'DTEND:' . $dtend;
            $lines[] = 'SUMMARY:' . self::escape($summary);
            $lines[] = 'DESCRIPTION:' . self::escape($desc);
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        echo implode("\r\n", $lines) . "\r\n";
    }

    protected static function escape($str) {
        $str = preg_replace('/([,;])/', '\\$1', $str);
        $str = str_replace("\n", "\\n", $str);
        return $str;
    }
}
