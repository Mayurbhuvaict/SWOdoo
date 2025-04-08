<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Subscriber;

use GuzzleHttp\Exception\GuzzleException;
use ICTECHOdooShopwareConnector\RestApi\OdooClient;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CustomerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly odooClient $odooClient,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerRegisterEvent::class => 'onRegister',
            CustomerEvents::CUSTOMER_WRITTEN_EVENT => 'onWrittenCustomerEvent',
            CustomerEvents::CUSTOMER_ADDRESS_WRITTEN_EVENT => 'onWrittenCustomerAddressEvent',
        ];
    }

    /**
     * @throws GuzzleException
     */
    public function onRegister(CustomerRegisterEvent $event): void
    {
        $this->odooClient->importCustomer($event->getCustomer(), $event->getContext());
    }

    public function onWrittenCustomerEvent(EntityWrittenEvent $event): void
    {
        $updatedData = $event->getWriteResults()[0]->getPayload();
        if (array_key_exists('lastLogin', $updatedData) || array_key_exists('remoteAddress', $updatedData)) {
            return;
        }
        $this->odooClient->updateCustomer($updatedData, $event->getContext());
    }

    public function onWrittenCustomerAddressEvent(EntityWrittenEvent $event): void
    {
        $updatedData = $event->getPayloads()[0];
        $this->odooClient->updateCustomerAddress($updatedData, $event->getContext());
    }
}
