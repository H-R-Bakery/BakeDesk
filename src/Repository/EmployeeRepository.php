<?php

namespace App\Repository;

use App\Entity\Employee;
use App\Repository\AbstractServiceEntityRepository;

/** @extends AbstractServiceEntityRepository<Employee> */
class EmployeeRepository extends AbstractServiceEntityRepository
{
    /**
     * @inheritDoc
     */
    public static function getEntityClass(): string
    {
        return Employee::class;
    }

    /**
     * @return list<Employee>
     */
    public function findActiveOrdered(): array
    {
        return $this->findBy(['active' => true], ['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
    }
}
