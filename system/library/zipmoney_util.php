<?php
class ZipmoneyUtil {
    public static $logger = null;

    const DB_TABLE_ZIPMONEY_ORDER = 'zipmoney_order';
    const DB_TABLE_ZIPMONEY_TRANSACTION = 'zipmoney_transaction';
    const DB_TABLE_ZIPMONEY_SETTING = 'zipmoney_setting';

    const ORDER_STATUS_PENDING = 'pending';
    const ORDER_STATUS_AUTHORIZED = 'authorized';
    const ORDER_STATUS_PROCESSING = 'processing';
    const ORDER_STATUS_PARTIAL_REFUND = 'partial_refund';
    const ORDER_STATUS_REFUND = 'refund';
    const ORDER_STATUS_COMPLETE = 'complete';
    const ORDER_STATUS_CANCELLED = 'cancelled';

    const TRANSACTION_TYPE_CHARGE = 'charge';
    const TRANSACTION_TYPE_REFUND = 'refund';

    const CHARGE_OPTION_CAPTURE_IMMEDIATELY = 1;
    const CHARGE_OPTION_AUTHORIZED = 2;

    public static function initialMerchantApi($config)
    {
        require_once(DIR_SYSTEM . 'library/zipmoney/autoload.php');

        //set the api key
        if ($config->get('payment_zipmoney_mode') == 'sandbox') {
            \zipMoney\Configuration::getDefaultConfiguration()->setEnvironment('sandbox');
            \zipMoney\Configuration::getDefaultConfiguration()->setApiKey('Authorization', 'Bearer ' . $config->get('payment_zipmoney_sandbox_merchant_private_key'));
        } else {
            \zipMoney\Configuration::getDefaultConfiguration()->setEnvironment('production');
            \zipMoney\Configuration::getDefaultConfiguration()->setApiKey('Authorization', 'Bearer ' . $config->get('payment_zipmoney_live_merchant_private_key'));
        }

        //set the platform string
        \zipMoney\Configuration::getDefaultConfiguration()->setPlatform(
            sprintf('Opencart/%s zipMoney/%s', VERSION, '1.0.0')
        );
    }

    /**
     * Extract error message
     *
     * @param \zipMoney\ApiException $exception
     * @return string
     */
    public static function handleCreateChargeApiException(\zipMoney\ApiException $exception)
    {
        $error_codes_map = array(
            "account_insufficient_funds" => "OC-0001",
            "account_inoperative" => "OC-0002",
            "account_locked" => "OC-0003",
            "amount_invalid" => "OC-0004",
            "fraud_check" => "OC-0005"
        );

        $error_code = 0;

        $response_object = $exception->getResponseObject();
        if(!empty($response_object)){
            $error_code = $response_object->getError()->getCode();
        }

        if($exception->getCode() == 402 && !empty($error_codes_map[$error_code])){
            $message = sprintf('The payment was declined by Zip.(%s)', $error_codes_map[$error_code]);
        } else {
            $message = $exception->getMessage();
        }

        return $message;
    }

    public static function roundNumber($number)
    {
        return round($number, 2);
    }

    /**
     * Log the message
     *
     * @param $data
     */
    public static function log($data)
    {
        if (is_array($data) || is_object($data)) {
            $data = print_r($data, true);
        }

        if (is_null(self::$logger)) {
            self::$logger = new Log('zipmoney.log');
        }

        self::$logger->write($data);
    }

    /**
     * Generate the uuid
     *
     * @return string
     */
    public static function get_uuid()
    {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }


    public static function getZipMoneyObjectById($db, $checkoutId)
    {
        $result = $db->query(sprintf("SELECT * FROM %s WHERE checkout_id = '%s' LIMIT 1", DB_PREFIX . self::DB_TABLE_ZIPMONEY_ORDER, $checkoutId))->row;

        if (empty($result) || empty($result['checkout_object'])) {
            return null;
        } else {
            return $result;
        }
    }


    public static function getZipMoneyOrderByOrderId($db, $orderId)
    {
        return $db->query(sprintf("SELECT * FROM %s WHERE order_id = '%s' LIMIT 1", DB_PREFIX . self::DB_TABLE_ZIPMONEY_ORDER, $orderId))->row;
    }


