<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MORES_Plugin {

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('init', [$this, 'register_block']);
        add_shortcode('mo_reservation', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        //add_action('wp_ajax_mores_get_slots', [$this, 'ajax_get_slots']);
        //add_action('wp_ajax_nopriv_mores_get_slots', [$this, 'ajax_get_slots']);
        add_action('wp_ajax_mores_make_booking', [$this, 'ajax_make_booking']);
        add_action('wp_ajax_nopriv_mores_make_booking', [$this, 'ajax_make_booking']);
        add_action('wp_ajax_mores_get_week', [$this, 'ajax_get_week']);
        add_action('wp_ajax_nopriv_mores_get_week', [$this, 'ajax_get_week']);
    }

    public function admin_menu() {
        add_menu_page('MO Reservations', 'Rezervace', 'manage_options', 'mores', [$this, 'page_bookings'], 'dashicons-calendar-alt', 26);
        add_submenu_page('mores', 'Kalendáře', 'Kalendáře', 'manage_options', 'mores-calendars', [$this, 'page_calendars']);
        add_submenu_page('mores', 'Služby/délky', 'Služby/délky', 'manage_options', 'mores-services', [$this, 'page_services']);
        add_submenu_page('mores', 'Výluky', 'Výluky', 'manage_options', 'mores-blackouts', [$this, 'page_blackouts']);
        if (get_option('mores_debug_enabled', 1)) { add_submenu_page('mores', 'Debug', 'Debug', 'manage_options', 'mores-debug', [$this, 'page_debug']); }
        add_submenu_page('mores', 'Nastavení', 'Nastavení', 'manage_options', 'mores-settings', [$this, 'page_settings']);
    }

    public function page_bookings() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mores_bookings';
        $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY start_utc DESC LIMIT 200");
        echo '<div class="wrap"><h1>Rezervace</h1><table class="widefat striped"><thead><tr><th>ID</th><th>Kalendář</th><th>Služba</th><th>Od</th><th>Do</th><th>Jméno</th><th>Email</th><th>Stav</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>'.esc_html($r->id).'</td>';
            echo '<td>'.esc_html($r->calendar_id).'</td>';
            echo '<td>'.esc_html($r->service_id).'</td>';
            echo '<td>'.esc_html($r->start_utc).'</td>';
            echo '<td>'.esc_html($r->end_utc).'</td>';
            echo '<td>'.esc_html($r->customer_name).'</td>';
            echo '<td>'.esc_html($r->customer_email).'</td>';
            echo '<td>'.esc_html($r->status).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    
    public function page_calendars(){
        global $wpdb;
        $tbl = $wpdb->prefix . 'mores_calendars';
        $srv_tbl = $wpdb->prefix . 'mores_services';
        $bkg_tbl = $wpdb->prefix . 'mores_bookings';
        $blk_tbl = $wpdb->prefix . 'mores_blackouts';

        // CREATE
        if (isset($_POST['mores_save_cal']) && !isset($_POST['mores_update_cal'])) {
            check_admin_referer('mores_cal');
            $name = sanitize_text_field($_POST['name']);
            $open = sanitize_text_field($_POST['open_time']);
            $close = sanitize_text_field($_POST['close_time']);
            $gran = intval($_POST['granularity']);
            $chain = in_array($_POST['chaining'], ['off','edge']) ? $_POST['chaining'] : 'off';
            $ics_secret = mores_random_token(16);
            $days_open = sanitize_text_field($_POST['days_open']);
            $buffer_after = intval($_POST['buffer_after_minutes']);
            $break_start = sanitize_text_field($_POST['break_start']);
            $break_end = sanitize_text_field($_POST['break_end']);
            $wpdb->insert($tbl, [
                'name' => $name,
                'timezone' => get_option('timezone_string') ?: 'UTC',
                'open_time' => $open,
                'close_time' => $close,
                'granularity' => $gran,
                'chaining' => $chain,
                'ics_secret' => $ics_secret,
                'days_open' => $days_open,
                'buffer_after_minutes' => $buffer_after,
                'break_start' => $break_start,
                'break_end' => $break_end
            ]);
            echo '<div class="updated"><p>Kalendář uložen.</p></div>';
        }

        // UPDATE
        if (isset($_POST['mores_update_cal'])) {
            check_admin_referer('mores_cal_update');
            $id = intval($_POST['id']);
            $name = sanitize_text_field($_POST['name']);
            $open = sanitize_text_field($_POST['open_time']);
            $close = sanitize_text_field($_POST['close_time']);
            $gran = intval($_POST['granularity']);
            $chain = in_array($_POST['chaining'], ['off','edge']) ? $_POST['chaining'] : 'off';
            $days_open = sanitize_text_field($_POST['days_open']);
            $buffer_after = intval($_POST['buffer_after_minutes']);
            $break_start = sanitize_text_field($_POST['break_start']);
            $break_end = sanitize_text_field($_POST['break_end']);
            $wpdb->update($tbl, [
                'name' => $name,
                'open_time' => $open,
                'close_time' => $close,
                'granularity' => $gran,
                'chaining' => $chain,
                'days_open' => $days_open,
                'buffer_after_minutes' => $buffer_after,
                'break_start' => $break_start,
                'break_end' => $break_end
            ], ['id'=>$id]);
            echo '<div class="updated"><p>Kalendář upraven.</p></div>';
        }

        // DELETE
        if (isset($_GET['_action']) && $_GET['_action']==='delete' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $force = isset($_GET['force']) && $_GET['force']=='1';
            $nonce_key = $force ? 'mores_cal_delforce_' . $id : 'mores_cal_del_' . $id;
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], $nonce_key)) {
                echo '<div class="error"><p>Neplatný požadavek.</p></div>';
            } else {
                if ($force) {
                    // delete children first
                    $wpdb->delete($bkg_tbl, ['calendar_id'=>$id]);
                    $wpdb->delete($srv_tbl, ['calendar_id'=>$id]);
                    if (isset($blk_tbl)) { $wpdb->delete($blk_tbl, ['calendar_id'=>$id]); }
                    $wpdb->delete($tbl, ['id'=>$id]);
                    echo '<div class="updated"><p>Kalendář včetně souvisejících rezervací, služeb a výluk byl smazán.</p></div>';
                } else {
                    $cnt_b = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $bkg_tbl WHERE calendar_id=%d", $id)));
                    $cnt_s = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $srv_tbl WHERE calendar_id=%d", $id)));
                    if ($cnt_b>0 || $cnt_s>0) {
                        echo '<div class="error"><p>Nelze smazat – kalendář má navázané záznamy (rezervace: '.esc_html($cnt_b).', služby: '.esc_html($cnt_s).'). Zvažte "Smazat s daty".</p></div>';
                    } else {
                        $wpdb->delete($tbl, ['id'=>$id]);
                        echo '<div class="updated"><p>Kalendář smazán.</p></div>';
                    }
                }
            }
        }

        // EDIT FORM
        if (isset($_GET['_action']) && $_GET['_action']==='edit' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $id));
            if (!$row) { echo '<div class="error"><p>Kalendář nenalezen.</p></div>'; }
            else {
                echo '<div class="wrap"><h1>Upravit kalendář</h1>';
                echo '<form method="post">';
                wp_nonce_field('mores_cal_update');
                echo '<input type="hidden" name="id" value="'.intval($row->id).'">';
                echo '<table class="form-table">';
                echo '<tr><th>Zobrazit jen pracovní dny</th><td><label><input type="checkbox" name="weekdays_only" '.checked(1,$weekdays_only,false).'> Po–Pá (skrýt víkend)</label></td></tr>';
                echo '<tr><th>Název</th><td><input name="name" class="regular-text" required value="'.esc_attr($row->name).'"></td></tr>';
                echo '<tr><th>Otevírací doba</th><td><input name="open_time" value="'.esc_attr($row->open_time).'" size="6"> – <input name="close_time" value="'.esc_attr($row->close_time).'" size="6"></td></tr>';
                echo '<tr><th>Granularita</th><td><input name="granularity" value="'.esc_attr($row->granularity).'" size="4"> min</td></tr>';
                echo '<tr><th>Řetězení</th><td><select name="chaining"><option value="off"'.selected($row->chaining,'off',false).'>Vypnuto</option><option value="edge"'.selected($row->chaining,'edge',false).'>Na okraje dne</option></select></td></tr>';
                echo '<tr><th>Příprava / přesun</th><td><input name="buffer_after_minutes" value="'.esc_attr($row->buffer_after_minutes).'" size="4"> min</td></tr>';
                echo '<tr><th>Pauza (svačina)</th><td><input name="break_start" value="'.esc_attr($row->break_start).'" size="6"> – <input name="break_end" value="'.esc_attr($row->break_end).'" size="6"></td></tr>';
                echo '<tr><th>Dny otevřeno</th><td><input name="days_open" value="'.esc_attr($row->days_open).'" class="regular-text" placeholder="1=Po,…,7=Ne"></td></tr>';
                echo '</table><p><button class="button button-primary" name="mores_update_cal" value="1">Uložit změny</button> ';
                $del_url = wp_nonce_url(add_query_arg(['_action'=>'delete','id'=>$row->id]), 'mores_cal_del_'.$row->id);
                echo '<a class="button button-secondary" href="'.esc_url(menu_page_url('mores-calendars', false)).'">Zpět</a> ';
                echo '<a class="button button-link-delete" href="'.esc_url($del_url).'" onclick="return confirm(\'Opravdu smazat?\')">Smazat kalendář</a>';
                echo '</p></form></div>';
                return;
            }
        }

        // LIST
        $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY id DESC");
        echo '<div class="wrap"><h1>Kalendáře</h1>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Název</th><th>Otevřeno</th><th>Granularita</th><th>Řetězení</th><th>Příprava (min)</th><th>Pauza</th><th>Dny otevřeno</th><th>ICS URL</th><th>Akce</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $ics = add_query_arg(['mo_res_ics' => $r->id, 'mo_res_key' => $r->ics_secret], home_url('/'));
            $edit_url = add_query_arg(['_action'=>'edit','id'=>$r->id]);
            $del_url = wp_nonce_url(add_query_arg(['_action'=>'delete','id'=>$r->id]), 'mores_cal_del_'.$r->id);
            echo '<tr>';
            echo '<td>'.esc_html($r->id).'</td>';
            echo '<td>'.esc_html($r->name).'</td>';
            echo '<td>'.esc_html($r->open_time).'–'.esc_html($r->close_time).'</td>';
            echo '<td>'.esc_html($r->granularity).' min</td>';
            echo '<td>'.esc_html($r->chaining).'</td>';
            echo '<td>'.esc_html($r->buffer_after_minutes).'</td>';
            echo '<td>'.esc_html($r->break_start).'–'.esc_html($r->break_end).'</td>';
            echo '<td>'.esc_html($r->days_open).'</td>';
            echo '<td><code>'.esc_html($ics).'</code></td>';
            echo '<td><a class="button button-small" href="'.esc_url($edit_url).'">Upravit</a> <a class="button button-small" href="'.esc_url($del_url).'" onclick="return confirm(\'Opravdu smazat?\')">Smazat</a></td>';
            echo '</tr>';
        }
        if (!$rows) { echo '<tr><td colspan="10">Zatím žádné kalendáře.</td></tr>'; }
        echo '</tbody></table>';

        // CREATE FORM
        echo '<h2>Přidat kalendář</h2>';
        echo '<form method="post">';
        wp_nonce_field('mores_cal');
        echo '<table class="form-table"><tr><th>Název</th><td><input name="name" class="regular-text" required></td></tr>';
        echo '<tr><th>Otevírací doba</th><td><input name="open_time" value="08:00" size="6"> – <input name="close_time" value="18:00" size="6"></td></tr>';
        echo '<tr><th>Granularita</th><td><input name="granularity" value="30" size="4"> min</td></tr>';
        echo '<tr><th>Řetězení</th><td><select name="chaining"><option value="off">Vypnuto</option><option value="edge">Na okraje dne</option></select></td></tr>';
        echo '<tr><th>Příprava / přesun</th><td><input name="buffer_after_minutes" value="0" size="4"> min (blokuje čas po rezervaci)</td></tr>';
        echo '<tr><th>Pauza (svačina)</th><td><input name="break_start" value="12:00" size="6"> – <input name="break_end" value="12:30" size="6"></td></tr>';
        echo '<tr><th>Dny otevřeno</th><td><input name="days_open" value="1,2,3,4,5" class="regular-text" placeholder="1=Po,…,7=Ne"></td></tr>';
        echo '</table><p><button class="button button-primary" name="mores_save_cal" value="1">Uložit</button></p>';
        echo '</form></div>';
    }


    public function page_services(){
        global $wpdb;
        $srv = $wpdb->prefix . 'mores_services';
        $bkg = $wpdb->prefix . 'mores_bookings';
        $cal = $wpdb->prefix . 'mores_calendars';

        // CREATE
        if (isset($_POST['mores_save_srv']) && !isset($_POST['mores_update_srv'])) {
            try {
                check_admin_referer('mores_srv');
                $name = sanitize_text_field($_POST['name']);
                $dur = intval($_POST['duration']);
                $cal_id = intval($_POST['calendar_id']);
                $wpdb->insert($srv, ['calendar_id'=>$cal_id,'name'=>$name,'duration_minutes'=>$dur,'enabled'=>1, 'price_now'=>floatval($_POST['price_now'] ?? 0), 'price_cash'=>floatval($_POST['price_cash'] ?? 0)]);
                echo '<div class="updated"><p>Služba uložena.</p></div>';
            } catch (Exception $e) {
                MORES_Logger::add('error', 'admin', $e->getMessage());
                wp_admin_notice('Chyba: ' . esc_html($e->getMessage()), ['type'=>'error']);
            }
        }
        // UPDATE
        if (isset($_POST['mores_update_srv'])) {
            try {
                check_admin_referer('mores_srv_upd');
                $id = intval($_POST['id']);
                $name = sanitize_text_field($_POST['name']);
                $dur = intval($_POST['duration']);
                $cal_id = intval($_POST['calendar_id']);
                $wpdb->update($srv, ['calendar_id'=>$cal_id,'name'=>$name,'duration_minutes'=>$dur, 'price_now'=>floatval($_POST['price_now'] ?? 0),'price_cash'=>floatval($_POST['price_cash'] ?? 0)], ['id'=>$id]);
                echo '<div class="updated"><p>Služba upravena.</p></div>';
            } catch (Exception $e) {
                MORES_Logger::add('error', 'admin', $e->getMessage());
                wp_admin_notice('Chyba: ' . esc_html($e->getMessage()), ['type'=>'error']);
            }
        }
        // DELETE
        if (isset($_GET['_action']) && $_GET['_action']==='delete' && isset($_GET['id'])) {
            try {
                $id = intval($_GET['id']);
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mores_srv_del_'.$id)) {
                    echo '<div class="error"><p>Neplatný požadavek.</p></div>';
                } else {
                    $cnt = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $bkg WHERE service_id=%d", $id)));
                    if ($cnt>0) {
                        echo '<div class="error"><p>Nelze smazat – existují rezervace ('.esc_html($cnt).').</p></div>';
                    } else {
                        $wpdb->delete($srv, ['id'=>$id]);
                        echo '<div class="updated"><p>Služba smazána.</p></div>';
                    }
                }
            } catch (Exception $e) {
                MORES_Logger::add('error', 'admin', $e->getMessage());
                wp_admin_notice('Chyba: ' . esc_html($e->getMessage()), ['type'=>'error']);
            }
        }
        // EDIT FORM
        if (isset($_GET['_action']) && $_GET['_action']==='edit' && isset($_GET['id'])) {
            try {
                $id = intval($_GET['id']);
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $srv WHERE id=%d", $id));
                $cals = $wpdb->get_results("SELECT id,name FROM $cal ORDER BY name ASC");
                echo '<div class="wrap"><h1>Upravit službu</h1><form method="post">'; wp_nonce_field('mores_srv_upd');
                echo '<input type="hidden" name="id" value="'.$id.'">';
                echo '<table class="form-table">';
                echo '<tr><th>Kalendář</th><td><select name="calendar_id">';
                foreach ($cals as $c) { echo '<option value="'.$c->id.'"'.selected($row->calendar_id,$c->id,false).'>'.esc_html($c->name).'</option>'; }
                echo '</select></td></tr>';
                echo '<tr><th>Název</th><td><input class="regular-text" name="name" value="'.esc_attr($row->name).'" required></td></tr>';
                echo '<tr><th>Délka</th><td><input name="duration" value="'.esc_attr($row->duration_minutes).'" size="4"> min</td></tr>';
                echo '<tr><th>Cena (platím teď)</th><td><input name="price_now" value="'.esc_attr($row->price_now ?? 0).'" size="8"> Kč</td></tr><tr><th>Cena (hotově)</th><td><input name="price_cash" value="'.esc_attr($row->price_cash ?? 0).'" size="8"> Kč</td></tr></table><p><button class="button button-primary" name="mores_update_srv" value="1">Uložit</button> ';
                echo '<a class="button" href="'.esc_url(menu_page_url('mores-services', false)).'">Zpět</a> ';
                $del = wp_nonce_url(add_query_arg(['_action'=>'delete','id'=>$id]), 'mores_srv_del_'.$id);
                echo '<a class="button button-link-delete" href="'.esc_url($del).'" onclick="return confirm(\'Smazat?\')">Smazat</a></p></form></div>';
                return;
            } catch (Exception $e) {
                MORES_Logger::add('error', 'admin', $e->getMessage());
                wp_admin_notice('Chyba: ' . esc_html($e->getMessage()), ['type'=>'error']);
            }
        }

        // LIST + CREATE
        $rows = $wpdb->get_results("SELECT s.*, c.name as cal_name FROM $srv s LEFT JOIN $cal c ON c.id=s.calendar_id ORDER BY s.id DESC");
        $cals = $wpdb->get_results("SELECT id,name FROM $cal ORDER BY name ASC");
        echo '<div class="wrap"><h1>Služby/délky</h1>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Kalendář</th><th>Název</th><th>Délka</th><th>Cena (platím teď)</th><th>Cena (hotově)</th><th>Akce</th></tr></thead><tbody>';
        if ($rows) {
            foreach ($rows as $r) {
                $edit = add_query_arg(['_action'=>'edit','id'=>$r->id]);
                $del = wp_nonce_url(add_query_arg(['_action'=>'delete','id'=>$r->id]), 'mores_srv_del_'.$r->id);
                echo '<tr><td>'.$r->id.'</td><td>'.esc_html($r->cal_name).'</td><td>'.esc_html($r->name).'</td><td>'.$r->duration_minutes.' min</td><td>' . $r->price_now . '</td><td>' . $r->price_cash . '</td><td><a class="button button-small" href="'.esc_url($edit).'">Upravit</a> <a class="button button-small" href="'.esc_url($del).'" onclick="return confirm(\'Smazat?\')">Smazat</a></td></tr>';
            }
        } else {
            echo '<tr><td colspan="5">Žádné služby.</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Přidat službu</h2><form method="post">'; wp_nonce_field('mores_srv');
        echo '<table class="form-table"><tr><th>Kalendář</th><td><select name="calendar_id">';
        foreach ($cals as $c) { echo '<option value="'.$c->id.'">'.esc_html($c->name).'</option>'; }
        echo '</select></td></tr>';
        echo '<tr><th>Název</th><td><input class="regular-text" name="name" required></td></tr>';
        echo '<tr><th>Délka</th><td><input name="duration" value="60" size="4"> min</td></tr>';
        echo '<tr><th>Cena (platím teď)</th><td><input name="price_now" value="0" size="8"> Kč</td></tr><tr><th>Cena (hotově)</th><td><input name="price_cash" value="0" size="8"> Kč</td></tr></table><p><button class="button button-primary" name="mores_save_srv" value="1">Uložit</button></p></form></div>';
    }

    public function page_blackouts() {
        global $wpdb;
        $blk = $wpdb->prefix . 'mores_blackouts';
        $cal = $wpdb->prefix . 'mores_calendars';

        // Save
        if (isset($_POST['mores_save_blk'])) {
            try {
                check_admin_referer('mores_blk');
                $cal_id = intval($_POST['calendar_id']);
                $from = sanitize_text_field($_POST['date_from']);
                $to = sanitize_text_field($_POST['date_to']);
                $reason = sanitize_text_field($_POST['reason']);
                if ($from && $to && $cal_id) {
                    $wpdb->insert($blk, ['calendar_id'=>$cal_id,'date_from'=>$from,'date_to'=>$to,'reason'=>$reason]);
                    echo '<div class="updated"><p>Výluka přidána.</p></div>';
                }
            } catch (Exception $e) {
                MORES_Logger::add('error', 'admin', $e->getMessage());
                wp_admin_notice('Chyba: ' . esc_html($e->getMessage()), ['type'=>'error']);
            }
        }

        // Delete
        if (isset($_GET['del']) && current_user_can('manage_options')) {
            try {
                $del = intval($_GET['del']);
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mores_blk_del_'.$del)) {
                    echo '<div class="error"><p>Neplatný požadavek.</p></div>';
                } else {
					$wpdb->delete($blk, ['id'=>$del]);
					echo '<div class="updated"><p>Výluka odstraněna.</p></div>';
				}
            } catch (Exception $e) {
                MORES_Logger::add('error', 'admin', $e->getMessage());
                wp_admin_notice('Chyba: ' . esc_html($e->getMessage()), ['type'=>'error']);
            }
        }

        $cals = $wpdb->get_results("SELECT id, name FROM $cal ORDER BY name ASC");
        $rows = $wpdb->get_results("SELECT b.*, c.name AS cal_name FROM $blk b JOIN $cal c ON c.id=b.calendar_id ORDER BY b.date_from DESC");

        echo '<div class="wrap"><h1>Výluky (dovolené, svátky)</h1>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Kalendář</th><th>Od</th><th>Do</th><th>Důvod</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $del_url = wp_nonce_url(add_query_arg(['del'=>$r->id]), 'mores_blk_del_'.$r->id);
            echo '<tr>';
            echo '<td>'.intval($r->id).'</td>';
            echo '<td>'.esc_html($r->cal_name).'</td>';
            echo '<td>'.esc_html($r->date_from).'</td>';
            echo '<td>'.esc_html($r->date_to).'</td>';
            echo '<td>'.esc_html($r->reason).'</td>';
            echo '<td><a href="'.esc_url($del_url).'" class="button">Smazat</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Přidat výluku</h2>';
        echo '<form method="post">';
        wp_nonce_field('mores_blk');
        echo '<table class="form-table">';
        echo '<tr><th>Kalendář</th><td><select name="calendar_id">';
        foreach ($cals as $c) { echo '<option value="'.intval($c->id).'">'.esc_html($c->name).'</option>'; }
        echo '</select></td></tr>';
        echo '<tr><th>Od</th><td><input type="date" name="date_from" required></td></tr>';
        echo '<tr><th>Do</th><td><input type="date" name="date_to" required></td></tr>';
        echo '<tr><th>Důvod</th><td><input type="text" name="reason" class="regular-text"></td></tr>';
        echo '</table><p><button class="button button-primary" name="mores_save_blk" value="1">Uložit</button></p>';
        echo '</form>';

        echo '</div>';
    }

    public function page_debug() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'mores_logs';
        // clear logs
        if (isset($_POST['mores_clear_logs'])) {
            check_admin_referer('mores_dbg');
            $wpdb->query("TRUNCATE TABLE $tbl");
            echo '<div class="updated"><p>Log vyčištěn.</p></div>';
        }
        // fetch logs
        $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY created_at DESC, id DESC LIMIT 200");
        echo '<div class="wrap"><h1>MO Reservations – Debug</h1>';
        echo '<p>Zobrazeno posledních 200 záznamů.</p>';
        echo '<form method="post" style="margin-bottom:10px;">'; wp_nonce_field('mores_dbg'); echo '<button class="button" name="mores_clear_logs" value="1" onclick="return confirm(\'Vyčistit všechny záznamy?\')">Vyčistit log</button></form>';
        echo '<table class="widefat striped"><thead><tr><th>Čas</th><th>Úroveň</th><th>Kontext</th><th>Zpráva</th><th>Data</th></tr></thead><tbody>';
        if ($rows) {
            foreach ($rows as $r) {
                echo '<tr>';
                echo '<td>'.esc_html($r->created_at).'</td>';
                echo '<td>'.esc_html($r->level).'</td>';
                echo '<td>'.esc_html($r->context).'</td>';
                echo '<td>'.esc_html($r->message).'</td>';
                $raw  = isset($r->data) ? $r->data : (isset($r->context) ? $r->context : '');
                $data = maybe_unserialize($raw);
                echo '<td><pre style="white-space:pre-wrap">'.esc_html(is_array($data)||is_object($data)? print_r($data,true) : (string)$data).'</pre></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="5">Žádné záznamy.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function page_settings(){
        
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['mores_save_settings'])) {
            try {
                check_admin_referer('mores_settings');
                update_option('mores_debug_enabled', isset($_POST['debug']) ? 1 : 0);
                update_option('mores_holidays_enabled', isset($_POST['holidays']) ? 1 : 0);
                update_option('mores_holidays_fixed', sanitize_text_field($_POST['holidays_fixed'] ?? ''));
                update_option('mores_drop_tables_on_uninstall', isset($_POST['drop']) ? 1 : 0);
                update_option('mores_empty_cart_text', sanitize_text_field($_POST['empty_cart_text'] ?? ''));
                update_option('mores_empty_cart_url',  esc_url_raw($_POST['empty_cart_url'] ?? ''));
                update_option('mores_show_weekdays_only', isset($_POST['weekdays_only']) ? 1 : 0);
                update_option('mores_redirect_after_add', in_array($_POST['redirect_after_add'] ?? 'checkout', ['checkout','cart'], true) ? $_POST['redirect_after_add'] : 'checkout');
                update_option('mores_cash_gateway', sanitize_text_field($_POST['cash_gateway'] ?? 'cod'));
                update_option('mores_hold_ttl_minutes', max(5, intval($_POST['hold_ttl'] ?? 20)));
                echo '<div class="updated"><p>Nastavení uloženo.</p></div>';
            
            } catch (Throwable $e) {
                MORES_Logger::add('error', 'admin', $e->getMessage());
                wp_admin_notice('Chyba: ' . esc_html($e->getMessage()), ['type'=>'error']);
            }
        }
            
        $debug = get_option('mores_debug_enabled', 1);
        $hol = get_option('mores_holidays_enabled', 1);
        $fixed = get_option('mores_holidays_fixed', '01-01,05-01,05-08,07-05,07-06,09-28,10-28,11-17,12-24,12-25,12-26');
        $drop = get_option('mores_drop_tables_on_uninstall', 0);
        echo '<div class="wrap"><h1>Nastavení</h1><form method="post">';
        $weekdays_only = get_option('mores_show_weekdays_only', 0);
        $redirect_after_add = get_option('mores_redirect_after_add', 'checkout');
        
        // Načti brány
		$cash_gateway = get_option('mores_cash_gateway', 'cod');
		$available_gateways = [];
		if (class_exists('WC_Payment_Gateways')) {
			foreach (WC()->payment_gateways()->payment_gateways() as $id => $gw) {
				$available_gateways[$id] = $gw->get_title() ?: $id;
			}
		}
		if (empty($available_gateways)) {
			$available_gateways = ['cod' => 'Dobírka / hotově (cod)'];
		}

		// Řádek tabulky
		wp_nonce_field('mores_settings');
        echo '<table class="form-table">';
		echo '<tr><th>Platební metoda – cena „hotově"</th><td><select name="cash_gateway">';
		foreach ($available_gateways as $gid => $gtitle) {
			echo '<option value="'.esc_attr($gid).'"'.selected($cash_gateway, $gid, false).'>'.esc_html($gtitle).' ('.esc_html($gid).')</option>';
		}
		echo '</select><br><small>Pro tuto metodu se použije cena „Hotově". Pro ostatní se použije „Platím teď".</small></td></tr>';
        echo '<tr><th>Přesměrování po výběru termínu</th><td><select name="redirect_after_add">'.
             '<option value="checkout"'.selected($redirect_after_add,'checkout',false).'>Pokladna</option>'.
             '<option value="cart"'.selected($redirect_after_add,'cart',false).'>Košík</option>'.
             '</select></td></tr>';
        $hold_ttl = intval(get_option('mores_hold_ttl_minutes', 20));
        echo '<tr><th>Životnost nepotvrzeného termínu</th><td><input type="number" name="hold_ttl" value="'.esc_attr($hold_ttl).'" min="5" max="120" size="4"> min<br><small>Jak dlouho čekat na dokončení platby než se termín uvolní.</small></td></tr>';
        echo '<tr><th>Zobrazit jen pracovní dny</th><td><label><input type="checkbox" name="weekdays_only" '.checked(1,$weekdays_only,false).'> Po–Pá (skrýt víkend)</label></td></tr>';
        echo '<tr><th>Debug log</th><td><label><input type="checkbox" name="debug" '.checked(1,$debug,false).'> Zapnout zapisování do logu a zobrazovat menu Debug</label></td></tr>';
        echo '<tr><th>Cyklické výluky – svátky</th><td><label><input type="checkbox" name="holidays" '.checked(1,$hol,false).'> Blokovat státní svátky + Velký pátek a Velikonoční pondělí</label><br>';
        echo '<input class="regular-text" name="holidays_fixed" value="'.esc_attr($fixed).'"><br><small>Fixní seznam ve formátu MM-DD, oddělený čárkou (výchozí pro ČR).</small></td></tr>';
        echo '<tr><th>Odinstalace</th><td><label><input type="checkbox" name="drop" '.checked(1,$drop,false).'> Smazat tabulek pluginu při odinstalaci</label></td></tr>';
        $empty_cart_text = get_option('mores_empty_cart_text', 'Váš košík je prázdný. Přejít na rezervaci.');
        $empty_cart_url  = get_option('mores_empty_cart_url', home_url('/'));

        $pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC']);
        $pages_select = '<select name="empty_cart_url"><option value="">— vyberte stránku —</option>';
        foreach ($pages as $page) {
            $page_url = get_permalink($page->ID);
            $pages_select .= '<option value="' . esc_attr($page_url) . '"' . selected($empty_cart_url, $page_url, false) . '>'
                           . esc_html($page->post_title) . '</option>';
        }
        $pages_select .= '</select>';
        echo '<tr><th>Prázdný košík – text odkazu</th><td><input class="regular-text" name="empty_cart_text" value="' . esc_attr($empty_cart_text) . '"></td></tr>';
        echo '<tr><th>Prázdný košík – cílová stránka</th><td>' . $pages_select . '<br><small>Kam zákazník přejde z prázdného košíku.</small></td></tr>';
        echo '</table><p><button class="button button-primary" name="mores_save_settings" value="1">Uložit</button></p></form></div>';
    }
    
    public function enqueue_assets() {
        wp_enqueue_script('mores-frontend', MORES_URL . 'assets/js/mores-frontend.js', ['jquery'], MORES_VER, true);
        wp_localize_script('mores-frontend', 'moresAjax', [
            'ajaxurl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mores_ajax'),
            'redirect' => (get_option('mores_redirect_after_add') === 'cart' && function_exists('wc_get_cart_url'))
            ? wc_get_cart_url() : (function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout')),
            'startOfWeek'  => (int) get_option('start_of_week', 1),                // 1 = pondělí (WP nastavení)
            'hideWeekends' => (bool) get_option('mores_show_weekdays_only', 0),    // „skrýt víkendy“
            'cashGateway' => get_option('mores_cash_gateway', 'cod'),
        ]);
        wp_enqueue_style('mores-style', MORES_URL . 'assets/mores.css', [], MORES_VER);
    }
    
    public function register_block() {
        if ( ! function_exists('register_block_type') ) return;

        wp_register_script(
            'mores-block-editor',
            MORES_URL . 'assets/js/mores-block.js',
            ['wp-blocks','wp-element','wp-block-editor','wp-components'],
            MORES_VER
        );

        // Předej seznam kalendářů do editoru
        global $wpdb;
        $cal_tbl = $wpdb->prefix . 'mores_calendars';
        $cals = $wpdb->get_results("SELECT id, name FROM $cal_tbl ORDER BY id ASC");
        $cal_list = [];
        foreach ($cals as $c) {
            $cal_list[] = ['value' => (int)$c->id, 'label' => $c->name . ' (#' . $c->id . ')'];
        }
        if (empty($cal_list)) {
            $cal_list[] = ['value' => 1, 'label' => 'Kalendář #1'];
        }
        wp_localize_script('mores-block-editor', 'moresBlockData', [
            'calendars' => $cal_list,
        ]);

        register_block_type('mo-reservations/calendar', [
            'api_version'     => 2,
            'editor_script'   => 'mores-block-editor',
            'render_callback' => [$this, 'render_block'],
            'attributes'      => [
                'calendar' => ['type'=>'number','default'=> (int)($cal_list[0]['value'] ?? 1)],
            ],
            'supports'        => [
                'align'           => ['wide','full'],
                'spacing'         => [
                    'padding' => true,
                    'margin'  => true,
                ],
                'color'           => [
                    'background' => false,
                    'text'       => false,
                ],
                '__experimentalBorder' => [
                    'radius' => true,
                ],
            ],
        ]);
    }

    public function render_block($atts, $content, $block) {
        $cal   = intval($atts['calendar'] ?? 1);
        $inner = $this->render_shortcode(['calendar' => $cal]);

        // Nativní WP wrapper s podporou spacing/align tříd a stylů
        $wrapper_attrs = '';
        if (function_exists('get_block_wrapper_attributes')) {
            $wrapper_attrs = get_block_wrapper_attributes(['class' => 'mores-block-wrap']);
        } else {
            $wrapper_attrs = 'class="mores-block-wrap"';
        }
        return '<div ' . $wrapper_attrs . '>' . $inner . '</div>';
    }

    
    public function render_shortcode($atts) {
        $a = shortcode_atts(['calendar' => 1], $atts, 'mo_reservation');
        $calendar_id = intval($a['calendar']);
        $services = MORES_Availability::get_services($calendar_id);
        ob_start();
        ?>
        <form class="mores-form mores-inline"
            data-calendar="<?php echo esc_attr($calendar_id); ?>"
            data-hide-weekends="<?php echo get_option('mores_show_weekdays_only') ? '1' : '0'; ?>">

            <div class="mores-top">
                <label>Vyberte službu/délku:</label>
                <select name="service_id" required>
                    <?php
			$first = '';
			foreach ($services as $s) {
			    $sel = '';
			    if (!$first) { $first = intval($s->id); $sel = ' selected'; }
			    echo '<option value="'.intval($s->id).'" '
				.'data-duration="'.intval($s->duration_minutes).'" '
				.'data-price-cash="'.esc_attr($s->price_cash).'" '
				.'data-price-now="'.esc_attr($s->price_now).'"'
				.$sel.'>'
				.esc_html($s->name.' ('.$s->duration_minutes.' min)')
				.'</option>';
			}
			?>
                </select>
            </div>

            <div class="mores-week-nav">
                <button type="button" class="mores-week-prev" aria-label="Předchozí týden">←</button>
                <strong>Zobrazený týden: <span class="mores-week-label"></span></strong>
                <button type="button" class="mores-week-next" aria-label="Další týden">→</button>
            </div>


            <div class="mores-grid-wrap">
                <div class="mores-grid-loading">Načítám dostupnost…</div>
                <table class="mores-grid" aria-label="Týdenní přehled dostupnosti" style="display:none;"></table>
            </div>
            <div class="mores-legend">
				<span class="legend-free"></span> volné
				<span class="legend-partial"></span> částečně
				<span class="legend-busy"></span> obsazené
				<span class="legend-holiday"></span> svátek/výluka
				<span class="legend-sel"></span> vybráno
			</div>

            <input type="hidden" name="date">
            <input type="hidden" name="time">

            <div class="mores-time-help"></div>

            <div class="mores-recap" style="display:none;">
	    	<div class="mores-picked"></div>
	    	<div class="mores-prices"></div>
	    	<button type="submit" class="button button-primary mores-go-checkout">Objednat</button>
	    </div>

        </form>
        <div class="mores-result"></div>
        <?php
        return ob_get_clean();
    }

    public function ajax_make_booking(){
        if ( class_exists('MORES_Woo') ) {
            MORES_Woo::ajax_add_to_cart();
            return;
        }
        try {
            check_ajax_referer('mores_ajax', 'nonce');
            $calendar_id = intval($_POST['calendar_id'] ?? 0);
            $service_id  = intval($_POST['service_id'] ?? 0);
            $date = sanitize_text_field($_POST['date'] ?? '');
            $time = sanitize_text_field($_POST['time'] ?? '');
            $name = sanitize_text_field($_POST['name'] ?? '');
            $email = sanitize_email($_POST['email'] ?? '');
            /*
            if (!$calendar_id || !$service_id || !$date || !$time || !$name || !$email) {
                wp_send_json_error(['message'=>'Vyplňte prosím požadovaná pole.']);
            }
            */
            $name  = '';
            $email = '';

            if (!$calendar_id || !$service_id || !$date || !$time) {
                wp_send_json_error(['message' => 'Chybí údaje o termínu.']);
            }
            $start_local = $date . ' ' . $time . ':00';
            $res = MORES_Availability::book($calendar_id, $service_id, $start_local, $name, $email, []);
            if (!empty($res['ok'])) {
                wp_send_json_success(['ok'=>true, 'booking_id'=>$res['booking_id']]);
            } else {
                wp_send_json_error(['message'=>$res['message'] ?? 'Rezervaci se nepodařilo uložit.']);
            }
        } catch (Throwable $e) {
            wp_send_json_error(['message'=>'Serverová chyba: '.$e->getMessage()]);
        }
    }

    public function ajax_get_week() {
        try {
            check_ajax_referer('mores_ajax', 'nonce');
            $calendar_id = intval($_POST['calendar_id'] ?? 0);
            $service_id = intval($_POST['service_id'] ?? 0);
            $week_start = sanitize_text_field($_POST['week_start'] ?? '');

            if (!$calendar_id || !$service_id || !$week_start) {
                MORES_Logger::add('warn','ajax_get_week','missing params', ['cal'=>$calendar_id,'srv'=>$service_id,'week'=>$week_start]);
                wp_send_json_error(['message'=>'Neúplný požadavek.']);
                return;
            }
            MORES_Logger::add('info','ajax_get_week','request',['cal'=>$calendar_id,'srv'=>$service_id,'week'=>$week_start]);
            if (!method_exists('MORES_Availability','compute_week_grid')) {
                MORES_Logger::add('error','ajax_get_week','compute_week_grid missing');
                wp_send_json_success(['grid'=>['openHour'=>8,'closeHour'=>18,'days'=>[]]]);
                return;
            }
            $grid = MORES_Availability::compute_week_grid($calendar_id, $service_id, $week_start);
            MORES_Logger::add('info','ajax_get_week','ok', ['days'=> isset($grid['days']) ? count($grid['days']) : 0 ]);
            wp_send_json_success(['grid' => $grid]);
        } catch (Throwable $e) {
            MORES_Logger::add('error','ajax_get_week',$e->getMessage());
            wp_send_json_error(['message' => 'Serverová chyba: '.$e->getMessage()]);
        }
    }

}
