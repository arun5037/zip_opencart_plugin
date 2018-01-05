<?php


class ControllerExtensionModuleZipmoney extends Controller {
    
    

    public function index($setting) {
        require_once(DIR_SYSTEM . 'library/zipmoney_util.php');
        $this->document->addScript('https://d3k1w8lx8mqizo.cloudfront.net/lib/js/zm-widget-js/dist/zipmoney-widgets-v1.min.js');

        $zipmoney_mode = $this->config->get('payment_zipmoney_mode');
       // ZipmoneyUtil::log("mode". $zipmoney_mode);


        $environment = ($zipmoney_mode == 'sandbox') ? 'sandbox':'production';

        $merchant_public_key = ($zipmoney_mode == 'sandbox') ? $this->config->get('payment_zipmoney_sandbox_merchant_public_key') : $this->config->get('payment_zipmoney_live_merchant_public_key');

        $setting['environment'] = $environment;
        $setting['merchant_public_key'] = $merchant_public_key;

        return $this->load->view('extension/module/zipmoney_widget', $setting);
    }

    protected function validate() {
        return true;
    }
}