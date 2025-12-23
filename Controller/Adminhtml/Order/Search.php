<?php
namespace Ferrousbg\AdminOrder\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Helper\Image as ImageHelper;

class Search extends Action
{
    protected $collectionFactory;
    protected $resultJsonFactory;
    protected $imageHelper;

    public function __construct(
        Context $context,
        CollectionFactory $collectionFactory,
        JsonFactory $resultJsonFactory,
        ImageHelper $imageHelper
    ) {
        parent::__construct($context);
        $this->collectionFactory = $collectionFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->imageHelper = $imageHelper;
    }

    public function execute()
    {
        $query = $this->getRequest()->getParam('q');
        $result = $this->resultJsonFactory->create();

        if (strlen($query) < 2) {
            return $result->setData([]);
        }

        $collection = $this->collectionFactory->create();

        // 1. Избираме само стандартните атрибути първо
        $collection->addAttributeToSelect(['name', 'price', 'sku', 'thumbnail', 'small_image']);

        // 2. Добавяме вашите специфични атрибути към селекцията
        // ВАЖНО: Уверете се, че кодовете тук са написани правилно (малки букви, без грешки)
        $customAttributes = [
            'pcd',
            'center_bore',
            'thickness',
            'outer_diameter',
            'inner_chamfer',
            'outer_chamfer',
            'material',
            'thread', // Това беше проблемният атрибут от грешката
            'adapters_type'
        ];

        foreach ($customAttributes as $attrCode) {
            $collection->addAttributeToSelect($attrCode);
        }

        $collection->addAttributeToFilter([
            ['attribute' => 'sku', 'like' => '%' . $query . '%'],
            ['attribute' => 'name', 'like' => '%' . $query . '%']
        ]);

        $collection->setPageSize(20);

        $items = [];

        /** @var \Magento\Catalog\Model\Product $product */
        foreach ($collection as $product) {

            $specs = [];

            // Списък за визуализация (Label => Attribute Code)
            $displayAttrs = [
                'PCD' => 'pcd',
                'Center Bore' => 'center_bore',
                'Thickness' => 'thickness',
                'Outer Diameter' => 'outer_diameter',
                'Inner Chamfer' => 'inner_chamfer',
                'Outer Chamfer' => 'outer_chamfer',
                'Material' => 'material',
                'Thread' => 'thread',
                'Adapters Type' => 'adapters_type'
            ];

            foreach ($displayAttrs as $label => $code) {
                // БЕЗОПАСЕН МЕТОД ЗА ВЗИМАНЕ НА СТОЙНОСТ
                try {
                    // 1. Взимаме суровата стойност
                    $rawValue = $product->getData($code);

                    if ($rawValue !== null && $rawValue !== '') {
                        $textValue = $rawValue;

                        // 2. Проверяваме дали е dropdown (Select)
                        $attribute = $product->getResource()->getAttribute($code);
                        if ($attribute && $attribute->usesSource()) {
                            // Опитваме да вземем текста безопасно
                            $optionText = $attribute->getSource()->getOptionText($rawValue);
                            if ($optionText) {
                                $textValue = $optionText;
                            }
                        }

                        // Ако е масив (multiselect), го правим на стринг
                        if (is_array($textValue)) {
                            $textValue = implode(', ', $textValue);
                        }

                        $specs[] = ['label' => $label, 'value' => $textValue];
                    }
                } catch (\Exception $e) {
                    // Ако гръмне за конкретен атрибут, просто го пропускаме,
                    // вместо да спираме цялото търсене
                    continue;
                }
            }

            // Снимка
            try {
                $imageUrl = $this->imageHelper->init($product, 'product_listing_thumbnail')->getUrl();
            } catch (\Exception $e) {
                $imageUrl = ''; // Fallback ако няма снимка
            }

            $items[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'price' => (float)$product->getPrice(),
                'image' => $imageUrl,
                'specs' => $specs
            ];
        }

        return $result->setData($items);
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Magento_Sales::create');
    }
}