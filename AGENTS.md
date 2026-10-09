# AGENTS.md

## Project overview

This project is **BakeDesk**, a local bakery order-management application.

It replaces handwritten phone-order slips with a browser-based ordering system, production reports, customer history, and printed 4x6 order labels.

The intended production host is a Raspberry Pi on the bakery's local network.

Before changing code, inspect `composer.json`, `symfony.lock`, and the existing project configuration. Do not assume a Symfony component or third-party package is installed just because it would be useful.

Use the versions actually installed by the project.

---

## Established architecture

These architectural decisions have already been made. Do not replace them with alternatives unless explicitly requested.

- Symfony 8.1+, kept current with the latest stable Symfony release.
- PHP 8.4+.
- Doctrine ORM.
- PostgreSQL as the application database.
- Server-rendered Twig pages.
- Stimulus and Turbo where they improve the UI.
- Valkey, the Redis-compatible service, for Symfony Messenger.
- Mercure for realtime browser updates.
- EasyAdmin for the secured administration area.
- Docker Compose for application infrastructure.
- Raspberry Pi as the intended production host.
- Hardware-facing CUPS runs on the Raspberry Pi host rather than inside the application container.
- Printers are accessed through IPP.
- No printer vendor or model may be hard-coded into application logic.

The public ordering and production-report interfaces do not currently require authentication.

The `/admin` area must require authentication.

EasyAdmin is used only for secured administrator-managed reference and configuration data.
The normal bakery operational UI remains purpose-built and unauthenticated for V1.
PackagingRules and Printers are managed through the administration area.
The main operational navbar always displays a link to the EasyAdmin dashboard.
Symfony security protects `/admin` and redirects unauthenticated visitors to
login. EasyAdmin Order management permits partial editing of pickupAt, paid,
status, and notes while operational order creation and editing remain in the
purpose-built BakeDesk UI. EasyAdmin Order status changes must use the same
lifecycle services and realtime publication behavior as operational actions.
Cancelled Orders remain terminal, and successful EasyAdmin Order modifications
update `updatedAt` using the bakery clock.
Orders must not be deleted.

---

## Core domain

The initial domain consists of:

- `Customer`
- `User`
- `Order`
- `OrderItem`
- `ProductType`
- `Unit`
- `PackagingRule`
- `Printer`
- `PrintJob`

Workflow/state concepts should use backed PHP enums where appropriate.

Initial enums include:

- `OrderStatus`
- `PrintJobStatus`
- `PrintDocumentType`

Use PHP enums for internal workflow/state values.

Do **not** use enums for administrator-managed reference data such as:

- product types
- units
- printers

These must remain database entities so they can be managed without changing code.

---

## Entity identifiers

Use normal PostgreSQL integer/bigint identity primary keys for application entities.

Do not introduce UUIDs unless a specific future requirement justifies them.

Human-facing order numbers must be separate from database primary keys.

For example:

- database ID: internal identity
- order number: value displayed to bakery staff and printed on labels

---

## Customer behavior

Customers are persistent records.

Initial customer data includes at least:

- name
- phone number
- active state
- created timestamp
- updated timestamp

Customer name and phone number must autocomplete during order entry.

Autocomplete should search both:

- customer name
- phone number using the installed libphonenumber integration's canonical representation

Order takers should never have to explicitly create a customer while taking an order.

When an order is saved:

1. Use the selected customer if one was chosen.
2. Otherwise attempt to match an existing customer, primarily by the canonical phone number representation provided by the installed libphonenumber integration.
3. If no matching customer exists, automatically create one.
4. Associate the order with that customer.

Do not interrupt the front-counter order workflow with duplicate-customer management.

Customer cleanup and future duplicate merging belong in the administration area.

### Customer snapshots

An order must preserve the customer information used when that order was created.

The `Order` should therefore retain snapshot fields such as:

- customer name
- customer phone

even when the order is associated with a `Customer` entity.

Editing the customer record later must not rewrite historical orders.

