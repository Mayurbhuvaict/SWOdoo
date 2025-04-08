<?php

namespace ICTECHOdooShopwareConnector\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class CustomerService
{
    public function __construct(
        private readonly EntityRepository $paymentMethodRepository,
        private readonly EntityRepository $languageRepository,
        private readonly EntityRepository $salutationRepository,
        private readonly EntityRepository $customerGroupRepository,
        private readonly EntityRepository $currencyRepository,
        private readonly EntityRepository $countryRepository,
        private readonly EntityRepository $countryStateRepository,
        private readonly EntityRepository $customerRepository
    ) {
    }

    public function generateCustomerPayload($customer, $context): array
    {
        $customerDataCollection = [];
        $paymentMethodData = $this->getDefaultPaymentMethodData($customer->getDefaultPaymentMethodId(), $context);
        $languageId = $customer->getLanguageId();
        $languageData = $this->getCustomerLanguageName($languageId, $context);
        $currencyData = $this->getCustomerCurrencyData($context);
        if ($customer->getActiveBillingAddress()) {
            $billingAddressData = $customer->getActiveBillingAddress();
        } else {
            $billingAddressData = $customer->getDefaultBillingAddress();
        }
        $billingAddress = [
            'firstName' => $billingAddressData->getFirstName(),
            'lastName' => $billingAddressData->getLastName(),
            'street' => $billingAddressData->getStreet(),
            'zip' => $billingAddressData->getZipcode() ? $billingAddressData->getZipcode() : '',
            'city' => $billingAddressData->getCity(),
            'company' => $billingAddressData->getCompany() ? $billingAddressData->getCompany() : '',
            'department' => $billingAddressData->getDepartment() ? $billingAddressData->getDepartment() : '',
            'title' => $billingAddressData->getTitle() ? $billingAddressData->getTitle() : '',
            'country' => $billingAddressData->getCountry()->getName(),
            'phoneNumber' => $billingAddressData->getPhoneNumber() ? $billingAddressData->getPhoneNumber() : '',
            'additionalAddressLine1' => $billingAddressData->getAdditionalAddressLine1() ? $billingAddressData->getAdditionalAddressLine1() : '',
            'additionalAddressLine2' => $billingAddressData->getAdditionalAddressLine2() ? $billingAddressData->getAdditionalAddressLine2() : '',
        ];
        if ($customer->getActiveBillingAddress()) {
		$shippingAddressData = $customer->getActiveShippingAddress();
        } else {
            $shippingAddressData = $customer->getDefaultShippingAddress();
        }
        $shippingAddress = [
            'firstName' => $shippingAddressData->getFirstName(),
            'lastName' => $shippingAddressData->getLastName(),
            'street' => $shippingAddressData->getStreet(),
            'zip' => $shippingAddressData->getZipcode() ? $shippingAddressData->getZipcode() : '',
            'city' => $shippingAddressData->getCity(),
            'company' => $shippingAddressData->getCompany() ? $shippingAddressData->getCompany() : '',
            'department' => $shippingAddressData->getDepartment() ? $shippingAddressData->getDepartment() : '',
            'title' => $shippingAddressData->getTitle() ? $shippingAddressData->getTitle() : '',
            'country' => $shippingAddressData->getCountry()->getName(),
            'phoneNumber' => $shippingAddressData->getPhoneNumber() ? $shippingAddressData->getPhoneNumber() : '',
            'additionalAddressLine1' => $shippingAddressData->getAdditionalAddressLine1() ? $shippingAddressData->getAdditionalAddressLine1() : '',
            'additionalAddressLine2' => $shippingAddressData->getAdditionalAddressLine2() ? $shippingAddressData->getAdditionalAddressLine2() : '',
        ];
        $customerDataCollection[] = [
            'id' => $customer->getId(),
            'customerType' => $customer->getAccountType() ? $customer->getAccountType() : '',
            'salutation' => $customer->getSalutation()->getDisplayName() ? $customer->getSalutation()->getDisplayName()
                : '',
            'title' => $customer->getTitle() ? $customer->getTitle() : '',
            'firstName' => $customer->getFirstName(),
            'lastName' => $customer->getLastName(),
            'email' => $customer->getEmail(),
            'dateOfBirth' => $customer->getBirthday() ? $customer->getBirthday() : '',
            'salesChannelId' => $customer->getSalesChannelId(),
            'language' => $languageData ? $languageData->getName() : $customer->getLanguage(),
            'paymentMethod' => $customer->getDefaultPaymentMethod() ? $customer->getDefaultPaymentMethod()->getName() : $paymentMethodData->getName(),
            'customerNumber' => $customer->getCustomerNumber(),
            'currency' => [
                'name' => $currencyData->getName(),
                'symbol' => $currencyData->getSymbol(),
                'shortName' => $currencyData->getShortName(),
            ],
            'company' => $customer->getCompany() ? $customer->getCompany() : '',
            'billingAddress' => $billingAddress,
            'shippingAddress' => $shippingAddress,
            'createdBy' => $customer->createdById ? 'admin' : 'customer'
        ];
        return $customerDataCollection;
    }

