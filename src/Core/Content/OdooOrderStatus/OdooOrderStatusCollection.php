<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Core\Content\OdooOrderStatus;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @package core
 * @method void                add(OdooOrderStatusEntity $entity)
 * @method void                set(string $key, OdooOrderStatusEntity $entity)
 * @method OdooOrderStatusEntity[]    getIterator()
 * @method OdooOrderStatusEntity[]    getElements()
 * @method OdooOrderStatusEntity|null get(string $key)
 * @method OdooOrderStatusEntity|null first()
 * @method OdooOrderStatusEntity|null last()
 */
class OdooOrderStatusCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return OdooOrderStatusEntity::class;
    }
}
