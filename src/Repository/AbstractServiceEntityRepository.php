<?php

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @experimental
 *
 * @template T of object
 *
 * @noinspection PhpUndefinedClassInspection
 *
 * @extends ServiceEntityRepository<T>
 *
 * @method T|null  find(mixed $id, ?int $lockMode = null, ?int $lockVersion = null)
 * @method T|null  findOneBy(array<string, mixed> $criteria, ?array<string, string> $orderBy = null)
 * @method list<T> findAll()
 * @method list<T> findBy(array<string, mixed> $criteria, ?array<string, string> $orderBy = null, ?int $limit = null, ?int $offset = null)
 */
abstract class AbstractServiceEntityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, static::getEntityClass());
    }

    /**
     * @return class-string<T>
     */
    abstract public static function getEntityClass(): string;

    /**
     * @param T $entity
     */
    public function save(object $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param T $entity
     */
    public function remove(object $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param T $entity
     *
     * @return T
     */
    public function refresh(object $entity): object
    {
        try {
            $this->getEntityManager()->refresh($entity);
        } catch (ORMException) {
            return $entity;
        }

        return $entity;
    }
}
