<?php

/**
 * Description of A2W_CurrencyApi
 *
 * @author Mikhail
 */
if (!class_exists('A2W_CurrencyApi')) {

    class A2W_CurrencyApi
    {
        public function get_conversion_rate($currency, $base_currency = 'USD'){
            $currency = strtoupper($currency);
            $req_url = 'https://api.exchangerate.host/latest?base=' . $base_currency . '&symbols=' . $currency;
            $request = a2w_remote_get($req_url);

            if (is_wp_error($request)) {
                $result = A2W_ResultBuilder::buildError($request->get_error_message());
            } else {
                $result = json_decode($request['body'], true);

                if ($result['success'] === true) {
              
                    if (isset($result['rates']) && isset($result['rates'][$currency])) {
                        $rate = round(floatval($result['rates'][$currency]), 2);
                        $result = A2W_ResultBuilder::buildOk(array('rate' => $rate));
                    } else {
                        $result = A2W_ResultBuilder::buildError(__('Api can`t get currency exchange rate for currency: ' . $currency, 'ali2woo'));
                    }           
                  
                } else {
                    $result = A2W_ResultBuilder::buildError(__('Api can`t get currency exchange rate for currency: ' . $currency, 'ali2woo'));
                }
            } 
            
            return $result;
        }
    }
}