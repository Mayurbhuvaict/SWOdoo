<?php

declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use AllowDynamicProperties;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use ICTECHOdooShopwareConnector\Components\Config\PluginConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AllowDynamicProperties] #[AsMessageHandler(handles: LanguageSyncTask::class)]
class LanguageSyncTaskHandler extends ScheduledTaskHandler
{
    private const MODULE = '/modify/shopware.language';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly PluginConfig $pluginConfig,
        private readonly EntityRepository $languageRepository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->client = new Client();
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $odooUrlData = $this->pluginConfig->fetchPluginConfigUrlData($context);
        $odooUrl = $odooUrlData . self::MODULE;
        $odooToken = $this->pluginConfig->getOdooAccessToken();
        if ($odooUrl !== 'null' && $odooToken) {
            $languageDataArray = $this->fetchLanguageData($context);
            dd($languageDataArray);
            if ($languageDataArray) {
                $languagesToUpsert = [];
                foreach ($languageDataArray as $language) {
                    $apiResponseData = $this->checkApiAuthentication($odooUrl, $odooToken, $language);
                    if ($apiResponseData['result']) {
                        $apiData = $apiResponseData['result'];
                        if ($apiData['success'] && isset($apiData['data']) && is_array($apiData['data'])) {
                            foreach ($apiData['data'] as $apiItem) {
                                $languageData = $this->buildLanguageData($apiItem);
                                if ($languageData) {
                                    $languagesToUpsert[] = $languageData;
                                }
                            }
                        } else {
                            foreach ($apiData['data'] ?? [] as $apiItem) {
                                $languageData = $this->buildLanguageErrorData($apiItem);
                                if ($languageData) {
                                    $languagesToUpsert[] = $languageData;
                                }
                            }
                        }
                        if (! $languagesToUpsert) {
                            try {
                                $this->languageRepository->upsert($languagesToUpsert, $context);
                            } catch (\Exception $e) {
                                $this->logger->error('Error in language sync task', [
                                    'exception' => $e,
                                    'data' => $languagesToUpsert,
                                    'apiResponse' => $apiData,
                                ]);
                            }
                        }
                    }
                }
            }
        }
    }

    public function fetchLanguageData($context)
    {
        $languageData = [];
        $criteria = new Criteria();
        $criteria->addAssociation('locale');
        $criteria->addAssociation('translationCode');
        $criteria->addAssociation('salesChannels');
        $criteria->addAssociation('salesChannelDefaultAssignments');
        $criteria->addAssociation('salesChannelDomains');
        $criteria->addAssociation('customers');
        $criteria->addAssociation('newsletterRecipients');
        $criteria->addAssociation('orders');
        $criteria->addAssociation('categoryTranslations');
        $criteria->addAssociation('countryStateTranslations');
        $criteria->addAssociation('countryTranslations');
        $criteria->addAssociation('currencyTranslations');
        $criteria->addAssociation('customerGroupTranslations');
        $criteria->addAssociation('localeTranslations');
        $criteria->addAssociation('mediaTranslations');
        $criteria->addAssociation('paymentMethodTranslations');
        $criteria->addAssociation('productManufacturerTranslations');
        $criteria->addAssociation('productTranslations');
        $criteria->addAssociation('shippingMethodTranslations');
        $criteria->addAssociation('unitTranslations');
        $criteria->addAssociation('propertyGroupTranslations');
        $criteria->addAssociation('propertyGroupOptionTranslations');
        $criteria->addAssociation('salesChannelTranslations');
        $criteria->addAssociation('salesChannelTypeTranslations');
        $criteria->addAssociation('salutationTranslations');
        $criteria->addAssociation('pluginTranslations');
        $criteria->addAssociation('productStreamTranslations');
        $criteria->addAssociation('stateMachineTranslations');
        $criteria->addAssociation('stateMachineStateTranslations');
        $criteria->addAssociation('cmsPageTranslations');
        $criteria->addAssociation('cmsSlotTranslations');
        $criteria->addAssociation('mailTemplateTranslations');
        $criteria->addAssociation('mailHeaderFooterTranslations');
        $criteria->addAssociation('documentTypeTranslations');
        $criteria->addAssociation('numberRangeTypeTranslations');
        $criteria->addAssociation('deliveryTimeTranslations');
        $criteria->addAssociation('productSearchKeywords');
        $criteria->addAssociation('productKeywordDictionaries');
        $criteria->addAssociation('mailTemplateTypeTranslations');
        $criteria->addAssociation('promotionTranslations');
        $criteria->addAssociation('numberRangeTranslations');
        $criteria->addAssociation('productReviews');
        $criteria->addAssociation('seoUrlTranslations');
        $criteria->addAssociation('taxRuleTypeTranslations');
        $criteria->addAssociation('productCrossSellingTranslations');
        $criteria->addAssociation('importExportProfileTranslations');
        $criteria->addAssociation('productSortingTranslations');
        $criteria->addAssociation('productFeatureSetTranslations');
        $criteria->addAssociation('appTranslations');
        $criteria->addAssociation('actionButtonTranslations');
        $criteria->addAssociation('landingPageTranslations');
        $criteria->addAssociation('appCmsBlockTranslations');
        $criteria->addAssociation('appScriptConditionTranslations');
        $criteria->addAssociation('productSearchConfig');
        $criteria->addAssociation('appFlowActionTranslations');
        $criteria->addAssociation('taxProviderTranslations');
        $languageDataArray = $this->languageRepository->search($criteria, $context)->getElements();
        if ($languageDataArray) {
            foreach ($languageDataArray as $languageData) {
                $customFields = $languageData->getCustomFields();
                if ($customFields) {
                    if (array_key_exists('odoo_language_id', $customFields) && $customFields['odoo_language_id'] === null || $customFields['odoo_language_id'] === 0) {
                        $languageData[] = $languageData;
                    } elseif (array_key_exists('odoo_language_error', $customFields) && $customFields['odoo_language_error'] === null) {
                        $languageData[] = $languageData;
                    }
                } else {
                    $languageData[] = $languageData;
                }
            }
        }
        return $languageData;
    }

    public function checkApiAuthentication($apiUrl, $odooToken, $language): ?array
    {
        try {
            $apiResponseData = $this->client->post(
                $apiUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Access-Token' => $odooToken,
                    ],
                    'json' => $language,
                ]
            );
            return json_decode($apiResponseData->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $this->logger->error('API request failed', [
                'exception' => $e,
                'apiUrl' => $apiUrl,
                'odooToken' => $odooToken,
            ]);

            return [
                'result' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function buildLanguageData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_language_id'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_language_id' => $apiItem['odoo_language_id'],
                    'odoo_language_error' => null,
                    'odoo_language_update_time' => date('Y-m-d H:i'),
                ],
            ];
        }
        return null;
    }

    public function buildLanguageErrorData($apiItem): ?array
    {
        if (isset($apiItem['id'], $apiItem['odoo_shopware_error'])) {
            return [
                'id' => $apiItem['id'],
                'customFields' => [
                    'odoo_language_error' => $apiItem['odoo_shopware_error'],
                ],
            ];
        }
        return null;
    }
}
