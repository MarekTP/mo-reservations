<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MORES_Availability {

    /*
    public static function get_services($calendar_id) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mores_services';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $tbl WHERE calendar_id=%d AND enabled=1 ORDER BY duration_minutes ASC", $calendar_id));
    }
    */
    
    public static function get_services($calendar_id) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, duration_minutes, price_cash, price_now, enabled
				 FROM " . $wpdb->prefix . "mores_services
				 WHERE calendar_id = %d AND enabled = 1
				 ORDER BY duration_minutes ASC",
				$calendar_id
			)
		);
	}

    public static function get_calendar($calendar_id) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mores_calendars';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $calendar_id));
    }

    protected static function normalize_calendar_defaults($cal) {
        if (!$cal) return null;
        if (is_array($cal)) $cal = (object)$cal;
        $defs = [
            'timezone' => 'Europe/Prague',
            'open_time' => '08:00',
            'close_time' => '18:00',
            'granularity' => 30,
            'chaining' => 'off',
            'days_open' => '1,2,3,4,5',
            'buffer_after_minutes' => 0,
            'break_start' => '00:00',
            'break_end' => '00:00',
        ];
        foreach ($defs as $k=>$v) {
            if (!isset($cal->$k) || $cal->$k === '' || $cal->$k === null) $cal->$k = $v;
        }
        return $cal;
    }

    public static function list_bookings_for_day($calendar_id, $date_str) {
        global $wpdb;
        $tz = mores_tz();
        $tbl = $wpdb->prefix . 'mores_bookings';
        $local_start = new DateTime($date_str . ' 00:00:00', $tz);
        $local_end = clone $local_start; $local_end->modify('+1 day');
        $utc_start = clone $local_start; $utc_start->setTimezone(new DateTimeZone('UTC'));
        $utc_end = clone $local_end; $utc_end->setTimezone(new DateTimeZone('UTC'));
        $now_utc = new DateTime('now', new DateTimeZone('UTC'));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tbl
             WHERE calendar_id=%d
               AND end_utc > %s AND start_utc < %s
               AND (status='confirmed' OR (status='hold' AND (expires_at IS NULL OR expires_at > %s)))
             ORDER BY start_utc ASC",
            $calendar_id, $utc_start->format('Y-m-d H:i:s'), $utc_end->format('Y-m-d H:i:s'), $now_utc->format('Y-m-d H:i:s')
        ));
    }

    protected static function get_blocked_intervals($calendar_id, $date_str, $cal) {
        $tz = mores_tz();
        $blocked = [];

        // bookings extended by buffer
        $bookings = self::list_bookings_for_day($calendar_id, $date_str);
        $buffer = max(0, intval($cal->buffer_after_minutes));
        foreach ($bookings as $b) {
            $s = new DateTime($b->start_utc, new DateTimeZone('UTC')); $s->setTimezone($tz);
            $e = new DateTime($b->end_utc,   new DateTimeZone('UTC')); $e->setTimezone($tz);
            if ($buffer > 0) $e->modify('+' . $buffer . ' minutes');
            $blocked[] = [$s, $e];
        }

        // break window
        list($bh, $bm) = mores_parse_hhmm($cal->break_start);
        list($eh, $em) = mores_parse_hhmm($cal->break_end);
        if (($bh + $bm + $eh + $em) > 0) {
            $bs = new DateTime($date_str . ' 00:00:00', $tz); $bs->setTime(intval($bh), intval($bm), 0);
            $be = new DateTime($date_str . ' 00:00:00', $tz); $be->setTime(intval($eh), intval($em), 0);
            $blocked[] = [$bs, $be];
        }

        return $blocked;
    }

    public static function compute_available_starts($calendar_id, $service_id, $date_str) {
        global $wpdb;
        $cal = self::get_calendar($calendar_id);
        $cal = self::normalize_calendar_defaults($cal);
        if (!$cal) return [];
        $tz = !empty($cal->timezone) ? new DateTimeZone($cal->timezone) : mores_tz();
        if ( self::is_blackout_day($calendar_id, $date_str) ) return [];
        $dow = (int)(new DateTime($date_str, $tz))->format('N'); // 1=Po..7=Ne
        $days_open = array_filter(array_map('intval', explode(',', $cal->days_open)));
        if ( !in_array($dow, $days_open, true) ) return [];

        $srv_tbl = $wpdb->prefix . 'mores_services';
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM $srv_tbl WHERE id=%d AND calendar_id=%d", $service_id, $calendar_id));
        if (!$service) return [];

        list($oh, $om) = mores_parse_hhmm($cal->open_time);
        list($ch, $cm) = mores_parse_hhmm($cal->close_time);
        $open_min = $oh*60 + $om;
        $close_min = $ch*60 + $cm;
        $gran = max(5, intval($cal->granularity));
        $dur  = max(5, intval($service->duration_minutes));

        $blocked = self::get_blocked_intervals($calendar_id, $date_str, $cal);

        $starts = [];
        for ($t = $open_min; $t + $dur <= $close_min; $t += $gran) {
            $start_local = new DateTime($date_str . ' 00:00:00', $tz); $start_local->setTime(intval($t/60), $t%60, 0);
            $end_local   = (clone $start_local); $end_local->modify('+' . $dur . ' minutes');
            $ok = true;
            foreach ($blocked as $blk) {
                $bs = $blk[0]; $be = $blk[1];
                if ($start_local < $be && $end_local > $bs) { $ok = false; break; }
            }
            if (!$ok) continue;
            $starts[] = sprintf('%02d:%02d', intval($t/60), $t%60);
        }
        
        // Chaining filter (strict adjacency)
        if (isset($cal->chaining) && $cal->chaining === 'edge') {
            $bookings = self::list_bookings_for_day($calendar_id, $date_str);
            $allowed = [];

            foreach ($bookings as $b) {
                $b_start = new DateTime($b->start_utc, new DateTimeZone('UTC')); $b_start->setTimezone($tz);
                $b_end   = new DateTime($b->end_utc,   new DateTimeZone('UTC')); $b_end->setTimezone($tz);
                $after = (clone $b_end);
                $after->modify('+' . max(0, intval($cal->buffer_after_minutes)) . ' minutes');
                $allowed[] = $after->format('H:i');
                $before = (clone $b_start);
                $before->modify('-' . intval($service->duration_minutes) . ' minutes');
                $allowed[] = $before->format('H:i');
            }

            // Hrany pracovní doby
            list($oh, $om) = mores_parse_hhmm($cal->open_time);
            list($ch, $cm) = mores_parse_hhmm($cal->close_time);
            $open_dt  = new DateTime($date_str . ' 00:00:00', $tz); $open_dt->setTime($oh, $om, 0);
            $close_dt = new DateTime($date_str . ' 00:00:00', $tz); $close_dt->setTime($ch, $cm, 0);
            $allowed[] = $open_dt->format('H:i');
            $before_close = (clone $close_dt); $before_close->modify('-' . intval($service->duration_minutes) . ' minutes');
            $allowed[] = $before_close->format('H:i');

            // Hrany pauzy
            list($bh, $bm) = mores_parse_hhmm($cal->break_start);
            list($eh, $em) = mores_parse_hhmm($cal->break_end);
            if (($bh + $bm + $eh + $em) > 0) {
                $pause_s = new DateTime($date_str . ' 00:00:00', $tz); $pause_s->setTime($bh, $bm, 0);
                $pause_e = new DateTime($date_str . ' 00:00:00', $tz); $pause_e->setTime($eh, $em, 0);
                $before_break = (clone $pause_s); $before_break->modify('-' . intval($service->duration_minutes) . ' minutes');
                $allowed[] = $before_break->format('H:i');
                $allowed[] = $pause_e->format('H:i');
            }

            $allowed = array_unique($allowed);
            $starts  = array_values(array_intersect($starts, $allowed));
        }
        return $starts;
    }

    public static function compute_week_grid( $calendar_id, $service_id, $week_start_date ) {
        global $wpdb;

        $cal_tbl = $wpdb->prefix . 'mores_calendars';
        $srv_tbl = $wpdb->prefix . 'mores_services';
        $bkg_tbl = $wpdb->prefix . 'mores_bookings';

        // kalendář + služba
        $cal = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$cal_tbl} WHERE id=%d", $calendar_id) );
        if ( ! $cal ) {
            return [ 'openHour' => 8, 'closeHour' => 18, 'step' => 60, 'days' => [] ];
        }
        $srv = $wpdb->get_row( $wpdb->prepare("SELECT id, duration_minutes FROM {$srv_tbl} WHERE id=%d", $service_id) );
        $duration = $srv ? (int) $srv->duration_minutes : 60;

        // časové pásmo kalendáře
        $tz = ! empty( $cal->timezone ) ? new DateTimeZone( $cal->timezone ) : wp_timezone();

        // pracovní doba
        $open_time  = $cal->open_time  ?: '08:00';
        $close_time = $cal->close_time ?: '18:00';
        list($oh,$om) = array_map('intval', explode(':', $open_time) + [0,0]);
        list($ch,$cm) = array_map('intval', explode(':', $close_time) + [0,0]);

        // UI sloupce po hodinách
        $gran = isset($cal->granularity) ? max(15, (int)$cal->granularity) : 60;
        $step_ui = $gran;

        // další parametry
        $buffer      = isset($cal->buffer_after_minutes) ? (int)$cal->buffer_after_minutes : 0;
        $days_open   = array_filter(array_map('intval', explode(',', $cal->days_open ?: '1,2,3,4,5')));
        $break_start = $cal->break_start ?: '';
        $break_end   = $cal->break_end   ?: '';

        // začátek týdne dle WP (0=Ne,1=Po,…)
        $start_of_week = (int) get_option('start_of_week', 1);

        try {
            $start_local = new DateTime($week_start_date.' 00:00:00', $tz);
        } catch (Exception $e) {
            $start_local = new DateTime('now', $tz);
        }
        $w = (int)$start_local->format('w'); // 0..6 (Ne..So)
        $diff = ($w - $start_of_week + 7) % 7;
        if ($diff) $start_local->modify('-'.$diff.' day'); // teď je to 1. den týdne lokálně

        // rozsah týdne v UTC (protože ukládáš start_utc)
        $start_utc = (clone $start_local); $start_utc->setTimezone(new DateTimeZone('UTC'));
        $end_local = (clone $start_local)->modify('+7 day');
        $end_utc   = (clone $end_local);   $end_utc->setTimezone(new DateTimeZone('UTC'));

        // Proaktivně smaž expirované holds (nejen hodinový cron)
        $now_utc_str = gmdate('Y-m-d H:i:s');
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$bkg_tbl}
             WHERE status = 'hold' AND expires_at IS NOT NULL AND expires_at <= %s",
            $now_utc_str
        ) );

        // stáhni rezervace v rozsahu (UTC) – včetně aktivních holds
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.start_utc,
                    COALESCE(s.duration_minutes, %d) AS duration_minutes
               FROM {$bkg_tbl} b
          LEFT JOIN {$srv_tbl} s ON s.id = b.service_id
              WHERE b.calendar_id = %d
                AND b.start_utc >= %s
                AND b.start_utc <  %s
                AND (
                    b.status IN ('new','pending','paid','confirmed')
                    OR (b.status = 'hold' AND (b.expires_at IS NULL OR b.expires_at > %s))
                )",
            $duration,
            $calendar_id,
            $start_utc->format('Y-m-d H:i:s'),
            $end_utc->format('Y-m-d H:i:s'),
            $now_utc_str
        ) );

        // helpery
        $mkDT = function($date_str, $hh, $mm=0) use ($tz) {
            return DateTime::createFromFormat('Y-m-d H:i', sprintf('%s %02d:%02d', $date_str, (int)$hh, (int)$mm), $tz);
        };
        $overlap = function(DateTime $a1, DateTime $a2, DateTime $b1, DateTime $b2){
            return ($a1 < $b2) && ($b1 < $a2);
        };

        $days = [];
        for ($i=0; $i<7; $i++) {
            $day_local = (clone $start_local)->modify("+{$i} day");
            $dateStr   = $day_local->format('Y-m-d');
            $weekdayN  = (int)$day_local->format('N'); // 1..7 (Po..Ne)

            $open  = $mkDT($dateStr, $oh, $om);
            $close = $mkDT($dateStr, $ch, $cm);

            // pauza (volitelné)
            $breakA = $breakB = null;
            if ($break_start && $break_end) {
                $breakA = DateTime::createFromFormat('Y-m-d H:i', $dateStr.' '.$break_start, $tz);
                $breakB = DateTime::createFromFormat('Y-m-d H:i', $dateStr.' '.$break_end,   $tz);
                if ($breakA && $breakB && $breakB <= $breakA) { $breakA = $breakB = null; }
            }

            // převod rezervací toho dne do lokálu
            $dayBookings = [];
            foreach ($bookings as $b){
                $bs_utc = DateTime::createFromFormat('Y-m-d H:i:s', $b->start_utc, new DateTimeZone('UTC'));
                if (!$bs_utc) continue;
                $bs = clone $bs_utc; $bs->setTimezone($tz);
                if ($bs->format('Y-m-d') !== $dateStr) continue;

                $mins = (int)$b->duration_minutes;
                if ($mins <= 0) { $mins = $duration; } // jistota: fallback na délku aktuálně vybrané služby
                $be = (clone $bs)->modify('+'.($mins + $buffer).' minute');

                $dayBookings[] = [$bs, $be];
            }
			// vygeneruj hodinové starty
            $busy    = [];
            $partial = [];
            $isOpenDay = in_array($weekdayN, $days_open, true);

            $t = $mkDT($dateStr, $oh, 0);
            $tEnd = $mkDT($dateStr, $ch, 0);

            while ($t < $tEnd) {
                $cellKey   = $t->format('H:i');
                $candStart = clone $t;
                $candEnd   = (clone $candStart)->modify('+'.($duration).' minute');
                // konec včetně bufferu pro účely blokování
                $candEndBuf = (clone $candStart)->modify('+'.($duration + $buffer).' minute');

                $block   = false;
                $blocked_partial = false;

                if (!$isOpenDay) { $block = true; }
                if (!$block && ($candStart < $open || $candEndBuf > $close)) { $block = true; }
                if (!$block && $breakA && $breakB && $overlap($candStart, $candEndBuf, $breakA, $breakB)) { $block = true; }
                if (!$block && $dayBookings) {
                    foreach ($dayBookings as list($bs,$be)) {
                        if ($overlap($candStart, $candEndBuf, $bs, $be)) {
                            // plné zablokování pokud zasahuje do samotné rezervace (bez bufferu)
                            $candEndNoBuf = (clone $candStart)->modify('+'.($duration).' minute');
                            if ($overlap($candStart, $candEndNoBuf, $bs, $be)) {
                                $block = true;
                            } else {
                                $blocked_partial = true; // zasahuje jen do bufferu
                            }
                            break;
                        }
                    }
                }

                if ($block) {
                    $busy[] = $cellKey;
                } elseif ($blocked_partial) {
                    $partial[] = $cellKey;
                }

                $t->modify('+'.$step_ui.' minute');
            }
			$isHoliday = self::is_blackout_day($calendar_id, $dateStr);
            $days[] = [
                'date'    => $dateStr,
                'busy'    => $isHoliday ? [] : $busy,
                'partial' => $isHoliday ? [] : $partial,
                'holiday' => $isHoliday,
            ];
        }

        return [
            'openHour' => (int)$oh,
            'closeHour'=> (int)$ch,
            'step'     => $step_ui,
            'days'     => $days,
        ];
    }


    protected static function cz_easter($year) {
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
        $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b+8, 25);
        $g = intdiv($b - $f + 1, 3); $h = (19*$a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2*$e + 2*$i - $h - $k) % 7;
        $m = intdiv($a + 11*$h + 22*$l, 451);
        $month = intdiv(($h + $l - 7*$m + 114), 31);
        $day = (($h + $l - 7*$m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function is_blackout_day($calendar_id, $date_str) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mores_blackouts';
        $cnt = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl WHERE calendar_id=%d AND date_from<=%s AND date_to>=%s", $calendar_id, $date_str, $date_str)));
        if ($cnt>0) return true;

        if (get_option('mores_holidays_enabled', 1)) {
            $fixed = get_option('mores_holidays_fixed', '01-01,05-01,05-08,07-05,07-06,09-28,10-28,11-17,12-24,12-25,12-26');
            $mmdd = substr($date_str,5,5);
            $fixed_list = array_map('trim', explode(',', $fixed));
            if (in_array($mmdd, $fixed_list, true)) return true;
            $y = intval(substr($date_str,0,4));
            $easter = self::cz_easter($y);
            $e = new DateTime($easter, new DateTimeZone('UTC'));
            $gf = clone $e; $gf->modify('-2 day');
            $em = clone $e; $em->modify('+1 day');
            if ($date_str === $gf->format('Y-m-d') || $date_str === $em->format('Y-m-d')) return true;
        }
        return false;
    }

    public static function book($calendar_id, $service_id, $start_local_str, $name, $email, $extra = []) {
        global $wpdb;
        $cal = self::get_calendar($calendar_id);
        $cal = self::normalize_calendar_defaults($cal);
        $tz = ($cal && !empty($cal->timezone)) ? new DateTimeZone($cal->timezone) : mores_tz();
        $srv_tbl = $wpdb->prefix . 'mores_services';
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM $srv_tbl WHERE id=%d AND calendar_id=%d", $service_id, $calendar_id));
        if (!$cal || !$service) return ['ok'=>false, 'message'=>'Konfigurace nebyla nalezena.'];

        $start_local = new DateTime($start_local_str, $tz);
        $end_local = (clone $start_local); $end_local->modify('+' . intval($service->duration_minutes) . ' minutes');
        $now_local = new DateTime('now', $tz);
        if ($start_local < $now_local) { return ['ok'=>false, 'message'=>'Minulý termín nelze rezervovat.']; }
        $start_utc = (clone $start_local); $start_utc->setTimezone(new DateTimeZone('UTC'));
        $end_utc = (clone $end_local); $end_utc->setTimezone(new DateTimeZone('UTC'));

        $bookings = self::list_bookings_for_day($calendar_id, $start_local->format('Y-m-d'));
        if ( self::is_blackout_day($calendar_id, $start_local->format('Y-m-d')) ) {
            return ['ok' => false, 'message' => 'Vybraný den je svátek nebo výluka.'];
        }
        foreach ($bookings as $b) {
            $b_start = new DateTime($b->start_utc, new DateTimeZone('UTC'));
            $b_end   = new DateTime($b->end_utc,   new DateTimeZone('UTC'));
            if ($start_utc < $b_end && $end_utc > $b_start) {
                return ['ok'=>false, 'message'=>'Termín už někdo zabral.'];
            }
        }

        $token = wp_generate_password(20, false);
        $tbl = $wpdb->prefix . 'mores_bookings';
        $wpdb->insert($tbl, [
            'calendar_id' => $calendar_id,
            'service_id' => $service_id,
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $extra['phone'] ?? null,
            'customer_address' => $extra['address'] ?? null,
            'start_utc' => $start_utc->format('Y-m-d H:i:s'),
            'end_utc' => $end_utc->format('Y-m-d H:i:s'),
            'token' => $token,
            'status' => 'confirmed',
            'created_at' => current_time('mysql'),
        ]);

        if (class_exists('MORES_Email')) {
            MORES_Email::send_confirmation($wpdb->insert_id);
        }
        return ['ok'=>true, 'booking_id'=>$wpdb->insert_id];
    }

    public static function create_hold($calendar_id, $service_id, $start_local_str, $name, $email, $extra = [], $ttl_minutes = 20) {
        global $wpdb;
        $srv_tbl = $wpdb->prefix . 'mores_services';
        $cal_for_tz = self::normalize_calendar_defaults(self::get_calendar($calendar_id));
        $tz = ($cal_for_tz && !empty($cal_for_tz->timezone)) ? new DateTimeZone($cal_for_tz->timezone) : mores_tz();
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM $srv_tbl WHERE id=%d AND calendar_id=%d", $service_id, $calendar_id));
        if (!$service) return ['ok'=>false, 'message'=>'Služba nenalezena.'];

        $start_local = new DateTime($start_local_str, $tz);
        $end_local = (clone $start_local); $end_local->modify('+' . intval($service->duration_minutes) . ' minutes');
        $start_utc = (clone $start_local); $start_utc->setTimezone(new DateTimeZone('UTC'));
        $end_utc = (clone $end_local); $end_utc->setTimezone(new DateTimeZone('UTC'));
        $now_local = new DateTime('now', $tz);
        if ($start_local < $now_local) { return ['ok'=>false, 'message'=>'Minulý termín nelze rezervovat.']; }

        $day = $start_local->format('Y-m-d');
        if ( self::is_blackout_day($calendar_id, $day) ) {
			return ['ok' => false, 'message' => 'Vybraný den je svátek nebo výluka.'];
		}
        $existing = self::list_bookings_for_day($calendar_id, $day);
        foreach ($existing as $b) {
            $b_s = new DateTime($b->start_utc, new DateTimeZone('UTC'));
            $b_e = new DateTime($b->end_utc, new DateTimeZone('UTC'));
            if ($start_utc < $b_e && $end_utc > $b_s) { return ['ok'=>false, 'message'=>'Termín je právě obsazen.']; }
        }

        $token = wp_generate_password(20, false);
        $expires = new DateTime('now', new DateTimeZone('UTC'));
        $expires->modify('+' . intval($ttl_minutes) . ' minutes');

        $tbl = $wpdb->prefix . 'mores_bookings';
        $wpdb->insert($tbl, [
            'calendar_id' => $calendar_id,
            'service_id' => $service_id,
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $extra['phone'] ?? null,
            'customer_address' => $extra['address'] ?? null,
            'start_utc' => $start_utc->format('Y-m-d H:i:s'),
            'end_utc' => $end_utc->format('Y-m-d H:i:s'),
            'status' => 'hold',
            'token' => $token,
            'order_id' => null,
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'created_at' => current_time('mysql'),
        ]);

        return ['ok'=>true, 'booking_id'=>$wpdb->insert_id, 'token'=>$token];
    }

    public static function confirm_booking($booking_id, $order_id) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mores_bookings';
        $wpdb->update($tbl, ['status'=>'confirmed', 'order_id'=>$order_id, 'expires_at'=>null], ['id'=>$booking_id]);
    }

    public static function cancel_booking($booking_id) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mores_bookings';
        // Hold záznamy smaž (uvolní termín), confirmed jen přepiš na cancelled
        $row = $wpdb->get_row($wpdb->prepare("SELECT status FROM $tbl WHERE id=%d", (int)$booking_id));
        if (!$row) return;
        if ($row->status === 'hold') {
            $wpdb->delete($tbl, ['id' => (int)$booking_id], ['%d']);
        } else {
            $wpdb->update($tbl, ['status' => 'cancelled'], ['id' => (int)$booking_id]);
        }
    }
    
    public static function cleanup_expired_holds() {
		global $wpdb;
		$tbl = $wpdb->prefix.'mores_bookings';
		$now = gmdate('Y-m-d H:i:s');
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM $tbl
			 WHERE status='hold' AND expires_at IS NOT NULL AND expires_at <= %s",
			$now
		));
	}

}
