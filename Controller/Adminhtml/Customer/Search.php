<?php
namespace Ferrousbg\AdminOrder\Controller\Adminhtml\Customer;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Framework\Controller\Result\JsonFactory;

class Search extends Action
{
    protected $collectionFactory;
    protected $resultJsonFactory;

    public function __construct(
        Context $context,
        CollectionFactory $collectionFactory,
        JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
        $this->collectionFactory = $collectionFactory;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    public function execute()
    {
        $query = $this->getRequest()->getParam('q');
        $result = $this->resultJsonFactory->create();

        if (strlen($query) < 2) {
            return $result->setData([]);
        }

        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['firstname', 'lastname', 'email']);

        // Join with default billing address to get telephone
        $collection->joinAttribute('telephone', 'customer_address/telephone', 'default_billing', null, 'left');
        $collection->joinAttribute('billing_postcode', 'customer_address/postcode', 'default_billing', null, 'left');
        $collection->joinAttribute('billing_city', 'customer_address/city', 'default_billing', null, 'left');
        $collection->joinAttribute('billing_street', 'customer_address/street', 'default_billing', null, 'left');

        // Търсене по Имена, Имейл или Телефон
        $collection->addAttributeToFilter([
            ['attribute' => 'firstname', 'like' => '%' . $query . '%'],
            ['attribute' => 'lastname', 'like' => '%' . $query . '%'],
            ['attribute' => 'email', 'like' => '%' . $query . '%'],
            ['attribute' => 'telephone', 'like' => '%' . $query . '%']
        ]);

        $collection->setPageSize(15);

        $items = [];
        foreach ($collection as $customer) {
            $items[] = [
                'id' => $customer->getId(),
                'firstname' => $customer->getFirstname(),
                'lastname' => $customer->getLastname(),
                'email' => $customer->getEmail(),
                'telephone' => $customer->getData('telephone') ?: '',
                'city' => $customer->getData('billing_city') ?: '',
                'street' => $customer->getData('billing_street') ?: '',
            ];
        }

        return $result->setData($items);
    }
}