<?php

/**
 * Description of A2W_Utils
 *
 * @author Andrey
 */
if (!class_exists('A2W_Utils')) {

    class A2W_Utils
    {

        public static function wcae_strack_active()
        {
            return is_plugin_active('woocommerce_aliexpress_shipment_tracking/index.php');
        }

        public static function update_post_terms_count($post_id)
        {
            global $wpdb;
            $update_taxonomies = array();
            $res = $wpdb->get_results("SELECT tt.taxonomy,tt.term_taxonomy_id,tt.term_id FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->term_taxonomy} tt ON (tr.term_taxonomy_id=tt.term_taxonomy_id) WHERE tr.object_id=" . absint($post_id), ARRAY_A);
            foreach ($res as $row) {
                if (!isset($update_taxonomies[$row['taxonomy']])) {
                    $update_taxonomies[$row['taxonomy']] = array();
                }
                $update_taxonomies[$row['taxonomy']][] = in_array($row['taxonomy'], array('product_cat', 'product_tag')) ? $row['term_taxonomy_id'] : $row['term_id'];
            }

            foreach ($update_taxonomies as $taxonomy => $terms) {
                wp_update_term_count_now($terms, $taxonomy);
            }
        }

        public static function clear_url($url)
        {
            if ($url) {
                $parts = parse_url($url);
                $res = '';
                if (isset($parts['scheme'])) {
                    $res .= $parts['scheme'] . '://';
                }
                if (isset($parts['host'])) {
                    $res .= $parts['host'];
                }
                if (isset($parts['path'])) {
                    $res .= $parts['path'];
                }
                return $res;
            }
            return '';
        }

        /**
         * Get size information for all currently-registered image sizes.
         * List available image sizes with width and height following
         *
         * @global $_wp_additional_image_sizes
         * @uses   get_intermediate_image_sizes()
         * @return array $sizes Data for all currently-registered image sizes.
         */
        public function get_image_sizes()
        {
            global $_wp_additional_image_sizes;

            $sizes = array();

            foreach (get_intermediate_image_sizes() as $_size) {
                if (in_array($_size, array('thumbnail', 'medium', 'medium_large', 'large'))) {
                    $sizes[$_size]['width'] = get_option("{$_size}_size_w");
                    $sizes[$_size]['height'] = get_option("{$_size}_size_h");
                    $sizes[$_size]['crop'] = (bool) get_option("{$_size}_crop");
                } elseif (isset($_wp_additional_image_sizes[$_size])) {
                    $sizes[$_size] = array(
                        'width' => $_wp_additional_image_sizes[$_size]['width'],
                        'height' => $_wp_additional_image_sizes[$_size]['height'],
                        'crop' => $_wp_additional_image_sizes[$_size]['crop'],
                    );
                }
            }

            return $sizes;
        }

        /**
         * Get size information for a specific image size.
         *
         * @uses   get_image_sizes()
         * @param  string $size The image size for which to retrieve data.
         * @return bool|array $size Size data about an image size or false if the size doesn't exist.
         */
        public function get_image_size($size)
        {
            $sizes = get_image_sizes();

            if (isset($sizes[$size])) {
                return $sizes[$size];
            }

            return false;
        }

        /**
         * Get the width of a specific image size.
         *
         * @uses   get_image_size()
         * @param  string $size The image size for which to retrieve data.
         * @return bool|string $size Width of an image size or false if the size doesn't exist.
         */
        public function get_image_width($size)
        {
            if (!$size = get_image_size($size)) {
                return false;
            }

            if (isset($size['width'])) {
                return $size['width'];
            }

            return false;
        }

        /**
         * Get the height of a specific image size.
         *
         * @uses   get_image_size()
         * @param  string $size The image size for which to retrieve data.
         * @return bool|string $size Height of an image size or false if the size doesn't exist.
         */
        public function get_image_height($size)
        {
            if (!$size = get_image_size($size)) {
                return false;
            }

            if (isset($size['height'])) {
                return $size['height'];
            }

            return false;
        }

        public static function delete_post_images($post_id)
        {
            global $wpdb;
            $external_id = get_post_meta($post_id, '_a2w_external_id', true);
            $post_type = get_post_type($post_id);
            if ($external_id || $post_type == 'product_variation') {
                $childrens = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_parent = %d and post_type='attachment'", $post_id));
                if ($childrens) {
                    foreach ($childrens as $attachment_id) {
                        A2W_Utils::delete_attachment($attachment_id, true);
                    }
                }
                $thumbnail_id = get_post_meta($post_id, '_thumbnail_id', true);
                if ($thumbnail_id && $post_type != 'product_variation') {
                    A2W_Utils::delete_attachment($thumbnail_id, true);
                }
            }
        }

        // cloned wp_delete_attachment function, use for overload sites
        // 2021.11.20 By default will use default function (wp_delete_attachment)
        public static function delete_attachment($post_id, $force_delete = false)
        {
            if (!a2w_check_defined('A2W_USE_CUSTOM_DELETE_ATTACHMENT')) {
                return wp_delete_attachment($post_id, $force_delete);
            }

            global $wpdb;

            $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->posts WHERE ID = %d", $post_id));

            if (!$post) {
                return $post;
            }

            $post = get_post($post);

            if ('attachment' !== $post->post_type) {
                return false;
            }

            if (!$force_delete && EMPTY_TRASH_DAYS && MEDIA_TRASH && 'trash' !== $post->post_status) {
                return wp_trash_post($post_id);
            }

            delete_post_meta($post_id, '_wp_trash_meta_status');
            delete_post_meta($post_id, '_wp_trash_meta_time');

            $meta = wp_get_attachment_metadata($post_id);
            $backup_sizes = get_post_meta($post->ID, '_wp_attachment_backup_sizes', true);
            $file = get_attached_file($post_id);

            if (is_multisite()) {
                delete_transient('dirsize_cache');
            }

            /**
             * Fires before an attachment is deleted, at the start of wp_delete_attachment().
             *
             * @since 2.0.0
             *
             * @param int $post_id Attachment ID.
             */
            do_action('delete_attachment', $post_id);

            wp_delete_object_term_relationships($post_id, array('category', 'post_tag'));
            wp_delete_object_term_relationships($post_id, get_object_taxonomies($post->post_type));

            // Delete all for any posts.
            // A2W disable this call!
            // delete_metadata( 'post', null, '_thumbnail_id', $post_id, true );

            wp_defer_comment_counting(true);

            $comment_ids = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID = %d", $post_id));
            foreach ($comment_ids as $comment_id) {
                wp_delete_comment($comment_id, true);
            }

            wp_defer_comment_counting(false);

            $post_meta_ids = $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM $wpdb->postmeta WHERE post_id = %d ", $post_id));
            foreach ($post_meta_ids as $mid) {
                delete_metadata_by_mid('post', $mid);
            }

            /** This action is documented in wp-includes/post.php */
            do_action('delete_post', $post_id);
            $result = $wpdb->delete($wpdb->posts, array('ID' => $post_id));
            if (!$result) {
                return false;
            }
            /** This action is documented in wp-includes/post.php */
            do_action('deleted_post', $post_id);

            wp_delete_attachment_files($post_id, $meta, $backup_sizes, $file);

            clean_post_cache($post);

            return $post;
        }

        public static function clear_image_url($img_url, $param_str = '')
        {
            //$img_url = str_replace("http:", "https:", $img_url);
            if (substr($img_url, 0, 4) !== "http") {
                $new_src = "https:" . $img_url;
            } else {
                $new_src = $img_url;
            }

            $parsed_url = parse_url($img_url);

            if (!empty($parsed_url['scheme']) && !empty($parsed_url['host']) && !empty($parsed_url['path'])) {
                $new_src = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'] . $param_str;
            } else if (empty($parsed_url['scheme']) && empty($parsed_url['host']) && !empty($parsed_url['path'])) {
                $new_src = $parsed_url['path'] . $param_str;
            }
            // remove _640x640.jpg from image url filename.jpg_640x640.jpg
            $new_src = preg_replace("/(.+?)(.jpg|.jpeg)(.*)/", "$1$2", $new_src);
            return $new_src;
        }

        public static function get_all_images_from_product($product, $skip = false, $product_images = true, $description_images = true)
        {
            $tmp_all_images = array();

            if ($product_images) {
                foreach ($product['images'] as $img) {
                    $img_id = md5($img);
                    if (!isset($tmp_all_images[$img_id])) {
                        $tmp_all_images[$img_id] = array('image' => $img, 'type' => 'gallery');
                    }
                }
            }

            if (!empty($product['sku_products']['variations'])) {
                foreach ($product['sku_products']['variations'] as $var) {
                    if (isset($var['image'])) {
                        $img_id = md5($var['image']);
                        if (!isset($tmp_all_images[$img_id])) {
                            $tmp_all_images[$img_id] = array('image' => $var['image'], 'type' => 'variant');
                        }
                    }
                }
            }

            if ($description_images && !empty($product['description'])) {
                $desc_images = A2W_Utils::get_images_from_description($product['description']);
                foreach ($desc_images as $img_id => $img) {
                    if (!isset($tmp_all_images[$img_id])) {
                        $tmp_all_images[$img_id] = array('image' => $img, 'type' => 'description');
                    }
                }
            }

            if (!empty($product['sku_products']['attributes'])) {
                foreach ($product['sku_products']['attributes'] as $attribute) {
                    foreach ($attribute['value'] as $attr_value) {
                        $has_variation = false;
                        foreach ($product['sku_products']['variations'] as $variation) {
                            if (!empty($product['skip_vars']) && !in_array($variation['id'], $product['skip_vars'])) {
                                foreach ($variation['attributes'] as $va) {
                                    if ($va == $attr_value['id']) {
                                        $has_variation = true;
                                    }
                                }
                            }
                        }

                        if ($has_variation) {
                            if (a2w_get_setting('use_external_image_urls')) {
                                if (!empty($attr_value['thumb'])) {
                                    $img_id = md5($attr_value['thumb']);
                                    if (!isset($tmp_all_images[$img_id])) {
                                        $tmp_all_images[$img_id] = array('image' => $attr_value['thumb'], 'type' => 'attribute');
                                    }
                                } else if (!empty($attr_value['image'])) {
                                    $img_id = md5($attr_value['image']);
                                    if (!isset($tmp_all_images[$img_id])) {
                                        $tmp_all_images[$img_id] = array('image' => $attr_value['image'], 'type' => 'attribute');
                                    }
                                }
                            } else {
                                if (!empty($attr_value['image'])) {
                                    $img_id = md5($attr_value['image']);
                                    if (!isset($tmp_all_images[$img_id])) {
                                        $tmp_all_images[$img_id] = array('image' => $attr_value['image'], 'type' => 'attribute');
                                    }
                                } else if (!empty($attr_value['thumb'])) {
                                    $img_id = md5($attr_value['thumb']);
                                    if (!isset($tmp_all_images[$img_id])) {
                                        $tmp_all_images[$img_id] = array('image' => $attr_value['thumb'], 'type' => 'attribute');
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if ($skip) {
                foreach ($product['skip_images'] as $img_id) {
                    unset($tmp_all_images[$img_id]);
                }
            }

            return $tmp_all_images;
        }

        public static function get_images_from_description($description)
        {
            $src_result = array();

            if ($description && class_exists('DOMDocument')) {
                $description = htmlspecialchars_decode(utf8_decode(htmlentities($description, ENT_COMPAT, 'UTF-8', false)));

                if (function_exists('libxml_use_internal_errors')) {
                    libxml_use_internal_errors(true);
                }
                $dom = new DOMDocument();
                @$dom->loadHTML($description);
                $dom->formatOutput = true;
                $tags = $dom->getElementsByTagName('img');

                foreach ($tags as $tag) {
                    $src_result[md5($tag->getAttribute('src'))] = $tag->getAttribute('src');
                }
            }

            return $src_result;
        }

        public static function normalizeChars($s)
        {
            $replace = array(
                '??' => '-', '??' => '-', '??' => '-', '??' => '-',
                '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'Ae',
                '??' => 'B',
                '??' => 'C', '??' => 'C', '??' => 'C',
                '??' => 'E', '??' => 'E', '??' => 'E', '??' => 'E', '??' => 'E',
                '??' => 'G',
                '??' => 'I', '??' => 'I', '??' => 'I', '??' => 'I', '??' => 'I',
                '??' => 'L',
                '??' => 'N', '??' => 'N',
                '??' => 'O', '??' => 'O', '??' => 'O', '??' => 'O', '??' => 'O', '??' => 'Oe',
                '??' => 'S', '??' => 'S', '??' => 'S', '??' => 'S',
                '??' => 'T',
                '??' => 'U', '??' => 'U', '??' => 'U', '??' => 'Ue',
                '??' => 'Y',
                '??' => 'Z', '??' => 'Z', '??' => 'Z',
                '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'ae', '??' => 'ae', '??' => 'ae', '??' => 'ae',
                '??' => 'b', '??' => 'b', '??' => 'b', '??' => 'b',
                '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'c', '??' => 'ch', '??' => 'ch',
                '??' => 'd', '??' => 'd', '??' => 'd', '??' => 'd', '??' => 'd', '??' => 'd', '??' => 'D', '??' => 'd',
                '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e',
                '??' => 'f', '??' => 'f', '??' => 'f',
                '??' => 'g', '??' => 'g', '??' => 'g', '??' => 'g', '??' => 'g', '??' => 'g', '??' => 'g', '??' => 'g', '??' => 'g', '??' => 'g', '??' => 'g', '??' => 'g',
                '??' => 'h', '??' => 'h', '??' => 'h', '??' => 'h', '??' => 'h', '??' => 'h', '??' => 'h', '??' => 'h',
                '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'ij', '??' => 'ij',
                '??' => 'j', '??' => 'j', '??' => 'j', '??' => 'j', '??' => 'ja', '??' => 'ja', '??' => 'je', '??' => 'je', '??' => 'jo', '??' => 'jo', '??' => 'ju', '??' => 'ju',
                '??' => 'k', '??' => 'k', '??' => 'k', '??' => 'k', '??' => 'k', '??' => 'k', '??' => 'k',
                '??' => 'l', '??' => 'l', '??' => 'l', '??' => 'l', '??' => 'l', '??' => 'l', '??' => 'l', '??' => 'l', '??' => 'l', '??' => 'l', '??' => 'l', '??' => 'l',
                '??' => 'm', '??' => 'm', '??' => 'm', '??' => 'm',
                '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n', '??' => 'n',
                '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'oe', '??' => 'oe', '??' => 'oe',
                '??' => 'p', '??' => 'p', '??' => 'p', '??' => 'p',
                '??' => 'q',
                '??' => 'r', '??' => 'r', '??' => 'r', '??' => 'r', '??' => 'r', '??' => 'r', '??' => 'r', '??' => 'r', '??' => 'r',
                '??' => 's', '??' => 's', '??' => 's', '??' => 's', '??' => 's', '??' => 's', '??' => 's', '??' => 's', '??' => 's', '??' => 'sch', '??' => 'sch', '??' => 'sh', '??' => 'sh', '??' => 'ss',
                '??' => 't', '??' => 't', '??' => 't', '??' => 't', '??' => 't', '??' => 't', '??' => 't', '??' => 't', '??' => 't', '??' => 't', '??' => 't', '???' => 'tm',
                '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'ue',
                '??' => 'v', '??' => 'v', '??' => 'v',
                '??' => 'w', '??' => 'w', '??' => 'w',
                '??' => 'y', '??' => 'y', '??' => 'y', '??' => 'y', '??' => 'y', '??' => 'y',
                '??' => 'y', '??' => 'z', '??' => 'z', '??' => 'z', '??' => 'z', '??' => 'z', '??' => 'z', '??' => 'z', '??' => 'zh', '??' => 'zh',
            );
            return strtr($s, $replace);
        }

        public static function safeTransliterate($text)
        {

            //for cyrilic first:
            $cyr = array(
                '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??',
                '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??', '??');
            $lat = array(
                'zh', 'ch', 'sht', 'sh', 'yu', 'a', 'b', 'v', 'g', 'd', 'e', 'z', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'u', 'f', 'h', 'c', 'y', 'x', 'q',
                'Zh', 'Ch', 'Sht', 'Sh', 'Yu', 'A', 'B', 'V', 'G', 'D', 'E', 'Z', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'R', 'S', 'T', 'U', 'F', 'H', 'c', 'Y', 'X', 'Q');
            $text = str_replace($cyr, $lat, $text);

            //for Brasilian and Arabic languages:

            //if available, this function uses PHP5.4's transliterate, which is capable of converting arabic, hebrew, greek,
            //chinese, japanese and more into ASCII! however, we use our manual (and crude) fallback *first* instead because
            //we will take the liberty of transliterating some things into more readable ASCII-friendly forms,
            //e.g. "100???" > "100degc" instead of "100oc"

            /* manual transliteration list:
            -------------------------------------------------------------------------------------------------------------- */
            /* this list is supposed to be practical, not comprehensive, representing:
            1. the most common accents and special letters that get typed, and
            2. the most practical transliterations for readability;

            this data was produced with the help of:
            http://www.unicode.org/charts/normalization/
            http://www.yuiblog.com/sandbox/yui/3.3.0pr3/api/text-data-accentfold.js.html
            http://www.utf8-chartable.de/
             */
            static $translit = array(
                'a' => '/[???????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????]/u',
                'b' => '/[??????????????????]/u', 'c' => '/[??????????????????????????]/u',
                'd' => '/[??????????????????????????????????????]/u',
                'e' => '/[??????????????????????????????????????????????????????????????????????????????????????????????????????????????????????]/u',
                'f' => '/[??????]/u', 'g' => '/[??????????????????????????????]/u',
                'h' => '/[?????????????????????????????????????????]/u', 'i' => '/[????????????????????????????????i??????????????????????????????]/u',
                'j' => '/[??????]/u', 'k' => '/[?????????????????????????????]/u',
                'l' => '/[????????????????????????????????????????]/u', 'm' => '/[??????????????????]/u',
                'n' => '/[????????????????????????????????????????????]/u',
                'o' => '/[????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????]/u',
                'p' => '/[????????????]/u', 'r' => '/[????????????????????????????????????????????]/u',
                's' => '/[????????????????????????????????????????????????????]/u', 'ss' => '/[??]/u',
                't' => '/[???????????????????????????????????????]/u', 'th' => '/[????]/u',
                'u' => '/[??????????????????????????????????????????????????????????????????????????????????????????????????????????????????????]/u',
                'v' => '/[????????????]/u', 'w' => '/[?????????????????????????????????????]/u',
                'x' => '/[??????????????]/u', 'y' => '/[?????????????????????????????????????????????????]/u',
                'z' => '/[??????????????????????????????]/u',
                //combined letters and ligatures:
                'ae' => '/[????????????????????]/u', 'oe' => '/[????]/u',
                'dz' => '/[????????????]/u',
                'ff' => '/[???]/u', 'fi' => '/[??????]/u', 'ffl' => '/[??????]/u',
                'ij' => '/[????]/u', 'lj' => '/[??????]/u', 'nj' => '/[??????]/u',
                'st' => '/[??????]/u', 'ue' => '/[????????????????????]/u',
                //currencies:
                'eur' => '/[???]/u', 'cents' => '/[??]/u', 'lira' => '/[???]/u', 'dollars' => '/[$]/u',
                'won' => '/[???]/u', 'rs' => '/[???]/u', 'yen' => '/[??]/u', 'pounds' => '/[??]/u',
                'pts' => '/[???]/u',
                //misc:
                'degc' => '/[???]/u', 'degf' => '/[???]/u',
                'no' => '/[???]/u', 'tm' => '/[???]/u',
            );
            //do the manual transliteration first
            $text = preg_replace(array_values($translit), array_keys($translit), $text);

            //flatten the text down to just a-z0-9 and dash, with underscores instead of spaces
            $text = preg_replace(
                //remove punctuation    //replace non a-z    //deduplicate    //trim underscores from start & end
                array( /*'/\p{P}/u',*//*'/[^_a-z0-9-]/i',*/'/_{2,}/', '/^_|_$/'),
                array( /*'',*//*  '_', */'_', ''),

                //attempt transliteration with PHP5.4's transliteration engine (best):
                //(this method can handle near anything, including converting chinese and arabic letters to ASCII.
                // requires the 'intl' extension to be enabled)
                function_exists('transliterator_transliterate') ? transliterator_transliterate(
                    //split unicode accents and symbols, e.g. "??" > "A??":
                    'NFKD; ' .
                    //convert everything to the Latin charset e.g. "???" > "ma":
                    //(splitting the unicode before transliterating catches some complex cases,
                    // such as: "???" >NFKD> "20???" >Latin> "20ri")
                    'Latin; ' .
                    //because the Latin unicode table still contains a large number of non-pure-A-Z glyphs (e.g. "??"),
                    //convert what remains to an even stricter set of characters, the US-ASCII set:
                    //(we must do this because "Latin/US-ASCII" alone is not able to transliterate non-Latin characters
                    // such as "???". this two-stage method also means we catch awkward characters such as:
                    // "???" >Latin> "k??" >Latin/US-ASCII> "kO")
                    'Latin/US-ASCII; ' .
                    //remove the now stand-alone diacritics from the string
                    '[:Nonspacing Mark:] Remove; ',
                    $text)

                //attempt transliteration with iconv: <php.net/manual/en/function.iconv.php>
                 : (function_exists('iconv') ? str_replace(array("'", '"', '`', '^', '~'), '',
                    //note: results of this are different depending on iconv version,
                    //      sometimes the diacritics are written to the side e.g. "??" = "~n", which are removed
                    iconv('UTF-8', 'US-ASCII//IGNORE//TRANSLIT', $text)
                ) : $text)
            );

            //old iconv versions and certain inputs may cause a nullstring. don't allow a blank response
            return !$text ? '_' : $text;
        }

        public static function get_product_shipping_info($_product, $quantity = 1, $default_country_to = false, $with_vars = true)
        {
            $woocommerce_model = new A2W_Woocommerce();
            $loader = new A2W_Aliexpress();

            $default_ff_method = a2w_get_setting('fulfillment_prefship');
            $product_id = $_product->get_type() == 'variation' ? $_product->get_parent_id() : $_product->get_id();

            $shipping_meta = new A2W_ProductShippingMeta($product_id);

            $product = $woocommerce_model->get_product_by_post_id($product_id, $with_vars);

            $product_country = isset($product['shipping_to_country']) && $product['shipping_to_country'] ? $product['shipping_to_country'] : '';
            $shiping_to_country = $default_country_to ? $default_country_to : $product_country;

            $shiping_from_country = get_post_meta($_product->get_id(), '_a2w_country_code', true);
            $shiping_from_country = empty($shiping_from_country) ? "CN" : $shiping_from_country;

            $items = $shipping_meta->get_items($quantity, $shiping_from_country, $shiping_to_country);

            // Load only if data not in cache
            // TODO: if no items (empty result) then try load again
            if ($shiping_to_country && $items === false/* !empty($items) */) {
                $res = $loader->load_shipping_info($product['id'], $quantity, $shiping_to_country, $shiping_from_country, $product['price'], $product['price']);
                if ($res['state'] !== 'error') {
                    $items = $res['items'];
                    $shipping_meta->save_items($quantity, $shiping_from_country, $shiping_to_country, $items, true);
                }
            }

            $items = !empty($items) ? $items : array();

            $default_method = !empty($product['shipping_default_method']) ? $product['shipping_default_method'] : $default_ff_method;

            $has_shipping_method = false;
            foreach ($items as $item) {
                if ($item['serviceName'] == $default_method) {
                    $has_shipping_method = true;
                    break;
                }
            }

            $current_currency = apply_filters('wcml_price_currency', NULL );
            if (!$has_shipping_method) {
                $default_method = "";
                $tmp_p = -1;
                foreach ($items as $k => $item) {
                    $price = isset($item['previewFreightAmount']['value']) ? $item['previewFreightAmount']['value'] : $item['freightAmount']['value'];
                    $price = apply_filters( 'wcml_raw_price_amount', $price, $current_currency );
                    if ($tmp_p < 0 || $price < $tmp_p || $item['serviceName'] == $default_ff_method) {
                        $tmp_p = $price;
                        $default_method = $item['serviceName'];
                        if ($default_method == $default_ff_method) {
                            break;
                        }
                    }
                }
            }

            $shipping_cost = 0;
            foreach ($items as $item) {
                if ($item['serviceName'] == $default_method) {
                    $shipping_cost = isset($item['previewFreightAmount']['value']) ? $item['previewFreightAmount']['value'] : $item['freightAmount']['value'];
                    $shipping_cost = apply_filters( 'wcml_raw_price_amount', $shipping_cost, $current_currency );
                }
            }

            return array('product_id' => $_product->get_id(), 'default_method' => $default_method, 'items' => $items, 'shipping_cost' => $shipping_cost);
        }

        public static function remove_ship_from($product, $country_from = 'CN')
        {
            $default_country = 'CN';
            $ship_from_attr_value = array();
            $ship_from_attr_name = "";
            foreach ($product['sku_products']['attributes'] as $attr) {
                foreach ($attr['value'] as $attr_val) {
                    if (isset($attr_val['country_code'])) {
                        $ship_from_attr_name = $attr['name'];
                        if ($attr_val['country_code'] === $default_country) {
                            $ship_from_attr_value[$default_country] = $attr_val['id'];
                        } else if ($attr_val['country_code'] === $country_from) {
                            $ship_from_attr_value[$country_from] = $attr_val['id'];
                        }
                    }
                }
            }

            $ship_from_attr_value = empty($ship_from_attr_value) ? false : (isset($ship_from_attr_value[$country_from]) ? $ship_from_attr_value[$country_from] : $ship_from_attr_value[$default_country]);

            if ($ship_from_attr_name && $ship_from_attr_value) {
                $product['disable_add_new_variants'] = true;
                $product['skip_vars'] = array();
                $product['skip_attr'] = array($ship_from_attr_name);
                foreach ($product['sku_products']['variations'] as $v) {
                    if (!in_array($ship_from_attr_value, $v['attributes'])) {
                        $product['skip_vars'][] = $v['id'];
                    }
                }
            }

            return $product;
        }

        public static function update_product_shipping($product, $country_from, $country_to, $page, $update_price)
        {
            $country_from = !empty($country_from) ? $country_from : 'CN';
            $country_from = A2W_ProductShippingMeta::normalize_country($country_from);
            $country_to = A2W_ProductShippingMeta::normalize_country($country_to);

            $country_model = new A2W_Country();

            $shipping_from_country_list = array();
            foreach ($product['sku_products']['variations'] as $var) {
                if (!empty($var['country_code'])) {
                    $shipping_from_country_list[$var['country_code']] = $var['country_code'];
                }
            }

            // TODO experemental
            if (empty($shipping_from_country_list) && isset($product['local_seller_tag']) && strlen($product['local_seller_tag']) == 2) {
                $shipping_from_country_list[$product['local_seller_tag']] = $product['local_seller_tag'];
            }

            $shipping_from_country_list = array_values($shipping_from_country_list);
            $product['shipping_from_country_list'] = $shipping_from_country_list;

            if (count($shipping_from_country_list) > 0 && !in_array($country_from, $shipping_from_country_list)) {
                $country_from = $shipping_from_country_list[0];
            }

            $product['shipping_from_country'] = $country_from;
            if ($c = $country_model->get_country($product['shipping_from_country'])) {
                $product['shipping_from_country_name'] = A2W_ProductShippingMeta::normalize_country($c);
            }

            if (!$country_to) {
                return $product;
            }

            $product['shipping_to_country'] = $country_to;
            if ($c = $country_model->get_country($product['shipping_to_country'])) {
                $product['shipping_to_country_name'] = A2W_ProductShippingMeta::normalize_country($c);
            }

            if ($update_price) {
                $loader = new A2W_Aliexpress();

                $country = A2W_ProductShippingMeta::meta_key($country_from, $country_to);

                if (empty($product['shipping_info'][$country])) {
                    $res = $loader->load_shipping_info($product['id'], 1, $country_to, $country_from, $page == 'import' ? $product['price_min'] : $product['price'], $page == 'import' ? $product['price_max'] : $product['price']);
                    if ($res['state'] !== 'error') {
                        $product['shipping_info'][$country] = $res['items'];
                    } else {
                        $product['shipping_info'][$country] = array();
                    }
                }

                $items = isset($product['shipping_info'][$country]) ? $product['shipping_info'][$country] : array();

                $default_ff_method = a2w_get_setting('fulfillment_prefship');

                if (empty($product['shipping_default_method'])) {
                    $product['shipping_default_method'] = $default_ff_method;
                }

                $has_shipping_method = false;
                foreach ($items as $item) {
                    if ($item['serviceName'] == $product['shipping_default_method']) {
                        $has_shipping_method = true;
                        break;
                    }
                }

                $current_currency = apply_filters('wcml_price_currency', NULL );
                if (!$has_shipping_method) {                    
                    $default_method = "";
                    $tmp_p = -1;
                    foreach ($items as $k => $item) {
                        $price = isset($item['previewFreightAmount']['value']) ? $item['previewFreightAmount']['value'] : $item['freightAmount']['value'];
                        $price = apply_filters( 'wcml_raw_price_amount', $price, $current_currency );
                        if ($tmp_p < 0 || $price < $tmp_p || $item['serviceName'] == $default_ff_method) {
                            $tmp_p = $price;
                            $default_method = $item['serviceName'];
                            if ($default_method == $default_ff_method) {
                                break;
                            }
                        }
                    }
                    $product['shipping_default_method'] = $default_method;
                }

                foreach ($items as $item) {
                    if ($item['serviceName'] == $product['shipping_default_method']) {
                        $product['shipping_cost'] = isset($item['previewFreightAmount']['value']) ? $item['previewFreightAmount']['value'] : $item['freightAmount']['value'];
                        $product['shipping_cost'] = apply_filters( 'wcml_raw_price_amount', $product['shipping_cost'], $current_currency );
                    }
                }
            }

            return $product;
        }

        /**
         * Get phone country code or return list of all country phone codes
         *
         * @param string $country
         *
         * @return mixed|string|null
         */
        public static function get_phone_country_code($country = '')
        {
            $data = array();
            $file = A2W()->plugin_path() . '/assets/data/phone_country_code.json';
            if (file_exists($file)) {
                $data = json_decode(file_get_contents($file), true);
            }
            if ($country) {
                return isset($data[$country]) ? $data[$country] : '';
            } else {
                return $data;
            }
        }

        public static function get_country_by_phone_code($code)
        {
            $data = array();
            $file = A2W()->plugin_path() . '/assets/data/phone_country_code.json';
            if (file_exists($file)) {
                $data = json_decode(file_get_contents($file), true);
            }
            foreach ($data as $country => $c) {
                if ($code === $code) {
                    return $country;
                }

            }
            return '';
        }

        public static function sanitize_phone_number($phone)
        {
            return preg_replace('/[^\d]/', '', $phone);
        }

        public static function wp_kses_post($content)
        {
            $allowed_html = wp_kses_allowed_html('post');
            $allowed_html = array_merge($allowed_html, array(
                'input' => array(
                    'type' => 1,
                    'id' => 1,
                    'name' => 1,
                    'class' => 1,
                    'placeholder' => 1,
                    'autocomplete' => 1,
                    'style' => 1,
                    'value' => 1,
                    'size' => 1,
                    'checked' => 1,
                    'disabled' => 1,
                    'readonly' => 1,
                    'data-*' => 1,
                ),
                'form' => array(
                    'method' => 1,
                    'id' => 1,
                    'class' => 1,
                    'action' => 1,
                    'data-*' => 1,
                ),
                'select' => array(
                    'id' => 1,
                    'name' => 1,
                    'class' => 1,
                    'multiple' => 1,
                    'data-*' => 1,
                ),
                'option' => array(
                    'value' => 1,
                    'selected' => 1,
                    'data-*' => 1,
                ),
            )
            );
            foreach ($allowed_html as $key => $value) {
                if ($key === 'input') {
                    $allowed_html[$key]['data-*'] = 1;
                    $allowed_html[$key]['checked'] = 1;
                    $allowed_html[$key]['disabled'] = 1;
                    $allowed_html[$key]['readonly'] = 1;
                } elseif (in_array($key, array('div', 'span', 'a', 'form', 'select', 'option', 'tr', 'td'))) {
                    $allowed_html[$key]['data-*'] = 1;
                }
            }

            return wp_kses($content, $allowed_html);
        }

    }

}
