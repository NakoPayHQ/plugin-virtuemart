<?php
/**
 * NakoPay Payment Plugin for VirtueMart
 *
 * @package     VirtueMart
 * @subpackage  Payment
 * @license     MIT
 * @link        https://nakopay.com/docs/integrations/virtuemart
 * @version     1.0.0
 */

defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
    require(VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php');
}

require_once __DIR__ . '/nakopay/NakoPayApi.php';
require_once __DIR__ . '/nakopay/NakoPayWebhook.php';

class plgVmPaymentNakopay extends vmPSPlugin
{
    /**
     * @var string Plugin version
     */
    private static $version = '1.0.0';

    /**
     * Constructor
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable   = true;
        $this->_tablepkey   = 'id';
        $this->_tableId     = 'id';
        $this->tableFields  = array_keys($this->getTableSQLFields());
        $this->setConfigParameterable($this->_configTableFieldName, $this->getVarsToPush());
    }

    /**
     * Create the payment table if it does not exist
     */
    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment NakoPay Table');
    }

    /**
     * Table fields for order-payment mapping
     */
    public function getTableSQLFields()
    {
        return [
            'id'                          => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(11) UNSIGNED',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(5000)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'smallint(1)',
            'nakopay_invoice_id'          => 'varchar(64)',
            'nakopay_checkout_url'        => 'text',
            'nakopay_status'              => 'varchar(32)',
        ];
    }

    /**
     * Redirect customer to NakoPay hosted checkout
     */
    public function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $session = JFactory::getSession();
        $returnContext = $session->getId();

        $orderDetails = $order['details']['BT'];
        $orderNumber  = $orderDetails->order_number;

        // Build callback URLs
        $returnUrl  = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginResponseReceived&pm=' . $orderDetails->virtuemart_paymentmethod_id . '&on=' . $orderNumber);
        $cancelUrl  = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $orderNumber . '&pm=' . $orderDetails->virtuemart_paymentmethod_id);
        $notifyUrl  = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&pm=nakopay');

        // Currency
        if (!class_exists('CurrencyDisplay')) {
            require(VMPATH_ADMIN . DS . 'helpers' . DS . 'currencydisplay.php');
        }
        $currencyModel = VmModel::getModel('currency');
        $currencyObj   = $currencyModel->getCurrency($orderDetails->order_currency);
        $currencyCode  = $currencyObj->currency_code_3;

        // Amount in major units
        $amount = round($orderDetails->order_total, 2);

        // Create NakoPay invoice
        $api = new NakoPayApi($method->nakopay_api_key, !empty($method->nakopay_sandbox));

        $invoiceData = $api->createInvoice([
            'amount'      => $amount,
            'currency'    => $currencyCode,
            'description' => 'VirtueMart order ' . $orderNumber,
            'metadata'    => [
                'virtuemart_order_number' => $orderNumber,
                'source'                  => 'virtuemart',
            ],
            'redirect_url' => $returnUrl,
            'customer'     => [
                'email' => $orderDetails->email,
            ],
        ]);

        if (!$invoiceData || empty($invoiceData['checkout_url'])) {
            vmError('NakoPay: Failed to create invoice for order ' . $orderNumber);
            return false;
        }

        // Save to payment table
        $dbValues = [
            'order_number'                => $orderNumber,
            'virtuemart_order_id'         => $orderDetails->virtuemart_order_id,
            'virtuemart_paymentmethod_id' => $orderDetails->virtuemart_paymentmethod_id,
            'payment_name'                => $this->renderPluginName($method),
            'payment_order_total'         => $amount,
            'payment_currency'            => $orderDetails->order_currency,
            'nakopay_invoice_id'          => $invoiceData['id'],
            'nakopay_checkout_url'        => $invoiceData['checkout_url'],
            'nakopay_status'              => 'pending',
        ];
        $this->storePSPluginInternalData($dbValues);

        // Update order status to pending
        $modelOrder = VmModel::getModel('orders');
        $order['order_status']      = $this->getNewStatus($method);
        $order['customer_notified'] = 1;
        $order['comments']          = 'NakoPay invoice created: ' . $invoiceData['id'];
        $modelOrder->updateStatusForOneOrder($orderDetails->virtuemart_order_id, $order, true);

        // Redirect to NakoPay checkout
        $app = JFactory::getApplication();
        $app->redirect($invoiceData['checkout_url']);
    }

    /**
     * Handle webhook notification from NakoPay
     */
    public function plgVmOnPaymentNotification()
    {
        $input = JFactory::getApplication()->input;
        if ($input->get('pm') !== 'nakopay') {
            return null;
        }

        $body    = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '';

        // Find the payment method to get webhook secret
        $virtuemart_paymentmethod_id = $this->getPaymentMethodId();
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            http_response_code(400);
            echo json_encode(['error' => 'Payment method not found']);
            return null;
        }

        // Verify webhook signature
        $verifier = new NakoPayWebhook($method->nakopay_webhook_secret);
        $event    = $verifier->verify($body, $sigHeader);

        if (!$event) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid webhook signature']);
            return null;
        }

        $invoiceData   = $event['data'] ?? [];
        $invoiceId     = $invoiceData['id'] ?? null;
        $orderNumber   = $invoiceData['metadata']['virtuemart_order_number'] ?? null;

        if (!$orderNumber) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing order number in metadata']);
            return null;
        }

        // Map event type to VirtueMart order status
        $eventType = $event['type'] ?? '';
        $statusMap = [
            'invoice.paid'     => 'C',  // Confirmed
            'invoice.expired'  => 'X',  // Cancelled
            'invoice.refunded' => 'R',  // Refunded
        ];

        $newStatus = $statusMap[$eventType] ?? null;
        if (!$newStatus) {
            http_response_code(200);
            echo json_encode(['ok' => true, 'skipped' => true]);
            return null;
        }

        // Update VirtueMart order
        $modelOrder = VmModel::getModel('orders');
        $orderId    = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNumber);

        if (!$orderId) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            return null;
        }

        $order = [
            'order_status'      => $newStatus,
            'customer_notified' => 1,
            'comments'          => 'NakoPay webhook: ' . $eventType . ' (invoice ' . $invoiceId . ')',
        ];
        $modelOrder->updateStatusForOneOrder($orderId, $order, true);

        // Update internal payment table
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->update($this->_tablename)
            ->set('nakopay_status = ' . $db->quote($eventType))
            ->where('order_number = ' . $db->quote($orderNumber));
        $db->setQuery($query);
        $db->execute();

        http_response_code(200);
        echo json_encode(['ok' => true]);
    }

    /**
     * Handle return from NakoPay checkout
     */
    public function plgVmOnPaymentResponseReceived(&$html)
    {
        // Customer returned from checkout - show thank-you page
        // Actual payment confirmation happens via webhook
        $html = '<p>Thank you for your payment. Your order will be confirmed once the payment is verified.</p>';
        return true;
    }

    /**
     * Handle user cancellation
     */
    public function plgVmOnUserPaymentCancel()
    {
        // Customer cancelled - VirtueMart handles the redirect
        return true;
    }

    /**
     * Get new pending status from method config or default
     */
    private function getNewStatus($method)
    {
        return $method->status_pending ?? 'P';
    }

    /**
     * Find the first NakoPay payment method ID
     */
    private function getPaymentMethodId()
    {
        $db    = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->select('virtuemart_paymentmethod_id')
            ->from('#__virtuemart_paymentmethods')
            ->where('payment_element = ' . $db->quote('nakopay'))
            ->where('published = 1');
        $db->setQuery($query, 0, 1);
        return $db->loadResult();
    }
}
