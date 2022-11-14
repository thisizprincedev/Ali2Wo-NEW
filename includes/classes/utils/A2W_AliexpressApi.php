<?php

/**
 * Description of A2W_AliexpressApi
 *
 * @author Mikhail
 */
if (!class_exists('A2W_AliexpressApi')) {

    class A2W_AliexpressApi
    {

        public function load_product_data($product_id, $session, $params = array()){
            $params  = !isset($params['data']) ? array() : $params['data'];
            $data_v1 = $this->load_product_data_v1($product_id, $session, $params);
            $data_v2 = $this->load_product_data_v2($product_id, $session, $params); 

            if ($data_v1['state'] === 'error' && $data_v2['state'] === 'error'){
               /* if ($data_v1['state'] === 'error'){
                    return $data_v1;
                }*/

                if ($data_v2['state'] === 'error'){
                    $result = $data_v2;
                }
            } else if ($data_v1['state'] !== 'error' && $data_v2['state'] !== 'error') {
                $result = $this->get_product_combined_data($data_v1, $data_v2);
            } else if ($data_v1['state'] !== 'error' && $data_v2['state'] === 'error'){
                //if we have only data from api v1, then need to convert prices to the currency
                $currency_exchange_rate = a2w_get_transient('a2w_currency_exchange_rate');
                if ($currency_exchange_rate){
                    $current_currency = strtoupper(A2W_AliexpressLocalizator::getInstance()->currency);
                    
                    foreach ( $data_v1['product']['sku_products']['variations'] as &$var) {
                        $var['currency'] = $current_currency;
                        $var['regular_price'] = round($var['regular_price'] * $currency_exchange_rate, 2);
                        $var['price'] = round($var['price'] * $currency_exchange_rate, 2);
                        $var['bulk_price'] = round($var['bulk_price'] * $currency_exchange_rate, 2);    
                    }

                    $result = $data_v1;
                } 
                else {
                    return $result = A2W_ResultBuilder::buildError(__('No currency exchange rate available, you have to synchronize it.', 'ali2woo'));    
                } 
            } else {
                $result = $data_v2;         
            }

            return  $result;
        }

        public function load_product_data_v2($product_id, $session, $params = array()){
      
              $payload = array(
                'product_id' => $product_id,
                'target_language' => isset($params['lang']) ? strtolower($params['lang']) : strtolower(A2W_AliexpressLocalizator::getInstance()->language),
                'target_currency' => isset($params['currency']) ? $params['currency'] : strtoupper(A2W_AliexpressLocalizator::getInstance()->currency),
                'ship_to_country' => A2W_Utils::filter_country(isset($params['lang']) ? strtoupper($params['lang']) : strtoupper(A2W_AliexpressLocalizator::getInstance()->language))
             );

             $api_us_id_fix = false;
             //todo: we can save in the product data that is us version id, and speed up the sync opertion in next time
             $original_params = $params;
             if (isset($params['api_us_id_fix']) && $params['api_us_id_fix']){
                //the api returns empty data for us version product ids if ship_to_country is set
                $api_us_id_fix = true;
                unset($payload['ship_to_country']);
             }

              $params = array(
                  "session" => $session,
                  "method" => "aliexpress.ds.product.get",
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
                    $result = A2W_ResultBuilder::buildOk();
                    if (intval($request['response']['code']) == 200) {

                        $lang = A2W_AliexpressLocalizator::getInstance()->language;
                      
                        $body = json_decode($request['body'], true);                     
                        if (isset($body['aliexpress_ds_product_get_response']) && !empty($body['aliexpress_ds_product_get_response']['result'])){
                            
                            $product_data = $body['aliexpress_ds_product_get_response']['result'];

                            $product = array();
        
                            $product['id'] = $product_id;

                            $product['import_lang'] = $lang;
                            if ($api_us_id_fix){
                                $product['import_lang'] = 'en';    
                            }
                            
                            $product['sku'] = a2w_random_str(); //todo: we can make it as option
                            $product['url'] = "https://" . ($lang === 'en' ? "www" : $lang) . ".aliexpress.com/item/{$product_id}/{$product_id}.html";
                            $product['title'] = $product_data['ae_item_base_info_dto']['subject'];

                            $product['seller_url'] = isset($product_data['ae_store_info']['store_id']) ? "https://" . ($lang === 'en' ? "www" : $lang) . ".aliexpress.com/store/" . $product_data['ae_store_info']['store_id'] : "";
                            $product['seller_name'] = isset($product_data['ae_store_info']['store_name']) ? $product_data['ae_store_info']['store_name'] : "Store";
                            
                            $product['images'] = explode(';', $product_data['ae_multimedia_info_dto']['image_urls']);
                            $product['thumb'] = $product['images'][0];

                            
                            $product['video'] = array();

                            if (isset($product_data['ae_multimedia_info_dto']) && isset($product_data['ae_multimedia_info_dto']['ae_video_dtos']) && isset($product_data['ae_multimedia_info_dto']['ae_video_dtos']['ae_video_d_t_o'])){
                                $sourceVideoData = $product_data['ae_multimedia_info_dto']['ae_video_dtos']['ae_video_d_t_o'];
                                if (!empty($sourceVideoData) && is_array($sourceVideoData)) {
                                    $product['video'] = $sourceVideoData[0];
                                }
                            }

                            $product['dimensions']['length'] = $product_data['package_info_dto']['package_length'];
                            $product['dimensions']['width'] = $product_data['package_info_dto']['package_width'];
                            $product['dimensions']['height'] = $product_data['package_info_dto']['package_height'];
                            $product['dimensions']['weight'] = $product_data['package_info_dto']['gross_weight'];
            
                           // $product['baseUnit'] = $product_data['package_info_dto']['base_unit'];
                            $product['productUnit'] = $product_data['package_info_dto']['product_unit'];
                            $product['packageType'] = $product_data['package_info_dto']['package_type'];
                            $product['category_id'] = $product_data['ae_item_base_info_dto']['category_id'];
                            $product['category_name'] = '';

                            $product['description'] = $product_data['ae_item_base_info_dto']['detail'];
                            $product['ordersCount'] = 0;

                            $product['sku_products'] = array('attributes' => array(), 'variations' => array());
                    
                            if (isset($product_data['ae_item_sku_info_dtos']['ae_item_sku_info_d_t_o']) 
                                && is_array($product_data['ae_item_sku_info_dtos']['ae_item_sku_info_d_t_o'])) {
                                $attr_value_name_hash = array();
                                $attributesWithPropertyIdAsKeys = array();

                                //fetch attributes

                                foreach ($product_data['ae_item_sku_info_dtos']['ae_item_sku_info_d_t_o'] as $src_key_var => $src_var) {
                                    $attr_value_name_hash[$src_key_var] = array();
                                    if (isset($src_var['ae_sku_property_dtos'])){
                                        foreach ($src_var['ae_sku_property_dtos']['ae_sku_property_d_t_o'] as $src_attr){
                                            if (!isset($attributesWithPropertyIdAsKeys[$src_attr['sku_property_id']])){
                                                $attributesWithPropertyIdAsKeys[$src_attr['sku_property_id']] = array('id' => $src_attr['sku_property_id'], 'name' => $src_attr['sku_property_name'], 'value' => array());
                                            }
                                        
                                            $attr = $attributesWithPropertyIdAsKeys[$src_attr['sku_property_id']];
            
                                            $propertyValueId = $src_attr['property_value_id'];
                                            $value = array('id' => $attr['id'] . ':' . $propertyValueId, 'name' => isset($src_attr['property_value_definition_name']) ? $src_attr['property_value_definition_name'] : $src_attr['sku_property_value']);
                                            $value['thumb'] = isset( $src_attr['sku_image'] ) ? str_replace( array(
                                                'ae02.alicdn.com',
                                                'ae03.alicdn.com',
                                                'ae04.alicdn.com',
                                                'ae05.alicdn.com',
                                            ), 'ae01.alicdn.com', $src_attr['sku_image'] ) : '';
                                            $value['image'] = $value['thumb'];

                                            if ($value['image']){
                                                //save image in src var for future use
                                                $product_data['ae_item_sku_info_dtos']['ae_item_sku_info_d_t_o'][$src_key_var]['sku_image'] = $value['thumb'];
                                            }

                                            $countryCode = $this->property_value_id_to_ship_from( $src_attr['sku_property_id'], $src_attr['property_value_id'] );
                                            if ($countryCode) {
                                                $value['country_code'] = $countryCode;
                                            }
            
                                            // Fix value name dublicate
                                            if (empty($attr_value_name_hash[$src_key_var][$value['name']])) {
                                                $attr_value_name_hash[$src_key_var][$value['name']] = 1;
                                            } else {
                                                $attr_value_name_hash[$src_key_var][$value['name']] += 1;
                                                $value['name'] = $value['name'] . "#" . $attr_value_name_hash[$src_key_var][$value['name']];
                                            }
            
                                            //$attr['value'][$value['id']] = $value;
                                            $attributesWithPropertyIdAsKeys[$src_attr['sku_property_id']]['value'][$value['id']] = $value;
                                        }
                                    }                        
                                }

                                $product['sku_products']['attributes'] = array_values($attributesWithPropertyIdAsKeys);

                                //fetch variants
                                $priceList = $product_data['ae_item_sku_info_dtos']['ae_item_sku_info_d_t_o'];
                                foreach ($priceList as $src_var) {
                                    $aa = array();
                                    $aa_names = array();
                                    $country_code = ''; 
                                    if (isset($src_var['id']) && $src_var['id']) {
                                        $sky_attrs = explode(";", $src_var['id']);
                                        foreach ($sky_attrs as $sky_attr) {
                                            $tmp_v = explode("#", $sky_attr);
                                            $aa[] = $tmp_v[0];
                
                                            foreach ($product['sku_products']['attributes'] as $attr) {
                                                if (isset($attr['value'][$tmp_v[0]])) {
                                                    $aa_names[] = $attr['value'][$tmp_v[0]]['name'];
                                                    if (isset($attr['value'][$tmp_v[0]]['country_code'])) {
                                                        $country_code = $attr['value'][$tmp_v[0]]['country_code'];
                                                    }
                                                }
                                            }
                                        }
                                    }

                                    $quantity =  isset($src_var['sku_available_stock'] ) ? $src_var['sku_available_stock'] : ( isset( $src_var['ipm_sku_stock'] ) ? $src_var['ipm_sku_stock'] : 0 );
                                    $regular_price = round(floatval($src_var['sku_price']), 2);
                                    $price = isset($src_var['offer_sale_price'] ) ? round(floatval($src_var['offer_sale_price']), 2) : $regular_price;
                                    $currency = $src_var['currency_code'];
                                    $discount = 100 - 100 * $price /  $regular_price;
                                    $bulk_price = isset( $src_var['offer_bulk_sale_price'] ) ? round(floatval($src_var['offer_bulk_sale_price']), 2) : 0;
        
                                    $variation = array(
                                        'id' => $product_id . '-' . ($aa ? implode('-', $aa) : (count($product['sku_products']['variations']) + 1)),
                                    /* 'skuId'=> $src_var['skuId'],
                                        'skuIdStr'=>$src_var['skuIdStr'],*/
                                        // 'sku' => $product_id . '-' . (count($product['sku_products']['variations']) + 1),
                                        'sku' => a2w_random_str(),
                                        'attributes' => $aa,
                                        'attributes_names' => $aa_names,
                                        'quantity' => $quantity,
                                        'currency' => $currency,
                                        'regular_price' => $regular_price,
                                        'price' => $price,
                                        'bulk_price' => $bulk_price,
                                        'discount' => $discount,
                                    );
                
                                    if ($country_code) {
                                        $variation['country_code'] = $country_code;
                                    }
                
                                    if (isset($src_var['sku_image'])) {
                                        $variation['image'] = $src_var['sku_image'];
                                    }
                
                                    $product['sku_products']['variations'][$variation['id']] = $variation;
                                }
                            }

                            $product['currency'] = $product_data['ae_item_base_info_dto']['currency_code'];

                            if (isset($product_data['ae_item_properties']['ae_item_property']) && is_array($product_data['ae_item_properties']['ae_item_property'])) {
                                foreach ($product_data['ae_item_properties']['ae_item_property'] as $prop) {
                                    //todo: here value is returned as simple value not as array
                                    $product['attribute'][] = array('name' => $prop['attr_name'], 'value' => isset($prop['attr_value']) ? $prop['attr_value'] : '');
                                }
                            }

                              //todo: check why it needs here?
                            $product['complete'] = true;

                            $result = A2W_ResultBuilder::buildOk(array('product' => $product));


                        }
                        else {
                            if (!isset($body['error_response'])){
                                if (!$api_us_id_fix){
                                    $original_params['api_us_id_fix'] = true;
                                    $result = $this->load_product_data_v2($product_id, $session, $original_params);
                                } else {
                                    $result = A2W_ResultBuilder::buildError('Perhaps product ID/Url is not correct?');   
                                }
                            } else {

                                $error_response = $body['error_response'];
                                $result = A2W_ResultBuilder::buildError('AliExpress get-product API error: "' . $error_response['msg'] . '", error code: ' . $error_response['code']);       
                            }
                        }
           
                    } else {
                        $result = A2W_ResultBuilder::buildError($request['response']['code'] . ' - ' . $request['response']['message']);
                    }
                }

                return $result;


        }

        public function load_product_data_v1($product_id, $session, $params = array()){
   
            $payload = array(
              'product_id' => $product_id,
              'local_country' => A2W_Utils::filter_country(isset($params['lang']) ? strtoupper($params['lang']) : strtoupper(A2W_AliexpressLocalizator::getInstance()->language)),
              'local_language' => isset($params['lang']) ? strtolower($params['lang']) : strtolower(A2W_AliexpressLocalizator::getInstance()->language),
            );

            $params = array(
                "session" => $session,
                "method" => "aliexpress.postproduct.redefining.findaeproductbyidfordropshipper",
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
 
                      $product_data = $body['aliexpress_postproduct_redefining_findaeproductbyidfordropshipper_response']['result'];

                      if (isset($product_data['error_message'])){
                        $result = A2W_ResultBuilder::buildError($product_data['error_message']);  
                      } else {
                        $lang = A2W_AliexpressLocalizator::getInstance()->language;
                        $product = array();
                        $product['id'] = $product_id;
               
                        $product['sku'] = a2w_random_str(); //todo: we can make it as option
                        $product['url'] = "https://" . ($lang === 'en' ? "www" : $lang) . ".aliexpress.com/item/{$product_id}/{$product_id}.html";
                        $product['title'] = $product_data['subject'];
  
                        $product['seller_url'] = isset($product_data['store_info']['store_id']) ? "https://" . ($lang === 'en' ? "www" : $lang) . ".aliexpress.com/store/" . $product_data['store_info']['store_id'] : "";
                        $product['seller_name'] = isset($product_data['store_info']['store_name']) ? $product_data['store_info']['store_name'] : "Store";
                        
                        //  $product['local_seller_tag'] = // skip this
                        $product['ratings'] = isset($product_data['avg_evaluation_rating']) ? $product_data['avg_evaluation_rating'] : null;
                        $product['ratings_count'] = isset($product_data['evaluation_count']) ? $product_data['evaluation_count'] : null;
          
                        $product['images'] = explode(';', $product_data['image_u_r_ls']);
                        $product['thumb'] = $product['images'][0];
  
                        $product['video'] = array();
                        if (isset($product_data['aeop_a_e_multimedia']) && isset($product_data['aeop_a_e_multimedia']['aeop_a_e_videos']) && isset($product_data['aeop_a_e_multimedia']['aeop_a_e_videos']['aeop_ae_video'])){
                            $sourceVideoData = $product_data['aeop_a_e_multimedia']['aeop_a_e_videos']['aeop_ae_video'];
                            if (!empty($sourceVideoData) && is_array($sourceVideoData)) {
                                $product['video'] = $sourceVideoData[0];
                            }
                        }

                        $product['dimensions']['length'] = $product_data['package_length'];
                        $product['dimensions']['width'] = $product_data['package_width'];
                        $product['dimensions']['height'] = $product_data['package_height'];
                        $product['dimensions']['weight'] = $product_data['gross_weight'];
        
                        $product['baseUnit'] = isset($product_data['base_unit']) ? $product_data['base_unit'] : 0;
                        $product['productUnit'] = $product_data['product_unit'];
                        $product['packageType'] = $product_data['package_type'];
                        $product['category_id'] = $product_data['category_id'];
                        $product['category_name'] = '';
  
                        $product['description'] = $product_data['detail'];
                        $product['ordersCount'] = $product_data['order_count'];
  
                        $product['sku_products'] = array('attributes' => array(), 'variations' => array());
                 
                        if (isset($product_data['aeop_ae_product_s_k_us']['aeop_ae_product_sku']) && is_array($product_data['aeop_ae_product_s_k_us']['aeop_ae_product_sku'])) {
                            $attr_value_name_hash = array();
                            $attributesWithPropertyIdAsKeys = array();
  
                            //fetch attributes
  
                            foreach ($product_data['aeop_ae_product_s_k_us']['aeop_ae_product_sku'] as $src_key_var => $src_var) {
                                  $attr_value_name_hash[$src_key_var] = array();
                                  if (isset($src_var['aeop_s_k_u_propertys'])){
                                    foreach ($src_var['aeop_s_k_u_propertys']['aeop_sku_property'] as $src_attr){
                                    
                                        if (!isset($attributesWithPropertyIdAsKeys[$src_attr['sku_property_id']])){
                                            $attributesWithPropertyIdAsKeys[$src_attr['sku_property_id']] = array('id' => $src_attr['sku_property_id'], 'name' => $src_attr['sku_property_name'], 'value' => array());
                                        }
                                     
                                        $attr = $attributesWithPropertyIdAsKeys[$src_attr['sku_property_id']];
        
                                        $propertyValueId = isset($src_attr['property_value_id_long']) ? $src_attr['property_value_id_long'] : '';
                                        $value = array('id' => $attr['id'] . ':' . $propertyValueId, 'name' => isset($src_attr['property_value_definition_name']) ? $src_attr['property_value_definition_name'] : $src_attr['sku_property_value']);
                                        $value['thumb'] = isset( $src_attr['sku_image'] ) ? str_replace( array(
                                            'ae02.alicdn.com',
                                            'ae03.alicdn.com',
                                            'ae04.alicdn.com',
                                            'ae05.alicdn.com',
                                        ), 'ae01.alicdn.com', $src_attr['sku_image'] ) : '';
                                        $value['image'] = $value['thumb'];
      
                                        if ($value['image']){
                                            //save image in src var for future use
                                            $product_data['aeop_ae_product_s_k_us']['aeop_ae_product_sku'][$src_key_var]['sku_image'] = $value['thumb'];
                                        }
      
                                        $countryCode = $this->property_value_id_to_ship_from( $src_attr['sku_property_id'], $propertyValueId );
                                        if ($countryCode) {
                                            $value['country_code'] = $countryCode;
                                        }
        
                                         // Fix value name dublicate
                                         if (empty($attr_value_name_hash[$src_key_var][$value['name']])) {
                                            $attr_value_name_hash[$src_key_var][$value['name']] = 1;
                                        } else {
                                            $attr_value_name_hash[$src_key_var][$value['name']] += 1;
                                            $value['name'] = $value['name'] . "#" . $attr_value_name_hash[$src_key_var][$value['name']];
                                        }
        
                                        //$attr['value'][$value['id']] = $value;
                                        $attributesWithPropertyIdAsKeys[$src_attr['sku_property_id']]['value'][$value['id']] = $value;
                                    }    
                                  }                    
                            }
  
                            $product['sku_products']['attributes'] = array_values($attributesWithPropertyIdAsKeys);
                                        
                            //fetch variants
                            $priceList = $product_data['aeop_ae_product_s_k_us']['aeop_ae_product_sku'];
                            foreach ($priceList as $src_var) {
                                $aa = array();
                                $aa_names = array();
                                $country_code = ''; 
                                if (isset($src_var['id']) && $src_var['id']) {
                                    $sky_attrs = explode(";", $src_var['id']);
                                    foreach ($sky_attrs as $sky_attr) {
                                        $tmp_v = explode("#", $sky_attr);
                                        $aa[] = $tmp_v[0];
            
                                        foreach ($product['sku_products']['attributes'] as $attr) {
                                            if (isset($attr['value'][$tmp_v[0]])) {
                                                $aa_names[] = $attr['value'][$tmp_v[0]]['name'];
                                                if (isset($attr['value'][$tmp_v[0]]['country_code'])) {
                                                    $country_code = $attr['value'][$tmp_v[0]]['country_code'];
                                                }
                                            }
                                        }
                                    }
                                }
  
                                $quantity =  isset($src_var['s_k_u_available_stock'] ) ? $src_var['s_k_u_available_stock'] : ( isset( $src_var['ipm_sku_stock'] ) ? $src_var['ipm_sku_stock'] : 0 );
                                $regular_price = round(floatval($src_var['sku_price']), 2);
                                $price = isset($src_var['offer_sale_price'] ) ? round(floatval($src_var['offer_sale_price']), 2) : $regular_price;
                                $currency = $src_var['currency_code'];
                                $discount = 100 -  round(100 * $price / $regular_price, 2);
                                $bulk_price = isset( $src_var['offer_bulk_sale_price'] ) ? round(floatval($src_var['offer_bulk_sale_price']), 2) : 0;
                                $min_bulk_order =  isset( $src_var['sku_bulk_order'] ) ? $src_var['sku_bulk_order'] : 0;
    
                                $variation = array(
                                    'id' => $product_id . '-' . ($aa ? implode('-', $aa) : (count($product['sku_products']['variations']) + 1)),
                                   /* 'skuId'=> $src_var['skuId'],
                                    'skuIdStr'=>$src_var['skuIdStr'],*/
                                    // 'sku' => $product_id . '-' . (count($product['sku_products']['variations']) + 1),
                                    'sku' => a2w_random_str(),
                                    'attributes' => $aa,
                                    'attributes_names' => $aa_names,
                                    'quantity' => $quantity,
                                    'currency' => $currency,
                                    'regular_price' => $regular_price,
                                    'price' => $price,
                                    'bulk_price' => $bulk_price,
                                    'min_bulk_order' => $min_bulk_order,
                                    'discount' => $discount,
                                );
            
                                if ($country_code) {
                                    $variation['country_code'] = $country_code;
                                }
            
                                if (isset($src_var['sku_image'])) {
                                    $variation['image'] = $src_var['sku_image'];
                                }
            
                                $product['sku_products']['variations'][$variation['id']] = $variation;
                            }
                        }
  
                        $product['currency'] = $product_data['currency_code'];
                        if (isset($product_data['aeop_ae_product_propertys']['aeop_ae_product_property']) && is_array($product_data['aeop_ae_product_propertys']['aeop_ae_product_property'])) {
                            foreach ($product_data['aeop_ae_product_propertys']['aeop_ae_product_property'] as $prop) {
                                //todo: here value is returned as simple value not as array
                                $product['attribute'][] = array('name' => $prop['attr_name'], 'value' => isset($prop['attr_value']) ? $prop['attr_value'] : '');
                            }
                        }
                        //todo: check why it needs here?
                        $product['complete'] = true;
    
                        $result = A2W_ResultBuilder::buildOk(array('product' => $product));
                      }

                  } else {
                      $result = A2W_ResultBuilder::buildError($request['response']['code'] . ' - ' . $request['response']['message']);
                  }
              }

              return $result;
        }

        private function property_value_id_to_ship_from( $property_id, $property_value_id ) {
            $ship_from = '';
            if ( $property_id == 200007763 ) {
                switch ( $property_value_id ) {
                    case 203372089:
                        $ship_from = 'PL';
                        break;
                    case 201336100:
                    case 201441035:
                        $ship_from = 'CN';
                        break;
                    case 201336103:
                        $ship_from = 'RU';
                        break;
                    case 100015076:
                        $ship_from = 'BE';
                        break;
                    case 201336104:
                        $ship_from = 'ES';
                        break;
                    case 201336342:
                        $ship_from = 'FR';
                        break;
                    case 201336106:
                        $ship_from = 'US';
                        break;
                    case 201336101:
                        $ship_from = 'DE';
                        break;
                    case 203124901:
                        $ship_from = 'UA';
                        break;
                    case 201336105:
                        $ship_from = 'UK';
                        break;
                    case 201336099:
                        $ship_from = 'AU';
                        break;
                    case 203287806:
                        $ship_from = 'CZ';
                        break;
                    case 201336343:
                        $ship_from = 'IT';
                        break;
                    case 203054831:
                        $ship_from = 'TR';
                        break;
                    case 203124902:
                        $ship_from = 'AE';
                        break;
                    case 100015009:
                        $ship_from = 'ZA';
                        break;
                    case 201336102:
                        $ship_from = 'ID';
                        break;
                    case 202724806:
                        $ship_from = 'CL';
                        break;
                    case 203054829:
                        $ship_from = 'BR';
                        break;
                    case 203124900:
                        $ship_from = 'VN';
                        break;
                    case 203124903:
                        $ship_from = 'IL';
                        break;
                    case 100015000:
                        $ship_from = 'SA';
                        break;
                    case 5581:
                        $ship_from = 'KR';
                        break;
                    default:
                }
            }
    
            return $ship_from;
        }

        private function get_product_combined_data($data_v1, $data_v2){

            $result = $data_v2;

            if ($data_v1['state'] !== 'error' &&  $result['state'] !== 'error') {
                foreach ( $result['product']['sku_products']['variations'] as $i => &$var) {
                    if (isset($data_v1['product']['sku_products']['variations'][$i])){
                        $var_v1 = $data_v1['product']['sku_products']['variations'][$i];

                        //take quantity from v1 if it's zero in v2
                        if ($var['quantity'] == 0){
                            $var['quantity'] = $var_v1['quantity'];
                        }

                        //take discount from v1
                        $discount = $var['discount'] = $var_v1['discount'];
                        if ($discount > 0){
                            $var['regular_price'] = round(floatval(100 * $var['price'] / (100 - $discount)), 2);
                        }
                        unset( $var['bulk_price']); //todo: we can calculate it and use if needed
                    } else {
                        //todo: make a logging of such products for which APIs returns different vars
                    }
                }

                if ($result['product']['import_lang'] !== A2W_AliexpressLocalizator::getInstance()->language){
                    $result['product']['title'] = $data_v1['product']['title'];
                    $result['product']['description'] = $data_v1['product']['description'];
                }

                $result['product']['ordersCount'] = $data_v1['product']['ordersCount'];
            }

            return $result;
        }
    }
}
