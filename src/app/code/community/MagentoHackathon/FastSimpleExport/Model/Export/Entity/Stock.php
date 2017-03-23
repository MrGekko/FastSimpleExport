<?php

class MagentoHackathon_FastSimpleExport_Model_Export_Entity_Stock extends Mage_ImportExport_Model_Export_Entity_Product
{
    const FILTER_ELEMENT_INCLUDE = 'attr_include';

    protected function _getExportAttrCodes()
    {
        if (null === self::$attrCodes) {
            if (!empty($this->_parameters[Mage_ImportExport_Model_Export::FILTER_ELEMENT_SKIP])
                && is_array($this->_parameters[Mage_ImportExport_Model_Export::FILTER_ELEMENT_SKIP])
            ) {
                $skipAttr = array_flip($this->_parameters[Mage_ImportExport_Model_Export::FILTER_ELEMENT_SKIP]);
            }
            else {
                $skipAttr = [];
            }

            $validAttr = ['sku'];
            if (!empty($this->_parameters[self::FILTER_ELEMENT_INCLUDE])
                && is_array($this->_parameters[self::FILTER_ELEMENT_INCLUDE])
            ) {
                $validAttr = array_merge($validAttr, $this->_parameters[self::FILTER_ELEMENT_INCLUDE]);
            }
            $attrCodes = [];
            foreach ($this->filterAttributeCollection($this->getAttributeCollection()) as $attribute) {
                if (!isset($skipAttr[$attribute->getAttributeId()])
                    || in_array($attribute->getAttributeCode(), $this->_permanentAttributes)
                ) {
                    if (in_array($attribute->getAttributeCode(), $validAttr)) {
                        $attrCodes[] = $attribute->getAttributeCode();
                    }
                }
            }
            self::$attrCodes = $attrCodes;
        }

        return self::$attrCodes;
    }

    /**
     * Prepare data for export.
     *
     * @return void
     */
    protected function _prepareExport()
    {
        //Execution time may be very long
        set_time_limit(0);

        /** @var $collection Mage_Catalog_Model_Resource_Eav_Mysql4_Product_Collection */
        $validAttrCodes = $this->_getExportAttrCodes();
        $writer         = $this->getWriter();
        $defaultStoreId = Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID;

        $dataRows        = [];
        $rowMultiselects = [];
d($this->_storeIdToCode);
        // prepare multi-store values and system columns values
        foreach ($this->_storeIdToCode as $storeId => &$storeCode) { // go through all stores
            $collection = $this->_prepareEntityCollection(Mage::getResourceModel('catalog/product_collection'));
            $collection
                ->setStoreId($storeId)
                ->load();

            if ($collection->count() == 0) {
                break;
            }
            if ($defaultStoreId == $storeId) {
                $collection->addCategoryIds()->addWebsiteNamesToResult();
            }
            foreach ($collection as $itemId => $item) { // go through all products
                $rowIsEmpty = true; // row is empty by default

                foreach ($validAttrCodes as &$attrCode) { // go through all valid attribute codes
                    $attrValue = $item->getData($attrCode);

                    if (!empty($this->_attributeValues[$attrCode])) {
                        if ($this->_attributeTypes[$attrCode] == 'multiselect') {
                            $attrValue = explode(',', $attrValue);
                            $attrValue = array_intersect_key(
                                $this->_attributeValues[$attrCode],
                                array_flip($attrValue)
                            );

                            switch ($this->_attributeScopes[$attrCode]) {
                                case Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_STORE:
                                    if (isset($rowMultiselects[$itemId][0][$attrCode])
                                        && $attrValue == $rowMultiselects[$itemId][0][$attrCode]
                                    ) {
                                        $attrValue = null;
                                    }
                                    break;

                                case Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL:
                                    if ($storeId != $defaultStoreId) {
                                        $attrValue = null;
                                    }
                                    break;

                                case Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_WEBSITE:
                                    $websiteId      = $this->_storeIdToWebsiteId[$storeId];
                                    $websiteStoreId = array_search($websiteId, $this->_storeIdToWebsiteId);
                                    if ((isset($rowMultiselects[$itemId][$websiteStoreId][$attrCode])
                                            && $attrValue == $rowMultiselects[$itemId][$websiteStoreId][$attrCode])
                                        || $attrValue == $rowMultiselects[$itemId][0][$attrCode]
                                    ) {
                                        $attrValue = null;
                                    }
                                    break;

                                default:
                                    break;
                            }

                            if ($attrValue) {
                                $rowMultiselects[$itemId][$storeId][$attrCode] = $attrValue;
                                $rowIsEmpty                                    = false;
                            }
                        }
                        else if (isset($this->_attributeValues[$attrCode][$attrValue])) {
                            $attrValue = $this->_attributeValues[$attrCode][$attrValue];
                        }
                        else {
                            $attrValue = null;
                        }
                    }
                    // do not save value same as default or not existent
                    if ($storeId != $defaultStoreId
                        && isset($dataRows[$itemId][$defaultStoreId][$attrCode])
                        && $dataRows[$itemId][$defaultStoreId][$attrCode] == $attrValue
                    ) {
                        $attrValue = null;
                    }
                    if (is_scalar($attrValue)) {
                        $dataRows[$itemId][$storeId][$attrCode] = $attrValue;
                        $rowIsEmpty                             = false; // mark row as not empty
                    }
                }
                if ($rowIsEmpty) { // remove empty rows
                    unset($dataRows[$itemId][$storeId]);
                }
                $item = null;
            }
            $collection->clear();
        }


        foreach ($dataRows as $productId => &$productData) {
            foreach ($productData as $storeId => &$dataRow) {
                $writer->writeRow($dataRow);
            }
        }

        return $writer->getContents();
    }

}
