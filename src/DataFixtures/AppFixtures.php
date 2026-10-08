<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Customer;
use App\Entity\Printer;
use App\Entity\ProductType;
use App\Entity\Unit;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use libphonenumber\PhoneNumberUtil;

final class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $phoneUtil = PhoneNumberUtil::getInstance();

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

        foreach ([
            ['Justin Davis', true, '+18129896767'],
            ['Amanda Davis', true, '+18128966936'],
            ['Steven Davis', true, '+18128966935'],
        ] as [$name, $active, $phone]) {
            $manager->persist((new Customer())
                ->setName($name)
                ->setActive($active)
                ->setPhone($phoneUtil->parse($phone))
            );
        }

        $manager->persist((new Printer())
            ->setName('Labeler')
            ->setAddress('ipp://192.168.20.40/ipp/print')
            ->setForLabels(true)
            ->setDefaultForLabels(true)
            ->setActive(true)
        );

        $manager->persist((new Printer())
            ->setName('Reporter')
            ->setAddress('ipp://192.168.20.40/ipp/print')
            ->setForReports(true)
            ->setActive(true)
        );

        $manager->flush();
    }
}
