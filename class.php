<?php

if (!class_exists('AP_AlikassaApi')) {
    class AP_AlikassaApi
    {
        private $baseURL = 'https://api.alikassa.com/v1/site';

        private $merchantUuid;
        private $secretKey;
        private $algo;

        public $lastRequestUrl;
        public $lastRequestParams;
        public $lastResponse;

        /**
         * AlikassaApi constructor.
         *
         * @param $merchantUuid
         * @param $secretKey
         * @param $algo
         */
        public function __construct($merchantUuid, $secretKey, $algo = 'sha256')
        {
            $this->merchantUuid = trim($merchantUuid);
            $this->secretKey = trim($secretKey);
            $this->algo = trim($algo);
        }

        /**
         * Deposit
         * @url https://alikassa.com/site/api-doc#section/2.-Deposit-API
         * @param $data
         * @return array|mixed
         * @throws Exception
         */
        public function deposit($data)
        {
            $params = [
                'merchantUuid' => $this->merchantUuid,
                'paySystem' => 'Card',
                'payWayVia' => 'Card',
                'commissionType' => 'customer',
            ];
            $params = array_merge($params, $data);

            $result = $this->request('/deposit', 'POST', true, $params);

            return isset($result['return']) ? $result['return'] : [];
        }

        /**
         * Withdrawal
         * @url https://alikassa.com/site/api-doc#section/Withdrawal-API
         * @param $data
         * @return array|mixed
         * @throws Exception
         */
        public function withdrawal($data)
        {
            $result = $this->request('/withdrawal', 'POST', true, $data);

            return isset($result['return']) ? $result['return'] : [];
        }

        /**
         * Transaction history
         * @url https://alikassa.com/site/api-doc#section/5.-Transaction
         * @param $id
         * @return array
         * @throws Exception
         */
        public function history($id)
        {
            $result = $this->request('/history/' . $id, 'POST', true);

            return isset($result['return']) ? $result['return'] : [];
        }

        /**
         * Site Wallet
         * @url https://alikassa.com/site/api-doc#section/2.-Site-Wallet
         * @return array|mixed
         * @throws Exception
         */
        public function wallet()
        {
            $result = $this->request('/wallet', 'POST', true);

            return isset($result['return']) ? $result['return'] : [];
        }

        /**
         * Site
         * @url https://alikassa.com/site/api-doc#section/1.-Site
         * @return array|mixed
         * @throws Exception
         */
        public function site()
        {
            $result = $this->request('', 'POST', true);

            return isset($result['return']) ? $result['return'] : [];
        }

        /**
         * @param $path
         * @param $method
         * @param array $params
         * @param bool $isPrivate
         *
         * @return array|bool|mixed|object
         * @throws Exception
         */
        public function request($path, $method, $isPrivate = false, $params = [])
        {
            $url = $this->baseURL . $path;

            $ch = curl_init();

            $this->lastRequestUrl = $url;

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

                $this->lastRequestParams = $params;
            } else {
                $get_params = http_build_query($params, '', '&');
                $url .= '?' . $get_params;

                $this->lastRequestParams = $get_params;
            }

            if ($isPrivate) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Authorization: Basic ' . base64_encode(
                        $this->merchantUuid . ':' . self::sign($params, $this->secretKey, $this->algo)
                    ),
                ));
            }

            curl_setopt($ch, CURLOPT_HEADER, 0);

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            curl_setopt($ch,CURLOPT_USERAGENT, 'AliKassa API');
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $result = json_decode(curl_exec($ch), true);

            $this->lastResponse = $result;

            if (empty($result)) {
                $err = 'Empty JSON response.';

                throw new \Exception($err);
            }

            if (isset($result['success']) && $result['success'] === 'false') {
                $err = isset($result['error']) ? $result['error'] : 'Unknown API error.';

                throw new \Exception($err);
            }

            if ($result['success'] === 'true') {
                return $result;
            }

            return false;
        }

        /**
         * @param array $dataSet
         * @param string $key
         *
         * @return string
         */
        public static function sign(array $dataSet, $key, $algo)
        {
            if (isset($dataSet['sign'])) {
                unset($dataSet['sign']);
            }

            ksort($dataSet, SORT_STRING);
            $dataSet[] = $key;

            $signString = implode(':', $dataSet);

            $signString = hash($algo, $signString, true);

            return base64_encode($signString);
        }
    }
}