Operational staff may view customer history at a purpose-built route outside
EasyAdmin. Customer history includes Orders in every lifecycle status, and
historical Order customer snapshots remain authoritative for historical display.
Active Customers may prefill the existing New Order form. Inactive Customers
must not be silently selected for new Orders. Customer history does not duplicate
Order creation logic; New Order prefill continues through the normal
CustomerResolver and order creation behavior.

---

## Order takers

Order takers are eligible `User` records. There is no separate Employee entity.

There are currently only a small number of order takers.

Order-taker selection on the order-entry form should use radio buttons rather than autocomplete.

The browser should remember the most recently selected order taker using browser-local storage.

This preference is browser-specific and is not an authenticated-user preference.

If the previously remembered order taker is inactive or no longer exists, it must not be automatically selected.

Do not require user authentication for order entry.

The selected User simply identifies who took the phone order.

---

## Orders

Initial order information includes:

- human-readable order number
- associated customer
- customer name snapshot
- customer phone snapshot
- User who took the order
- pickup datetime
- order timestamp
- paid boolean
- order status
- optional notes
- created timestamp
- updated timestamp

Initial `OrderStatus` values are:

- `OPEN`
- `COMPLETED`
- `CANCELLED`

Orders may transition from `OPEN` to `COMPLETED`, and accidentally completed
orders may transition from `COMPLETED` back to `OPEN`. `CANCELLED` is terminal
for V1. Paid state and order lifecycle status are independent; completing an
unpaid order does not change its payment state.

Cancelled orders must be retained.

Do not delete an order merely because it was cancelled.

Do not add additional workflow statuses such as baking, ready, packed, or picked-up unless explicitly requested.

### Payments

V1 is **not** a POS or payment-processing system.

Do not add:

- prices
- subtotals
- taxes
- discounts
- payment transactions
- tender types
- payment integrations

The only payment-related field in V1 is a simple boolean indicating whether the order has been paid.

Future Toast integration may change this, but it is outside the initial implementation.

---

## Order items

Each order can contain multiple order items.

An order item initially contains:

- product type
- quantity
- unit
- description
- sort order if needed for preserving entry order

Do not introduce a full Product/MenuItem entity in V1 unless explicitly requested.

The description is intentionally free-form.

### Initial product types

Seed administrator-managed `ProductType` records for:

- Donuts
- Brownies
- Cookies
- Shape Cookies

### Initial units

Seed administrator-managed `Unit` records for:

- Each
- Dozen
- Half Dozen
- Tray
- Box

Units must not be PHP enums because administrators may need to add or change them later.

Do not automatically rewrite stored or displayed order quantities and units.

Every Unit resolves globally to a configurable number of individual items
(`Each`) through its required decimal `eachEquivalent` value. Unit names have
no hard-coded mathematical meaning, and the value does not vary by
`ProductType`. Production totals calculate `OrderItem.quantity ×
Unit.eachEquivalent` using decimal-safe arithmetic and the current Unit
configuration. This derived production quantity does not change the original
OrderItem or its package label content.

For example:

- `12 Each`
- `1 Dozen`

remain separate quantities unless a future requirement explicitly adds normalization.
Production conversion is a separate calculation for reports.

`eachEquivalent` and `isPackageUnit` answer separate questions. The former
converts a Unit to individual production items; the latter determines physical
package and label behavior. PackagingRule calculations and production
calculations remain separate concerns.

Production reports initially use the currently configured `eachEquivalent`.
Conversion history is not versioned in this task, so later Unit changes can
affect future calculations without rewriting historical OrderItems.

### Physical package labels

Labels represent physical packages or boxes, not entire Orders. A Unit marked
`isPackageUnit` represents one physical package per whole unit; this behavior
must come from the database flag rather than hard-coded unit names.

