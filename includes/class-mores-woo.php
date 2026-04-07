<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MORES_Woo {
    const SESSION_KEY = 'mores_payment_method';

    const PRODUCT_OPTION = 'mores_wc_product_id';
    const HOLD_TTL_MIN = 20;

    public static function init() {
        add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'on_checkout_update']);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'adjust_cart_prices'], 1000);
        if ( ! class_exists('WooCommerce') ) { return; }

        add_action('init', [__CLASS__, 'maybe_create_product']);
        add_action('wp_ajax_mores_wc_add_to_cart', [__CLASS__, 'ajax_add_to_cart']);
        add_action('wp_ajax_nopriv_mores_wc_add_to_cart', [__CLASS__, 'ajax_add_to_cart']);

        add_filter('woocommerce_before_calculate_totals', [__CLASS__, 'apply_cart_item_price'], 20, 1);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'add_item_meta'], 10, 4);
        
        add_filter('woocommerce_cart_item_name', [__CLASS__, 'filter_cart_item_name'], 10, 3);
		add_filter('woocommerce_get_item_data', [__CLASS__, 'filter_cart_item_data'], 10, 2);
		add_filter('woocommerce_product_get_description', [__CLASS__, 'filter_placeholder_description'], 10, 2);
		add_filter('woocommerce_product_get_short_description', [__CLASS__, 'filter_placeholder_description'], 10, 2);
		
		add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'on_create_order_item'], 10, 4);
		add_filter('woocommerce_order_item_name', [__CLASS__, 'filter_order_item_name'], 10, 2);
		add_filter('woocommerce_hidden_order_itemmeta', [__CLASS__, 'hide_meta_keys']);


        add_action('woocommerce_payment_complete', [__CLASS__, 'on_payment_complete'], 10, 1);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'on_processing'], 10, 1);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'on_processing'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'on_cancelled'], 10, 1);
        add_action('woocommerce_order_status_failed', [__CLASS__, 'on_cancelled'], 10, 1);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart_holds']);
		add_action('woocommerce_before_checkout_process', [__CLASS__, 'validate_cart_holds']);
		add_filter('woocommerce_checkout_cart_item_quantity', [__CLASS__, 'checkout_remove_link'], 10, 3);
        add_action('woocommerce_cart_is_empty', [__CLASS__, 'empty_cart_message'], 5);
        add_filter('woocommerce_product_is_visible',       [__CLASS__, 'hide_placeholder_product'], 10, 2);
		add_action('woocommerce_cart_is_empty', [__CLASS__, 'suppress_empty_cart_upsells'], 1);
		
		//cancel order
		add_action('woocommerce_email_after_order_table', [__CLASS__, 'email_cancel_link'], 10, 4);
		add_action('template_redirect', [__CLASS__, 'maybe_handle_public_cancel']);
		add_action('template_redirect', [__CLASS__, 'maybe_serve_booking_ics']);

		add_action('woocommerce_order_status_cancelled', [__CLASS__, 'release_booking_for_order']);
		add_action('woocommerce_order_status_refunded',  [__CLASS__, 'release_booking_for_order']);
		add_action('woocommerce_order_status_failed',    [__CLASS__, 'release_booking_for_order']);
		
		add_action('mores_cleanup_expired_holds', [__CLASS__, 'cleanup_expired_holds']);
		if ( ! wp_next_scheduled('mores_cleanup_expired_holds') ) {
			wp_schedule_event(time(), 'hourly', 'mores_cleanup_expired_holds');
		}
	}
	
	public static function get_cash_gateway() {
		return get_option('mores_cash_gateway', 'cod');
	}
    
    public static function maybe_create_product() {
		if ( ! class_exists('WC_Product') ) return;
		$pid = intval( get_option(self::PRODUCT_OPTION, 0) );

		if ( $pid ) {
			$status = get_post_status($pid);
			if ( $status === 'publish' ) {
				// Doplň _stock_status pokud chybí (starší instalace)
				if ( ! get_post_meta($pid, '_stock_status', true) ) {
					update_post_meta($pid, '_stock_status', 'instock');
					update_post_meta($pid, '_manage_stock',  'no');
				}
				return;
			}
			if ( $status !== false ) {
				wp_update_post(['ID' => $pid, 'post_status' => 'publish']);
				if ( ! get_post_meta($pid, '_stock_status', true) ) {
					update_post_meta($pid, '_stock_status', 'instock');
					update_post_meta($pid, '_manage_stock',  'no');
				}
				return;
			}
		}

		$post_id = wp_insert_post([
			'post_title'   => 'Rezervace služby',
			'post_content' => '',
			'post_excerpt' => '',
			'post_status'  => 'publish',
			'post_type'    => 'product',
			'menu_order'   => 0
		]);
		if ( is_wp_error($post_id) ) return;

		update_post_meta($post_id, '_virtual',          'yes');
		update_post_meta($post_id, '_sold_individually', 'yes');
		update_post_meta($post_id, '_regular_price',    '0');
		update_post_meta($post_id, '_price',            '0');
		update_post_meta($post_id, '_visibility',       'hidden');
		update_post_meta($post_id, '_stock_status',     'instock');
		update_post_meta($post_id, '_manage_stock',     'no');
		wp_set_object_terms($post_id, 'simple', 'product_type');
		update_option(self::PRODUCT_OPTION, $post_id);
	}

    protected static function get_service_price($service_id, $mode = 'cash') {
        global $wpdb;
        $srv = $wpdb->prefix . 'mores_services';
        $row = $wpdb->get_row($wpdb->prepare("SELECT price_now, price_cash FROM $srv WHERE id=%d", $service_id));
        if (!$row) return 0;
        $price_cash = floatval($row->price_cash);
        $price_now  = floatval($row->price_now);
        if ($mode === 'now') {
            return ($price_now > 0) ? $price_now : $price_cash;
        }
        return $price_cash;
    }

    public static function ajax_add_to_cart() {
        try {
            check_ajax_referer('mores_ajax', 'nonce');
            /*
            if ( ! class_exists('WooCommerce') || ! function_exists('WC') ) {
                wp_send_json_error(['message'=>'WooCommerce není aktivní.']);
            }
            */
            if ( ! class_exists('WooCommerce') || ! function_exists('WC') ) {
				wp_send_json_error(['message'=>'WooCommerce není aktivní.']);
				return; // ← toto chybí!
			}

			// Ujisti se, že WC session a cart jsou inicializované
			if ( ! WC()->session ) {
				WC()->initialize_session();
			}
			if ( ! WC()->cart ) {
				WC()->initialize_cart();
			}
			          
            $calendar_id = intval($_POST['calendar_id'] ?? 0);
            $service_id = intval($_POST['service_id'] ?? 0);
            $date = sanitize_text_field($_POST['date'] ?? '');
            $time = sanitize_text_field($_POST['time'] ?? '');
            $name = sanitize_text_field($_POST['name'] ?? '');
            $email = sanitize_email($_POST['email'] ?? '');
            $phone = sanitize_text_field($_POST['phone'] ?? '');
            $address = sanitize_textarea_field($_POST['address'] ?? '');
            if (!$calendar_id || !$service_id || !$date || !$time) { wp_send_json_error(['message'=>'Vyberte prosím službu a termín.']); }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
                wp_send_json_error(['message'=>'Neplatný formát data/času.']);
            }
            $start_local = $date . ' ' . $time . ':00';

            // Create hold
            $res = MORES_Availability::create_hold($calendar_id, $service_id, $start_local, $name, $email, ['phone'=>$phone,'address'=>$address], self::HOLD_TTL_MIN);
            if (empty($res['ok'])) {
                wp_send_json_error(['message'=>$res['message'] ?? 'Termín je obsazen.']);
            }
            $booking_id = intval($res['booking_id']);

            // Add to cart with price and meta
            $pid = intval( get_option(self::PRODUCT_OPTION, 0) );
            if (!$pid) { self::maybe_create_product(); $pid = intval(get_option(self::PRODUCT_OPTION, 0)); }
            $price = self::get_service_price($service_id, 'cash');
            
            global $wpdb;
			$svc_tbl = $wpdb->prefix.'mores_services';
			$svc_row = $wpdb->get_row( $wpdb->prepare("SELECT name FROM $svc_tbl WHERE id=%d", $service_id) );
			$svc_name = $svc_row ? $svc_row->name : '';

            $data = [
                'mores_booking_id' => $booking_id,
                'mores_calendar_id'=> $calendar_id,
                'mores_service_id' => $service_id,
                'mores_start_local'=> $start_local,
                'mores_price'      => $price,
                'mores_customer'   => ['name'=>$name,'email'=>$email,'phone'=>$phone,'address'=>$address],
                'mores_service_name' => $svc_name,
            ];
            // Zachyť WC notices před i po
            /*
			wc_clear_notices();

			$added = WC()->cart->add_to_cart($pid, 1, 0, [], $data);

			$notices = wc_get_notices('error');
			$notice_texts = array_map(function($n){ return wp_strip_all_tags(is_array($n) ? ($n['notice'] ?? '') : $n); }, $notices);
			wc_clear_notices();

			$added = WC()->cart->add_to_cart($pid, 1, 0, [], $data);

			if ( $added === false ) {
				$notices = wc_get_notices('error');
				$notice_texts = array_map(function($n){
					return wp_strip_all_tags(is_array($n) ? ($n['notice'] ?? '') : $n);
				}, $notices);
				wc_clear_notices();

				// Produkt je already v košíku (sold_individually) → jen přesměruj
				foreach ( $notice_texts as $msg ) {
					if ( strpos($msg, 'nemůžete přidat další') !== false
					  || strpos($msg, 'cannot add another') !== false ) {
						MORES_Availability::cancel_booking($booking_id); // zruš nový hold, ten starý zůstane
						$redir = get_option('mores_redirect_after_add', 'checkout');
						$url = ($redir === 'cart') ? wc_get_cart_url() : wc_get_checkout_url();
						wp_send_json_success(['redirect' => $url]);
						return;
					}
				}

				// Jiná chyba – zruš hold a vrať chybu
				MORES_Availability::cancel_booking($booking_id);
				wp_send_json_error(['message' => implode('; ', $notice_texts) ?: 'Přidání do košíku selhalo.']);
				return;
			}

			$redir = get_option('mores_redirect_after_add', 'checkout');
			$url = ($redir === 'cart') ? wc_get_cart_url() : wc_get_checkout_url();
			*/
			// Zkontroluj, zda stejný termín již v košíku není
            foreach ( WC()->cart->get_cart() as $ci ) {
                if ( isset($ci['mores_start_local']) && $ci['mores_start_local'] === $start_local
                  && isset($ci['mores_service_id']) && (int)$ci['mores_service_id'] === $service_id ) {
                    MORES_Availability::cancel_booking($booking_id);
                    $redir = get_option('mores_redirect_after_add', 'checkout');
                    $url = ($redir === 'cart') ? wc_get_cart_url() : wc_get_checkout_url();
                    wp_send_json_success(['redirect' => $url]);
                    return;
                }
            }

            wc_clear_notices();
            $added = WC()->cart->add_to_cart($pid, 1, 0, [], $data);

            if ( $added === false ) {
                $notices = wc_get_notices('error');
                $notice_texts = array_map(function($n){
                    return wp_strip_all_tags(is_array($n) ? ($n['notice'] ?? '') : $n);
                }, $notices);
                wc_clear_notices();
                MORES_Availability::cancel_booking($booking_id);
                wp_send_json_error(['message' => implode('; ', $notice_texts) ?: 'Přidání do košíku selhalo.']);
                return;
            }

            $redir = get_option('mores_redirect_after_add', 'checkout');
            $url = ($redir === 'cart') ? wc_get_cart_url() : wc_get_checkout_url();
			wp_send_json_success(['redirect' => $url]);
		} catch (Throwable $e) {
            wp_send_json_error(['message'=>'Chyba: '.$e->getMessage()]);
        }
    }

    public static function apply_cart_item_price($cart) {
        if ( is_admin() && ! defined('DOING_AJAX') ) return;
        if ( empty($cart) ) return;
        foreach ($cart->get_cart() as $ci) {
            if (!empty($ci['mores_price'])) {
                $ci['data']->set_price( floatval($ci['mores_price']) );
            }
        }
    }

    public static function add_item_meta($item, $cart_item_key, $values, $order) {
        $keys = ['mores_booking_id','mores_calendar_id','mores_service_id','mores_start_local'];
        foreach ($keys as $k) {
            if (isset($values[$k])) {
                $item->add_meta_data($k, $values[$k], true);
            }
        }
    }

    public static function on_processing($order_id) {
        self::transition_booking($order_id, true);
    }

    public static function on_payment_complete($order_id) {
        self::transition_booking($order_id, true);
    }

    public static function on_cancelled($order_id) {
        self::transition_booking($order_id, false);
    }

    protected static function transition_booking($order_id, $confirm) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        foreach ($order->get_items() as $item) {
            $booking_id = intval( $item->get_meta('_mores_booking_id', true) );
            if ($booking_id) {
                if ($confirm) {
                    MORES_Availability::confirm_booking($booking_id, $order_id);
                    self::update_booking_from_order($booking_id, $order);
                    // email confirmation to customer
                    if (class_exists('MORES_Email')) { MORES_Email::send_confirmation($booking_id); }
                } else {
                    MORES_Availability::cancel_booking($booking_id);
                }
            }
        }
    }
