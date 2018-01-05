<?php
class ModelExtensionPaymentZipmoney extends Model {

    public function __construct($registry)
    {
        parent::__construct($registry);

        require_once(DIR_SYSTEM . 'library/zipmoney_util.php');
        ZipmoneyUtil::initialMerchantApi($this->config);
    }

    public function install() {
        //create the setting table
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "zipmoney_setting` (
                  `zipmoney_setting_id` int(11) NOT NULL AUTO_INCREMENT,
                  `zipmoney_setting_type` VARCHAR(128) NOT NULL,
                  `zipmoney_setting_key` VARCHAR(128) NOT NULL,
                  `zipmoney_setting_value` VARCHAR(128) DEFAULT NULL,
                  PRIMARY KEY (`zipmoney_setting_id`)
                ) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci
        ");

        //check if the order status has been set and insert the required status
        $getStatusScript = "SELECT * FROM %s WHERE `language_id` = '%s' AND `name` = '%s' LIMIT 1;";
        $insertStatusScript = "INSERT INTO %s (`language_id`, `name`) VALUES ('%s', '%s');";
        $insertSettingScript = "INSERT INTO %s (`zipmoney_setting_type`, `zipmoney_setting_key`, `zipmoney_setting_value`) VALUES ('status', '%s', '%s')";
        $requiredStatuses = array(
            'pending' => 'Pending',
            'authorized' => 'Authorized',
            'processing' => 'Processing',
            'refund' => 'Refunded',
            'complete' => 'Complete',
            'cancelled' => 'Canceled'
        );
        $language_id = $this->config->get('config_language_id');
        foreach ($requiredStatuses as $key => $name){
            $status = $this->db->query(sprintf($getStatusScript, DB_PREFIX . 'order_status', $language_id, $name))->row;
            if(empty($status)){
                $this->db->query(sprintf($insertStatusScript, DB_PREFIX . 'order_status', $language_id, $name));
                $status_id = $this->db->getLastId();
                $this->db->query(sprintf($insertSettingScript, DB_PREFIX . 'zipmoney_setting', $key, $status_id));
            } else {
                $this->db->query(sprintf($insertSettingScript, DB_PREFIX . 'zipmoney_setting', $key, $status['order_status_id']));
            }
        }
        $this->cache->delete('order_status');
        //add the processing status
        $this->load->model('setting/setting');
        $processingStatuses = $this->config->get('config_processing_status');
        if (empty($processingStatuses)) {
            $processingStatuses = array();
        }
        $zipProcessingStatusId = ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_PROCESSING);

        if (!in_array($zipProcessingStatusId, $processingStatuses)) {
            $processingStatuses[] = $zipProcessingStatusId;

            if(!$this->config->get('config_processing_status')){
                $setingSql = sprintf("INSERT INTO %s (`code`, `key`, `value`) VALUES ('config', '%s', '%s')", DB_PREFIX . 'setting', 'config_processing_status', $processingStatuses);
            } else {
                $setingSql = sprintf("UPDATE  %s  SET `value` = '%s' WHERE key='%s'", DB_PREFIX . 'setting', 'config_processing_status', $processingStatuses);
            }
            
            $this->db->query($setingSql);

        }
        //add the complete status
        $completeStatuses = $this->config->get('config_complete_status');

        if (empty($completeStatuses)) {
            $completeStatuses = array();
        }
        $zipCompleteStatusId = ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_COMPLETE);
        if (!in_array($zipCompleteStatusId, $completeStatuses)) {
            $completeStatuses[] = $zipCompleteStatusId;

            if(!$this->config->get('config_complete_status')){
                $setingSql = sprintf("INSERT INTO %s (`code`, `key`, `value`) VALUES ('config', '%s', '%s')", DB_PREFIX . 'setting', 'config_complete_status', $processingStatuses);
            } else {
                $setingSql = sprintf("UPDATE  %s  SET `value` = '%s' WHERE key='%s'", DB_PREFIX . 'setting', 'config_complete_status', $completeStatuses);
            }
            
            $this->db->query($setingSql);
        }

        //create the zipmoney checkout
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "zipmoney_order` (
              `zipmoney_order_id` int(11) NOT NULL AUTO_INCREMENT,
              `order_id` int(11) NOT NULL,
              `checkout_id` VARCHAR(128) DEFAULT NULL,
              `charge_id` VARCHAR(128) DEFAULT NULL,
              `checkout_object` TEXT DEFAULT NULL,
              PRIMARY KEY (`zipmoney_order_id`)
            ) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci
        ");

        //create the transactions
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "zipmoney_transaction` (
              `zipmoney_transaction_id` INT(11) NOT NULL AUTO_INCREMENT,
              `order_id` INT(11) NOT NULL,
              `type` varchar(255),
              `id` varchar(255),
              `date_created` DATETIME NOT NULL,
              `amount` DECIMAL( 10, 2 ) NOT NULL,
              PRIMARY KEY (`zipmoney_transaction_id`)
            ) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;
            ");

    }

    public function uninstall()
    {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "zipmoney_order`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "zipmoney_transaction`");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "zipmoney_setting`");
    }


    public function initChargesApi()
    {
        return new \zipMoney\Api\ChargesApi();
    }


    public function cancel($orderId, \zipMoney\Api\ChargesApi $chargesApi)
    {
        $result = array(
            'result' => false
        );

        try {
            //get order detail
            $this->load->model('sale/order');
            $order = $this->model_sale_order->getOrder($orderId);

            if (empty($order)) {
                //unable to find the order
                throw new Exception('ERROR: Unable to find order: ' . $orderId);
            }

            if ($order['order_status_id'] != ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_AUTHORIZED)) {
                //if it's not processing status, then we won't do refund
                throw new Exception('ERROR: The order: ' . $orderId . ' is not in authorised state.');
            }

            //get the zipmoney order record
            $zipMoneyOrder = ZipmoneyUtil::getZipMoneyOrderByOrderId($this->db, $orderId);

            ZipmoneyUtil::log('Cancelling charge...');

            $body = self::getCaptureChargeRequestBody($zipMoneyOrder);

            ZipmoneyUtil::log('Request cancelling charge: ' . json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($body)));

            $charge = $chargesApi->chargesCancel($zipMoneyOrder['charge_id'], ZipmoneyUtil::get_uuid());

            ZipmoneyUtil::log('Received cancelling charge response: ' . json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($charge)));

            if ($charge->getState() == 'cancelled') {
                ZipmoneyUtil::log('Charge cancelled. charge_id: ' . $charge->getId());

                ZipmoneyUtil::addOrderHistory(
                    $orderId,
                    ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_CANCELLED),
                    "Charge has been successfully cancelled. charge_id: " . $charge->getId(),
                    $this->config->get('config_secure'),
                    true
                );

                $result['result'] = true;
            } else {
                ZipmoneyUtil::log('Failed to cancel charge. charge_id: ' . $charge->getId());
                $result['message'] = 'Failed to cancel charge';
            }

        } catch (\zipMoney\ApiException $exception) {
            ZipmoneyUtil::log($exception->getCode() . $exception->getMessage());
            ZipmoneyUtil::log($exception->getResponseBody());

            $result['message'] = $exception->getCode() . $exception->getMessage();
        } catch (Exception $exception) {
            ZipmoneyUtil::log($exception->getCode() . $exception->getMessage());

            $result['message'] = $exception->getCode() . $exception->getMessage();
        }

        return $result;
    }



    /**
     * Capture charge
     *
     * @param $orderId
     * @return array
     */
    public function capture($orderId, \zipMoney\Api\ChargesApi $chargesApi)
    {
        $result = array(
            'result' => false
        );

        try {
            //get order detail
            $this->load->model('sale/order');
            $order = $this->model_sale_order->getOrder($orderId);

            if (empty($order)) {
                //unable to find the order
                throw new Exception('ERROR: Unable to find order: ' . $orderId);
            }

            if ($order['order_status_id'] != ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_AUTHORIZED)) {
                //if it's not processing status, then we won't do refund
                throw new Exception('ERROR: The order: ' . $orderId . ' is not in authorised state.');
            }

            //get the zipmoney order record
            $zipMoneyOrder = ZipmoneyUtil::getZipMoneyOrderByOrderId($this->db, $orderId);

            ZipmoneyUtil::log('Capturing charge...');

            $body = self::getCaptureChargeRequestBody($zipMoneyOrder);

            ZipmoneyUtil::log('Request capturing charge: ' . json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($body)));

            $charge = $chargesApi->chargesCapture($zipMoneyOrder['charge_id'], $body, ZipmoneyUtil::get_uuid());

            ZipmoneyUtil::log('Received capturing charge response: ' . json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($charge)));

            if ($charge->getState() == 'captured') {
                ZipmoneyUtil::log('Has captured. charge_id: ' . $charge->getId());

                ZipmoneyUtil::addOrderHistory(
                    $orderId,
                    ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_PROCESSING),
                    "Order has been successfully charge. charge_id: " . $charge->getId(),
                    $this->config->get('config_secure'),
                    true
                );

                //Create a transaction
                ZipmoneyUtil::createTransaction(
                    $this->db,
                    $orderId,
                    ZipmoneyUtil::TRANSACTION_TYPE_CHARGE,
                    $charge->getId(),
                    $charge->getAmount()
                );

                $result['result'] = true;
            } else {
                ZipmoneyUtil::log('Charge failed. charge_id: ' . $charge->getId());
                $result['message'] = 'Charge failed';
            }

        } catch (\zipMoney\ApiException $exception) {
            ZipmoneyUtil::log($exception->getCode() . $exception->getMessage());
            ZipmoneyUtil::log($exception->getResponseBody());

            $result['message'] = $exception->getCode() . $exception->getMessage();
        } catch (Exception $exception) {
            ZipmoneyUtil::log($exception->getCode() . $exception->getMessage());

            $result['message'] = $exception->getCode() . $exception->getMessage();
        }

        return $result;
    }


    private function getCaptureChargeRequestBody($zipMoneyOrder)
    {
        $checkoutObject = json_decode($zipMoneyOrder['checkout_object'], true);

        if (empty($zipMoneyOrder) || empty($checkoutObject)) {
            //return null if it can't find anything
            throw new Exception('Unable to find checkout object');
        }

        return new \zipMoney\Model\CaptureChargeRequest(array('amount' => ZipmoneyUtil::roundNumber($checkoutObject['order']['amount'])));
    }


    public function initRefundApi()
    {
        return new \zipMoney\Api\RefundsApi();
    }

    public function refund($orderId, $refundAmount, $refundReason, \zipMoney\Api\RefundsApi $refundsApi, $notify = false)
    {
        $result = array(
            'result' => false
        );

        ZipmoneyUtil::log('Creating refund...');
        try {

            //get order detail
            $this->load->model('sale/order');
            $order = $this->model_sale_order->getOrder($orderId);

            if (empty($order)) {
                //unable to find the order
                throw new Exception('ERROR: Unable to find order: ' . $orderId);
            }

            if ($order['order_status_id'] != ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_PROCESSING)) {
                //if it's not processing status, then we won't do refund
                throw new Exception('ERROR: The order: ' . $orderId . ' is not in processing state.');
            }

            //get the zipmoney order record
            $zipMoneyOrder = ZipmoneyUtil::getZipMoneyOrderByOrderId($this->db, $orderId);

            //create the refund object
            $createRefundRequest = self::getRefundRequestBody($zipMoneyOrder, $refundReason, $refundAmount);

            ZipmoneyUtil::log('Request refund: ' . json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($createRefundRequest)));

            $refundResponse = $refundsApi->refundsCreate($createRefundRequest, ZipmoneyUtil::get_uuid());
            ZipmoneyUtil::log('Received refund response: ' . json_encode(\zipMoney\ObjectSerializer::sanitizeForSerialization($createRefundRequest)));

            //create a transaction
            ZipmoneyUtil::createTransaction($this->db, $orderId, ZipmoneyUtil::TRANSACTION_TYPE_REFUND, $refundResponse->getId(), $refundAmount * -1);

            //create a history record
            ZipmoneyUtil::addOrderHistory(
                $orderId,
                ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_PROCESSING),
                sprintf("Order has been refunded with amount $%s, reason: %s. Refund id: %s", $refundAmount, $refundReason, $refundResponse->getId()),
                $this->config->get('config_secure'),
                $notify
            );

            if (ZipmoneyUtil::getOrderAvailableFund($this->db, $orderId) <= 0) {
                //update the order status to refunded if there is full refunded
                ZipmoneyUtil::addOrderHistory(
                    $orderId,
                    ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_REFUND),
                    "Order has been fully refunded",
                    $this->config->get('config_secure'),
                    $notify
                );
            }

            $result['result'] = true;
        } catch (\zipMoney\ApiException $exception) {
            ZipmoneyUtil::log($exception->getCode() . $exception->getMessage());
            ZipmoneyUtil::log($exception->getResponseBody());

            $result['message'] = $exception->getCode() . $exception->getMessage();
        } catch (Exception $exception) {
            ZipmoneyUtil::log($exception->getCode() . $exception->getMessage());

            $result['message'] = $exception->getCode() . $exception->getMessage();
        }

        return $result;
    }


    private function getRefundRequestBody($zipMoneyOrder, $refundReason, $refundAmount)
    {
        return new \zipMoney\Model\CreateRefundRequest(array(
            'charge_id' => $zipMoneyOrder['charge_id'],
            'reason' => $refundReason,
            'amount' => $refundAmount
        ));
    }


    public function getTransactions($orderId)
    {
        return ZipmoneyUtil::getOrderTransactions($this->db, $orderId);
    }

    public function getOrderAuthorizedStatusId()
    {
        return ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_AUTHORIZED);
    }

    public function getOrderProcessingStatusId()
    {
        return ZipmoneyUtil::getOrderStatusId($this->db, ZipmoneyUtil::ORDER_STATUS_PROCESSING);
    }

    public function getOrderAvailableFund($orderId)
    {
        return ZipmoneyUtil::getOrderAvailableFund($this->db, $orderId);
    }
}