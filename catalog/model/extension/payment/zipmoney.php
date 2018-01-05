<?php
class ModelExtensionPaymentZipmoney extends Model {

    public function __construct($registry)
    {
        parent::__construct($registry);

        require_once(DIR_SYSTEM . 'library/zipmoney_util.php');

        ZipmoneyUtil::initialMerchantApi($this->config);
    }

    public function getWidgetJs() {
        return 'https://static.zipmoney.com.au/checkout/checkout-v1.js';
    }

    /**
     * The method will be called on the checkout page
     *
     * @param $address
     * @param $total
     * @return array
     */
    public function getMethod($address, $total) {

        if(strtoupper($this->session->data['currency']) != 'AUD'){
            return array();
        }

        if($total < $this->config->get('payment_zipmoney_minimum_order_total')){
            return array();
        }

        $method_data = array(
            'code'       => 'zipmoney',
            'terms'      => '',
            'title'      => $this->config->get('payment_zipmoney_title'),
            'sort_order' => $this->config->get('payment_zipmoney_sort_order')
        );

        return $method_data;
    }

    public function initChargeApi()
    {
        return new \zipMoney\Api\ChargesApi();
    }


    public function createCharge($checkoutId, \zipMoney\Api\ChargesApi $chargesApi)
    {
        $response = array(
            'success' => false,
            'message' => ''
        );

        $this->load->model('checkout/order');

        $zipmoneyOrder = ZipmoneyUtil::getZipMoneyObjectById($this->db, $checkoutId);

        $checkoutObject = json_decode($zipmoneyOrder['checkout_object'], true);

        if(empty($zipmoneyOrder) || empty($checkoutObject)){
            //return null if it can't find anything
            return null;
        }

        ZipmoneyUtil::log('Creating charge ...');

        try{
            $body = self::prepareRequestForCharge($checkoutObject);

            ZipmoneyUtil::log('Request creating charge: ' . json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($body)));

            $charge = $chargesApi->chargesCreate($body, ZipmoneyUtil::get_uuid());

            ZipmoneyUtil::log('Received charge response: ' . json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($charge)));

            //update the charge_id
            ZipmoneyUtil::setChargeIdToCheckout($this->db, $checkoutId, $charge->getId());

            if($charge->getState() == 'captured'){
                $this->model_checkout_order->addOrderHistory(
                    $zipmoneyOrder['order_id'],
                    ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_PROCESSING),
                    sprintf("Checkout id: %s, Charge id: %s", $checkoutId, $charge->getId())
                );

                //Create a transaction
                ZipmoneyUtil::createTransaction(
                    $this->db,
                    $zipmoneyOrder['order_id'],
                    ZipmoneyUtil::TRANSACTION_TYPE_CHARGE,
                    $charge->getId(),
                    $charge->getAmount()
                );

