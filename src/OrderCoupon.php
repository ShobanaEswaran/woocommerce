<?php

namespace Rnoc\Retainful;

if (!defined('ABSPATH')) exit;

use Rnoc\Retainful\Admin\Settings;

class OrderCoupon
{
    public $wc_functions, $admin;
    protected static $applied_coupons = NULL;

    function __construct()
    {
        $this->wc_functions = new WcFunctions();
        $this->admin = new Settings();
    }

    /**
     * Validate app Id
     */
    function validateAppKey()
    {
        $app_id = isset($_REQUEST['app_id']) ? $_REQUEST['app_id'] : '';
        $response = array();
        if (empty($app_id)) {
            $response['error'] = __('Please enter App-Id', RNOC_TEXT_DOMAIN);
        }
        if (empty($response)) {
            $is_api_enabled = $this->admin->isApiEnabled($app_id);
            if ($is_api_enabled) {
                $response['success'] = __('Successfully connected to Retainful', RNOC_TEXT_DOMAIN);
            } else {
                $response['error'] = __('Please enter Valid App-Id', RNOC_TEXT_DOMAIN);
            }
        }
        wp_send_json($response);
    }

    /**
     * Init the Admin
     */
    function init()
    {
        $this->admin->renderPage();
    }

    /**
     * Add settings link
     * @param $links
     * @return array
     */
    function pluginActionLinks($links)
    {
        $action_links = array(
            'abandoned_carts' => '<a href="' . admin_url('admin.php?page=retainful_abandoned_cart') . '">' . __('Abandoned carts', RNOC_TEXT_DOMAIN) . '</a>',
            'premium_add_ons' => '<a href="' . admin_url('admin.php?page=retainful_premium') . '">' . __('Add-ons', RNOC_TEXT_DOMAIN) . '</a>',
            'settings' => '<a href="' . admin_url('admin.php?page=retainful_settings') . '">' . __('Settings', RNOC_TEXT_DOMAIN) . '</a>',
            'license' => '<a href="' . admin_url('admin.php?page=retainful_license') . '">' . __('License', RNOC_TEXT_DOMAIN) . '</a>',
        );
        return array_merge($action_links, $links);
    }

    /**
     * Send required details for email customizer
     * @param $order_coupon_data
     * @param $order
     * @param $sending_email
     * @return array
     */
    function wooEmailCustomizerRetainfulCouponContent($order_coupon_data, $order, $sending_email)
    {
        $coupon_code = $this->wc_functions->getOrderMeta($order, '_rnoc_next_order_coupon');
        if (!empty($coupon_code)) {
            $coupon_details = $this->getCouponDetails($coupon_code);
            if (!empty($coupon_details)) {
                $post_id = $coupon_details->ID;
                $coupon_amount = get_post_meta($post_id, 'coupon_value', true);
                if ($coupon_amount > 0) {
                    $is_api_enabled = $this->admin->isAppConnected();
                    $tracker = '';
                    if (!empty($is_api_enabled) && $sending_email) {
                        $request_params = $this->getRequestParams($order);
                        $tracker .= '<img width="1" height="1" src="' . $this->admin->getPixelTagLink('track/pixel.gif', $request_params) . '" />';
                    }
                    $coupon_type = get_post_meta($post_id, 'coupon_type', true);
                    $order_coupon_data = array(
                        'wec_next_order_coupon_code' => $coupon_code,
                        'wec_next_order_coupon' => $coupon_code . $tracker,
                        'wec_next_order_coupon_value' => ($coupon_type) ? $this->wc_functions->formatPrice($coupon_amount) : $coupon_amount . '%',
                        'woo_mb_site_url_link_with_coupon' => site_url() . '?retainful_coupon_code=' . $coupon_code,
                    );
                }
            }
        }
        return $order_coupon_data;
    }

    /**
     * Register retainful short codes with Email Customizer
     * @param $short_codes
     * @return mixed
     */
    function wooEmailCustomizerRegisterRetainfulShortCodes($short_codes)
    {
        if ($this->admin->isAppConnected()) {
            $short_codes['retainful_coupon_expiry_date'] = "Next order coupon expiry date";
        }
        return $short_codes;
    }

