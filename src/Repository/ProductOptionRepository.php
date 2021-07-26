<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class ProductOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, ParameterBagInterface $parameterBag)
    {
        parent::__construct($registry, $parameterBag->get('sylius.model.product_option.class'));
    }

    public function getRemovedOptionIds(array $codes): array
    {
        $removedOptionResults = $this->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.code NOT IN (:codes)')
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getResult()
        ;

        if (0 === \count($removedOptionResults)) {
            return [];
        }

        return array_map(function (array $data) {
            return $data['id'];
        }, $removedOptionResults);
    }
}
