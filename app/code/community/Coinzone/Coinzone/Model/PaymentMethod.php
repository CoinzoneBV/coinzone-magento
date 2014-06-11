<?php

class Coinzone_Coinzone_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'Coinzone';

    protected $_isGateway = true;

    protected $_canAuthorize = true;

    protected $_canCapture = false;

    protected $_canCapturePartial = true;

    protected $_canRefundInvoicePartial = true;

    protected $_canRefund = true;

    protected $_canVoid = false;

    protected $_canUseInternal = true;

    protected $_canUseCheckout = true;

    protected $_canUseForMultishipping = true;

    protected $_canSaveCc = false;

    /**
     * Get URL to redirect to
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getSingleton('customer/session')->getRedirectUrl();
    }

    /**
     * Magento Authorize
     * @param Varien_Object $payment
     * @param float $amount
     * @return $this|Mage_Payment_Model_Abstract
     * @throws Exception
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        require_once(Mage::getModuleDir('coinzone-lib', 'Coinzone_Coinzone') . '/coinzone-lib/Coinzone.php');

        $clientCode = Mage::getStoreConfig('payment/Coinzone/clientCode');
        $apiKey = Mage::getStoreConfig('payment/Coinzone/apiKey');
        $speed = Mage::getStoreConfig('payment/Coinzone/speed');

        if (is_null($clientCode) || is_null($apiKey)) {
            throw new Exception('Missing Client Code/API Key');
        }
        /** @var \Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();

        /* add products ordered to API request */
        $items = $order->getAllItems();
        $displayItems = array();
        foreach ($items as $item) {
            $displayItems[] = array(
                'name' => $item->getName(),
                'quantity' => $item->getQtyOrdered(),
                'unitPrice' => $item->getPrice(),
                'shortDescription' => $item->getDescription(),
                'imageUrl' => (string)Mage::helper('catalog/image')->init($item->getProduct(), 'thumbnail')
            );
        }

        /* create payload array */
        $payload = array(
            'amount' => $amount,
            'currency' => $order->getBaseCurrencyCode(),
            'reference' => $order->getIncrementId(),
            'speed' => $speed,
            'email' => $order->getCustomerEmail(),
            'displayOrderInformation' => array(
                'items' => $displayItems,
                'tax' => $order->getTaxAmount(),
                'shippingCost' => $order->getShippingAmount(),
                'discount' => $order->getDiscountAmount()
            ),
        );

        $coinzone = new Coinzone($clientCode, $apiKey);
        $response = $coinzone->callApi('transaction', $payload);

        if ($response->status->code !== 201) {
            throw new Exception('Could not generate transaction.');
        }

        /* set order status to `Payment Review` */
        $payment->setIsTransactionPending(true);
        $payment->setTransactionId($response->response->idTransaction);
        Mage::getSingleton('customer/session')->setRedirectUrl($response->response->url);

        return $this;
    }

    /**
     * Magento Refund
     * @param Varien_Object $payment
     * @param float $amount
     * @return $this|Mage_Payment_Model_Abstract
     * @throws Exception
     */
    public function refund(Varien_Object $payment, $amount)
    {
        /** @var Mage_Sales_Model_Order_Payment $payment */
        require_once(Mage::getModuleDir('coinzone-lib', 'Coinzone_Coinzone') . '/coinzone-lib/Coinzone.php');

        $clientCode = Mage::getStoreConfig('payment/Coinzone/clientCode');
        $apiKey = Mage::getStoreConfig('payment/Coinzone/apiKey');

        if (is_null($clientCode) || is_null($apiKey)) {
            throw new Exception('Missing Client Code/API Key');
        }

        /** @var \Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();

        /* create payload array */
        $transactionId = $order->getPayment()->getLastTransId();
        $payload = array(
            'idTransaction' => $transactionId,
            'amount' => $amount,
            'currency' => $order->getBaseCurrencyCode(),
            'reason' => Mage::getSingleton('adminhtml/session')->getCommentText()
        );

        $coinzone = new Coinzone($clientCode, $apiKey);
        $response = $coinzone->callApi('cancel_request', $payload);
        if ($response->status->code !== 201) {
            throw new Exception('Could not generate refund transaction');
        }

        $payment->setTransactionId($response->response->refNo);
        $payment->setIsTransactionClosed(true);
        $payment->setShouldCloseParentTransaction(!$payment->getCreditmemo()->getInvoice()->canRefund());
        $payment->getCreditmemo()->setState(Mage_Sales_Model_Order_Creditmemo::STATE_OPEN);
        $order->addStatusHistoryComment('Coinzone: Refund request sent to gateway');

        /* refund request success */
        return $this;
    }
}
