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
     * @return list<User>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
    }
}
