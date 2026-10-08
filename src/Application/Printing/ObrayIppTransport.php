<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Entity\Printer;
use obray\ipp\exceptions\IppStatusException;
use obray\ipp\Printer as IppPrinter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(IppTransportInterface::class)]
final class ObrayIppTransport implements IppTransportInterface
{
    public function __construct(
        private readonly ObrayIppClientFactoryInterface $clientFactory,
    ) {
    }

    public function getPrinterStatus(Printer $printer): PrinterStatus
    {
        $client = $this->printer($printer);
        $response = $client->getPrinterAttributes(1, [
            'document-format-supported',
            'printer-state',
            'printer-state-reasons',
            'printer-is-accepting-jobs',
        ]);
        $attributes = $this->firstAttributeGroup($response->printerAttributes);

        return new PrinterStatus(
            state: $this->printerState($attributes),
            acceptsJobs: $this->booleanAttribute($attributes, 'printer-is-accepting-jobs'),
            reasons: $this->stringAttributes($attributes, 'printer-state-reasons'),
            documentFormatsSupported: $this->stringAttributes($attributes, 'document-format-supported'),
            rawAttributes: $this->rawAttributes($attributes),
        );
    }

    public function submitPdf(Printer $printer, string $pdf, string $jobName): PrintSubmission
    {
        try {
            $response = $this->printer($printer)->printJob($pdf, 1, [
                'document-format' => 'application/pdf',
                'job-name' => $jobName,
            ]);
        } catch (IppStatusException $exception) {
            throw PrinterSubmissionException::rejected($exception->getMessage());
        }

        $attributes = $this->firstAttributeGroup($response->jobAttributes);
        $externalJobId = $this->stringAttribute($attributes, 'job-id') ?? $this->stringAttribute($attributes, 'job-uri');
        if (null === $externalJobId || '' === $externalJobId) {
            throw PrinterSubmissionException::outcomeUnknown(new \UnexpectedValueException('The IPP response did not contain a job-id or job-uri.'));
        }

        return new PrintSubmission($externalJobId, $this->jobStatusSnapshot($attributes));
    }

    public function getJobStatus(Printer $printer, string $externalJobId): PrintJobStatusSnapshot
    {
        $jobId = ctype_digit($externalJobId) ? (int) $externalJobId : $externalJobId;
        $response = $this->clientFactory->createJob($printer->getAddress(), $jobId)->getJobAttributes(1, [
            'job-id',
            'job-state',
            'job-state-reasons',
            'job-state-message',
        ]);
        $attributes = $this->firstAttributeGroup($response->jobAttributes);

        return $this->jobStatusSnapshot($attributes);
    }

    private function printer(Printer $printer): IppPrinter
    {
        return $this->clientFactory->createPrinter($printer->getAddress());
    }

    private function firstAttributeGroup(mixed $groups): ?object
    {
        if (is_object($groups)) {
            return $groups;
        }
        if (!is_array($groups) || [] === $groups) {
            return null;
        }

        $group = reset($groups);

        return is_object($group) ? $group : null;
    }

    private function printerState(?object $attributes): PrinterState
    {
        $state = $this->stringAttribute($attributes, 'printer-state');

        return match ($state) {
            '3', 'idle' => PrinterState::IDLE,
            '4', 'processing' => PrinterState::PROCESSING,
            '5', 'stopped' => PrinterState::STOPPED,
            default => PrinterState::UNKNOWN,
        };
    }

    private function jobStatusSnapshot(?object $attributes): PrintJobStatusSnapshot
    {
        $state = $this->stringAttribute($attributes, 'job-state');

        return new PrintJobStatusSnapshot(
            state: match ($state) {
                '3', '4', 'pending', 'pending-held' => PrintJobState::PENDING,
                '5', '6', 'processing', 'processing-stopped' => PrintJobState::PROCESSING,
                '7', 'canceled' => PrintJobState::CANCELLED,
                '8', 'aborted' => PrintJobState::ABORTED,
                '9', 'completed' => PrintJobState::COMPLETED,
                default => PrintJobState::UNKNOWN,
            },
            reasons: $this->stringAttributes($attributes, 'job-state-reasons'),
            message: $this->stringAttribute($attributes, 'job-state-message'),
            rawAttributes: $this->rawAttributes($attributes),
        );
    }

    private function stringAttribute(?object $attributes, string $name): ?string
    {
        if (null === $attributes || !method_exists($attributes, 'has') || !$attributes->has($name)) {
            return null;
        }

        $attribute = $attributes->{$name};

        return method_exists($attribute, 'getAttributeValue') ? (string) $attribute->getAttributeValue() : (string) $attribute;
    }

    /** @return list<string> */
    private function stringAttributes(?object $attributes, string $name): array
    {
        if (null === $attributes || !method_exists($attributes, 'has') || !$attributes->has($name)) {
            return [];
        }

        $values = $attributes->{$name};
        if (!is_array($values)) {
            $values = [$values];
        }

        return array_values(array_map(
            static fn (mixed $value): string => method_exists($value, 'getAttributeValue') ? (string) $value->getAttributeValue() : (string) $value,
            $values,
        ));
    }

    private function booleanAttribute(?object $attributes, string $name): ?bool
    {
        if (null === $attributes || !method_exists($attributes, 'has') || !$attributes->has($name)) {
            return null;
        }

        $attribute = $attributes->{$name};
        $value = method_exists($attribute, 'getAttributeValue') ? $attribute->getAttributeValue() : $attribute;

        return (bool) $value;
    }

    /** @return array<string, mixed> */
    private function rawAttributes(?object $attributes): array
    {
        if (null === $attributes) {
            return [];
        }

        return $attributes instanceof \JsonSerializable ? (array) $attributes->jsonSerialize() : [];
    }
}