    public function getDefaultPaymentMethodData($getDefaultPaymentMethodId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('billingAddress');
        $criteria->addAssociation('country');
        $criteria->addAssociation('countryState');
        $criteria->addFilter(new EqualsFilter('id', $getDefaultPaymentMethodId));
        return $this->paymentMethodRepository->search($criteria, $context)->first();
    }

    public function getCustomerLanguageName($languageId, $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $languageId));
        return $this->languageRepository->search($criteria, $context)->first();
    }

    public function getCustomerCurrencyData($context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $context->getCurrencyId()));
        return $this->currencyRepository->search($criteria, $context)->first();
    }

    //get payload with proper data and array
    public function getName($id, $type, $context): ?Entity
    {
        $repository = match ($type) {
            'payment' => $this->paymentMethodRepository,
            'salutation' => $this->salutationRepository,
            'customerGroup' => $this->customerGroupRepository,
            'language' => $this->languageRepository,
            'country' => $this->countryRepository,
            'countryState' => $this->countryStateRepository,
            default => null,
        };
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $id));
        return $repository->search($criteria, $context)->first();
    }

    public function getNewUpdatedCustomer($updateCustomerData, $context): array
    {
        if (array_key_exists('defaultPaymentMethodId', $updateCustomerData)) {
            $paymentMethodId = $updateCustomerData['defaultPaymentMethodId'];
            $paymentMethodName = $this->getName($paymentMethodId, 'payment', $context)->getName();
            $updateCustomerData = $this->replaceCustomerArray($updateCustomerData, 'defaultPaymentMethodId', 'paymentMethod');
            $updateCustomerData['paymentMethod'] = $paymentMethodName;
        }
        if (array_key_exists('salutationId', $updateCustomerData)) {
            $salutationId = $updateCustomerData['salutationId'];
            $salutationName = $this->getName($salutationId, 'salutation', $context)->getDisplayName();
            $updateCustomerData = $this->replaceCustomerArray($updateCustomerData, 'salutationId', 'salutation');
            $updateCustomerData['salutation'] = $salutationName;
        }
        if (array_key_exists('groupId', $updateCustomerData)) {
            $customerGroupId = $updateCustomerData['groupId'];
            $customerGroupName = $this->getName($customerGroupId, 'customerGroup', $context)->getName();
            $updateCustomerData = $this->replaceCustomerArray($updateCustomerData, 'groupId', 'customerGroup');
            $updateCustomerData['customerGroup'] = $customerGroupName;
        }
        if (array_key_exists('languageId', $updateCustomerData)) {
            $languageId = $updateCustomerData['languageId'];
            $languageName = $this->getName($languageId, 'language', $context)->getName();
            $updateCustomerData = $this->replaceCustomerArray($updateCustomerData, 'languageId', 'language');
            $updateCustomerData['language'] = $languageName;
        }
        if (array_key_exists('countryId', $updateCustomerData)) {
            $countryId = $updateCustomerData['countryId'];
            $countryName = $this->getName($countryId, 'country', $context)->getName();
            $updateCustomerData = $this->replaceCustomerArray($updateCustomerData, 'countryId', 'country');
            $updateCustomerData['country'] = $countryName;
        }
        if (!array_key_exists('customerId', $updateCustomerData)) {
            return $updateCustomerData;
        }
        return $this->getDefaultCustomerAddress($updateCustomerData, $context);
    }

    // get name of data using by IDs
    public function replaceCustomerArray($updateCustomerData, $oldKey, $newKey): array
    {
        $keys = array_keys($updateCustomerData);
        $values = array_values($updateCustomerData);
        $index = array_search($oldKey, $keys);
        $keys[$index] = $newKey;
        return array_combine($keys, $values);
    }

    public function getDefaultCustomerAddress($updateCustomerData, $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $updateCustomerData['customerId']));
        $customer = $this->customerRepository->search($criteria, $context)->first();
        if ($updateCustomerData['id'] === $customer->getDefaultBillingAddressId()) {
            $updateCustomerData['defaultBillingAddress'] = true;
        }
        if ($updateCustomerData['id'] === $customer->getDefaultShippingAddressId()) {
            $updateCustomerData['defaultShippingAddress'] = true;
        }
        if ($updateCustomerData['id'] === $customer->getDefaultBillingAddressId() && $updateCustomerData['id'] === $customer->getDefaultShippingAddressId()) {
            $updateCustomerData['defaultBillingAddress'] = true;
            $updateCustomerData['defaultShippingAddress'] = true;
        }
        return $updateCustomerData;
    }
}