                $response['success'] = true;
            } elseif($charge->getState() == 'authorised'){
                $this->model_checkout_order->addOrderHistory(
                    $zipmoneyOrder['order_id'],
                    ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_AUTHORIZED),
                    sprintf("Checkout id: %s, Charge id: %s", $checkoutId, $charge->getId())
                );

                $response['success'] = true;
            } else {
                $response['message'] = 'Unable to create charge. The charge state is: ' . $charge->getState();
            }
        } catch (\zipMoney\ApiException $exception) {
            ZipmoneyUtil::log($exception->getCode() . $exception->getMessage());
            ZipmoneyUtil::log($exception->getResponseBody());

            $response['message'] = ZipmoneyUtil::handleCreateChargeApiException($exception);
        } catch (Exception $exception) {
            ZipmoneyUtil::log($exception->getCode() . $exception->getMessage());

            $response['message'] = $exception->getMessage();
        }

        return $response;
    }


    private function prepareRequestForCharge($checkout)
    {
        //get the charge order
        $chargeOrder = self::createChargeOrder($checkout);

        //get authority
        $authority = new \zipMoney\Model\Authority(
            array(
                'type' => 'checkout_id',
                'value' => $checkout['id']
            )
        );

        $shouldCapture = $this->config->get('payment_zipmoney_charge_capture_option') == ZipmoneyUtil::CHARGE_OPTION_CAPTURE_IMMEDIATELY ? true :false;

        return new \zipMoney\Model\CreateChargeRequest(array(
            'authority' => $authority,
            'amount' => ZipmoneyUtil::roundNumber($checkout['order']['amount']),
            'currency' => strtoupper($checkout['order']['currency']),
            'order' => $chargeOrder,
            'capture' => $shouldCapture
        ));
    }


    private function createChargeOrder($checkout)
    {
        //shipping address
        $shipping_array = $checkout['order']['shipping']['address'];
        $shippingAddress = new \zipMoney\Model\Address(
            array(
                'line1' => $shipping_array['line1'],
               // 'line2' => $shipping_array['line2'],
                'city' => $shipping_array['city'],
                'state' => $shipping_array['state'],
                'postal_code' => $shipping_array['postal_code'],
                'country' => $shipping_array['country'],
                'first_na/me' => $shipping_array['first_name'],
                'last_name' => $shipping_array['last_name']
            )
        );
        $orderShipping = new \zipMoney\Model\OrderShipping(
            array(
                'address' => $shippingAddress
            )
        );

        //order item
        $items = array();
        foreach($checkout['order']['items'] as $item){
            $items[] = new \zipMoney\Model\OrderItem($item);
        }

        return new \zipMoney\Model\ChargeOrder(array(
            'shipping' => $orderShipping,
            'items' => $items
        ));
    }

    public function initCheckoutApi(){
        return new \zipMoney\Api\CheckoutsApi();
    }


    /**
     * Create the checkout for API request
     *
     * @param $sessionData
     * @param $cart
     * @param \zipMoney\Api\CheckoutsApi $checkoutsApi
     * @return null|\zipMoney\Model\Checkout
     */
    public function createCheckout($order, $cart, \zipMoney\Api\CheckoutsApi $checkoutsApi)
    {
        ZipmoneyUtil::log('Creating checkout...');

        $body = self::prepareRequestForCheckout($order, $cart);

        ZipmoneyUtil::log('Request checkout: ' . json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($body)));

        try {
            $checkout = $checkoutsApi->checkoutsCreate($body);

            ZipmoneyUtil::log('Receive checkout: ' . json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($checkout)));

            //set checkout id to zipmoney table
            ZipmoneyUtil::setCheckoutIdToOrder(
                $this->db,
                $order['order_id'],
                $checkout->getId(),
                json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($checkout))
            );

            return $checkout;

        } catch (\zipMoney\ApiException $exception) {
            ZipmoneyUtil::log($exception->getCode() . $exception->getMessage());
            ZipmoneyUtil::log($exception->getResponseBody());
        } catch (Exception $exception) {
            ZipmoneyUtil::log($exception->getCode() . $exception->getMessage());
        }

        return null;
    }

    private function prepareRequestForCheckout($order, $cart)
    {
        //config object
        $checkoutConfiguration = new \zipMoney\Model\CheckoutConfiguration(array(
            'redirect_uri' => $this->url->link('extension/payment/zipmoney/complete', '', true)
        ));

        //Create checkout request
        return new \zipMoney\Model\CreateCheckoutRequest(
            array(
                'shopper' => self::createShopper($order),
                'order' => self::createCheckoutOrder($order, $cart),
                'config' => $checkoutConfiguration
            )
        );
    }


    private function createCheckoutOrder($order, $cart)
    {
       // $shippingAddress = empty($order['shipping_address1']) ? $order['payment_address'] : $sessionData['shipping_address'];

        $orderShipping = new \zipMoney\Model\OrderShipping(array(
            'address' => self::createAddress($order,"shipping"),
            'pickup' => false
        ));

        //create checkout order
        $order_items = self::getOrderItems($order, $cart);

        $total = self::getOrderTotalInTotal($order['order_id']);

        $checkoutOrder = new \zipMoney\Model\CheckoutOrder(array(
            'amount' => ZipmoneyUtil::roundNumber($total['value']),
            'currency' => strtoupper($order['currency_code']),
            'shipping' => $orderShipping,
            'items' => $order_items
        ));

        return $checkoutOrder;
    }

    private function getOrderItems($order, $cart)
    {
        $orderItems = array();

        $this->load->model('catalog/product');
        $this->load->model('tool/image');
        $this->load->model('extension/total/coupon');
        $this->load->model('account/order');

        $cartProducts = $cart->getProducts();

        //get order item
        foreach ($cartProducts as $cartProduct) {
            $product = $this->model_catalog_product->getProduct($cartProduct['product_id']);
            $price = $this->tax->calculate($cartProduct['price'], $product['tax_class_id'], $this->config->get('config_tax'));
            $orderItemData = array(
                'name' => $product['name'],
                'amount' => round($price,2),//$cartProduct['price'],
                'description' => trim(strip_tags(htmlspecialchars_decode($product['description']))),
                'quantity' => $cartProduct['quantity'],
                'type' => 'sku',
                'item_uri' => $this->url->link('product/product', array('product_id' => $cartProduct['product_id']), true),
                'product_code' => strval($cartProduct['product_id'])
            );

            if (!empty($product['image'])) {

                if ($this->request->server['HTTPS']) {
                    $orderItemData['image_uri'] = $this->config->get('config_ssl') . 'image/' . $product['image'];
                } else {
                    $orderItemData['image_uri'] = $this->config->get('config_url') . 'image/' . $product['image'];
                }
            }

            $orderItems[] = new \zipMoney\Model\OrderItem($orderItemData);
        }

        //get discount
        $coupons = self::getCouponInTotal($order['order_id']);
        if (!empty($coupons)) {
            foreach($coupons as $coupon){
                $orderItems[] = new \zipMoney\Model\OrderItem(
                    array(
                        'name' => $coupon['title'],
                        'amount' => round(floatval($coupon['value']),2),
                        'quantity' => 1,
                        'type' => 'discount'
                    )
                );
            }
        }

        //get voucher
        $vouchers = self::getVouchersInTotal($order['order_id']);
        if(!empty($vouchers)){
            foreach($vouchers as $voucher){
                $orderItems[] = new \zipMoney\Model\OrderItem(
                    array(
                        'name' => $voucher['title'],
                        'amount' => round(floatval($voucher['value']),2),
                        'quantity' => 1,
                        'type' => 'discount'
                    )
                );
            }
        }

        $order_totals = $this->model_account_order->getOrderTotals($order['order_id']);

        foreach ($order_totals as $key => $total) {
            if($total['code']=="shipping"){
                $shipping = $total;
            }
        }
        //get shipping
        if (!empty($order['shipping_method'])) {
            $price = $this->tax->calculate($this->session->data['shipping_method']['cost'], $this->session->data['shipping_method']['tax_class_id'], $this->config->get('config_tax'));

            $orderItems[] = new \zipMoney\Model\OrderItem(
                array(
                    'name' => $shipping['title'],
                    'amount' => round(floatval($price),2),
                    'quantity' => 1,
                    'type' => 'shipping'
                )
            );
        }

        //get Tax for products
        // $taxes = $cart->getTaxes();
        // if(!empty($taxes)){
        //     foreach($cart->getTaxes() as $taxValue){
        //         $orderItems[] = new \zipMoney\Model\OrderItem(
        //             array(
        //                 'name' => 'Product Tax',
        //                 'amount' => floatval($taxValue),
        //                 'quantity' => 1,
        //                 'type' => 'tax'
        //             )
        //         );
        //     }
        // }

        //get Tax for Shipping tax
        // if ($this->session->data['shipping_method']['tax_class_id']) {
        //     $tax_rates = $this->tax->getRates($this->session->data['shipping_method']['cost'], $this->session->data['shipping_method']['tax_class_id']);

        //     $shippingTax = 0;

        //     foreach ($tax_rates as $tax_rate) {
        //         $shippingTax += $tax_rate['amount'];
        //     }

        //     $orderItems[] = new \zipMoney\Model\OrderItem(
        //         array(
        //             'name' => 'Shipping Tax',
        //             'amount' => $shippingTax,
        //             'quantity' => 1,
        //             'type' => 'tax'
        //         )
        //     );
        // }

        return $orderItems;
    }


    /**
     * Create shopper object
     *
     * @param $sessionData
     * @return \zipMoney\Model\Shopper
     */
    private function createShopper($order)
    {       

        ZipmoneyUtil::log($order);

        $data = array(
            'first_name' => $order['payment_firstname'],
            'last_name' => $order['payment_lastname']
        );

        if($this->customer->isLogged()){
            $data['email'] = $this->customer->getEmail();
            $phone = $this->customer->getTelephone();

        } else {
            $data['email'] = $order['email'];
            $phone = $order['telephone'];
        }

        if(!empty($phone)){
            $data['phone'] = $phone;
        }

        $data['billing_address'] = self::createAddress($order,"payment");

        return new \zipMoney\Model\Shopper($data);
    }


    /**
     * Create billing address object
     *
     * @param $billingAddress
     * @return \zipMoney\Model\Address
     */
    private function createAddress($order,$type="payment")
    {          
        $this->load->model('localisation/zone');
        $this->load->model('localisation/country');

        $zone_info = $this->model_localisation_zone->getZone($order[$type.'_zone_id']);
        $country = $this->model_localisation_country->getCountry($order[$type.'_country_id']);

        return new \zipMoney\Model\Address(array(
            'line1' => $order[$type.'_address_1'],
            'line2' => $order[$type.'_address_2'],
            'city' => $order[$type.'_city'],
            'state' => $zone_info['code'],
            'postal_code' => $order[$type.'_postcode'],
            'country' => $country['iso_code_2'],
            'first_name' => $order[$type.'_firstname'],
            'last_name' => $order[$type.'_lastname']
        ));
    }

    private function getCouponInTotal($orderId)
    {
        return $this->db->query(sprintf("SELECT * FROM `%sorder_total` WHERE `order_id` = %s AND code = 'coupon'", DB_PREFIX, $orderId))->rows;
    }

    private function getOrderTotalInTotal($orderId)
    {
        return $this->db->query(sprintf("SELECT * FROM `%sorder_total` WHERE `order_id` = %s AND code = 'total'", DB_PREFIX, $orderId))->row;
    }

    private function getVouchersInTotal($orderId)
    {
        return $this->db->query(sprintf("SELECT * FROM `%sorder_total` WHERE `order_id` = %s AND code = 'voucher'", DB_PREFIX, $orderId))->rows;
    }


}
