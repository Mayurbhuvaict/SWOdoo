<?php

namespace ICTECHOdooShopwareConnector\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class ManufacturerCreate
{
    public function __construct(
        private readonly EntityRepository $manufacturerRepository
    ) {
    }

    public function manufacturerDataGenerate($manufacturerDataMain, $context): array
    {
        $manufacturerDataResponse = [];
        foreach ($manufacturerDataMain as $manufacturerData) {
            $odooManufacturerId = $manufacturerData['id'];
            $shopwareManufacturerData = $this->getManufacturerData($manufacturerData, $context);
            if (array_key_exists('name', $manufacturerData)) {
                $manufacturerName = $manufacturerData['name'];
            } else {
                $manufacturerName = $shopwareManufacturerData->getName();
            }
            if (array_key_exists('website', $manufacturerData) && $manufacturerData['website']) {
                $manufacturerWebsite = $manufacturerData['website'];
            }
            if (array_key_exists('description', $manufacturerData)) {
                $manufacturerDescription = $manufacturerData['description'];
            }
            $data = [
                'id' => $shopwareManufacturerData ? $shopwareManufacturerData->getId() : Uuid::randomHex(),
                'name' => $manufacturerName,
                'customFields' => ['odoo_manufacturer_id' => $odooManufacturerId],
            ];
            if (isset($manufacturerWebsite)) {
                $data['link'] = $manufacturerWebsite;
            }
            if (isset($manufacturerDescription) && $manufacturerDescription) {
                $data['description'] = $manufacturerDescription;
            }
            $this->manufacturerRepository->upsert([$data], $context);
            $manufacturerDataResponse[] = [
                'odooManufacturerId' => $odooManufacturerId,
                'shopwareManufacturerId' => $data['id']
            ];
        }
        return [
            'type' => 'Success',
            'responseCode' => 200,
            'manufacturerData' => $manufacturerDataResponse,
        ];
    }

    public function getManufacturerData($manufacturerData, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_manufacturer_id', $manufacturerData['id']));
        if ($manufacturerData['shopware_product_brand_id']) {
            $criteria->addFilter(new EqualsFilter('id', $manufacturerData['shopware_product_brand_id']));
        }
        return $this->manufacturerRepository->search($criteria, $context)->first();
    }
}
