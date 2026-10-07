<?php

namespace App\Repository;

use App\Entity\Employee;

/** @extends AbstractServiceEntityRepository<Employee> */
class EmployeeRepository extends AbstractServiceEntityRepository
{
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

    /**
     * @return list<Employee>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']);
    }
}
