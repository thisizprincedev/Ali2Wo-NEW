<?php

/**
 * Description of A2W_Aliexpress
 *
 * @author Mikhail
 */
if (!class_exists('A2W_Aliexpress')) {

    class A2W_Aliexpress
    {

        private $product_import_model;
        private $account;

        public function __construct()
        {
            $this->product_import_model = new A2W_ProductImport();
            $this->aliexpress_api = new A2W_AliexpressApi();
            $this->account = A2W_Account::getInstance();
        }

        public function load_products($filter, $page = 1, $per_page = 20, $params = array())
        {
            //todo: fix this method
            /** @var wpdb $wpdb */
            global $wpdb;

            $products_in_import = $this->product_import_model->get_product_id_list();

            $request_url = A2W_RequestHelper::build_request('get_products', array_merge(array('page' => $page, 'per_page' => $per_page), $filter));
            $request = a2w_remote_get($request_url);
  
            if (is_wp_error($request)) {
                $result = A2W_ResultBuilder::buildError($request->get_error_message());
            } else if (intval($request['response']['code']) != 200) {
                $result = A2W_ResultBuilder::buildError($request['response']['code'] . " " . $request['response']['message']);
            } else {
                $result = json_decode($request['body'], true);

                if (isset($result['state']) && $result['state'] !== 'error') {
                    $default_type = a2w_get_setting('default_product_type');
                    $default_status = a2w_get_setting('default_product_status');

                    $tmp_urls = array();

                    foreach ($result['products'] as &$product) {
                        $product['post_id'] = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_a2w_external_id' AND meta_value='%s' LIMIT 1", $product['id']));
                        $product['import_id'] = in_array($product['id'], $products_in_import) ? $product['id'] : 0;
                        $product['product_type'] = $default_type;
                        $product['product_status'] = $default_status;
                        $product['is_affiliate'] = true;

                        if (isset($filter['country']) && $filter['country']) {
                            $product['shipping_to_country'] = $filter['country'];
                        }

                        $tmp_urls[] = $product['url'];
                    }

                    if ($this->account->custom_account) {
                        try {
                            $promotionUrls = $this->get_affiliate_urls($tmp_urls);
                            if (!empty($promotionUrls) && is_array($promotionUrls)) {
                                foreach ($result["products"] as $i => $product) {
                                    foreach ($promotionUrls as $pu) {
                                        if ($pu['url'] == $product['url']) {
                                            $result["products"][$i]['affiliate_url'] = $pu['promotionUrl'];
                                            break;
                                        }
                                    }
                                }
                            }
                        } catch (Throwable $e) {
                            a2w_print_throwable($e);
                            foreach ($result['products'] as &$product) {
                                $product['affiliate_url'] = $product['url'];
                            }
                        } catch (Exception $e) {
                            a2w_print_throwable($e);
                            foreach ($result['products'] as &$product) {
                                $product['affiliate_url'] = $product['url'];
                            }
                        }
                    }
                }
            }
            return $result;
        }

        public function load_reviews($product_id, $page, $page_size = 20, $params = array())
        {
            $request_url = A2W_RequestHelper::build_request('get_reviews', array('lang' => A2W_AliexpressLocalizator::getInstance()->language, 'product_id' => $product_id, 'page' => $page, 'page_size' => $page_size));
            $request = a2w_remote_get($request_url);

            if (is_wp_error($request)) {
                $result = A2W_ResultBuilder::buildError($request->get_error_message());
            } else {
                $result = json_decode($request['body'], true);

                if ($result['state'] !== 'error') {
                    $result = A2W_ResultBuilder::buildOk(array('reviews' => isset($result['reviews']['evaViewList']) ? $result['reviews']['evaViewList'] : array(), 'totalNum' => isset($result['reviews']['totalNum']) ? $result['reviews']['totalNum'] : 0));
                }
            }

            return $result;
        }

        public function load_product($product_id, $session, $params = array())
        {
            
            /** @var wpdb $wpdb */
            global $wpdb;

            $products_in_import = $this->product_import_model->get_product_id_list();

            $result = $this->aliexpress_api->load_product_data($product_id, $session, $params);

            if ($result['state'] !== 'error') {
                $result['product']['post_id'] = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_a2w_external_id' AND meta_value='%s' LIMIT 1", $result['product']['id']));
                $result['product']['import_id'] = in_array($result['product']['id'], $products_in_import) ? $result['product']['id'] : 0;
                $result['product']['import_lang'] = A2W_AliexpressLocalizator::getInstance()->language;

                $result['product']['regular_price_min'] =  $result['product']['regular_price_max'] =  $result['product']['price_min'] =  $result['product']['price_max'] = 0.00;
                foreach ($result['product']['sku_products']['variations'] as $var) {
                    $result['product']['currency'] = $var['currency'];
    
                    if (!$result['product']['price_min'] || !$result['product']['price_max']) {
                        $result['product']['price_min'] = $result['product']['price_max'] = $var['price'];
                        $result['product']['regular_price_min'] = $result['product']['regular_price_max'] = $var['regular_price'];
                    }
    
                    if ($result['product']['price_min'] > $var['price']) {
                        $result['product']['price_min'] = $var['price'];
                        $result['product']['regular_price_min'] = $var['regular_price'];
                    }
                    if ($result['product']['price_max'] < $var['price']) {
                        $result['product']['price_max'] = $var['price'];
                        $result['product']['regular_price_max'] = $var['regular_price'];
                    }
                }

                if ($this->account->custom_account) {
                    try {
                        $promotionUrls = $this->get_affiliate_urls($result['product']['url']);
                        if (!empty($promotionUrls) && is_array($promotionUrls)) {
                            $result['product']['affiliate_url'] = $promotionUrls[0]['promotionUrl'];
                        }
                    } catch (Throwable $e) {
                        a2w_print_throwable($e);
                        $result['product']['affiliate_url'] = $result['product']['url'];
                    } catch (Exception $e) {
                        a2w_print_throwable($e);
                        $result['product']['affiliate_url'] = $result['product']['url'];
                    }
                } else {
                    try {
                        $promotionUrls = $this->get_default_affiliate_urls($result['product']['url']);
                        if (is_array($promotionUrls)) {
                            $result['product']['affiliate_url'] = $promotionUrls[0]['promotionUrl'];
                        }
                    } catch (Exception $e) {}
    
                }

                if (a2w_get_setting('remove_ship_from')) {
                    $default_ship_from = a2w_get_setting('default_ship_from');
                    $result['product'] = A2W_Utils::remove_ship_from($result['product'], $default_ship_from);
                }

                $country_from = a2w_get_setting('aliship_shipfrom', 'CN');
                $country_to = a2w_get_setting('aliship_shipto');
                $result['product'] = A2W_Utils::update_product_shipping($result['product'], $country_from, $country_to, 'import', a2w_get_setting('add_shipping_to_price'));

                if (($convert_attr_casea = a2w_get_setting('convert_attr_case')) != 'original') {
                    $convert_func = false;
                    switch ($convert_attr_casea) {
                        case 'lower':
                            $convert_func = function ($v) {return strtolower($v);};
                            break;
                        case 'sentence':
                            $convert_func = function ($v) {return ucfirst(strtolower($v));};
                            break;
                    }

                    if ($convert_func) {
                        foreach ($result['product']['sku_products']['attributes'] as &$product_attr) {
                            if (!isset($product_attr['original_name'])) {
                                $product_attr['original_name'] = $product_attr['name'];
                            }

                            $product_attr['name'] = $convert_func($product_attr['name']);

                            foreach ($product_attr['value'] as &$product_attr_val) {
                                $product_attr_val['name'] = $convert_func($product_attr_val['name']);
                            }
                        }

                        foreach ($result['product']['sku_products']['variations'] as &$product_var) {
                            $product_var['attributes_names'] = array_map($convert_func, $product_var['attributes_names']);
                        }
                    }
                }

                if (!empty($result['product']['video'])){
                    //todo: add option to load video or not because it slow down the process
                    $video_link = $this->get_valid_aliexpress_video_link( $result['product']['video'] );
                    if ( $video_link ) {
                        $result['product']['video']['url'] = $video_link;
                    }
                }

                if (a2w_get_setting('use_random_stock')) {
                    $result['product']['disable_var_quantity_change'] = true;
                    foreach ($result['product']['sku_products']['variations'] as &$variation) {
                        $variation['original_quantity'] = intval($variation['quantity']);
                        $tmp_quantity = rand(intval(a2w_get_setting('use_random_stock_min')), intval(a2w_get_setting('use_random_stock_max')));
                        $tmp_quantity = ($tmp_quantity > $variation['original_quantity']) ? $variation['original_quantity'] : $tmp_quantity;
                        $variation['quantity'] = $tmp_quantity;
                    }
                }

                if (isset($result['product']['attribute']) && is_array($result['product']['attribute'])) {
                    $convertedAttributes = array();
                    $split_attribute_values = a2w_get_setting('split_attribute_values');
                    $attribute_values_separator = a2w_get_setting('attribute_values_separator');
                    foreach ($result['product']['attribute'] as $attr) {
                        $el = array('name' => $attr['name']);
                        if ($split_attribute_values) {
                            $el['value'] = array_map('a2w_phrase_apply_filter_to_text', array_map('trim', explode($attribute_values_separator, $attr['value'])));
                        } else {
                            $el['value'] = array(a2w_phrase_apply_filter_to_text(trim($attr['value'])));
                        }
                        $convertedAttributes[] = $el;
                    }
                    $result['product']['attribute'] = $convertedAttributes;
                }

                $sourceDescription = $result['product']['description'];
                $result['product']['description'] = '';
                if (a2w_check_defined('A2W_SAVE_ATTRIBUTE_AS_DESCRIPTION')) {
                    $convertedDescription = '';
                    if ($result['product']['attribute'] && count($result['product']['attribute']) > 0) {
                        $convertedDescription .= '<table class="shop_attributes"><tbody>';
                        foreach ($result['product']['attribute'] as $attribute) {
                            $convertedDescription .= '<tr><th>' . $attribute['name'] . '</th><td><p>' . (is_array($attribute['value']) ? implode(", ", $attribute['value']) : $attribute['value']) . "</p></td></tr>";
                        }
                        $convertedDescription .= '</tbody></table>';
                    }
                    $result['product']['description'] = $convertedDescription;
                }

                if (!a2w_get_setting('not_import_description')) {
                    $result['product']['description'] .= $this->clean_description($sourceDescription);
                }

                $result['product']['description'] = A2W_PhraseFilter::apply_filter_to_text($result['product']['description']);

                $tmp_all_images = A2W_Utils::get_all_images_from_product($result['product']);

                $not_import_gallery_images = false;
                $not_import_variant_images = false;
                $not_import_description_images = a2w_get_setting('not_import_description_images');

                $result['product']['skip_images'] = array();
                foreach ($tmp_all_images as $img_id => $img) {
                    if (!in_array($img_id, $result['product']['skip_images']) && (($not_import_gallery_images && $img['type'] === 'gallery') || ($not_import_variant_images && $img['type'] === 'variant') || ($not_import_description_images && $img['type'] === 'description'))) {
                        $result['product']['skip_images'][] = $img_id;
                    }
                }
            }

            return $result;
        }
        
        private function get_valid_aliexpress_video_link( $video ) {
            $link    = "https://cloud.video.taobao.com/play/u/{$video['ali_member_id']}/p/1/e/6/t/10301/{$video['media_id']}.mp4";
            $request = wp_safe_remote_get( $link );
            if ( wp_remote_retrieve_response_code( $request ) == 400 ) {
                $link    = "https://video.aliexpress-media.com/play/u/ae_sg_item/{$video['ali_member_id']}/p/1/e/6/t/10301/{$video['media_id']}.mp4";
                $request = wp_safe_remote_get( $link );
                if ( wp_remote_retrieve_response_code( $request ) == 400 ) {
                    $link = false;
                }
            }

            return $link;
        }

        public function update_currency_exchange_rate(){
            $currency_exchange_rate = a2w_get_transient('a2w_currency_exchange_rate');

            if (!$currency_exchange_rate){

                $current_currency = strtoupper(A2W_AliexpressLocalizator::getInstance()->currency);

                if ($current_currency === 'USD'){
                    a2w_set_transient('a2w_currency_exchange_rate', 1, 60 * 60 * 6);
                    $result = A2W_ResultBuilder::buildOk(array('currency_exchange_rate' => 1));
                }
                else {
                    $currencyApi = new A2W_CurrencyApi();
                    $currency_api_result = $currencyApi->get_conversion_rate($current_currency);

                    if ($currency_api_result['state'] !== 'error'){
                        a2w_set_transient('a2w_currency_exchange_rate', $currency_api_result['rate'] , 60 * 60 * 6); 
                        $result = A2W_ResultBuilder::buildOk(array('currency_exchange_rate' => $currency_api_result['rate']));    
                    } else {
                        $result = $currency_api_result;  
                    }
                }

            } else {
                $result = A2W_ResultBuilder::buildOk(array('currency_exchange_rate' => $currency_exchange_rate));
            }
 
            return  $result;
        }
   
        public function check_affiliate($product_id)
        {
            //todo: fix this method
            $request_url = A2W_RequestHelper::build_request('check_affiliate', array('product_id' => $product_id));
            $request = a2w_remote_get($request_url);
            if (is_wp_error($request)) {
                $result = A2W_ResultBuilder::buildError($request->get_error_message());
            } else {
                $result = json_decode($request['body'], true);
            }
            return $result;
        }

        public function sync_products($product_ids, $session, $params = array())
        {
            //todo: check what to do with pc param
            //also check what to do when one of the product is not updated
            $product_ids = is_array($product_ids) ? $product_ids : array($product_ids);

            $request_params = array('product_id' => implode(',', $product_ids));
            if (!empty($params['manual_update'])) {
                $request_params['manual_update'] = 1;
            }
            if (!empty($params['pc'])) {
                $request_params['pc'] = $params['pc'];
            }

            $products = array();

            foreach($product_ids as $product_id){

                $product_id_parts = explode(';', $product_id);
                $params['lang'] = $product_id_parts[1];

                $result = $this->aliexpress_api->load_product_data($product_id_parts[0], $session, $params);

                if ( $result['state'] !== 'error'){
                    $products[] = $result['product'];
                } else {
                    //$result = A2W_ResultBuilder::buildError($request->get_error_message());
                }
            }

            $result = A2W_ResultBuilder::buildOk(array('products' => $products));

            $use_random_stock = a2w_get_setting('use_random_stock');
            if ($use_random_stock) {
                $random_stock_min = intval(a2w_get_setting('use_random_stock_min'));
                $random_stock_max = intval(a2w_get_setting('use_random_stock_max'));

                foreach ($result['products'] as &$product) {
                    foreach ($product['sku_products']['variations'] as &$variation) {
                        $variation['original_quantity'] = intval($variation['quantity']);
                        $tmp_quantity = rand($random_stock_min, $random_stock_max);
                        $tmp_quantity = ($tmp_quantity > $variation['original_quantity']) ? $variation['original_quantity'] : $tmp_quantity;
                        $variation['quantity'] = $tmp_quantity;
                    }
                }
            }

            if ($this->account->custom_account && isset($result['products'])) {
                $tmp_urls = array();

                foreach ($result['products'] as $product) {
                    if (!empty($product['url'])) {
                        $tmp_urls[] = $product['url'];
                    }
                }

                try {
                    $promotionUrls = $this->get_affiliate_urls($tmp_urls);
                    if (!empty($promotionUrls) && is_array($promotionUrls)) {
                        foreach ($result["products"] as &$product) {
                            foreach ($promotionUrls as $pu) {
                                if ($pu['url'] == $product['url']) {
                                    $product['affiliate_url'] = $pu['promotionUrl'];
                                    break;
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    a2w_print_throwable($e);
                    foreach ($result['products'] as &$product) {
                        $product['affiliate_url'] = ''; //set empty to disable update!
                    }
                } catch (Exception $e) {
                    a2w_print_throwable($e);
                    foreach ($result['products'] as &$product) {
                        $product['affiliate_url'] = ''; //set empty to disable update!
                    }
                }

            }else {
                try {
                    foreach ($result["products"] as $product) {
                        $promotionUrls = $this->get_default_affiliate_urls($product['url']);
                        if (is_array($promotionUrls)) {
                            $product['affiliate_url'] = $promotionUrls[0]['promotionUrl'];
                        }
                    }
             
                } catch (Exception $e) {}

            }

            //we don't want to update description by default
            foreach ($result["products"] as &$product) {

                $product['source_description'] = $product['description'];
                $product['description'] = '';
            }

            if (isset($params['manual_update']) && $params['manual_update'] && a2w_check_defined('A2W_FIX_RELOAD_DESCRIPTION') && !a2w_get_setting('not_import_description')) {

                foreach ($result["products"] as &$product) {
                    $source_description = $product['source_description'];
                    $product['description'] = $this->clean_description($source_description);
                    $product['description'] = A2W_PhraseFilter::apply_filter_to_text($product['description']);
                }
            }

            /*
            $request_url = A2W_RequestHelper::build_request('sync_products', $request_params);

            if (empty($params['data'])) {
                $request = a2w_remote_get($request_url);
            } else {
                $request = a2w_remote_post($request_url, $params['data']);
            }*/

            return $result;
        }

        public static function load_shipping_info($session, $product_id, $quantity, $country_code, $country_code_from = 'CN', $min_price = '', $max_price = '', $freight_ext = '', $province = '', $city = '' ){
            
            $currency_exchange_rate = a2w_get_transient('a2w_currency_exchange_rate');

            if ($currency_exchange_rate){
                $param_aeop_freight_calculate_for_buyer_d_t_o = array(
                    'country_code' => A2W_Utils::filter_country($country_code), 
                    'product_id' => $product_id, 
                    'product_num' => $quantity, 
                    'send_goods_country_code' => $country_code_from
                );
    
                $payload = array(
                    'param_aeop_freight_calculate_for_buyer_d_t_o' => json_encode($param_aeop_freight_calculate_for_buyer_d_t_o),
                  );
      
                $params = array(
                      "session" => $session,
                      "method" => "aliexpress.logistics.buyer.freight.calculate",
                      "payload" => json_encode($payload),
                );
      
                $request_url = A2W_RequestHelper::build_request('sign', $params);
                $request = a2w_remote_get($request_url);
      
                    if (is_wp_error($request)) {
                        $result = A2W_ResultBuilder::buildError($request->get_error_message());
                    } else {
                        if (intval($request['response']['code']) == 200) {
                            $result = json_decode($request['body'], true);
                        } else {
                            $result = A2W_ResultBuilder::buildError($request['response']['code'] . ' - ' . $request['response']['message']);
                        }
                    }
      
                    if ($result['state'] == 'error') {
                        return $result = A2W_ResultBuilder::buildError($request['data']);
                    }
                    
                    $request = a2w_remote_post($result['request']['requestUrl'], $result['request']['apiParams']);
      
                    if (is_wp_error($request)) {
                        $result = A2W_ResultBuilder::buildError($request->get_error_message());
                    } else {
                        
                        if (intval($request['response']['code']) == 200) {
                            $body = json_decode($request['body'], true);
                            $shipping_data = $body['aliexpress_logistics_buyer_freight_calculate_response']['result'];
    
                            $hasShipping = isset($shipping_data['aeop_freight_calculate_result_for_buyer_d_t_o_list']) &&
                                            !empty($shipping_data['aeop_freight_calculate_result_for_buyer_d_t_o_list']['aeop_freight_calculate_result_for_buyer_dto'])
                                            && !isset($shipping_data['error_desc']);
                          
                            if ($hasShipping){    
                                    $current_currency = strtoupper(A2W_AliexpressLocalizator::getInstance()->currency);
                                    $items = $shipping_data['aeop_freight_calculate_result_for_buyer_d_t_o_list']['aeop_freight_calculate_result_for_buyer_dto'];
                                    
                                    $normalized_items = array();
                            
                                    foreach ($items as $item){
                                        $normalized_item = array(
                                            'serviceName' => $item['service_name'], 
                                            'company' => $item['service_name'],
                                            'time' => $item['estimated_delivery_time'],
                                            'freightAmount' => array()
                                        );
    
                                        $value = round(floatval($item['freight']['amount'])  *  floatval($currency_exchange_rate), 2);
    
                                        $normalized_item['freightAmount'] = array(
                                            'formatedAmount' => $value . ' ' . $current_currency,
                                            'value'=> $value
                                        );
    
                                        $normalized_items[] = $normalized_item;
                                    }
                                    $result = A2W_ResultBuilder::buildOk(array('items' => $normalized_items));
                            } else {
                                $result = A2W_ResultBuilder::buildError('can`t get shipping info');    
                            }
                        }
                    } 
            } else {
                return $result = A2W_ResultBuilder::buildError(__('No currency exchange rate available, you have to synchronize it.', 'ali2woo'));    
            }
    
            return $result;
        }

        public static function clean_description($description)
        {
            $html = $description;

            if (function_exists('mb_convert_encoding')) {
                $html = trim(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            } else {
                $html = htmlspecialchars_decode(utf8_decode(htmlentities($html, ENT_COMPAT, 'UTF-8', false)));
            }

            if (function_exists('libxml_use_internal_errors')) {
                libxml_use_internal_errors(true);
            }

            if ($html && class_exists('DOMDocument')) {
                $dom = new DOMDocument();
                @$dom->loadHTML($html);
                $dom->formatOutput = true;

                $tags = apply_filters('a2w_clean_description_tags', array('script', 'head', 'meta', 'style', 'map', 'noscript', 'object', 'iframe'));

                foreach ($tags as $tag) {
                    $elements = $dom->getElementsByTagName($tag);
                    for ($i = $elements->length; --$i >= 0;) {
                        $e = $elements->item($i);
                        if ($tag == 'a') {
                            while ($e->hasChildNodes()) {
                                $child = $e->removeChild($e->firstChild);
                                $e->parentNode->insertBefore($child, $e);
                            }
                            $e->parentNode->removeChild($e);
                        } else {
                            $e->parentNode->removeChild($e);
                        }
                    }
                }

                if (!in_array('img', $tags)) {
                    $elements = $dom->getElementsByTagName('img');
                    for ($i = $elements->length; --$i >= 0;) {
                        $e = $elements->item($i);
                        $e->setAttribute('src', A2W_Utils::clear_image_url($e->getAttribute('src')));
                    }
                }

                $html = $dom->saveHTML();
            }

            $html = preg_replace('~<(?:!DOCTYPE|/?(?:html|body))[^>]*>\s*~i', '', $html);

            $html = preg_replace('/(<[^>]+) style=".*?"/i', '$1', $html);
            $html = preg_replace('/(<[^>]+) class=".*?"/i', '$1', $html);
            $html = preg_replace('/(<[^>]+) width=".*?"/i', '$1', $html);
            $html = preg_replace('/(<[^>]+) height=".*?"/i', '$1', $html);
            $html = preg_replace('/(<[^>]+) alt=".*?"/i', '$1', $html);
            $html = preg_replace('/^<!DOCTYPE.+?>/', '$1', str_replace(array('<html>', '</html>', '<body>', '</body>'), '', $html));
            $html = preg_replace("/<\/?div[^>]*\>/i", "", $html);

            $html = preg_replace('/<a[^>]*>(.*)<\/a>/iU', '', $html);
            $html = preg_replace('/<a[^>]*><\/a>/iU', '', $html); //delete empty A tags
            $html = preg_replace("/<\/?h1[^>]*\>/i", "", $html);
            $html = preg_replace("/<\/?strong[^>]*\>/i", "", $html);
            $html = preg_replace("/<\/?span[^>]*\>/i", "", $html);

            //$html = str_replace(' &nbsp; ', '', $html);
            $html = str_replace('&nbsp;', ' ', $html);
            $html = str_replace('\t', ' ', $html);
            $html = str_replace('  ', ' ', $html);

            $html = preg_replace("/http:\/\/g(\d+)\.a\./i", "https://ae$1.", $html);

            $html = preg_replace("/<[^\/>]*[^td]>([\s]?|&nbsp;)*<\/[^>]*[^td]>/", '', $html); //delete ALL empty tags
            $html = preg_replace('/<td[^>]*><\/td>/iU', '', $html); //delete empty TD tags

            $html = str_replace(array('<img', '<table'), array('<img class="img-responsive"', '<table class="table table-bordered'), $html);
            $html = force_balance_tags($html);

            return html_entity_decode($html, ENT_COMPAT, 'UTF-8');
        }

        public function get_affiliate_urls($urls)
        {
            if ($this->account->account_type == 'admitad') {
                return A2W_AdmitadAccount::getInstance()->getDeeplink($urls);
            } else if ($this->account->account_type == 'epn') {
                return A2W_EpnAccount::getInstance()->getDeeplink($urls);
            } else {
                return A2W_AliexpressAccount::getInstance()->getDeeplink($urls);
            }
        }

        public function get_default_affiliate_urls($urls)
        {
            $cashback_url = 'https://alitems.site/g/1e8d114494507e24cafe16525dc3e8/';

            if (!is_array($urls)) {
                $urls = array(strval($urls));
            }

            $result = array();

            foreach ($urls as $url) {
                $result[] = array('url' => $url, 'promotionUrl' => $cashback_url . '?ulp=' . urlencode($url));
            }

            return $result;

        }

        public function links_to_affiliate($content)
        {
            if ($content && class_exists('DOMDocument')) {
                $hrefs = array();
                if (function_exists('libxml_use_internal_errors')) {
                    libxml_use_internal_errors(true);
                }
                $dom = new DOMDocument();
                @$dom->loadHTML($content);
                $dom->formatOutput = true;
                $tags = $dom->getElementsByTagName('a');
                foreach ($tags as $tag) {
                    $hrefs[] = $tag->getAttribute('href');
                }

                try {
                    if ($hrefs) {
                        $promotionUrls = $this->get_affiliate_urls($hrefs);
                        if (!empty($promotionUrls) && is_array($promotionUrls)) {
                            foreach ($promotionUrls as $link) {
                                $content = str_replace($link['url'], $link['promotionUrl'], $content);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    a2w_print_throwable($e);
                } catch (Exception $e) {
                    a2w_print_throwable($e);
                }
            }
            return $content;
        }

        public function place_order($data, $session)
        {
            $order = $data['order'];

            $wm = new A2W_Woocommerce();
            $customer_info = $wm->get_order_user_info($order);

            if (!$customer_info['phone']) {
                return A2W_ResultBuilder::buildError(__('Phone number is required', 'ali2woo'));
            } else if (!$customer_info['street'] && !$customer_info['address2']) {
                return A2W_ResultBuilder::buildError(__('Street is required', 'ali2woo'));
            } else if (!$customer_info['name']) {
                return A2W_ResultBuilder::buildError(__('Contact name is required', 'ali2woo'));
            } else if (!$customer_info['country']) {
                return A2W_ResultBuilder::buildError(__('Country is required', 'ali2woo'));
            } else if (!$customer_info['city'] && !$customer_info['state']) {
                return A2W_ResultBuilder::buildError(__('City/State/Province is required', 'ali2woo'));
            } else if (!$customer_info['postcode']) {
                return A2W_ResultBuilder::buildError(__('Zip/Postal code is required', 'ali2woo'));
            } else if ($customer_info['country'] === 'BR' && !$customer_info['cpf']) {
                return A2W_ResultBuilder::buildError(__('CPF is mandatory in Brazil', 'ali2woo'));
            } else if ($customer_info['country'] === 'CL' && !$customer_info['rutNo']) {
                return A2W_ResultBuilder::buildError(__('RUT number is mandatory for Chilean customers', 'ali2woo'));
            }

            $order_note = a2w_get_setting('fulfillment_custom_note', '');

            $product_items = array();
            $processing_order_items = array();
            $errors = array();
            foreach ($data['order_items'] as $order_item) {
                if (get_class($order_item) !== 'WC_Order_Item_Product') {
                    continue;
                }

                $order_item_id = $order_item->get_id();

                $quantity = $order_item->get_quantity() + $order->get_qty_refunded_for_item($order_item_id);

                if ($quantity == 0) {
                    continue;
                }

                $a2w_order_item = new A2W_WooCommerceOrderItem($order_item);

                $product_id = $order_item->get_product_id();
                $variation_id = $order_item->get_variation_id();

                $aliexpress_product_id = get_post_meta($product_id, '_a2w_external_id', true);
                if (!$aliexpress_product_id) {
                   $errors[] = array('order_item_id' => $order_item_id, 'message' => __('AliExpress product not found', 'ali2woo'));
                   continue;
                }

                if ($a2w_order_item->get_external_order_id()) {
                //    $errors[] = array('order_item_id' => $order_item_id, 'message' => __('Aliexpress order exists', 'ali2woo'));
                //    continue;
                }

                if ($variation_id) {
                    $sku_attr = get_post_meta($variation_id, '_aliexpress_sku_props', true);
                } else {
                    $sku_attr = get_post_meta($product_id, '_aliexpress_sku_props', true);
                }

                $shipping_company = a2w_get_setting('fulfillment_prefship', '');
                $shipping_meta = $order_item->get_meta(A2W_Shipping::get_order_item_shipping_meta_key());
                if ($shipping_meta) {
                    $shipping_meta = json_decode($shipping_meta, true);
                    $shipping_company = $shipping_meta['service_name'];
                }

                if (!$shipping_company) {
                    $errors[] = array('order_item_id' => $order_item_id, 'message' => __('Missing Shipping method', 'ali2woo'));
                    continue;
                }

                $product_items[] = array(
                    'product_count' => $quantity,
                    'product_id' => $aliexpress_product_id,
                    'sku_attr' => $sku_attr,
                    'logistics_service_name' => $shipping_company,
                    'order_memo' => $order_note,
                );

                $processing_order_items[] = $a2w_order_item;
            }

            if (!empty($errors)) {
                return A2W_ResultBuilder::buildError(__('Product error', 'ali2woo'), array('error_code' => 'product_error', 'errors' => $errors));
            }

            $logistics_address = array(
                'address' => $customer_info['street'],
                'city' => remove_accents($customer_info['city']),
                'contact_person' => $customer_info['name'],
                'country' => $customer_info['country'],
                'full_name' => $customer_info['name'],
                'mobile_no' => $customer_info['phone'],
                'phone_country' => $customer_info['phoneCountry'],
                'province' => remove_accents($customer_info['state']),
                // additional fields
                // 'locale' => 'en_US',
                // 'rut_no' => '',
                //'location_tree_address_id'=> '',
            );
            if (!empty($customer_info['cpf'])) {
                $logistics_address['cpf'] = $customer_info['cpf'];
            }
            if (!empty($customer_info['rutNo'])) {
                $logistics_address['rutNo'] = $customer_info['rutNo'];
            }
            if ($customer_info['postcode']) {
                $logistics_address['zip'] = $customer_info['postcode'];
            }
            if ($customer_info['address2']) {
                if ($customer_info['street']) {
                    $logistics_address['address2'] = remove_accents($customer_info['address2']);
                } else {
                    $logistics_address['address'] = remove_accents($customer_info['address2']);
                }
            }
            // additional fields
            if (!empty($customer_info['passport_no'])) {
                $logistics_address['passport_no'] = $customer_info['passport_no'];
            }
            if (!empty($customer_info['passport_no_date'])) {
                $logistics_address['passport_no_date'] = $customer_info['passport_no_date'];
            }
            if (!empty($customer_info['passport_organization'])) {
                $logistics_address['passport_organization'] = $customer_info['passport_organization'];
            }
            if (!empty($customer_info['tax_number'])) {
                $logistics_address['tax_number'] = $customer_info['tax_number'];
            }
            if (!empty($customer_info['foreigner_passport_no'])) {
                $logistics_address['foreigner_passport_no'] = $customer_info['foreigner_passport_no'];
            }
            if (!empty($customer_info['is_foreigner']) && $customer_info['is_foreigner']==='yes') {
                $logistics_address['is_foreigner'] = 'true';
            }
            if (!empty($customer_info['vat_no'])) {
                $logistics_address['vat_no'] = $customer_info['vat_no'];
            }
            if (!empty($customer_info['tax_company'])) {
                $logistics_address['tax_company'] = $customer_info['tax_company'];
            }

            $logistics_address = apply_filters('a2w_orders_logistics_address', $logistics_address, $customer_info);

            $payload = array(
                'param_place_order_request4_open_api_d_t_o' => json_encode(
                    array(
                        'logistics_address' => $logistics_address,
                        'product_items' => $product_items,
                    )
                ),
            );

            $params = array(
                "session" => $session,
                "method" => "aliexpress.trade.buy.placeorder",
                "payload" => json_encode($payload),
            );

            $request_url = A2W_RequestHelper::build_request('sign', $params);
            $request = a2w_remote_get($request_url);

            if (is_wp_error($request)) {
                $result = A2W_ResultBuilder::buildError($request->get_error_message());
            } else {
                if (intval($request['response']['code']) == 200) {
                    $result = json_decode($request['body'], true);
                } else {
                    $result = A2W_ResultBuilder::buildError($request['response']['code'] . ' - ' . $request['response']['message']);
                }
            }

            // $result = A2W_ResultBuilder::buildError('DEBUG STOP');
            if ($result['state'] == 'error') {
                return $result;
            }

            $request = a2w_remote_post($result['request']['requestUrl'], $result['request']['apiParams']);
            if (is_wp_error($request)) {
                $result = A2W_ResultBuilder::buildError($request->get_error_message());
            } else {
                if (intval($request['response']['code']) == 200) {
                    $body = json_decode($request['body'], true);
                    if(isset($body['aliexpress_trade_buy_placeorder_response']['result'])){
                        $aliexpress_result = $body['aliexpress_trade_buy_placeorder_response']['result'];
                        if ($aliexpress_result['is_success'] && isset($aliexpress_result['order_list']['number'])) {
                            $aliexpress_order_ids = $aliexpress_result['order_list']['number'];
                            
                            foreach ($aliexpress_order_ids as $aliexpress_order_id) {
                                $result = $this->load_order($aliexpress_order_id, $session);
                                if($result['state'] === 'error') {
                                    break;
                                } else {
                                    foreach($result['order']['child_order_list']['ae_child_order_info'] as $ae_product_info){
                                        foreach($processing_order_items as $order_item) {
                                            if($order_item->get_external_product_id() == $ae_product_info['product_id']){
                                                $order_item->update_external_order($aliexpress_order_id, true);
                                            }
                                        }
                                    }
                                }
                            }

                            $result = A2W_ResultBuilder::buildOk();

                            $placed_order_status = a2w_get_setting('placed_order_status');
                            if ($placed_order_status) {
                                $order->update_status($placed_order_status);
                            }
                        } else {
                            $result = A2W_ResultBuilder::buildError(A2W_AliexpressError::message($aliexpress_result));
                        }
                    } else{
                        a2w_error_log('plase order error: '.print_r($body, true));
                        $result = A2W_ResultBuilder::buildError(A2W_AliexpressError::message($body));
                    }
                } else {
                    $result = A2W_ResultBuilder::buildError($request['response']['code'] . ' - ' . $request['response']['message']);
                }
            }

            return $result;
        }

        public function load_order($order_id, $session)
        {
            $payload = array('order_id' => $order_id);
            $params = array(
                "session" => $session,
                "method" => "aliexpress.ds.trade.order.get",
                "payload" => json_encode($payload),
            );

            $request_url = A2W_RequestHelper::build_request('sign', $params);
            $request = a2w_remote_get($request_url);

            if (is_wp_error($request)) {
                $result = A2W_ResultBuilder::buildError($request->get_error_message());
            } else {
                if (intval($request['response']['code']) == 200) {
                    $result = json_decode($request['body'], true);
                } else {
                    $result = A2W_ResultBuilder::buildError($request['response']['code'] . ' - ' . $request['response']['message']);
                }
            }

            // $result = A2W_ResultBuilder::buildError('DEBUG STOP');
            if ($result['state'] == 'error') {
                return $result;
            }

            $request = a2w_remote_post($result['request']['requestUrl'], $result['request']['apiParams']);
            if (is_wp_error($request)) {
                $result = A2W_ResultBuilder::buildError($request->get_error_message());
            } else {
                $result = A2W_ResultBuilder::buildOk();
                if (intval($request['response']['code']) == 200) {
                    $body = json_decode($request['body'], true);
                    if(isset($body['error_response']['msg'])){
                        $result = A2W_ResultBuilder::buildError($body['error_response']['code'] . ' - ' . $body['error_response']['msg']);
                    } else if(intval($body['aliexpress_ds_trade_order_get_response']['rsp_code']) !== 200) {
                        $result = A2W_ResultBuilder::buildError($body['aliexpress_ds_trade_order_get_response']['rsp_msg'], array('error_code'=>$body['aliexpress_ds_trade_order_get_response']['rsp_code']));
                    } else {
                        $result = A2W_ResultBuilder::buildOk(array('order' => $body['aliexpress_ds_trade_order_get_response']['result']));
                    }
                } else {
                    $result = A2W_ResultBuilder::buildError($request['response']['code'] . ' - ' . $request['response']['message']);
                }
            }
            return $result;
        }

    }

}