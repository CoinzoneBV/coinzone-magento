<?php

class Coinzone_Coinzone_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'Coinzone';

    protected $_isGateway = true;

    protected $_canAuthorize = true;

    protected $_canCapture = false;

    protected $_canCapturePartial = true;

    protected $_canRefund = true;

    protected $_canVoid = false;

    protected $_canUseInternal = true;

    protected $_canUseCheckout = true;

    protected $_canUseForMultishipping = true;

    protected $_canSaveCc = false;

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getSingleton('customer/session')->getRedirectUrl();
    }

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
        $items = $order->getAllItems();
        $displayItems = array();
        foreach ($items as $item) {
            /** @var \Mage_Sales_Model_Order_Item $item */
            $displayItems[] = array(
                'name' => $item->getName(),
                'quantity' => $item->getQtyOrdered(),
                'unitPrice' => $item->getPrice(),
                'shortDescription' => $item->getDescription(),
                'imageUrl' => (string)Mage::helper('catalog/image')->init($item->getProduct(), 'thumbnail')
            );
        }
        $payload = array(
            'amount' => $amount,
            'currency' => $order->getBaseCurrencyCode(),
            'reference' => $order->getIncrementId(),
            'speed' => $speed,
            'displayOrderInformation' => array(
                'items' => $displayItems,
                'tax' => $order->getTaxAmount(),
                'shippingCost' => $order->getShippingAmount(),
                'discount' => $order->getDiscountAmount()
            ),
        );
        $coinzone = new Coinzone($clientCode, $apiKey);
        $response = $coinzone->callApi('transaction', $payload);

        if ($response->status->code === 201) {
            $payment->setIsTransactionPending(true);
            $payment->setTransactionId($response->response->idTransaction);
            Mage::getSingleton('customer/session')->setRedirectUrl($response->response->url);
        } else {
            throw new Exception('Could not generate transaction.');
        }
        return $this;
    }

    public function refund(Varien_Object $payment, $amount)
    {
        require_once(Mage::getModuleDir('coinzone-lib', 'Coinzone_Coinzone') . '/coinzone-lib/Coinzone.php');

        $clientCode = Mage::getStoreConfig('payment/Coinzone/clientCode');
        $apiKey = Mage::getStoreConfig('payment/Coinzone/apiKey');

        if (is_null($clientCode) || is_null($apiKey)) {
            throw new Exception('Missing Client Code/API Key');
        }
        /** @var \Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();
        $transactionId = explode('-', $order->getPayment()->getLastTransId())[0];
        $payload = array(
            'refNo' => $transactionId,
            'amount' => $amount,
            'currency' => $order->getBaseCurrencyCode(),
            'reason' => Mage::getSingleton('adminhtml/session')->getCommentText()
        );

        $coinzone = new Coinzone($clientCode, $apiKey);
        $response = $coinzone->callApi('cancel_request', $payload);

        if ($response->status->code !== 201) {
            throw new Exception('Could not generate refund transaction');
        }
        return $this;
    }
}
