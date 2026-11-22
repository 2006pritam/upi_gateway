<?php
/**
 * Plugin Name: e-Mudra UPI Gateway for WooCommerce
 * Description: Accept UPI payments through your e-Mudra gateway.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 */
if (! defined('ABSPATH')) {
    exit;
}

add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Gateway_EMudra';

    return $gateways;
});

add_action('plugins_loaded', function () {
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_EMudra extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'emudra';
            $this->method_title = 'e-Mudra UPI';
            $this->method_description = 'Pay with any UPI app. Webhook URL: '.WC()->api_request_url('emudra_webhook');
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title', 'UPI (GPay, PhonePe, Paytm, BHIM)');
            $this->enabled = $this->get_option('enabled');
            add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_emudra_webhook', [$this, 'webhook']);
            add_action('woocommerce_api_emudra_return', [$this, 'return_page']);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => ['title' => 'Enable', 'type' => 'checkbox', 'label' => 'Enable e-Mudra UPI', 'default' => 'no'],
                'title' => ['title' => 'Title', 'type' => 'text', 'default' => 'UPI (GPay, PhonePe, Paytm, BHIM)'],
                'base_url' => ['title' => 'Gateway URL', 'type' => 'text', 'default' => '__GATEWAY_URL__'],
                'api_key' => ['title' => 'API key', 'type' => 'text'],
                'api_secret' => ['title' => 'API secret', 'type' => 'password', 'description' => 'Also signs webhooks. Set the webhook URL (shown above) under API & Webhooks in your gateway.'],
            ];
        }

        private function call($method, $path, $body = null)
        {
            $res = wp_remote_request(rtrim($this->get_option('base_url'), '/').$path, [
                'method' => $method,
                'timeout' => 20,
                'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Api-Key' => $this->get_option('api_key'), 'X-Api-Secret' => $this->get_option('api_secret')],
                'body' => $body ? wp_json_encode($body) : null,
            ]);
            if (is_wp_error($res)) {
                return null;
            }
            $json = json_decode(wp_remote_retrieve_body($res), true);

            return (is_array($json) && ! empty($json['success'])) ? $json['data'] : null;
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $body = [
                'amount' => number_format((float) $order->get_total(), 2, '.', ''),
                // Unique per attempt: a customer may retry the same WooCommerce order.
                'client_order_id' => 'WC'.$order_id.'-'.time(),
                'customer_name' => trim($order->get_billing_first_name().' '.$order->get_billing_last_name()),
                'customer_email' => $order->get_billing_email(),
                'customer_mobile' => substr(preg_replace('/\D/', '', (string) $order->get_billing_phone()), -15),
                'note' => substr('Order #'.$order->get_order_number(), 0, 50),
                // Needs this site's domain under "Redirect domains" in the gateway settings.
                'redirect_url' => add_query_arg(['wc_order' => $order_id, 'key' => $order->get_order_key()], WC()->api_request_url('emudra_return')),
            ];
            $data = $this->call('POST', '/api/v1/orders', $body);
            if (! $data) {
                // Retry without redirect_url in case the domain is not allow-listed yet.
                unset($body['redirect_url']);
                $data = $this->call('POST', '/api/v1/orders', $body);
            }
            if (! $data) {
                wc_add_notice('Could not start the UPI payment. Please try again.', 'error');

                return ['result' => 'failure'];
            }
            $order->update_meta_data('_emudra_order_id', $data['order_id']);
            $order->save();
            $order->update_status('pending', 'Waiting for UPI payment '.$data['order_id']);

            return ['result' => 'success', 'redirect' => $data['payment_url']];
        }

        /** Never trusts query params: asks the gateway whether the order is really paid. */
        private function settle($order)
        {
            $gw = $order->get_meta('_emudra_order_id');
            if (! $gw || $order->is_paid()) {
                return $order->is_paid();
            }
            $data = $this->call('GET', '/api/v1/orders/'.rawurlencode($gw));
            if ($data && $data['status'] === 'paid' && abs((float) $data['amount'] - (float) $order->get_total()) < 0.01) {
                $order->payment_complete($data['utr'] ?: $gw);
                $order->add_order_note('Paid via e-Mudra. UTR: '.($data['utr'] ?: 'n/a'));

                return true;
            }

            return false;
        }

        public function return_page()
        {
            $order = wc_get_order((int) ($_GET['wc_order'] ?? 0));
            if (! $order || ! hash_equals($order->get_order_key(), (string) ($_GET['key'] ?? ''))) {
                wp_die('Invalid order', '', ['response' => 400]);
            }
            $this->settle($order);
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        public function webhook()
        {
            $raw = file_get_contents('php://input');
            $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
            if (! hash_equals(hash_hmac('sha256', $raw, $this->get_option('api_secret')), $sig)) {
                status_header(401);
                exit;
            }
            $event = json_decode($raw, true);
            $cid = $event['data']['client_order_id'] ?? '';
            if (($event['event'] ?? '') === 'order.paid' && preg_match('/^WC(\d+)-\d+$/', $cid, $m)) {
                $order = wc_get_order((int) $m[1]);
                if ($order) {
                    $this->settle($order);
                }
            }
            status_header(200);
            exit;
        }
    }
});