`PackagingRule` records are optional. A rule means: “When this ProductType is
ordered using this Unit, split the quantity into packages of this capacity.”
Package Units create one package per whole Unit quantity. Non-package Units with
an active rule are split according to that rule. Non-package Units without an
active rule default to one package containing the entire OrderItem quantity.
Missing PackagingRules must never prevent label creation. Package arithmetic
must be decimal-safe and must not use PHP binary floating point. A fractional
quantity for a package Unit is a focused packaging error; use a separate
configurable Unit such as `Half Dozen` instead.

---

## Production reports

Production reports are generated for a selected pickup date.

Production report totals are grouped by ProductType and convert every
contributing OrderItem to Each using the Unit's current eachEquivalent. Report
totals are independent of package and label allocation, include `OPEN` and
`COMPLETED` Orders, exclude `CANCELLED` Orders, and use one shared report model
for browser and PDF output.

V1 uses simple reporting rather than a normalized product catalog.

Reports should:

- group order items by product type
- retain each item's free-form description
- retain its entered unit
- show useful totals for identical units within the same product type where practical

Do not attempt to normalize free-form descriptions.

For example, do not automatically combine:

- `Glazed`
- `glazed donuts`
- `Plain Glazed`

into one product.

The production report tells bakers broadly how much of each product type must be produced.

The printed order label tells packing staff how the individual customer's order must be assembled.

Each physical package receives its own label and its own LABEL `PrintJob`.
Package allocations are calculated fulfillment data and are not persisted as
entities. A package `PrintJob` stores nullable package metadata so historical
whole-order print jobs remain readable.

Reports must be available for download.

Reports may also be asynchronously sent to an IPP printer configured for report printing.

User-initiated report printing creates a `PrintDocumentType::REPORT` `PrintJob`
for the explicitly selected active report printer and dispatches it through
Messenger. Report rendering reuses `ProductionReportBuilder` and the browser
PDF renderer. Label-specific printer options must not be applied to report
jobs.

---

## Printers

Printers are database-managed entities.

Printers are not tied to a specific manufacturer or model.

A printer should include, at minimum:

- name
- IPP address/URI
- active state
- whether it may print labels
- whether it may print reports
- whether it is the active default label printer

Additional configuration may be added later when demonstrated by an actual requirement.

Do not hard-code:

- JADENS
- JD-668BT
- CUPS queue names
- IP addresses
- development printers

into domain or printing logic.

The currently planned JADENS printer is only one possible configured printer.

Development must be able to target another IPP printer.

### Printer abstraction

Printing infrastructure must be isolated behind an application service abstraction.

Order controllers, report controllers, and Messenger handlers must not contain raw CUPS/IPP commands.

Printing-related responsibilities should be separated so that another IPP implementation can be substituted later.

BakeDesk performs IPP printing through the selected PHP IPP client behind `PrinterClientInterface`. The configured printer address remains the complete direct or CUPS IPP URI.

Production report printing is initiated by the user for a selected report
date and printer. It remains asynchronous through the durable `PrintJob` and
Messenger pipeline; scheduled report printing is outside V1.

### Label document rendering and storage

Gotenberg 8 is the application PDF rendering service for HTML documents, using the official `gotenberg/gotenberg-php` client.

Generated documents are private application artifacts stored through `league/flysystem-bundle`; application code must use the Flysystem abstraction rather than depending on local filesystem paths.
`PrintJob` stores the generated document's logical Flysystem path, never an absolute filesystem path.

---

## Printing workflow

All application-initiated printing is asynchronous.

Saving an order and printing its labels are separate operations.

An order save must succeed even if printing fails.

Expected flow:

1. Validate the order.
2. Persist the order and order items.
3. Calculate every physical package before creating label jobs.
4. If all allocations succeed, persist one LABEL `PrintJob` per package.
5. Commit the database transaction.
6. Dispatch one Messenger message per print-job identifier.
7. Return control to the browser.
8. A Messenger worker processes each print job independently.
9. The worker renders the package document.
10. The worker submits it to the configured IPP printer.
11. The worker records the accepted external IPP job identifier and asynchronously refreshes its status.
12. Print status changes are persisted to PostgreSQL.
13. Relevant status changes may be published through Mercure.

