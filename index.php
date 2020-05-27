<?php
if( !defined( 'ABSPATH')){ exit(); }

/*
title: [en_US:]Alikassa[:en_US][ru_RU:]Alikassa[:ru_RU]
description: [en_US:]Alikassa automatic payouts[:en_US][ru_RU:]авто выплаты Alikassa[:ru_RU]
version: 2.1
author: max.dynko@gmail.com
*/

if(!class_exists('AutoPayut_Premiumbox')){ return; }

if(!class_exists('paymerchant_alikassa')){
	class paymerchant_alikassa extends AutoPayut_Premiumbox {

        public $responseMessage = 'OK';

        public $currencies = array('RUB','UAH','BTC','LTC','DOGE','ZEC','EUR','USD');

        public function __construct($file, $title)
        {
            parent::__construct($file, $title, 0);

            $ids = $this->get_ids('paymerchants', $this->name);
            foreach ($ids as $m_id) {
                add_action('premium_merchant_ap_' . $m_id . '_status' . hash_url($m_id, 'ap'), [$this, 'merchant_status']);
            }
        }

        public function get_map()
        {
            $map = array(
                'merchantUuid' => array(
                    'title' => '[en_US:]merchantUuid key[:en_US][ru_RU:]Идентификатор сайта[:ru_RU]',
                    'view' => 'input',
                ),
                'secretKey' => array(
                    'title' => '[en_US:]Secret key[:en_US][ru_RU:]Секретный ключ[:ru_RU]',
                    'view' => 'input',
                ),
            );

            return $map;
        }

        public function settings_list()
        {
            $arrs = array();
            $arrs[] = array('merchantUuid', 'secretKey');

            return $arrs;
        }

        public function options($options, $data, $id, $place)
        {
            $options = pn_array_unset($options, 'checkpay');

            $statused = apply_filters('bid_status_list', array());
            if ( ! is_array($statused)) {
                $statused = array();
            }

            $error_status = trim(is_isset($data, 'error_status'));
            if ( ! $error_status) {
                $error_status = 'realpay';
            }
            $options[] = array(
                'view' => 'select',
                'title' => __('API status error', 'pn'),
                'options' => $statused,
                'default' => $error_status,
                'name' => 'error_status',
                'work' => 'input',
            );

            $options['private_line'] = array(
                'view' => 'line',
                'colspan' => 2,
            );

            $text = '
                <div><a href="' . get_mlink('ap_' . $id . '_status' . hash_url($id, 'ap')) . '" target="_blank" rel="noreferrer noopener">' . get_mlink('ap_' . $id . '_status' . hash_url($id, 'ap')) . '</a></div>
	  		';

            $options[] = array(
                'view' => 'textfield',
                'title' => 'Cron URL',
                'default' => $text,
            );

            $options['private_line'] = array(
                'view' => 'line',
                'colspan' => 2,
            );

            // Алгоритм шифрования
            $opts = array(
                '0' => 'sha256',
                '1' => 'md5',
            );
            $options['algo'] = array(
                'view' => 'select',
                'title' => __('Алгоритм хеширования', 'pn'),
                'options' => $opts,
                'default' => intval(is_isset($data, 'algo')),
                'name' => 'algo',
                'work' => 'int',
            );

            // Способ оплаты
            $opts = array(
                '0' => 'Банковские карты',
                '1' => 'Zcash',
                '2' => 'Bitcoin',
                '3' => 'Dash',
                '4' => 'Litcoin',
                '5' => 'Яндекс.Деньги',
                '6' => 'Qiwi',
                '7' => 'Приват24',
            );
            $options['methodpay'] = array(
                'view' => 'select',
                'title' => __('Payment method', 'pn'),
                'options' => $opts,
                'default' => intval(is_isset($data, 'methodpay')),
                'name' => 'methodpay',
                'work' => 'int',
            );

            $options[] = array(
                'view' => 'user_func',
                'title' => '',
                'func' => 'func_option_mds',
            );

            return $options;
        }

        public function get_reserve_lists($m_id, $m_defin)
        {
            $purses = array();

            foreach($this->currencies as $currency){
                $purses[$m_id . '_' . strtolower($currency)] = strtoupper($currency);
            }

            return $purses;
        }

        public function update_reserve($code, $m_id, $m_defin)
        {
            $sum = 0;

            if (strripos($code, $m_id) !== false) {
                $purses = $this->get_reserve_lists($m_id, $m_defin);
                $purse = trim(is_isset($purses, $code));
                if ($purse) {

                    $m_data = get_paymerch_data($m_id);

                    $algo = $this->getAlgo($m_data);

                    $merchantUuid = is_deffin($m_defin, 'merchantUuid');
                    $secretKey = is_deffin($m_defin, 'secretKey');
                    if ($merchantUuid && $secretKey) {
                        try {
                            $rezerv = '-1';

                            $api = new AP_AlikassaApi($merchantUuid, $secretKey, $algo);
                            $res = $api->site();
                            if (isset($res['totalBalance']) && is_array($res['totalBalance'])) {
                                foreach ($res['totalBalance'] as $currency => $balance) {
                                    if (strcasecmp($currency, $purse) === 0) {
                                        $rezerv = trim((string)$balance);
                                    }
                                }
                            }
                            if ($rezerv !== '-1') {
                                $sum = $rezerv;
                            }
                        } catch (Exception $e) {
                        }
                    }
                }
            }

            return $sum;
        }

        public function do_auto_payouts($error, $pay_error, $m_id, $item, $place, $direction_data, $paymerch_data, $unmetas, $modul_place, $direction, $test, $m_defin)
        {
            $item_id = $item->id;
            $trans_id = 0;

            $currency = mb_strtoupper($item->currency_code_get);
            $currency = str_replace('RUR', 'RUB', $currency);

            if ( ! in_array($currency, $this->currencies)) {
                $error[] = __('Wrong currency code', 'pn');
            }

            $account = $item->account_get;


            // Способ оплаты
            $methodPayOpt = intval(is_isset($paymerch_data, 'methodpay'));
            $methodPay = $this->getMethodPay($methodPayOpt);

            if ($methodPay === 'Qiwi') {
                if (strpos($account, '+') !== 0) {
                    $account = '+' . $account;
                }
                if ( ! preg_match("/^\+[\w]{10,30}$/", $account, $matches)) {
                    $error[] = __('Client wallet type does not match with currency code', 'pn');
                }
            } else {
                if ( ! preg_match("/^[\w]{4,50}$/", $account, $matches)) {
                    $error[] = __('Client wallet type does not match with currency code', 'pn');
                }
            }

            if (in_array($methodPay, array('BTC','LTC','DOGE','ZEC'))) {
                $sum = is_sum(is_paymerch_sum($item, $paymerch_data));
            } else {
                $sum = is_sum(is_paymerch_sum($item, $paymerch_data), 2);
            }

            // Алгоритм
            $algo = $this->getAlgo($paymerch_data);

            $merchantUuid = is_deffin($m_defin, 'merchantUuid');
            $secretKey = is_deffin($m_defin, 'secretKey');
            if ( ! $merchantUuid || ! $secretKey) {
                $error[] = 'Error interfaice';
            }

            if (count($error) == 0) {

                $result = $this->set_ap_status($item, $test);
                if ($result) {

                    try {

                        $api = new AP_AlikassaApi($merchantUuid, $secretKey, $algo);
                        $res = $api->withdrawal(array(
                            'orderId' => $item_id,
                            'currency' => $currency,
                            'paySystem' => $methodPay,
                            'amount' => $sum,
                            'number' => $account,
                        ));

                        if (isset($res['id'], $res['payStatus'])) {

                            $status = strtoupper($res['payStatus']);

                            if (in_array($status, array('WAIT', 'PROCESS', 'SUCCESS'))) {
                                //
                            } else {
                                $error[] = __('Payment error', 'pn');

                                if (in_array($status, array('CANCELED', 'FAIL'))) {
                                    $pay_error = 1;
                                }
                            }

                            $trans_id = $res['id'];

                        } else {
                            $this->logs('Wrong response API!', $item_id);
                        }

                    } catch (Exception $e) {
                        $error[] = $e->getMessage();
                    }

                    $this->logs(array('Request url' => $api->lastRequestUrl), $item_id);
                    $this->logs(array('Request params' => $api->lastRequestParams), $item_id);
                    $this->logs(array('Response' => $api->lastResponse), $item_id);

                } else {
                    $error[] = 'Database error';
                }

            }

            if (count($error) > 0) {
                $this->reset_ap_status($error, $pay_error, $item, $place, $test);
            } else {
                $params = array(
                    'from_account' => '',
                    'trans_out' => $trans_id,
                    'system' => 'user',
                    'ap_place' => $place,
                    'm_place' => $modul_place . ' ' . $m_id,
                );

                if (in_array($status, array('WAIT', 'PROCESS'))) {
                    set_bid_status('coldsuccess', $item_id, $params, 1, $direction);

                    if($place == 'admin'){
                        pn_display_mess(__('Payment is successfully created. Waiting for confirmation.','pn'),__('Payment is successfully created. Waiting for confirmation.','pn'),'true');
                    }
                } elseif (in_array($status, array('SUCCESS'))) {
                    set_bid_status('success', $item_id, $params, 1, $direction);

                    if ($place === 'admin') {
                        pn_display_mess(__('Automatic payout is done', 'pn'), __('Automatic payout is done', 'pn'), 'true');
                    }
                }
            }
        }

        public function merchant_status()
        {
            global $wpdb;

            $m_id = key_for_url('_status', 'ap_');
            $m_defin = $this->get_file_data($m_id);
            $pm_data = get_paymerch_data($m_id);

            $error_status = is_status_name(is_isset($pm_data, 'error_status'));
            if ( ! $error_status) {
                $error_status = 'realpay';
            }

            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . $wpdb->prefix . "exchange_bids WHERE status = 'coldsuccess' && m_out = %s;", $m_id
            ));

            if ( ! empty($items)) {

                $merchantUuid = is_deffin($m_defin, 'merchantUuid');
                $secretKey = is_deffin($m_defin, 'secretKey');
                $algo = $this->getAlgo($pm_data);

                $api = new AP_AlikassaApi($merchantUuid, $secretKey, $algo);

                foreach ($items as $key => $item) {
                    $orderId = $item->id;

                    if (empty($item->trans_out)) {
                        $this->logs('Empty trans_out', $orderId);
                        exit();
                    }

                    $currency = mb_strtoupper($item->currency_code_get);
                    $currency = str_replace('RUR', 'RUB', $currency);

                    if (in_array($currency, $this->currencies)) {

                        if ($merchantUuid && $secretKey) {

                            try {
                                $res = $api->history((int)$item->trans_out);

                                if (isset($res['payStatus'], $res['action'], $res['orderId'])
                                    && strcasecmp($res['action'], 'withdrawal') === 0
                                    && $res['orderId'] == $item->id) {

                                    $status = strtoupper($res['payStatus']);

                                    if ($status === 'SUCCESS') {
                                        $params = array(
                                            'system' => 'system',
                                            'ap_place' => 'site',
                                            'm_place' => 'status ' . $m_id,
                                        );

                                        set_bid_status('success', $item->id, $params, 1);

                                    } elseif (in_array($status, array('CANCELED', 'FAIL'))) {

                                        send_paymerchant_error($item->id, __('Your payment is declined', 'pn'));

                                        update_bids_meta($item->id, 'ap_status', 0);
                                        update_bids_meta($item->id, 'ap_status_date', current_time('timestamp'));

                                        $arr = array(
                                            'status' => $error_status,
                                            'edit_date' => current_time('mysql'),
                                        );
                                        $wpdb->update($wpdb->prefix . 'exchange_bids', $arr, array('id' => $item->id));
                                    }
                                }

                            } catch (Exception $e) {
                                $id = isset($orderId) ? $orderId : 1;
                                $this->logs('Error API check: ' . $e->getMessage(), $id);
                            }

                        }

                    }
                }
            }

            _e('Done', 'pn');
        }

        protected function getAlgo($mData)
        {
            $algoOpt = intval(is_isset($mData, 'algo'));
            switch ($algoOpt) {
                case 0:
                    $algo = 'sha256';
                    break;
                case 1:
                    $algo = 'md5';
                    break;

                default:
                    $algo = 'sha256';
            }

            return $algo;
        }

        protected function getMethodPay($methodPayOpt)
        {
            switch ($methodPayOpt) {
                case 0:
                    $methodPay = 'Card';
                    break;
                case 1:
                    $methodPay = 'ZEC';
                    break;
                case 2:
                    $methodPay = 'BTC';
                    break;
                case 3:
                    $methodPay = 'DASH';
                    break;
                case 4:
                    $methodPay = 'LTC';
                    break;
                case 5:
                    $methodPay = 'YandexMoney';
                    break;
                case 6:
                    $methodPay = 'Qiwi';
                    break;
                case 7:
                    $methodPay = 'Privat24';
                    break;

                default:
                    $methodPay = 'ZEC';
            }

            return $methodPay;
        }
		
	}
}

new paymerchant_alikassa(__FILE__, 'Alikassa');

if( ! function_exists('func_option_mds')) {
    function func_option_mds() {
        $str = base64_decode('ZGV2ZWxvcGVkIGJ5IG1heC5keW5rb0BnbWFpbC5jb20gfCBAbWF4aW1kaw==');
        $temp = "<div class='premium_h3' style='color: #d70084; position: absolute; width: 1px; cursor: pointer; z-index: 999; bottom: 10px; left: 20px' title='$str'>&#128712;</div>";
        echo $temp;
    }
}