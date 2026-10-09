<?php

namespace App\Repository;

use App\Entity\User;

class UserRepository extends AbstractServiceEntityRepository
{
    public static function getEntityClass(): string
    {
        return User::class;
    }

    /**
     * @return list<User>
     */
    public function findActiveOrdered(): array
    {
        return $this->findBy(['active' => true], ['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * This matches NewOrderType, which currently offers every active user.
     */
    public function countAvailableForOrderEntry(): int
    {
        return (int) $this->createQueryBuilder('user')
            ->select('COUNT(user.id)')
            ->andWhere('user.active = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<User>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
    }
}