If a package allocation fails, the Order remains saved, no initial label jobs
are created for that Order, and the queueing error identifies the ProductType
and Unit when applicable.

Reprinting creates a new LABEL `PrintJob` from the current Order and current
package allocation. It uses the current default active label printer.
Retrying creates a new LABEL `PrintJob` from a FAILED label job's historical
context and uses that job's original printer. Historical PrintJobs are
immutable workflow history and are never reset for staff retries. When a
failed job has a stored rendered document, a retry reuses that document;
otherwise it starts queued so the document is rendered again.

CANCELLED Orders cannot produce new labels. All print, reprint, and retry
actions are asynchronous and use POST routes protected by scoped CSRF tokens.

Never put Doctrine entities directly into Messenger messages.

Administrators may cancel PrintJobs only while they are `QUEUED`, `PROCESSING`,
or `RENDERED`; arbitrary PrintJob status editing remains forbidden. Terminal
PrintJobs (`COMPLETED`, `FAILED`, or `CANCELLED`) may be deleted administratively
with confirmation. Deleting a PrintJob does not imply deleting its generated
Flysystem document, which may be shared by retries or report paths.

Messenger messages should contain scalar identifiers, such as a `PrintJob` ID.

---

## Print jobs

`PrintJob` is durable application state stored in PostgreSQL.

Valkey is only the queue transport and is not the source of truth for print history.

A print job should retain enough information to diagnose a failed print attempt.

Likely information includes:

- associated printer
- document type
- status
- associated order when applicable
- associated order item and package number/count/quantity for new package labels
- report parameters when applicable
- external IPP job identifier when available
- attempt count
- created timestamp
- started timestamp
- submitted timestamp
- completed timestamp
- error information

Initial `PrintDocumentType` values are:

- `REPORT`
- `LABEL`

Initial `PrintJobStatus` values are:

- `QUEUED`
- `PROCESSING`
- `RENDERED`
- `SUBMITTED`
- `COMPLETED`
- `FAILED`
- `CANCELLED`

Meaning:

- `QUEUED`: persisted and waiting for a Messenger worker
- `PROCESSING`: worker is preparing/rendering the document
- `SUBMITTED`: document submission was accepted by the printer/IPP subsystem; physical completion may still be unknown
- `COMPLETED`: printer subsystem reported successful completion
- `FAILED`: processing or printing failed
- `CANCELLED`: job was intentionally cancelled
- `RENDERED`: the application generated and stored the document; no printer submission has occurred yet.

Document submission and physical completion are distinct states. Do not assume every printer can reliably report physical print completion.

For some printers, `SUBMITTED` may be the strongest reliable success indication.

UI wording must not claim physical printing occurred unless the underlying printer status supports that claim.

---

## Messenger

Use Symfony Messenger for asynchronous printing.

Valkey is the intended Messenger transport.

Printing should have its own transport/queue.

The print queue should initially use a single worker so jobs destined for physical printers are handled predictably and sequentially.

Do not introduce RabbitMQ or another queue system unless specifically requested.

Use Symfony's retry facilities rather than implementing hand-written retry loops.

Retries for physical printing should remain conservative to avoid unexpected duplicate labels. Avoid blindly resubmitting when a network failure leaves the IPP acceptance outcome unknown; duplicate-print avoidance is more important than an automatic retry.

Application-level `PrintJob` state remains authoritative even when Messenger retries occur.

---

## Mercure and realtime updates

Mercure is used for realtime-ish browser updates.

Potential realtime information includes:

- queued print job
- processing print job
- submitted print job
- completed print job
- failed print job
- future order/dashboard updates

The database remains the source of truth.

Mercure updates are notifications, not durable state.

Order lifecycle state changes publish best-effort Mercure order updates after
successful persistence. Publication failure does not fail the state change.

A browser that reconnects must be able to retrieve current state from Symfony/PostgreSQL.

Do not require Mercure for basic order creation or reporting to function.

