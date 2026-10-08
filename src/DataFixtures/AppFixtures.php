<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Customer;
use App\Entity\Employee;
use App\Entity\PackagingRule;
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

        $productTypes = [];
        foreach ([
            ['Donuts', 10],
            ['Brownies', 20],
            ['Cookies', 30],
            ['Shape Cookies', 40],
        ] as [$name, $sortOrder]) {
            $productType = (new ProductType())
                ->setName($name)
                ->setActive(true)
                ->setSortOrder($sortOrder);
            $productTypes[$name] = $productType;
            $manager->persist($productType);
        }

        $units = [];
        foreach ([
            ['Each', 'ea', 10, false],
            ['Dozen', 'doz', 20, true],
            ['Half Dozen', '1/2 doz', 30, false],
            ['Tray', 'tray', 40, true],
            ['Box', 'box', 50, true],
        ] as [$name, $abbreviation, $sortOrder, $isPackageUnit]) {
            $unit = (new Unit())
                ->setName($name)
                ->setAbbreviation($abbreviation)
                ->setActive(true)
                ->setPackageUnit($isPackageUnit)
                ->setSortOrder($sortOrder);
            $units[$name] = $unit;
            $manager->persist($unit);
        }

        foreach ([
            [$productTypes['Donuts'], $units['Each'], '12'],
            [$productTypes['Cookies'], $units['Each'], '24'],
        ] as [$productType, $units, $quantityPerPackage]) {
            $manager->persist((new PackagingRule())
                ->setProductType($productType)
                ->setUnit($units)
                ->setQuantityPerPackage($quantityPerPackage)
                ->setActive(true));
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

        foreach ([
            ['Cory Baker', true],
            ['Adrienne Baker', true],
            ['John Smith', true],
        ] as [$name, $active]) {
            $manager->persist((new Employee())
                ->setName($name)
                ->setActive($active)
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
