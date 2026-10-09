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
    public function findActiveOrderTakers(): array
    {
        return $this->findBy(
            ['active' => true, 'employee' => true],
            ['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC'],
        );
    }

    public function countAvailableForOrderEntry(): int
    {
        return (int) $this->createQueryBuilder('user')
            ->select('COUNT(user.id)')
            ->andWhere('user.active = :active')
            ->andWhere('user.employee = :employee')
            ->setParameter('active', true)
            ->setParameter('employee', true)
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
