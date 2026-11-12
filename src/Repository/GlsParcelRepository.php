<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Repository;

use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\ResourceRepositoryTrait;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @extends ServiceEntityRepository<GlsParcel>
 * @implements RepositoryInterface<GlsParcel>
 */
class GlsParcelRepository extends ServiceEntityRepository implements RepositoryInterface
{
    use ResourceRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GlsParcel::class);
    }

    public function findOneByShipment(ShipmentInterface $shipment): ?GlsParcel
    {
        $parcel = $this->findOneBy(['shipment' => $shipment]);

        return $parcel instanceof GlsParcel ? $parcel : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<GlsParcel>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->findBy(['id' => $ids], ['id' => 'ASC']);
    }

    public function createAdminGridQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('p')->orderBy('p.createdAt', 'DESC');
    }
}
