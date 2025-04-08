<?php declare(strict_types=1);

namespace ICTECHOdooShopwareConnector\Core\Content\OdooOrderStatus;

use DateTimeInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class OdooOrderStatusEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $odooStatusType;

    /**
     * @var string
     */
    protected $odooStatusKey;

    /**
     * @var string
     */
    protected $odooStatus;

    /**
     * @var DateTimeInterface
     */
    protected $createdAt;

    /**
     * @var DateTimeInterface|null
     */
    protected $updatedAt;

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getOdooStatusType(): string
    {
        return $this->odooStatusType;
    }

    public function setOdooStatusType(string $odooStatusType): void
    {
        $this->odooStatusType = $odooStatusType;
    }

    public function getOdooStatusKey(): string
    {
        return $this->odooStatusKey;
    }

    public function setOdooStatusKey(string $odooStatusKey): void
    {
        $this->odooStatusKey = $odooStatusKey;
    }

    public function getOdooStatus(): string
    {
        return $this->odooStatus;
    }

    public function setOdooStatus(string $odooStatus): void
    {
        $this->odooStatus = $odooStatus;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
