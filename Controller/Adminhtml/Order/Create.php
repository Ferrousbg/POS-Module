<?php
namespace Ferrousbg\AdminOrder\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Catalog\Model\ProductRepository;
use Magento\Store\Model\StoreManagerInterface;

class Create extends Action
{
    protected $jsonFactory;
    protected $quoteFactory;
    protected $quoteManagement;
    protected $productRepository;
    protected $storeManager;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        QuoteFactory $quoteFactory,
        QuoteManagement $quoteManagement,
        ProductRepository $productRepository,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->quoteFactory = $quoteFactory;
        $this->quoteManagement = $quoteManagement;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        // Взимаме JSON-а от тялото на заявката
        $postContent = $this->getRequest()->getContent();
        $data = json_decode($postContent, true);

        if (!$data) {
            return $result->setData(['success' => false, 'message' => 'Invalid data']);
        }

        try {
            $customerData = $data['customer'];
            $items = $data['items'];

            $store = $this->storeManager->getStore();
            $quote = $this->quoteFactory->create();
            $quote->setStore($store);

            // Клиент (Guest)
            $quote->setCustomerEmail($customerData['email']);
            $quote->setCustomerFirstname($customerData['firstname']);
            $quote->setCustomerLastname($customerData['lastname']);
            $quote->setCustomerIsGuest(true);

            // Продукти
            foreach ($items as $item) {
                $product = $this->productRepository->getById($item['id']);
                $quote->addProduct($product, intval($item['qty']));
            }

            // Адрес
            $addressData = [
                'firstname' => $customerData['firstname'],
                'lastname' => $customerData['lastname'],
                'street' => 'Admin Order',
                'city' => 'Admin City',
                'country_id' => 'BG',
                'postcode' => '1000',
                'telephone' => $customerData['telephone'] ?: '0000000000',
                'email' => $customerData['email']
            ];

            $quote->getBillingAddress()->addData($addressData);
            $quote->getShippingAddress()->addData($addressData);

            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->setCollectShippingRates(true)
                ->setShippingMethod('flatrate_flatrate');

            $quote->setPaymentMethod('checkmo');
            $quote->setInventoryProcessed(false);

            $quote->save();
            $quote->getPayment()->importData(['method' => 'checkmo']);
            $quote->collectTotals()->save();

            $order = $this->quoteManagement->submit($quote);

            return $result->setData([
                'success' => true,
                'order_increment_id' => $order->getIncrementId()
            ]);

        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Magento_Sales::create');
    }
}