    /**
     * assign values for short codes
     * @param $short_codes
     * @param $order
     * @param $sending_email
     * @return mixed
     */
    function wooEmailCustomizerRetainfulShortCodesValues($short_codes, $order, $sending_email)
    {
        if ($this->admin->isAppConnected()) {
            $short_codes['retainful_coupon_expiry_date'] = '';
            $coupon_code = $this->wc_functions->getOrderMeta($order, '_rnoc_next_order_coupon');
            if (!empty($coupon_code)) {
                $coupon_details = $this->getCouponDetails($coupon_code);
                if (!empty($coupon_details)) {
                    $post_id = $coupon_details->ID;
                    $coupon_expiry_date = get_post_meta($post_id, 'coupon_expired_on', true);
                    if (!empty($coupon_expiry_date)) {
                        $date_format = $this->admin->getExpireDateFormat();
                        $short_codes['retainful_coupon_expiry_date'] = $this->formatDate($coupon_expiry_date, $date_format);
                    }
                }
            }
        }
        return $short_codes;
    }

    /**
     * Give url to Email customizer
     * @return mixed
     */
    function wooEmailCustomizerRetainfulSettingsUrl()
    {
        return admin_url("admin.php?page=retainful");
    }

    /**
     * Run the scheduled cron tasks with retainful
     * @param $order_id
     * @return bool
     *
     */
    function cronSendCouponDetails($order_id)
    {
        if (!isset($order_id) || empty($order_id))
            return false;
        $api_key = $this->admin->getApiKey();
        if ($this->admin->isAppConnected() && !empty($api_key)) {
            $order = $this->wc_functions->getOrder($order_id);
            $request_params = $this->getRequestParams($order);
            $request_params['app_id'] = $api_key;
            return $this->admin->sendCouponDetails('track', $request_params);
        }
        return false;
    }

    /**
     * Attach the coupon details to Order
     * @param $order
     * @param $sent_to_admin
     * @param $plain_text
     * @param $email
     * @return bool
     */
    function attachOrderCoupon($order, $sent_to_admin, $plain_text, $email)
    {
        $order_id = $this->wc_functions->getOrderId($order);
        if (!$this->hasValidOrderStatus($order_id)) {
            return false;
        }
        $coupon_code = '';
        if ($this->admin->autoGenerateCouponsForOldOrders()) {
            //Create new coupon if coupon not found for order while sending the email
            $coupon_code = $this->createNewCoupon($order_id, array());
            $this->scheduleSync($order_id);
        }
        if (empty($coupon_code)) {
            $coupon_code = $this->wc_functions->getOrderMeta($order, '_rnoc_next_order_coupon');
        }
        if (!empty($coupon_code)) {
            $message = "";
            $coupon_details = $this->getCouponDetails($coupon_code);
            if (!empty($coupon_details)) {
                $post_id = $coupon_details->ID;
                $coupon_amount = get_post_meta($post_id, 'coupon_value', true);
                if ($coupon_amount > 0) {
                    $coupon_type = get_post_meta($post_id, 'coupon_type', true);
                    $string_to_replace = array(
                        '{{coupon_amount}}' => ($coupon_type) ? $this->wc_functions->formatPrice($coupon_amount) : $coupon_amount . '%',
                        '{{coupon_code}}' => $coupon_code,
                        '{{coupon_url}}' => site_url() . '?retainful_coupon_code=' . $coupon_code
                    );
                    $coupon_expiry_date = get_post_meta($post_id, 'coupon_expired_on', true);
                    if (!empty($coupon_expiry_date)) {
                        $date_format = $this->admin->getExpireDateFormat();
                        $string_to_replace['{{coupon_expiry_date}}'] = $this->formatDate($coupon_expiry_date, $date_format);
                    } else {
                        $string_to_replace['{{coupon_expiry_date}}'] = '';
                    }
                    $message = $this->admin->getCouponMessage();
                    $message = str_replace(array_keys($string_to_replace), $string_to_replace, $message);
                    $is_api_enabled = $this->admin->isAppConnected();
                    if ($is_api_enabled && !$sent_to_admin) {
                        $request_params = $this->getRequestParams($order);
                        if (isset($request_params['new_coupon']) && empty($request_params['new_coupon'])) {
                            $request_params['new_coupon'] = $coupon_code;
                        }
                        $message .= '<img width="1" height="1" src="' . $this->admin->getPixelTagLink('track/pixel.gif', $request_params) . '" />';
                    }
                }
            }
            echo $message;
        }
    }

