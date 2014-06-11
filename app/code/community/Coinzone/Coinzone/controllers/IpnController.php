<?php

class Coinzone_Coinzone_IpnController extends Mage_Core_Controller_Front_Action
{
    /**
     * @var Mage_Sales_Model_Order
     */
    private $order;

    /**
     * Coinzone IPN front action
     */
    public function indexAction()
    {
        $content = file_get_contents('php://input');
        $input = json_decode($content);

        /** check signature */
        $apiKey = Mage::getStoreConfig('payment/Coinzone/apiKey');
        $stringToSign = $content . $this->getRequest()->getRequestUri() . $this->getRequest()->getHeader('timestamp');
        $signature = hash_hmac('sha256', $stringToSign, $apiKey);
        if ($signature !== $this->getRequest()->getHeader('signature')) {
            Mage::log('Coinzone - Invalid signature for callback');
            Mage::app()->getResponse()
                ->setHeader('HTTP/1.1', '400 Bad Request')
                ->sendResponse();
            exit('Invalid Signature');
        }

        /** check order */
        $this->order = Mage::getModel('sales/order')->loadByIncrementId($input->reference);
        if (!$this->order->getIncrementId()) {
            Mage::log('Coinzone - Invalid callback with orderId:' . $input->reference);
            Mage::app()->getResponse()
                ->setHeader('HTTP/1.1', '400 Bad Request')
                ->sendResponse();
            exit;
        }

        switch ($input->status) {
            case "PAID":
            case "COMPLETE":
                $this->pay($input);
                break;
            case "REFUND":
                $this->refund($input);
                break;
        }
    }

    /**
     * Transaction PAID type IPN
     * @param $input
     */
    private function pay($input)
    {
        $payment = $this->order->getPayment();
        $payment->setTransactionId($input->idTransaction);
        $payment->setPreparedMessage('Coinzone: Paid with Transaction ID:' . $input->idTransaction);
        $payment->setShouldCloseParentTransaction(true);
        $payment->setIsTransactionCLosed(0);
        $payment->registerCaptureNotification($input->amount);

        $this->order->save();
    }

    /**
     * Transaction REFUND type IPN
     * @param $input
     */
    private function refund($input)
    {
        $payment = $this->order->getPayment();
        $payment->setTransactionId($input->idTransaction);
        $payment->setPreparedMessage('Coinzone: Refunded with Transaction ID:' . $input->idTransaction);
        $payment->registerRefundNotification($input->amount);

        $this->order->save();

        $creditMemo = null;
        foreach ($this->order->getCreditmemosCollection() as $memo) {
            /** @var Mage_Sales_Model_Order_Creditmemo $memo */
            if ($memo->getState() == Mage_Sales_Model_Order_Creditmemo::STATE_OPEN
                && $memo->getBaseGrandTotal() == $input->amount
            ) {
                $creditMemo = $memo;
            }
        }

        if (!is_null($creditMemo)) {
            $creditMemo->setState(Mage_Sales_Model_Order_Creditmemo::STATE_REFUNDED);
            $creditMemo->sendEmail();
            $creditMemo->save();
            $this->order->addStatusHistoryComment('Notified customer about creditmemo ' . $creditMemo->getIncrementId())
                ->setIsCustomerNotified(true)
                ->save();
        }
    }
}
