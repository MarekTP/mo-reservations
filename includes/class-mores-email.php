<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MORES_Email {
    public static function send_confirmation($booking_id) {
        global $wpdb;
        $b_tbl = $wpdb->prefix . 'mores_bookings';
        $s_tbl = $wpdb->prefix . 'mores_services';
        $c_tbl = $wpdb->prefix . 'mores_calendars';

        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $b_tbl WHERE id=%d", $booking_id));
        if (!$booking) return;

        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM $s_tbl WHERE id=%d", $booking->service_id));
        $calendar = $wpdb->get_row($wpdb->prepare("SELECT * FROM $c_tbl WHERE id=%d", $booking->calendar_id));

        $tz = mores_tz();
        $start = new DateTime($booking->start_utc, new DateTimeZone('UTC')); $start->setTimezone($tz);
        $end = new DateTime($booking->end_utc, new DateTimeZone('UTC')); $end->setTimezone($tz);

        $to = $booking->customer_email;
        $subject = sprintf(__('Potvrzení rezervace %s', 'mores'), $service ? $service->name : '');
        $cancel_url = add_query_arg(['mo_res_cancel' => $booking->cancel_token], home_url('/'));

        
        $cancel_url = esc_url($cancel_url);
        $phone = $booking->customer_phone ?? '';
        $address = $booking->customer_address ?? '';
        $body = '<p>Dobrý den ' . esc_html($booking->customer_name) . ',</p>' .
            '<p>vaše rezervace byla potvrzena.</p>' .
            '<p><strong>Kdy:</strong> ' . esc_html($start->format('Y-m-d H:i')) . ' – ' . esc_html($end->format('Y-m-d H:i')) . '<br>' .
            '<strong>Služba:</strong> ' . esc_html($service ? $service->name : '') . '<br>' .
            '<strong>Kalendář:</strong> ' . esc_html($calendar ? $calendar->name : '') . '</p>' .
            '<p><a href="' . $cancel_url . '" style="display:inline-block;padding:10px 14px;background:#d9534f;color:#fff;text-decoration:none;border-radius:4px">Zrušit rezervaci</a></p>' .
            '<p>Kalendářní pozvánka je přiložena (.ics).</p>';
        $headers = ['Content-Type: text/html; charset=UTF-8'];


        // ICS attachment
        $ics = MORES_ICS::generate_booking_ics($booking_id);
        $upload_dir = wp_upload_dir();
        $ics_path = trailingslashit($upload_dir['basedir']) . "mores-booking-{$booking_id}.ics";
        file_put_contents($ics_path, $ics);

        add_filter('wp_mail_content_type', function($ct){ return 'text/html; charset=UTF-8'; });
        $sent = wp_mail($to, $subject, $body, $headers, [$ics_path]);
        @unlink($ics_path);
        remove_filter('wp_mail_content_type', '__return_false');

        return $sent;
    }
}