    /**
     * Format the date
     * @param $date
     * @param string $format
     * @return string|null
     */
    function formatDate($date, $format = "F j, Y, g:i a")
    {
        if (empty($date))
            return NULL;
        if (function_exists('get_date_from_gmt')) {
            return get_date_from_gmt(date('Y-m-d H:i:s', strtotime($date)), $format);
        } else {
            try {
                $date = new \DateTime($date);
                return $date->format($format);
            } catch (\Exception $e) {
                return NULL;
            }
        }
    }

    /**
     * Add coupon to checkout
     */
    public function addCouponToCheckout()
    {
        $coupon_code = $this->wc_functions->getPHPSession('retainful_coupon_code');
        if (!empty($coupon_code) && !empty($this->wc_functions->getCart()) && !$this->wc_functions->hasDiscount($coupon_code)) {
            //Do not apply coupon until the coupon is valid
            if ($this->checkCouponBeforeCouponApply($coupon_code)) {
                $this->wc_functions->addDiscount($coupon_code);
            }
        }
    }

    /**
     * Remove coupon on user request
     * @param $remove_coupon
     */
    function removeCouponFromCart($remove_coupon)
    {
        $coupon_code = $this->wc_functions->getPHPSession('retainful_coupon_code');
        if (strtoupper($remove_coupon) == strtoupper($coupon_code) && $this->checkCouponBeforeCouponApply($remove_coupon)) {
            $this->removeCouponFromSession();
        }
    }

    /**
     * Remove Coupon from session
     */
    function removeCouponFromSession()
    {
        $coupon_code = $this->wc_functions->getPHPSession('retainful_coupon_code');
        if (!empty($coupon_code)) {
            $this->wc_functions->removePHPSession('retainful_coupon_code');
        }
    }

