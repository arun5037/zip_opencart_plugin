<?php
class ControllerExtensionPaymentZipmoney extends Controller {
    public function index() {
        $data['button_continue'] = 'Continue';
        $data['text_loading'] = 'Loading...';

        $data['iframe_checkout'] = $this->config->get('zipmoney_iframe_checkout');
        $data['checkout_uri'] = $this->url->link('extension/payment/zipmoney/checkout', '', true);
        $data['redirect_uri'] = $this->url->link('extension/payment/zipmoney/complete', '', true);

        return $this->load->view('extension/payment/zipmoney_confirm', $data);
    }

    public function checkout()
    {
        $this->load->model('extension/payment/zipmoney');
        $this->load->model('checkout/order');

        if ($this->config->get('zipmoney_minimum_order_total') > $this->cart->getTotal()) {
            $this->failure(sprintf('Minimum order amount for zipMoney is %s!', $this->currency->format($this->config->get('zipmoney_minimum_order_total'), $this->session->data['currency'])));
        }

        if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout'))) {
            $this->response->redirect($this->url->link('checkout/cart'));
        }

        $order_id = $this->session->data['order_id'];
        $order_data = $this->model_checkout_order->getOrder($order_id);
        $checkoutApi = $this->model_extension_payment_zipmoney->initCheckoutApi();
        $checkout = $this->model_extension_payment_zipmoney->createCheckout($order_data, $this->cart, $checkoutApi);


        $this->response->addHeader('Content-Type: application/json');

        if (empty($checkout)) {
            $this->session->data['error_warning'] = 'Can not redirect to zipMoney.';

            //if there are something wrong
            $this->response->setOutput(json_encode(array(
                'message' => 'Can not redirect to zipMoney.',
                'redirect_uri' => $this->url->link('checkout/checkout', '', true),
                'success' => false
            )));
        } else {
            $this->response->setOutput(json_encode(array(
                'message' => 'Redirecting to zipMoney.',
                'redirect_uri' => $checkout->getUri(),
                'success' => true,
                'checkout_id' => $checkout->getId()
            )));
        }
    }


    public function addOrderHistory()
    {
        $requiredFields = array(
            'order_id',
            'order_status_id',
            'comment'
        );

        if(empty($this->request->post)){
            return '';
        }

        foreach($requiredFields as $requiredField){
            if(empty($this->request->post[$requiredField])){
                return '';
            }
        }

        $this->load->model('checkout/order');
        $this->model_checkout_order->addOrderHistory(
            $this->request->post['order_id'],
            $this->request->post['order_status_id'],
            $this->request->post['comment'],
            empty($this->request->post['notify']) ? false : true
        );

        return '';
    }


    /**
     * Create charge
     */
    public function complete()
    {
        $result = array(
            'result' => false
        );

        //validate the parameters
        if(isset($_GET['result']) == false || isset($_GET['checkoutId']) == false){
            $result['title'] = 'Invalid request';
            $result['content'] = 'There are some parameters missing in the request url.';
            return self::result($result);
        }

        $this->load->model('extension/payment/zipmoney');

        switch ($_GET['result']){
            case 'approved':
                $chargeApi = $this->model_extension_payment_zipmoney->initChargeApi();
                $result = $this->model_extension_payment_zipmoney->createCharge($_GET['checkoutId'], $chargeApi);

                if ($result['success'] == true){
                    $this->response->redirect($this->url->link('checkout/success', '', true));
                    exit;
                } else {
                    $result['title'] = 'Error';
                    $result['content'] = $result['message'];
                }
                break;
            case 'referred':
                $result['title'] = 'The payment is in referred state';
                $result['content'] = 'Your application is currently under review by zipMoney and will be processed very shortly. You can contact the customer care at customercare@zipmoney.com.au for any enquiries.';
                break;
            case 'declined':
                $result['title'] = 'The checkout is declined';
                $result['content'] = 'Your application has been declined by zipMoney. Please contact zipMoney for further information.';
                break;
            case 'cancelled':
                $result['title'] = 'The checkout has been cancelled';
                $result['content'] = 'The checkout has bee cancelled.';
                break;
        }

        return self::result($result);

    }

    /**
     *
     *
     * @param $values   =>      array(
     *                              'title' => '',
     *                              'content' => ''
     *                          )
     * @return mixed
     */
    public function result($values)
    {
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $data['title'] = $values['title'];
        $data['content'] = $values['content'];

        return $this->response->setOutput($this->load->view('extension/payment/zipmoney_result', $data));
    }

}