/*
    }
        if ($has_cash && !$has_now) {
            // allow only COD for cash
            foreach ($gateways as $id => $gw) {
                if ($id !== 'cod') unset($gateways[$id]);
            }
        } elseif ($has_now && !$has_cash) {
            // disallow COD when paying now
            if (isset($gateways['cod'])) unset($gateways['cod']);
        }
        return $gateways;
    }
*/
    protected static function update_booking_from_order($booking_id, $order) {
        $name = trim($order->get_formatted_billing_full_name());
        $email = $order->get_billing_email();
        $phone = $order->get_billing_phone();
        $addr1 = $order->get_billing_address_1();
        $addr2 = $order->get_billing_address_2();
        $city = $order->get_billing_city();
        $zip = $order->get_billing_postcode();
        $country = $order->get_billing_country();
        $address = trim($addr1.' '.($addr2?:'').', '.$zip.' '.$city.', '.$country);
        global $wpdb; $tbl = $wpdb->prefix.'mores_bookings';
        $wpdb->update($tbl, [
            'customer_name'=>$name?:'',
            'customer_email'=>$email?:'',
            'customer_phone'=>$phone?:'',
            'customer_address'=>$address?:''
        ], ['id'=>$booking_id]);
    }

    public static function on_checkout_update($posted_data) {
        if (is_string($posted_data)) { parse_str($posted_data, $arr); } else { $arr = (array)$posted_data; }
        $method = isset($arr['payment_method']) ? sanitize_text_field($arr['payment_method']) : '';
        if (function_exists('WC') && WC()->session) { WC()->session->set(self::SESSION_KEY, $method); }
    }
    /*
    public static function adjust_cart_prices($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!function_exists('WC') || !WC()->session) return;
        $method = WC()->session->get(self::SESSION_KEY, '');
        foreach ($cart->get_cart() as $ci_key => $ci) {
            if (!empty($ci['mores_booking_id']) && !empty($ci['mores_service_id'])) {
                $mode = ($method === 'cod') ? 'cash' : 'now';
                $price = self::get_service_price(intval($ci['mores_service_id']), $mode);
                if ($price <= 0) { $price = self::get_service_price(intval($ci['mores_service_id']), 'cash'); }
                if ($price > 0) { $ci['data']->set_price($price); }
            }
        }
    }
    */
    
    public static function adjust_cart_prices($cart) {
		if (is_admin() && !defined('DOING_AJAX')) return;
		if (!function_exists('WC') || !WC()->session) return;
		$method = WC()->session->get(self::SESSION_KEY, '');
		if (!$method) {
			$method = WC()->session->get('chosen_payment_method', '');
		}
		$cash_gateway = self::get_cash_gateway();
		foreach ($cart->get_cart() as $ci_key => $ci) {
			if (!empty($ci['mores_booking_id']) && !empty($ci['mores_service_id'])) {
				$mode = ($method === $cash_gateway) ? 'cash' : 'now';
				$price = self::get_service_price(intval($ci['mores_service_id']), $mode);
				if ($price <= 0) { $price = self::get_service_price(intval($ci['mores_service_id']), 'cash'); }
				if ($price > 0) { $ci['data']->set_price($price); }
			}
		}
	}
    
    public static function filter_cart_item_name($name, $cart_item, $cart_item_key){
		if (!empty($cart_item['mores_service_name'])) {
			$title = esc_html($cart_item['mores_service_name']);
			if (!empty($cart_item['mores_start_local'])) {
				$title .= '<br><small>'.esc_html($cart_item['mores_start_local']).'</small>';
			}
			return $title;
		}
		return $name;
	}
	
	public static function checkout_remove_link($qty_html, $cart_item, $cart_item_key) {
        if ( empty($cart_item['mores_booking_id']) ) return $qty_html;
        $remove_url = add_query_arg([
            'remove_item' => $cart_item_key,
            '_wpnonce'    => wp_create_nonce('woocommerce-cart'),
        ], wc_get_cart_url());
        $link = ' <a href="' . esc_url($remove_url) . '" style="color:#a00;font-size:0.85em;" title="Odebrat termín">✕ Odebrat</a>';
        return $qty_html . $link;
    }
	
	/*
    public static function empty_cart_message() {
        $text = get_option('mores_empty_cart_text', 'Váš košík je prázdný. Přejít na rezervaci.');
        $url  = get_option('mores_empty_cart_url', home_url('/'));
        echo '<p class="cart-empty woocommerce-info">'
           . '<a href="' . esc_url($url) . '">' . esc_html($text) . '</a>'
           . '</p>';
    }
    */
    
    public static function empty_cart_message() {
		$text = get_option('mores_empty_cart_text', 'Váš košík je prázdný. Přejít na rezervaci.');
		$url  = get_option('mores_empty_cart_url', home_url('/'));
		if ($url) {
			echo '<p class="cart-empty">'
			   . '<a href="' . esc_url($url) . '">' . esc_html($text) . '</a>'
			   . '</p>';
		} else {
			echo '<p class="cart-empty">' . esc_html($text) . '</p>';
		}
	}
    
    public static function hide_placeholder_product($visible, $product_id) {
		$placeholder_id = (int) get_option(self::PRODUCT_OPTION, 0);
		if ($placeholder_id && $product_id === $placeholder_id) {
			return false;
		}
		return $visible;
	}

	/*
	public static function suppress_empty_cart_upsells() {
		// Odstraní WC výchozí "Novinka" / upsell sekci na prázdném košíku
		remove_action('woocommerce_cart_is_empty', 'woocommerce_output_all_notices', 10);
		add_filter('woocommerce_product_related_posts_relate_by_category', '__return_false');
		add_filter('woocommerce_product_related_posts_relate_by_tag',      '__return_false');
		// Odstraní sekci s "náhodným zbožím" (cross-sells / random products)
		remove_action('woocommerce_cart_collaterals', 'woocommerce_cross_sell_display');
		remove_action('woocommerce_after_cart',       'woocommerce_output_upsell_products', 10);
	}
	*/
	
	public static function suppress_empty_cart_upsells() {
		// Odstraň WC výchozí hlášku – naše empty_cart_message (priorita 5) ji nahradí
		remove_action('woocommerce_cart_is_empty', 'woocommerce_empty_cart_message', 10);
		// Odstraň sekci s produkty ("Novinka") která se zobrazuje pod prázdným košíkem
		remove_action('woocommerce_cart_is_empty', 'wc_empty_cart_message',          10);
		remove_action('woocommerce_after_cart',    'woocommerce_cross_sell_display');
		// Skryj náhodné produkty které WC theme blok zobrazuje pod prázdným košíkem
		add_filter('woocommerce_product_loop_start', [__CLASS__, 'hide_empty_cart_product_loop'], 1);
		add_filter('woocommerce_product_loop_end',   [__CLASS__, 'hide_empty_cart_product_loop'], 1);
		add_filter('woocommerce_shop_loop_item_title',    '__return_empty_string', 1);
		add_filter('woocommerce_after_shop_loop_item',    [__CLASS__, 'ob_start_discard'], 1);
		add_filter('woocommerce_before_shop_loop_item',   [__CLASS__, 'ob_start_discard'], 1);
		// Nejspolehlivější metoda – obalíme výstup za naší zprávou do output bufferu a zahodíme
		add_action('woocommerce_cart_is_empty', [__CLASS__, 'start_discard_after_message'], 6);
		add_action('woocommerce_after_cart',    [__CLASS__, 'end_discard'], 1);
		register_shutdown_function([__CLASS__, 'end_discard']);
	}

	public static function start_discard_after_message() {
		ob_start(); // zachytí vše co přijde po naší zprávě (=sekce Novinka)
	}

	public static function end_discard() {
		if ( ob_get_level() > 0 ) {
			ob_end_clean(); // zahodí "Novinka" sekci
		}
	}

	public static function filter_cart_item_data($item_data, $cart_item){
		if (!empty($cart_item['mores_service_name']) || !empty($cart_item['mores_start_local'])) {
			$item_data[] = [
				'name'  => 'Služba',
				'value' => esc_html($cart_item['mores_service_name'] ?? ''),
				'display' => esc_html($cart_item['mores_service_name'] ?? ''),
			];
			if (!empty($cart_item['mores_start_local'])) {
				$item_data[] = [
					'name'  => 'Termín',
					'value' => esc_html($cart_item['mores_start_local']),
					'display' => esc_html($cart_item['mores_start_local']),
				];
			}
		}
		return $item_data;
	}

	/**
	 * Schová popisek placeholder produktu v košíku/pokladně
	 * (případně jej nahradí rekapitulací služby).
	 */
	public static function filter_placeholder_description($desc, $product){
		$placeholder_id = (int) get_option(self::PRODUCT_OPTION, 0);
		if ($placeholder_id && (int) $product->get_id() === $placeholder_id) {
			// pokus o zobrazení služby z kartové položky
			if (function_exists('WC') && WC()->cart) {
				foreach (WC()->cart->get_cart() as $ci) {
					if ((int)$ci['product_id'] === $placeholder_id) {
						$svc = $ci['mores_service_name'] ?? '';
						$dt  = $ci['mores_start_local'] ?? '';
						if ($svc || $dt) {
							return esc_html(trim($svc.($dt ? ' — '.$dt : '')));
						}
					}
				}
			}
			return ''; // fallback: nic (schovat generický text)
		}
		return $desc;
	}
	
	public static function validate_cart_holds() {
		if (!function_exists('WC') || !WC()->cart) return;
		global $wpdb;
		$tbl = $wpdb->prefix . 'mores_bookings';
		$now = gmdate('Y-m-d H:i:s');

		foreach (WC()->cart->get_cart() as $key => $item) {
			if (empty($item['mores_booking_id'])) continue;

			$bid = intval($item['mores_booking_id']);
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT status, expires_at FROM $tbl WHERE id=%d", $bid
			) );

			// Rezervace nenalezena → ven z košíku
			if (!$row) {
				wc_add_notice(__('Rezervace nenalezena. Vyberte nový termín.', 'mo-reservations'), 'error');
				WC()->cart->remove_cart_item($key);
				continue;
			}

			// Už není "hold" (např. confirmed/pending) → pustit dál
			if ($row->status !== 'hold') continue;

			// Hold vypršel → zablokovat platbu
			if (!empty($row->expires_at) && $row->expires_at <= $now) {
				wc_add_notice(__('Rezervace vypršela (30 min). Vyberte nový termín.', 'mo-reservations'), 'error');
				WC()->cart->remove_cart_item($key);
			}
		}
	}

	public static function on_create_order_item($item, $cart_item_key, $values, $order){
		// technická meta skrytá (podtržítko) – nezobrazí se
		if (!empty($values['mores_booking_id']))   $item->add_meta_data('_mores_booking_id',   $values['mores_booking_id']);
		if (!empty($values['mores_calendar_id']))  $item->add_meta_data('_mores_calendar_id',  $values['mores_calendar_id']);
		if (!empty($values['mores_service_id']))   $item->add_meta_data('_mores_service_id',   $values['mores_service_id']);
		if (!empty($values['mores_start_local']))  $item->add_meta_data('_mores_start_local',  $values['mores_start_local']);
		if (!empty($values['mores_service_name'])) $item->add_meta_data('_mores_service_name', $values['mores_service_name']);

		// lidský údaj do přehledu (bude vidět): „Termín“
		if (!empty($values['mores_start_local'])) {
			$item->add_meta_data(__('Termín','mo-reservations'), $values['mores_start_local']);
		}
		
		// propsat order_id do mores_bookings
		if (!empty($values['mores_booking_id'])) {
			global $wpdb;
			$tbl = $wpdb->prefix.'mores_bookings';
			$wpdb->update($tbl, ['order_id' => $order->get_id()], ['id' => (int)$values['mores_booking_id']], ['%d'], ['%d']);
		}

	}
	
	public static function filter_order_item_name($name, $item){
		$svc = $item->get_meta('_mores_service_name', true);
		$dt  = $item->get_meta('_mores_start_local', true);
		if ($svc) {
			$name = esc_html($svc);
			if ($dt) $name .= '<br><small>'.esc_html($dt).'</small>';
		}
		return $name;
	}

	public static function hide_meta_keys($hidden){
		$hidden = (array)$hidden;
		// skryj staré klíče bez podtržítka, kdyby už v objednávkách byly
		return array_merge($hidden, ['mores_booking_id','mores_calendar_id','mores_service_id','mores_start_local','mores_service_name']);
	}

	public static function email_cancel_link( $order, $sent_to_admin, $plain_text, $email ){
		// jen zákaznické maily
		if ( $sent_to_admin || ! method_exists($email, 'is_customer_email') || ! $email->is_customer_email() ) return;

		// najdi první položku s naší rezervací
		foreach ( $order->get_items() as $item ) {
			$bid = $item->get_meta('_mores_booking_id', true);
			if ( ! $bid ) continue;

			global $wpdb;
			$tbl = $wpdb->prefix.'mores_bookings';
			$row = $wpdb->get_row( $wpdb->prepare("SELECT token FROM $tbl WHERE id=%d", (int)$bid) );
			if ( ! $row || empty($row->token) ) continue;

			$url = add_query_arg( 'mores_cancel', rawurlencode($row->token), home_url('/') );

			if ( $plain_text ) {
				echo "\n" . __( 'Zrušit rezervaci:', 'mo-reservations' ) . ' ' . $url . "\n";
			} else {
				echo '<p><a href="'.esc_url($url).'">'.esc_html__( 'Zrušit rezervaci', 'mo-reservations' ).'</a></p>';
			}
			$ics_url = add_query_arg([
				'mores_ics_booking' => (int)$bid,
				'mores_token'       => rawurlencode($row->token),
			], home_url('/'));
			if ( $plain_text ) {
				echo "\n" . __( 'Stáhnout kalendářní pozvánku (.ics):', 'mo-reservations' ) . ' ' . $ics_url . "\n";
			} else {
				echo '<p><a href="'.esc_url($ics_url).'">'.esc_html__( 'Stáhnout kalendářní pozvánku (.ics)', 'mo-reservations' ).'</a></p>';
			}
			break; // stačí jeden odkaz
		}
	}

	public static function maybe_handle_public_cancel(){
		if ( empty($_GET['mores_cancel']) ) return;
		$token = sanitize_text_field( wp_unslash($_GET['mores_cancel']) );

		global $wpdb;
		$tbl = $wpdb->prefix.'mores_bookings';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, order_id FROM $tbl WHERE token=%s", $token
		) );

		if ( ! $row ) {
			wp_die( esc_html__( 'Rezervace nenalezena nebo již byla zrušena.', 'mo-reservations' ) );
		}

		// zruš objednávku (pokud existuje a ještě není zrušená)
		if ( function_exists('wc_get_order') && $row->order_id ) {
			$order = wc_get_order( (int)$row->order_id );
			if ( $order && ! $order->has_status('cancelled') ) {
				$order->update_status( 'cancelled', __( 'Zrušeno zákazníkem přes e-mailový odkaz.', 'mo-reservations' ) );
			}
		}

		// uvolni termín (smazat rezervaci)
		$wpdb->delete( $tbl, ['id' => (int)$row->id], ['%d'] );

		// jednoduchá potvrzovací stránka
		wp_die( esc_html__( 'Rezervace byla zrušena a termín uvolněn.', 'mo-reservations' ),
				esc_html__( 'Zrušeno', 'mo-reservations' ),
				['response'=>200] );
	}
	
	public static function cleanup_expired_holds() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'mores_bookings';
		$now = gmdate('Y-m-d H:i:s');

		// 1) Expirované holds → zruš objednávku + smaž
		$expired = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM $tbl WHERE status = 'hold' AND expires_at IS NOT NULL AND expires_at <= %s",
			$now
		) );

		foreach ($expired as $bid) {
			$row = $wpdb->get_row( $wpdb->prepare("SELECT order_id FROM $tbl WHERE id=%d", (int)$bid) );
			if ($row && $row->order_id && function_exists('wc_get_order')) {
				$order = wc_get_order((int)$row->order_id);
				if ($order && $order->has_status('pending')) {
					$order->update_status('cancelled', 'Automaticky zrušeno – zákazník nedokončil platbu v časovém limitu.');
				}
			}
			$wpdb->delete($tbl, ['id' => (int)$bid], ['%d']);
		}

		// 2) Smaž osiřelé záznamy se statusem 'cancelled' (pozůstatky starších verzí)
		$wpdb->query("DELETE FROM $tbl WHERE status = 'cancelled'");
	}

	public static function release_booking_for_order( $order_id ){
		if ( ! $order_id ) return;
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		global $wpdb;
		$tbl = $wpdb->prefix.'mores_bookings';

		foreach ( $order->get_items() as $item ) {
			$bid = $item->get_meta('_mores_booking_id', true);
			if ( $bid ) {
				$wpdb->delete( $tbl, ['id' => (int)$bid], ['%d'] );
			}
		}
	}
	
	public static function maybe_serve_booking_ics() {
        if ( empty($_GET['mores_ics_booking']) || empty($_GET['mores_token']) ) return;
        $bid   = intval($_GET['mores_ics_booking']);
        $token = sanitize_text_field(wp_unslash($_GET['mores_token']));
        global $wpdb;
        $tbl = $wpdb->prefix.'mores_bookings';
        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM $tbl WHERE id=%d AND token=%s", $bid, $token));
        if (!$row) { wp_die('Neplatný odkaz.'); }
        $ics = MORES_ICS::generate_booking_ics($bid);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="rezervace-'.$bid.'.ics"');
        header('Cache-Control: no-cache');
        echo $ics;
        exit;
    }


}
