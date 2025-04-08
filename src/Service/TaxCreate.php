<?php

namespace ICTECHOdooShopwareConnector\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class TaxCreate
{
    public function __construct(
        private readonly EntityRepository $taxRepository
    ) {
    }

    public function taxDataGenerate($taxDataMain, $context): array
    {
        $taxDataResponse = [];
        foreach ($taxDataMain as $taxData) {
            $odooTaxId = $taxData['id'];
            $shopwareTaxData = $this->getTaxData($taxData, $context);
            if (array_key_exists('name', $taxData)) {
                $taxName = $taxData['name'];
            } else {
                $taxName = $shopwareTaxData->getName();
            }
            if (array_key_exists('amount', $taxData)) {
                $taxRate = $taxData['amount'];
            } else {
                $taxRate = $shopwareTaxData->getTaxRate();
            }
            $data = [
                'id' => $shopwareTaxData ? $shopwareTaxData->getId() : Uuid::randomHex(),
                'name' => $taxName,
                'taxRate' => $taxRate,
                'customFields' => ['odoo_tax_id' => $odooTaxId],
            ];
            $this->taxRepository->upsert([$data], $context);
            $taxDataResponse[] = [
                'odooTaxId' => $odooTaxId,
                'shopwareTaxId' => $data['id']
            ];
        }
        return [
            'type' => 'Success',
            'responseCode' => 200,
            'taxData' => $taxDataResponse,
        ];
    }

    public function getTaxData($taxData, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.odoo_tax_id', $taxData['id']));
        if ($taxData['shopware_tax_id']) {
            $criteria->addFilter(new EqualsFilter('id', $taxData['shopware_tax_id']));
        }
        return $this->taxRepository->search($criteria, $context)->first();
    }
}
