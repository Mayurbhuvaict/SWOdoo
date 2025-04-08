<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class SyncOrderTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'shopware_odoo.order.sync';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // 1 Day
    }
}
