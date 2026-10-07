# AGENTS.md

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Project architecture

This project is BakeSlip, a local bakery order-management application.

Established decisions:

- Symfony 8.1, kept on the latest stable Symfony release.
- PHP 8.4+.
- Doctrine ORM with PostgreSQL.
- Server-rendered Twig application with Stimulus/Turbo where useful.
- Redis for Symfony Messenger.
- Mercure for realtime UI updates.
- EasyAdmin will be used for the secured administration area.
- Public ordering and production-report screens do not require authentication.
- `/admin` requires authentication.
- Printing is asynchronous through Messenger.
- Printer configuration is stored in the database and uses IPP addresses.
- Label and report printers are configurable; do not hard-code a printer model.
- CUPS/IPP printing infrastructure must stay isolated behind a printer service abstraction.
- Raspberry Pi is the intended production host.
- Application services run with Docker Compose; hardware-facing CUPS runs on the host.
- Use backed PHP enums for internal workflow/state values such as OrderStatus and PrintJobStatus. Do not use enums for admin-managed reference data such as Employee, ProductType, Unit, or Printer.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
  Don't mark a service `readonly` if it might become `lazy: true`: a lazy proxy
  can't extend a `readonly` class.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).

## Domain rules

Core entities planned for V1:

- Customer
- Employee
- Order
- OrderItem
- ProductType
- Unit
- Printer
- PrintJob

Order-entry rules:

- Employee selection is a small radio-button list.
- The browser remembers the last selected employee locally.
- Customer name/phone autocomplete existing customers.
- Saving an order automatically creates a customer when no existing customer matches.
- Front-counter users never explicitly create customers.
- Customer phone numbers should be normalized for matching/search.
- Orders preserve customer name/phone snapshots even when linked to a Customer.
- Pickup is stored as a datetime.
- Orders have human-readable order numbers.
- Cancelled orders are retained rather than deleted.

Order item fields:

- Product type
- Quantity
- Unit
- Description

Initial product types:

- Donuts
- Brownies
- Cookies
- Shape Cookies

Initial units:

- Each
- Dozen
- Half Dozen
- Tray
- Box

Production reports:

- Group by product type.
- Do not normalize free-form descriptions in V1.
- Show totals by identical unit where useful.
- Reports can be downloaded or asynchronously printed to a configured report printer.

Printing:

- Printers have a name, IPP address, active state, and capabilities such as labels/reports.
- Printers are not tied to JADENS or any other vendor.
- Labels are 4x6.
- Saving an order must succeed independently of printing.
- Print jobs are durable database entities.
- Messenger messages should contain identifiers, not Doctrine entities.
- Print status should be publishable through Mercure.

## Definition of done

Before considering a feature complete:

1. Run relevant PHPUnit tests.
2. Run `bin/console lint:container`.
3. Run Twig/YAML linters when those files changed.
4. Run PHPStan when installed.
5. Run php-cs-fixer check when installed.
6. For Doctrine model changes:
   - generate a migration,
   - inspect the migration,
   - do not use `doctrine:schema:update`.
7. Do not leave generated TODOs or placeholder implementations.