Mercure provides best-effort realtime UI updates. Database and application state
remain authoritative, and Mercure publication failure must never fail an order
operation or printing. Operational pages use small JSON Mercure events and
Stimulus DOM updates. The Orders list subscribes to order-summary changes;
Order detail and order-created pages subscribe to per-order changes, including
label PrintJob status. Do not duplicate server-side filtering or business rules
in JavaScript, and do not publish sensitive or unnecessary customer data. The
operational UI uses `bakedesk:orders` for global order summaries and
`bakedesk:order:{id}` for per-order order and label PrintJob events.

---

## Administration

The administration area will use EasyAdmin.

The application branding values are configured through the parameters in
`config/services.yaml` and their environment variables. The committed defaults
use the H&R Bakery logo assets for the main UI, favicon, EasyAdmin branding,
and print contexts where applicable.

EasyAdmin is intended for management of data such as:

- customers
- users eligible to take orders
- product types
- units
- printers
- application configuration where appropriate

The administration area must be authenticated.

The normal order-entry and production-report interfaces are public on the bakery LAN for V1.

Do not build the front-counter order workflow as EasyAdmin CRUD screens.

Order entry should have a purpose-built interface optimized for speed and clarity.

---

## Interface conventions

The primary application interface is server-rendered Twig.

Use Symfony Forms for normal server-rendered form handling.

Use Stimulus for targeted browser behavior such as:

- order-taker local-storage preference
- customer autocomplete
- dynamic order-item rows
- realtime status display

Use Turbo where it provides a clear benefit.

Do not introduce React, Vue, Angular, or a separate SPA/API frontend unless explicitly requested.

Controllers must remain thin and delegate business logic to services.

---

## Symfony conventions

Follow the Symfony best practices appropriate to the installed Symfony version.

Prefer PHP attributes for framework metadata, including:

- `#[Route]`
- `#[IsGranted]`
- `#[Assert\...]`
- `#[AsCommand]`
- `#[AsEventListener]`
- `#[AsMessageHandler]`
- `#[Autowire]`
- related framework attributes where appropriate

Do not add YAML/XML routing when PHP attribute routing is appropriate.

Rely on autowiring and autoconfiguration.

Type-hint constructor dependencies and allow the service container to resolve them.

Use service configuration only when the dependency cannot reasonably be expressed through normal autowiring or Symfony attributes.

Use Symfony components rather than hand-written infrastructure when Symfony already provides the capability.

Examples:

- Form for forms
- Validator for validation
- Security for authentication/authorization
- Messenger for async work
- Lock for mutual exclusion
- HttpClient for HTTP integrations
- Serializer for serialization
- Mercure for realtime publishing

Do not reinvent these facilities unnecessarily.

---

## Concurrency

Assume multiple bakery users may submit or modify orders at approximately the same time.

Do not rely on process-local flags or static variables for concurrency control.

When actual mutual exclusion is required, use Symfony Lock.

Printing must not block the HTTP request that saves an order.

---

## Doctrine conventions

Use Doctrine ORM mappings with PHP attributes.

Schema changes must use Doctrine migrations.

Use:

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

Do not use:

```bash
doctrine:schema:update
```

Do not hand-write production schema changes directly in PostgreSQL.

Inspect generated migrations before considering them complete.

Repositories should contain meaningful persistence/query logic.

Do not create custom repository methods merely to wrap `find()`, `findAll()`, or other existing Doctrine functionality.

---

## Timestamps and timezones

The application represents one bakery operating in one configured bakery timezone.

Do not rely implicitly on the PHP, container, PostgreSQL, or operating-system timezone.

Audit timestamps should be represented consistently.

Pickup time is bakery-local business time and must be displayed accordingly.

Avoid scattering timezone conversion logic throughout controllers and templates.

---

## Docker and production infrastructure

Docker Compose is used for application infrastructure.

Expected containerized services include, as applicable:

- Symfony/PHP application
- web server
- Messenger worker
- PostgreSQL
- Valkey
- Mercure

CUPS remains on the Raspberry Pi host because it is hardware-facing.

Do not require USB printer devices inside normal application containers.

