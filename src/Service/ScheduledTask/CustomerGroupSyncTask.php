<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class CustomerGroupSyncTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'shopware_odoo.customer.group.sync';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // 1 Day
    }
}
