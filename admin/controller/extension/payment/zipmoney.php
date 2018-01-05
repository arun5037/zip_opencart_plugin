<?php

require_once(DIR_SYSTEM . 'library/zipmoney_util.php');

class ControllerExtensionPaymentZipmoney extends Controller {
    private $error = array();

    public function index() {

        $this->load->language('extension/payment/zipmoney');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        $this->load->model('extension/payment/zipmoney');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
        	 ZipmoneyUtil::log($this->request->post);
            $this->model_setting_setting->editSetting('payment_zipmoney', $this->request->post);
             
             
            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $data['heading_title'] = $this->language->get('heading_title');

        //set the title keys
        $data_title_keys = array(
            'text_title'
        );
        foreach($data_title_keys as $data_title_key){
            $data[$data_title_key] = $this->language->get($data_title_key);
        }

        //set the values
        $data_value_keys = array(
            'payment_zipmoney_status' => '0',
            'payment_zipmoney_title' => 'ZipMoney, Buy Now, Pay Later',
            'payment_zipmoney_mode' => 'sandbox',
            'payment_zipmoney_sandbox_merchant_public_key' => '',
            'payment_zipmoney_sandbox_merchant_private_key' => '',
            'payment_zipmoney_live_merchant_public_key' => '',
            'payment_zipmoney_live_merchant_private_key' => '',
            'payment_zipmoney_product' => 'zipPay',
            'payment_zipmoney_charge_capture_option' => 1,
            'payment_zipmoney_log_message_level' => 1,
            'payment_zipmoney_iframe_checkout' => 0,
            'payment_zipmoney_minimum_order_total' => 1.0,
            'payment_zipmoney_sort_order' => 1
        );


        //use a for-loop to set the values
        foreach ($data_value_keys as $key => $value) {
            //set the values
            if (isset($this->request->post[$key])) {
                $data[$key] = $this->request->post[$key];
                
            } elseif ($this->config->get($key)) {
                $data[$key] = $this->config->get($key);
                
            } else {
                $data[$key] = $value;
                
            }
        }
        
 
        //set teh error values for form validation
        $error_value_keys = array(
            'error_zipmoney_sandbox_merchant_public_key',
            'error_zipmoney_sandbox_merchant_private_key',
            'error_zipmoney_live_merchant_public_key',
            'error_zipmoney_live_merchant_private_key',
            'error_warning'
        );
        foreach ($error_value_keys as $error_value_key) {
            $data[$error_value_key] = isset($this->error[$error_value_key]) ? $this->error[$error_value_key] : '';
        }


        $data['text_edit'] = $this->language->get('text_edit');

        $data['button_cancel'] = $this->language->get('button_cancel');
        $data['button_save'] = $this->language->get('button_save');

    
        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true),
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true),
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/zipmoney', 'user_token=' . $this->session->data['user_token'], true),
        );

        $data['action'] = $this->url->link('extension/payment/zipmoney', 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/zipmoney', $data));
    }

    public function install() {
        $this->load->model('extension/payment/zipmoney');

       // $this->load->model('extension/event');
        $this->model_extension_payment_zipmoney->install();
    }

    public function uninstall() {
        $this->load->model('extension/payment/zipmoney');
       // $this->load->model('extension/event');
        $this->model_extension_payment_zipmoney->uninstall();
    }

    public function order(){
        if ($this->config->get('payment_zipmoney_status')) {

            $this->load->model('extension/payment/zipmoney');
            $this->load->model('sale/order');


            if (isset($this->request->get['order_id'])) {
                $order_id = $this->request->get['order_id'];
            } else {
                $order_id = 0;
            }

           

            $data = array();
            $data['transactions'] = $this->model_extension_payment_zipmoney->getTransactions($this->request->get['order_id']);
            $data['available_refund_amount'] = $this->model_extension_payment_zipmoney->getOrderAvailableFund($this->request->get['order_id']);

            
            $data['order_info'] = $this->model_sale_order->getOrder($this->request->get['order_id']);

            $data['authorized_status_id'] = $this->model_extension_payment_zipmoney->getOrderAuthorizedStatusId($this->request->get['order_id']);
            $data['processing_status_id'] = $this->model_extension_payment_zipmoney->getOrderProcessingStatusId($this->request->get['order_id']);
            
            

            $data['refund_url'] = $this->url->link('extension/payment/zipmoney/refund', 'user_token=' . $this->session->data['user_token'], true);
            $data['capture_url'] = $this->url->link('extension/payment/zipmoney/capture', 'user_token=' . $this->session->data['user_token'], true);
            $data['cancel_url'] = $this->url->link('extension/payment/zipmoney/cancel', 'user_token=' . $this->session->data['user_token'], true);

            return $this->load->view('extension/payment/zipmoney_order', $data);
        }
    }

    public function refund()
    {
        $response = array(
            'result' => false
        );

        //check the required fields
        if (empty($this->request->post['order_id'])) {
            $response['message'] = 'ERROR: order id is not set';
        }
        if (empty($this->request->post['refund_amount'])) {
            $response['message'] = 'ERROR: Invalid refund amount';
        }

        if (!empty($response['message'])) {
            $this->response->setOutput(json_encode($response));
        } else {
            $this->load->model('extension/payment/zipmoney');

            if (empty($this->request->post['refund_reason'])) {
                $this->request->post['refund_reason'] = 'No reason';
            }

            $refundApi = $this->model_extension_payment_zipmoney->initRefundApi();
            $result = $this->model_extension_payment_zipmoney->refund(
                $this->request->post['order_id'],
                $this->request->post['refund_amount'],
                $this->request->post['refund_reason'],
                $refundApi,
                $this->request->post['zip_notify']
            );
            
            ZipmoneyUtil::log( $result);
            $this->response->setOutput(json_encode($result));
        }
    }


    public function cancel()
    {
        $response = array(
            'result' => false
        );

        //check the required fields
        if (empty($this->request->post['order_id'])) {
            $response['message'] = 'ERROR: order id is not set';
        }

        if (!empty($response['message'])) {
            $this->response->setOutput(json_encode($response));
        } else {
            $this->load->model('extension/payment/zipmoney');

            $chargesApi = $this->model_extension_payment_zipmoney->initChargesApi();
            $result = $this->model_extension_payment_zipmoney->cancel(
                $this->request->post['order_id'],
                $chargesApi
            );

            $this->response->setOutput(json_encode($result));
        }
    }

    public function capture()
    {
        $response = array(
            'result' => false
        );

        //check the required fields
        if (empty($this->request->post['order_id'])) {
            $response['message'] = 'ERROR: order id is not set';
        }

        if (!empty($response['message'])) {
            $this->response->setOutput(json_encode($response));
        } else {
            $this->load->model('extension/payment/zipmoney');

            $chargesApi = $this->model_extension_payment_zipmoney->initChargesApi();
            $result = $this->model_extension_payment_zipmoney->capture(
                $this->request->post['order_id'],
                $chargesApi
            );

            $this->response->setOutput(json_encode($result));
        }
    }

    protected function validate() {
        $this->load->model('localisation/currency');

        if (!$this->user->hasPermission('modify', 'extension/payment/zipmoney')) {
            $this->error['warning'] = 'You do not have permissions to modify this module';
        }

        if (!$this->error) {
            return true;
        } else {
            return false;
        }
    }
}