Persistent application data must not depend on disposable container filesystems.

Database and other persistent data require explicit persistent storage.

---

## Environment configuration

`.env` contains committed defaults only.

Do not put production secrets in `.env`.

Use:

- `.env.local`
- environment variables
- Symfony secrets

for sensitive deployment-specific values.

Do not hard-code deployment hostnames, credentials, printer addresses, or IP addresses into PHP source.

---

## Adding packages

Prefer Composer and Symfony Flex.

Use:

```bash
composer require ...
```

or:

```bash
composer require --dev ...
```

rather than manually wiring Symfony bundles.

Allow Flex recipes to register bundles and generate configuration where appropriate.

Do not manually edit `config/bundles.php` when a valid Flex recipe should perform that work.

Before adding a third-party package, first check whether Symfony or PHP already provides an appropriate solution.

Do not add packages speculatively.

Add them when an actual implementation requires them.

---

## Coding style

Use modern PHP appropriate to the configured PHP version.

Prefer:

- constructor property promotion
- strict type declarations where consistent with the project
- backed enums for workflow states
- readonly DTOs/value objects where appropriate
- typed properties
- explicit return types

Follow Symfony coding standards.

If `friendsofphp/php-cs-fixer` is installed, use the `@Symfony` ruleset or the repository's configured rules.

Do not introduce excessive abstractions without a demonstrated use case.

Prefer simple services with clear responsibilities.

---

## Testing

Features are not complete merely because they execute without throwing an exception.

Add tests that exercise behavior from the appropriate caller's perspective.

Use:

- `WebTestCase` for HTTP/controller behavior
- `KernelTestCase` for container-backed/service behavior
- normal PHPUnit tests for isolated domain/value-object behavior

Important business rules should have tests.

Likely areas requiring tests include:

- automatic customer matching/creation
- customer snapshot behavior
- order status rules
- order-number generation
- print-job creation
- Messenger dispatch behavior
- print-job state transitions
- report grouping/totals
- admin authorization

Do not test framework internals.

Test application behavior.

---

## Development workflow

When possible, inspect the running project before guessing about framework configuration.

Useful commands include:

```bash
php bin/console about
php bin/console debug:router
php bin/console debug:container
php bin/console debug:autowiring
php bin/console debug:config
php bin/console config:dump-reference
php bin/console lint:container
php bin/console lint:twig templates/
php bin/console lint:yaml config/
```

When something fails, inspect:

- `var/log/dev.log`
- Symfony profiler
- actual service/container configuration
- installed package source/docblocks when necessary

Do not assume framework APIs from memory when the installed version can be inspected.

If MakerBundle is installed, `make:*` commands may be used.

For non-interactive automation, provide command arguments up front and use `--no-interaction` where supported.

Do not start a command that will hang waiting for interactive input.

---

## Definition of done

Before considering a feature complete:

1. Relevant PHPUnit tests pass.
2. `php bin/console lint:container` passes.
3. Twig lint passes when templates changed.
4. YAML lint passes when YAML configuration changed.
5. PHPStan passes when installed.
6. php-cs-fixer check passes when installed.
7. Doctrine model changes include an inspected migration.
8. No `doctrine:schema:update` was used as the implementation mechanism.
9. No generated TODOs, placeholder methods, dead code, or debugging output remain.
10. Failure paths have been considered, especially around printing and asynchronous work.
11. The implementation follows the established architecture instead of introducing an alternative stack.

---

## Scope discipline

Do not turn BakeDesk into a general POS, inventory system, or online-ordering platform unless specifically requested.

V1 intentionally excludes:

- Toast integration
- prices and monetary totals
- payment processing
- inventory management
- online ordering
- SMS notifications
- email notifications
- complex bakery-production workflow states
- customer authentication
- user authentication for normal order entry
- full product/menu catalog normalization

The architecture should leave reasonable room for future expansion without implementing those features prematurely.

When requirements are unclear, prefer the smallest implementation consistent with the established architecture and current task.

Do not silently invent business rules.
