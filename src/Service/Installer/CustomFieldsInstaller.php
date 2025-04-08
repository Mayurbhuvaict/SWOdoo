<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\Installer;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class CustomFieldsInstaller
{
    private const ID = '_id';
    private const ERROR = '_error';
    private const UPDATEAT = '_update_time';
    private const ODOO_SHOPWARE_PRODUCT = 'odoo_product';
    private const ODOO_SHOPWARE_CATEGORY = 'odoo_category';
    private const ODOO_SHOPWARE_MANUFACTURER = 'odoo_manufacturer';
    private const ODOO_SHOPWARE_CUSTOMER = 'odoo_customer';
    private const ODOO_SHOPWARE_ORDER = 'odoo_order';
    private const ODOO_SHOPWARE_CUSTOMER_GROUP = 'odoo_customer_group';
    private const ODOO_SHOPWARE_SHIPPING_METHOD = 'odoo_shipping_method';
    private const ODOO_SHOPWARE_CURRENCY = 'odoo_currency';
    private const ODOO_SHOPWARE_TAX = 'odoo_tax';
    private const ODOO_SHOPWARE_LANGUAGE = 'odoo_language';
    private const ODOO_SHOPWARE_SALESCHANNEL = 'odoo_sales_channel';
    private const ODOO_SHOPWARE_DELIVERYTIME = 'odoo_delivery_time';
    private const CUSTOM_FIELDSET_NAME = [
        'odoo_product',
        'odoo_category',
        'odoo_manufacturer',
        'odoo_customer',
        'odoo_order',
        'odoo_customer_group',
        'odoo_shipping_method',
        'odoo_currency',
        'odoo_tax',
        'odoo_language',
        'odoo_sales_channel',
        'odoo_delivery_time',
    ];
    private const CUSTOM_FIELDSET = [
        [
            'name' => self::ODOO_SHOPWARE_PRODUCT,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Produkt',
                    'en-GB' => 'Odoo Product'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_PRODUCT . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Product Id',
                            'de-DE' => 'Odoo Produkt Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Product Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ],
                ],
                [
                    'name' => self::ODOO_SHOPWARE_PRODUCT . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Product Error',
                            'de-DE' => 'Odoo-Produktfehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Product Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_PRODUCT . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'product',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_CATEGORY,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Kategorie',
                    'en-GB' => 'Odoo Category'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_CATEGORY . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Category Id',
                            'de-DE' => 'Odoo Kategorie Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Category Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_CATEGORY . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Category Error',
                            'de-DE' => 'Odoo-Kategoriefeler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Category Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_CATEGORY . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'category',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_MANUFACTURER,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Hersteller',
                    'en-GB' => 'Odoo Manufacturer'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_MANUFACTURER . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Manufacturer Id',
                            'de-DE' => 'Odoo Hersteller Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Manufacturer Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_MANUFACTURER . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Manufacturer Error',
                            'de-DE' => 'Odoo-Herstellungsfehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Manufacturer Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_MANUFACTURER . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'product_manufacturer',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_CUSTOMER,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Kunde',
                    'en-GB' => 'Odoo Customer'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_CUSTOMER . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Customer Id',
                            'de-DE' => 'Odoo Kunden Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Customer Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ],
                    'allowCustomerWrite' => true
                ],
                [
                    'name' => self::ODOO_SHOPWARE_CUSTOMER . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Customer Error',
                            'de-DE' => 'Odoo Kundenfehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Customer Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ],
                    'allowCustomerWrite' => true
                ],
                [
                    'name' => self::ODOO_SHOPWARE_CUSTOMER . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'customer',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_ORDER,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Bestellung',
                    'en-GB' => 'Odoo Order'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_ORDER . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Order Id',
                            'de-DE' => 'Odoo Bestell-ID',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Order Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ],
                    'allowCustomerWrite' => true
                ],
                [
                    'name' => self::ODOO_SHOPWARE_ORDER . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Order Error',
                            'de-DE' => 'Odoo Bestellfehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Order Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ],
                    'allowCustomerWrite' => true
                ],
                [
                    'name' => self::ODOO_SHOPWARE_ORDER . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'order',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_CUSTOMER_GROUP,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Kunden Gruppe',
                    'en-GB' => 'Odoo Customer Group'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_CUSTOMER_GROUP . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Customer Group Id',
                            'de-DE' => 'Odoo Kunden Gruppen Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Customer Group Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ],
                ],
                [
                    'name' => self::ODOO_SHOPWARE_CUSTOMER_GROUP . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Customer Group Error',
                            'de-DE' => 'Odoo Kunden Gruppen Fehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Customer Group Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_CUSTOMER_GROUP . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'customer_group',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_SHIPPING_METHOD,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Versandmethode',
                    'en-GB' => 'Odoo Shipping Method'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_SHIPPING_METHOD . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Shipping Method Id',
                            'de-DE' => 'Odoo Versandmethoden Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Shipping Method Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ],
                ],
                [
                    'name' => self::ODOO_SHOPWARE_SHIPPING_METHOD . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Shipping Method Error',
                            'de-DE' => 'Odoo Versandmethoden Fehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Shipping Method Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_SHIPPING_METHOD . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'shipping_method',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_CURRENCY,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Währung',
                    'en-GB' => 'Odoo Currency'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_CURRENCY . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Currency Id',
                            'de-DE' => 'Odoo Währung Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Currency Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ],
                ],
                [
                    'name' => self::ODOO_SHOPWARE_CURRENCY . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Currency Error',
                            'de-DE' => 'Odoo Währung Fehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Currency Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_CURRENCY . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'currency',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_TAX,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Steuer ',
                    'en-GB' => 'Odoo Tax'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_TAX . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Tax Id',
                            'de-DE' => 'Odoo Steuer n Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Tax Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ],
                ],
                [
                    'name' => self::ODOO_SHOPWARE_TAX . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Tax Error',
                            'de-DE' => 'Odoo Steuer n Fehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Tax Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_TAX . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'tax',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_LANGUAGE,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Sprache',
                    'en-GB' => 'Odoo Language'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_LANGUAGE . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Language Id',
                            'de-DE' => 'Odoo Sprache Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Language Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_LANGUAGE . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Language Error',
                            'de-DE' => 'Odoo Sprachfehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Language Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_LANGUAGE . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'language',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_SALESCHANNEL,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Vertriebskanal',
                    'en-GB' => 'Odoo Sales Channel'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_SALESCHANNEL . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Sales Channel Id',
                            'de-DE' => 'Odoo Vertriebskanal Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Sales Channel Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_SALESCHANNEL . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Sales Channel Error',
                            'de-DE' => 'Odoo Vertriebskanal-Fehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Sales Channel Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_SALESCHANNEL . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'sales_channel',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_DELIVERYTIME,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Lieferzeiten',
                    'en-GB' => 'Odoo Delivery Time'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_DELIVERYTIME . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Delivery Time Id',
                            'de-DE' => 'Odoo Lieferzeiten Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Delivery Time Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_DELIVERYTIME . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Delivery Time Error',
                            'de-DE' => 'Odoo Lieferzeiten-Fehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Delivery Time Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_DELIVERYTIME . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'delivery_time',
                ],
            ],
        ],
        [
            'name' => self::ODOO_SHOPWARE_LANGUAGE,
            'position' => 1,
            'config' => [
                'label' => [
                    'de-DE' => 'Odoo Sprache',
                    'en-GB' => 'Odoo Language'
                ],
                'translated' => true,
            ],
            'customFields' => [
                [
                    'name' => self::ODOO_SHOPWARE_LANGUAGE . self::ID,
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Language Id',
                            'de-DE' => 'Odoo Sprache Id',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Language Id'
                        ],
                        'customFieldType' => 'int',
                        'customFieldPosition' => 1
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_LANGUAGE . self::ERROR,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Odoo Language Error',
                            'de-DE' => 'Odoo Sprachfehler',
                            Defaults::LANGUAGE_SYSTEM => 'Odoo Language Error'
                        ],
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2
                    ]
                ],
                [
                    'name' => self::ODOO_SHOPWARE_LANGUAGE . self::UPDATEAT,
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update Time',
                            'de-DE' => 'Letzte Aktualisierungszeit',
                            Defaults::LANGUAGE_SYSTEM => 'Last Update Time'
                        ],
                        'customFieldType' => 'date',
                        'customFieldPosition' => 3
                    ]
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'language',
                ],
            ],
        ],
    ];

    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
    )
    {
    }

    public function install(Context $context): void
    {
        $customFieldCount = count(self::CUSTOM_FIELDSET_NAME);
        for ($i = 0; $i < $customFieldCount; $i++) {
            $name = self::CUSTOM_FIELDSET_NAME[$i];
            $getReferralCustomFieldSet = $this->getCustomFieldSetIds($name, $context);
            if (!$getReferralCustomFieldSet) {
                $this->customFieldSetRepository->upsert([
                    self::CUSTOM_FIELDSET[$i]
                ], $context);
            }
        }
    }

    public function getCustomFieldSetIds($name, $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));
        return $this->customFieldSetRepository->searchIds($criteria, $context)->getIds();
    }

    public function uninstall(Context $context): void
    {
        $customFieldCount = count(self::CUSTOM_FIELDSET_NAME);
        for ($i = 0; $i < $customFieldCount; $i++) {
            $name = self::CUSTOM_FIELDSET_NAME[$i];
            $getReferralCustomFieldSet = $this->getCustomFieldSetIds($name, $context);
            if ($getReferralCustomFieldSet) {
                $this->customFieldSetRepository->delete([
                    ['id' => $getReferralCustomFieldSet[0]]
                ], $context);
            }
        }
    }
}