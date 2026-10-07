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
 * @method T|null find($id, $lockMode = null, $lockVersion = null)
 * @method T|null findOneBy(array $criteria, array $orderBy = null)
 * @method T[] findAll()
 * @method T[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
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
	 * @param object<T> $entity
	 */
	public function save(object $entity, bool $flush = false): void
	{
		$this->getEntityManager()->persist($entity);

		if ($flush) {
			$this->getEntityManager()->flush();
		}
	}

	/**
	 * @param object<T> $entity
	 */
	public function remove(object $entity, bool $flush = false): void
	{
		$this->getEntityManager()->remove($entity);

		if ($flush) {
			$this->getEntityManager()->flush();
		}
	}

	/**
	 * @param object<T> $entity
	 *
	 * @return object<T>
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
