<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ProductType;
use App\Entity\Unit;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        foreach ([
            ['Donuts', 10],
            ['Brownies', 20],
            ['Cookies', 30],
            ['Shape Cookies', 40],
        ] as [$name, $sortOrder]) {
            $manager->persist(
                (new ProductType())
                    ->setName($name)
                    ->setActive(true)
                    ->setSortOrder($sortOrder),
            );
        }

        foreach ([
            ['Each', 'ea', 10],
            ['Dozen', 'doz', 20],
            ['Half Dozen', '1/2 doz', 30],
            ['Tray', 'tray', 40],
            ['Box', 'box', 50],
        ] as [$name, $abbreviation, $sortOrder]) {
            $manager->persist(
                (new Unit())
                    ->setName($name)
                    ->setAbbreviation($abbreviation)
                    ->setActive(true)
                    ->setSortOrder($sortOrder),
            );
        }

        $manager->flush();
    }
}
