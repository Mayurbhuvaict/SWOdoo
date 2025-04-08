<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Core\Content\OdooOrderStatus;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class OdooOrderStatusDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'odoo_order_status';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return OdooOrderStatusCollection::class;
    }

    public function getEntityClass(): string
    {
        return OdooOrderStatusEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection(
            [
                (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
                (new StringField('odoo_status_type', 'odooStatusType'))->addFlags(new Required()),
                (new StringField('odoo_status_key', 'odooStatusKey'))->addFlags(new Required()),
                (new StringField('odoo_status', 'odooStatus'))->addFlags(new Required())
            ]
        );
    }
}
