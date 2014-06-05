<?php

class Coinzone_Coinzone_IpnController extends Mage_Core_Controller_Front_Action
{
    public function indexAction()
    {
        require_once(Mage::getModuleDir('coinzone-lib', 'Coinzone_Coinzone') . "/coinzone-lib/Coinzone.php");

        $clientCode = Mage::getStoreConfig('payment/Coinzone/clientCode');
        $apiKey = Mage::getStoreConfig('payment/Coinzone/apiKey');

        $coinzone = new Coinzone($clientCode, $apiKey);

        $input = json_decode(file_get_contents('php://input'));
        /** @var \Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->loadByIncrementId($input->extRef);
        if (!$order) {
            Mage::log('Coinzone - Invalid callback with orderId:' . $input->extRef);
            return;
        }

        $orderInfo = $coinzone->callApi('transaction/' . $input->intRef);
        if ($orderInfo->status->code !== 200) {
            Mage::log('Coinzone - Invalid Coinzone transaction with ID:' . $input->intRef);
            return;
        }
        /** @var \Mage_Sales_Model_Order_Payment $payment */
        $payment = $order->getPayment();
        $payment->setPreparedMessage('Paid - Coinzone Transaction ID:' . $input->intRef);
        $payment->setShouldCloseParentTransaction(true);
        $payment->setIsTransactionCLosed(0);

        if ($orderInfo->response->status === 'COMPLETE') {
            $payment->registerCaptureNotification($orderInfo->response->amount);
        }

        $order->save();
    }
}