    //get the status id from zipmoney_setting table
    public static function getOrderStatusId($db, $key)
    {
        $result = $db->query(sprintf("SELECT * FROM %s WHERE zipmoney_setting_type = 'status' AND `zipmoney_setting_key` = '%s' LIMIT 1", DB_PREFIX . self::DB_TABLE_ZIPMONEY_SETTING, $key))->row;

        if(empty($result) || empty($result['zipmoney_setting_value'])){
            return null;
        }

        return $result['zipmoney_setting_value'];
    }

    /**
     * Create a transaction
     *
     * @param $db
     * @param $orderId
     * @param $type
     * @param $id
     * @param $amount
     */
    public static function createTransaction($db, $orderId, $type, $id, $amount)
    {
        $db->query(sprintf(
            "INSERT INTO %s (`order_id`, `type`, `id`, `amount`, `date_created`) VALUES ('%s', '%s', '%s', %s, '%s')",
            DB_PREFIX . self::DB_TABLE_ZIPMONEY_TRANSACTION,
            $orderId,
            $type,
            $id,
            $amount,
            date('Y-m-d H:i:s', time())
        ));
    }


    public static function setChargeIdToCheckout($db, $checkoutId, $chargeId)
    {
        $db->query(sprintf("UPDATE %s SET charge_id='%s' WHERE checkout_id='%s'", DB_PREFIX . self::DB_TABLE_ZIPMONEY_ORDER, $chargeId, $checkoutId));
    }


    /**
     * Set the checkout id to order
     *
     * @param $db
     * @param $orderId
     * @param $checkoutId
     * @param $checkoutObject
     */
    public static function setCheckoutIdToOrder($db, $orderId, $checkoutId, $checkoutObject)
    {
        //check the order id is inserted or not
        $zipmoneyOrder = $db->query(sprintf("SELECT * FROM %s WHERE order_id = %s", DB_PREFIX . self::DB_TABLE_ZIPMONEY_ORDER, $orderId))->row;

        if (empty($zipmoneyOrder)) {
            //if there is no record for it
            $db->query(
                sprintf("INSERT INTO %s (order_id, checkout_id, checkout_object) VALUES (%s, '%s', '%s')",
                    DB_PREFIX . self::DB_TABLE_ZIPMONEY_ORDER,
                    $orderId,
                    $checkoutId,
                    $db->escape($checkoutObject)
                )
            );
        } else {
            $db->query(
                sprintf("UPDATE %s set checkout_id = '%s', checkout_object = '%s' WHERE order_id = %s",
                    DB_PREFIX . self::DB_TABLE_ZIPMONEY_ORDER,
                    $checkoutId,
                    $db->escape($checkoutObject),
                    $orderId
                )
            );
        }
    }

    public static function getOrderTransactions($db, $orderId)
    {
        return $db->query(sprintf("SELECT * FROM %s WHERE order_id = %s ORDER BY date_created ASC", DB_PREFIX . self::DB_TABLE_ZIPMONEY_TRANSACTION, $orderId))->rows;
    }


    public static function getOrderAvailableFund($db, $orderId)
    {
        $result = $db->query(sprintf("SELECT SUM(amount) as sum_amount FROM %s WHERE order_id = %s", DB_PREFIX . self::DB_TABLE_ZIPMONEY_TRANSACTION, $orderId))->row;

        if(empty($result['sum_amount'])){
            return 0;
        }

        return $result['sum_amount'];
    }


    public static function addOrderHistory($orderId, $orderStatusId, $comment, $config_secure, $notify = false)
    {
        $link = $config_secure ? HTTPS_CATALOG : HTTP_CATALOG;
        $link .= 'index.php?route=extension/payment/zipmoney/addOrderHistory';

        self::log($link);

        $post = [
            'order_id' => $orderId,
            'order_status_id' => $orderStatusId,
            'comment'   => $comment,
            'notify' => $notify
        ];

        self::log($post);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $link);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $response = curl_exec($ch);
        self::log($response);
        curl_close($ch);
    }
}