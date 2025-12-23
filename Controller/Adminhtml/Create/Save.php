<?php
namespace Ferrousbg\AdminOrder\Controller\Adminhtml\Create;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ProductRepository;
use Magento\Customer\Model\CustomerFactory;

class Save extends Action
{
    protected $resultJsonFactory;
    protected $quoteFactory;
    protected $quoteManagement;
    protected $storeManager;
    protected $productRepository;
    protected $customerFactory;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJsonFactory,
        QuoteFactory $quoteFactory,
        QuoteManagement $quoteManagement,
        StoreManagerInterface $storeManager,
        ProductRepository $productRepository,
        CustomerFactory $customerFactory
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->quoteFactory = $quoteFactory;
        $this->quoteManagement = $quoteManagement;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->customerFactory = $customerFactory;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $data = $this->getRequest()->getContent();
            $request = json_decode($data, true);

            if (!$request || empty($request['items'])) {
                throw new \Exception("Empty cart");
            }

            $store = $this->storeManager->getStore();
            $quote = $this->quoteFactory->create();
            $quote->setStore($store);
            $quote->setCurrency();

            // 1. Set Customer (Guest or Load existing)
            $customerEmail = $request['customer']['email'];
            $quote->setCustomerEmail($customerEmail);
            $quote->setCustomerIsGuest(true);
            // Тук може да добавите логика за проверка дали клиента съществува

            // 2. Add Items
            foreach ($request['items'] as $itemData) {
                $product = $this->productRepository->get($itemData['sku']);
                $quote->addProduct($product, intval($itemData['qty']));
            }

            // 3. Billing & Shipping Address (Dummy for speed, or take from input)
            $addressData = [
                'firstname' => $request['customer']['firstname'],
                'lastname' => $request['customer']['lastname'],
                'street' => 'Factory Pickup',
                'city' => 'Factory City',
                'country_id' => 'BG',
                'region' => 'Sofia',
                'postcode' => '1000',
                'telephone' => '0000000000',
                'save_in_address_book' => 0
            ];

            $quote->getBillingAddress()->addData($addressData);
            $quote->getShippingAddress()->addData($addressData);

            // 4. Shipping Method
            $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();
            $quote->getShippingAddress()->setShippingMethod('flatrate_flatrate'); // Уверете се, че Flat Rate е включен или сменете с 'freeshipping_freeshipping'

            // 5. Payment Method
            $quote->setPaymentMethod('checkmo'); // Check / Money Order
            $quote->setInventoryProcessed(false);

            // 6. Collect Totals & Save Quote
            $quote->collectTotals()->save();

            // 7. Place Order
            $order = $this->quoteManagement->submit($quote);
            $order->setEmailSent(0); // Don't send email immediately if not needed

            return $result->setData([
                'success' => true,
                'order_increment_id' => $order->getIncrementId()
            ]);

        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}