    /**
     * Check that coupon is validated in retainful usage restriction
     * @param $coupon_code
     * @return bool
     */
    public function checkCouponBeforeCouponApply($coupon_code)
    {
        if (empty($coupon_code))
            return false;
        $return = array();
        $coupon_details = $this->isValidCoupon($coupon_code);
        if (!empty($coupon_details)) {
            $usage_restrictions = $this->admin->getUsageRestrictions();
            //Return true if there is any usage restriction
            if (empty($usage_restrictions))
                return true;
            //Check for coupon expired or not
            $coupon_expiry_date = get_post_meta($coupon_details->ID, 'coupon_expired_on', true);
            if (!empty($coupon_expiry_date) && strtotime('Y-m-d H:i:s') > strtotime($coupon_expiry_date)) {
                array_push($return, false);
            }
            $cart_total = $this->wc_functions->getCartTotal();
            //Check for minimum spend
            $minimum_spend = (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'minimum_spend']) && $usage_restrictions[RNOC_PLUGIN_PREFIX . 'minimum_spend'] > 0) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'minimum_spend'] : '';
            if (!empty($minimum_spend) && $cart_total < $minimum_spend) {
                array_push($return, false);
            }
            //Check for maximum spend
            $maximum_spend = (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'maximum_spend']) && $usage_restrictions[RNOC_PLUGIN_PREFIX . 'maximum_spend'] > 0) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'maximum_spend'] : '';
            if (!empty($maximum_spend) && $cart_total > $maximum_spend) {
                array_push($return, false);
            }
            $products_in_cart = $this->wc_functions->getProductIdsInCart();
            //Check the cart having only sale items
            $sale_products_in_cart = $this->wc_functions->getSaleProductIdsInCart();
            if ((count($sale_products_in_cart) >= count($products_in_cart)) && isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_sale_items'])) {
                array_push($return, false);
            }
            //Check for must in cart products
            $must_in_cart_products = (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'products'])) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'products'] : array();
            if (!empty($must_in_cart_products) && count(array_intersect($must_in_cart_products, $products_in_cart)) == 0) {
                array_push($return, false);
            }
            $categories_in_cart = $this->wc_functions->getCategoryIdsOfProductInCart();
            //Check for must in categories of cart
            $must_in_cart_categories = (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'product_categories'])) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'product_categories'] : array();
            if (!empty($must_in_cart_categories) && count(array_intersect($must_in_cart_categories, $categories_in_cart)) == 0) {
                array_push($return, false);
            }
            //Check for must in cart products and exclude products in cart are given
            $must_not_in_cart_products = (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_products'])) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_products'] : array();
            $must_not_in_cart_categories = (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_product_categories'])) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_product_categories'] : array();
            if (array_intersect($must_not_in_cart_products, $must_in_cart_products) || array_intersect($must_in_cart_categories, $must_not_in_cart_categories)) {
                array_push($return, false);
            }
        } else {
            $this->removeCouponFromSession();
        }
        if (in_array(false, $return))
            return false;
        return true;
    }

    /**
     * Save the coupon code to session
     */
    function setCouponToSession()
    {
        if (isset($_REQUEST['retainful_coupon_code'])) {
            $coupon_code = $this->wc_functions->getPHPSession('retainful_coupon_code');
            if (empty($coupon_code)) {
                $coupon_code = sanitize_text_field($_REQUEST['retainful_coupon_code']);
                $this->wc_functions->setPHPSession('retainful_coupon_code', $coupon_code); // Set the coupon code in session
            }
        }
    }

    /**
     * Create the virtual coupon
     * @param $response
     * @param $coupon_code
     * @return array|bool
     */
    function addVirtualCoupon($response, $coupon_code)
    {
        if (empty($coupon_code))
            return $response;
        $coupon_details = $this->isValidCoupon($coupon_code);
        if (!empty($coupon_details)) {
            $is_coupon_already_applied = false;
            if (!empty(self::$applied_coupons) && self::$applied_coupons != $coupon_code)
                $is_coupon_already_applied = true;
            if (isset($coupon_details->ID) && !empty($coupon_details->ID) && !$is_coupon_already_applied) {
                self::$applied_coupons = $coupon_code;
                $discount_type = 'fixed_cart';
                $usage_restrictions = $this->admin->getUsageRestrictions();
                $coupon_type = get_post_meta($coupon_details->ID, 'coupon_type', true);
                $coupon_value = get_post_meta($coupon_details->ID, 'coupon_value', true);
                $coupon_expiry_date = get_post_meta($coupon_details->ID, 'coupon_expired_on', true);
                if ($coupon_type == 0)
                    $discount_type = 'percent';
                $coupon = array(
                    'id' => 321123 . rand(2, 9),
                    'amount' => $coupon_value,
                    'individual_use' => (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'individual_use_only'])) ? true : false,
                    'product_ids' => (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'products'])) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'products'] : array(),
                    'excluded_product_ids' => (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_products'])) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_products'] : array(),
                    //'exclude_product_ids' => (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_products'])) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_products'] : array(),
                    'usage_limit' => '',
                    'usage_limit_per_user' => '',
                    'limit_usage_to_x_items' => '',
                    'usage_count' => '',
                    'expiry_date' => $coupon_expiry_date,
                    'apply_before_tax' => 'yes',
                    'free_shipping' => false,
                    'product_categories' => (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'product_categories'])) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'product_categories'] : array(),
                    'excluded_product_categories' => (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_product_categories'])) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_product_categories'] : array(),
                    //'exclude_product_categories' => (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_product_categories'])) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_product_categories'] : array(),
                    'exclude_sale_items' => (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'exclude_sale_items'])) ? true : false,
                    'minimum_amount' => (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'minimum_spend']) && $usage_restrictions[RNOC_PLUGIN_PREFIX . 'minimum_spend'] > 0) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'minimum_spend'] : '',
                    'maximum_amount' => (isset($usage_restrictions[RNOC_PLUGIN_PREFIX . 'maximum_spend']) && $usage_restrictions[RNOC_PLUGIN_PREFIX . 'maximum_spend'] > 0) ? $usage_restrictions[RNOC_PLUGIN_PREFIX . 'maximum_spend'] : '',
                    'customer_email' => '',
                    'discount_type' => $discount_type,
                    'virtual' => true
                );
                return $coupon;
            }
        }
        return $response;
    }

    /**
     * Process after the Place order
     * @param $order_id
     * @return bool
     */
    function onAfterPayment($order_id)
    {
        if (empty($order_id))
            return false;
        $order = $this->wc_functions->getOrder($order_id);
        $request_params = $this->getRequestParams($order);
        if (isset($request_params['applied_coupon']) && !empty($request_params['applied_coupon'])) {
            $coupon_details = $this->isValidCoupon($request_params['applied_coupon'], $order);
            if (!empty($coupon_details)) {
                $my_post = array(
                    'ID' => $coupon_details->ID,
                    'post_status' => 'expired',
                );
                wp_update_post($my_post);
            }
        }
        $this->scheduleSync($order_id);
        return true;
    }

    /**
     * Schedule sync
     * @param $order_id
     */
    function scheduleSync($order_id)
    {
        $is_api_enabled = $this->admin->isAppConnected();
        if ($is_api_enabled) {
            //Handle API Requests
            $api_key = $this->admin->getApiKey();
            if (!empty($api_key)) {
                as_schedule_single_action(time() + 60, 'retainful_cron_sync_coupon_details', array($order_id));
            }
        }
    }

    /**
     * Check the given Coupon code is valid or not
     * @param $coupon
     * @param null $order
     * @return String|null
     */
    function isValidCoupon($coupon, $order = NULL)
    {
        $coupon_details = $this->getCouponByCouponCode($coupon);
        if (!empty($coupon_details) && $coupon_details->post_status == "publish") {
            $coupon_only_for = $this->admin->couponFor();
            $current_user_id = $current_email = '';
            if ($coupon_only_for != 'all') {
                if (!empty($order)) {
                    $current_user_id = $this->wc_functions->getOrderUserId($order);
                    if ($coupon_only_for != 'login_users') {
                        $current_email = $this->wc_functions->getOrderEmail($order);
                    }
                } else {
                    $current_user_id = get_current_user_id();
                    if ($coupon_only_for != 'login_users') {
                        $current_email = $this->getCurrentEmail();
                    }
                }
            }
            if ($coupon_only_for == 'all') {
                return $coupon_details;
            } else if ($coupon_only_for == 'login_users') {
                $user_id = get_post_meta($coupon_details->ID, 'user_id', true);
                if ($current_user_id == $user_id) return $coupon_details;
            } else {
                $user_id = get_post_meta($coupon_details->ID, 'user_id', true);
                $email = get_post_meta($coupon_details->ID, 'email', true);
                if (!empty($current_user_id) || !empty($current_email)) {
                    if ($current_user_id == $user_id || $current_email == $email)
                        return $coupon_details;
                } else if (empty($current_user_id) && empty($current_email)) {
                    return $coupon_details;
                }
            }
        }
        return NULL;
    }

    /**
     * Get all the params required for API
     * @param $order
     * @return array
     */
    function getRequestParams($order)
    {
        if (empty($order)) return array();
        return array(
            'order_id' => $this->wc_functions->getOrderId($order),
            'email' => $this->wc_functions->getOrderEmail($order),
            'firstname' => $this->wc_functions->getOrderFirstName($order),
            'lastname' => $this->wc_functions->getOrderLastName($order),
            'total' => $this->wc_functions->getOrderTotal($order),
            'new_coupon' => $this->wc_functions->getOrderMeta($order, '_rnoc_next_order_coupon'),
            'applied_coupon' => $this->wc_functions->getOrderMeta($order, '_rnoc_next_order_coupon_applied'),
            'order_date' => strtotime($this->wc_functions->getOrderDate($order))
        );
    }

    /**
     * Check for order has valid order status to generate coupon
     * @param $order_id
     * @return bool
     */
    function hasValidOrderStatus($order_id)
    {
        $valid_order_statuses = $this->admin->getCouponValidOrderStatuses();
        if (!empty($valid_order_statuses)) {
            if (!in_array('all', $valid_order_statuses)) {
                $order = $this->wc_functions->getOrder($order_id);
                $status = 'wc-' . $this->wc_functions->getStatus($order);
                if (!in_array($status, $valid_order_statuses)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Create new coupon
     * @param $order_id
     * @param $data
     * @return bool
     */
    function createNewCoupon($order_id, $data)
    {
        $order_id = sanitize_key($order_id);
        if (empty($order_id)) return false;
        $coupon = $this->isCouponFound($order_id);
        if (!$this->hasValidOrderStatus($order_id)) {
            return NULL;
        }
        $order = $this->wc_functions->getOrder($order_id);
        $email = $this->wc_functions->getOrderEmail($order);
        //Sometime email not found in the order object when order created from backend. So, get  from the request
        if (empty($email)) {
            $email = (isset($_REQUEST['_billing_email']) && !empty($_REQUEST['_billing_email'])) ? $_REQUEST['_billing_email'] : '';
        }
        $order_date = $this->wc_functions->getOrderDate($order);
        if (empty($coupon)) {
            $new_coupon_code = strtoupper(uniqid());
            $new_coupon_code = chunk_split($new_coupon_code, 5, '-');
            $new_coupon_code = rtrim($new_coupon_code, '-');
            $this->addNewCouponToOrder($new_coupon_code, $order_id, $email, $order_date);
        } else {
            $new_coupon_code = strtoupper($coupon);
            $coupon_details = $this->getCouponDetails($new_coupon_code);
            if (empty($coupon_details)) {
                $this->addNewCouponToOrder($new_coupon_code, $order_id, $email, $order_date);
            }
        }
        $new_coupon_code = sanitize_text_field($new_coupon_code);
        if (empty($new_coupon_code))
            return NULL;
        update_post_meta($order_id, '_rnoc_next_order_coupon', $new_coupon_code);
        $used_coupons = $this->wc_functions->getUsedCoupons($order);
        if (!empty($used_coupons)) {
            foreach ($used_coupons as $used_coupon) {
                if (empty($used_coupon))
                    continue;
                $coupon_details = $this->getCouponDetails($used_coupon);
                if (!empty($coupon_details)) {
                    update_post_meta($order_id, '_rnoc_next_order_coupon_applied', strtoupper($used_coupon));
                    update_post_meta($coupon_details->ID, 'applied_for', $order_id);
                }
            }
        }
        return $new_coupon_code;
    }

    /**
     * Get Coupon Details by coupon code
     * @param $coupon
     * @return String|null
     */
    function getCouponDetails($coupon)
    {
        $coupon_details = $this->getCouponByCouponCode($coupon);
        if (!empty($coupon_details)) {
            $coupon_only_for = $this->admin->couponFor();
            if ($coupon_only_for == 'all') {
                return $coupon_details;
            } else if ($coupon_only_for == 'login_users') {
                $user_id = get_post_meta($coupon_details->ID, 'user_id', true);
                $current_user_id = get_current_user_id();
                if ($current_user_id == $user_id) return $coupon_details;
            } else {
                $user_id = get_post_meta($coupon_details->ID, 'user_id', true);
                $email = get_post_meta($coupon_details->ID, 'email', true);
                $current_email = $this->getCurrentEmail();
                $current_user_id = get_current_user_id();
                if ($current_user_id == $user_id || $current_email == $email) return $coupon_details;
            }
        }
        return NULL;
    }

    /**
     * Get Order Email
     * @return mixed|string|null
     */
    function getCurrentEmail()
    {
        $postData = isset($_REQUEST['post_data']) ? $_REQUEST['post_data'] : '';
        $postDataArray = array();
        if (is_string($postData) && $postData != '') {
            parse_str($postData, $postDataArray);
        }
        $postBillingEmail = isset($_REQUEST['billing_email']) ? $_REQUEST['billing_email'] : '';
        if ($postBillingEmail != '') {
            $postDataArray['billing_email'] = $postBillingEmail;
        }
        if (!get_current_user_id()) {
            $order_id = isset($_REQUEST['order-received']) ? $_REQUEST['order-received'] : 0;
            if ($order_id) {
                $order = $this->wc_functions->getOrder($order_id);
                $postDataArray['billing_email'] = $this->wc_functions->getOrderEmail($order);
            }
        }
        $user_email = '';
        if (isset($postDataArray['billing_email']) && $postDataArray['billing_email'] != '') {
            $user_email = $postDataArray['billing_email'];
        } else if (get_current_user_id()) {
            $user_email = get_user_meta(get_current_user_id(), 'billing_email', true);
            if ($user_email != '' && !empty($user_email)) {
                return $user_email;
            } else {
                $user_details = get_userdata(get_current_user_id());
                if (isset($user_details->data->user_email) && $user_details->data->user_email != '') {
                    $user_email = $user_details->data->user_email;
                    return $user_email;
                }
            }
        }
        return sanitize_email($user_email);
    }

    /**
     * Save Coupon to order
     * @param $new_coupon_code
     * @param $order_id
     * @param $email
     * @param $order_date
     * @return int|\WP_Error
     */
    function addNewCouponToOrder($new_coupon_code, $order_id, $email, $order_date)
    {
        $new_coupon_code = sanitize_text_field($new_coupon_code);
        $order_id = sanitize_text_field($order_id);
        $email = sanitize_email($email);
        if (empty($new_coupon_code) || empty($order_id) || empty($email))
            return NULL;
        $post = array(
            'post_title' => $new_coupon_code,
            'post_name' => $new_coupon_code . '-' . $order_id,
            'post_content' => 'Virtual coupon code created through Retainful Next order coupon',
            'post_type' => 'rnoc_order_coupon',
            'post_status' => 'publish'
        );
        $id = wp_insert_post($post, true);
        if ($id) {
            $settings = $this->admin->getCouponSettings();
            add_post_meta($id, 'order_id', $order_id);
            add_post_meta($id, 'email', $email);
            $user_id = get_current_user_id();
            add_post_meta($id, 'user_id', $user_id);
            add_post_meta($id, 'coupon_type', isset($settings['coupon_type']) ? sanitize_text_field($settings['coupon_type']) : '0');
            add_post_meta($id, 'coupon_value', isset($settings['coupon_amount']) ? sanitize_text_field($settings['coupon_amount']) : '0');
            add_post_meta($id, 'coupon_expired_on', $this->admin->getCouponExpireDate($order_date));
        }
        return $id;
    }

    /**
     * Check is coupon found
     * @param $order_id
     * @return String|null
     */
    function isCouponFound($order_id)
    {
        if (empty($order_id)) return NULL;
        $post_args = array('post_type' => 'rnoc_order_coupon', 'numberposts' => '1', 'post_status' => 'publish', 'meta_key' => 'order_id', 'meta_value' => $order_id);
        $posts = get_posts($post_args);
        if (!empty($posts)) {
            foreach ($posts as $post) {
                if (isset($post->ID)) {
                    $post_order_id = get_post_meta($post->ID, 'order_id', true);
                    if (($post_order_id == $order_id) && isset($post->post_title)) {
                        return $post->post_title;
                    }
                }
            }
        }
        return NULL;
    }

    /**
     * @param $coupon_code
     * @return Object|null
     */
    function getCouponByCouponCode($coupon_code)
    {
        $coupon_code = sanitize_text_field($coupon_code);
        if (empty($coupon_code)) return NULL;
        $post_args = array('post_type' => 'rnoc_order_coupon', 'numberposts' => '1', 'title' => strtoupper($coupon_code));
        $posts = get_posts($post_args);
        if (!empty($posts)) {
            foreach ($posts as $post) {
                if (strtoupper($post->post_title) == strtoupper($coupon_code)) {
                    return $post;
                }
            }
        }
        return NULL;
    }
}