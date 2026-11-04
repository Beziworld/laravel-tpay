<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Http\Controllers;

use Beziworld\Tpay\Events\NotificationReceived;
use Beziworld\Tpay\Events\TransactionChargedBack;
use Beziworld\Tpay\Events\TransactionPaid;
use Beziworld\Tpay\Exceptions\InvalidNotification;
use Beziworld\Tpay\Notifications\JwsVerifier;
use Beziworld\Tpay\Notifications\NotificationValidator;
use Beziworld\Tpay\TpayConfig;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Receives Tpay transaction notifications.
 *
 * Authentic notifications are acknowledged with the plain-text body "TRUE",
 * which stops further deliveries. Rejected notifications receive HTTP 400
 * with a "FALSE - <reason>" body, so Tpay retries them according to its
 * schedule.
 */
final class NotificationController
{
    public function __construct(
        private readonly NotificationValidator $validator,
        private readonly TpayConfig $config,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();

        // Parse the raw body: global middleware (TrimStrings, ConvertEmptyStringsToNull)
        // alters the parsed input and would break the md5sum of fields such as tr_crc.
        parse_str($body, $parsed);
        /** @var array<string, mixed> $fields */
        $fields = $parsed !== [] ? $parsed : $request->request->all();

        try {
            $notification = $this->validator->validate($body, $fields, $request->headers->get(JwsVerifier::HEADER));
        } catch (InvalidNotification $exception) {
            $this->logger->warning('Rejected Tpay notification: ' . $exception->getMessage(), ['ip' => $request->ip()]);

            return new Response('FALSE - ' . $exception->getMessage(), 400, ['Content-Type' => 'text/plain']);
        }

        $this->events->dispatch(new NotificationReceived($notification));

        if ($notification->testMode && ! $this->config->sandbox) {
            // Test payments made while test mode is enabled in the merchant panel are
            // acknowledged but never treated as real payments in production.
            $this->logger->info('Ignored Tpay test mode notification in production.', ['tr_id' => $notification->transactionTitle]);

            return new Response('TRUE', 200, ['Content-Type' => 'text/plain']);
        }

        if ($notification->isPaid()) {
            $this->events->dispatch(new TransactionPaid($notification));
        } elseif ($notification->isChargeback()) {
            $this->events->dispatch(new TransactionChargedBack($notification));
        }

        return new Response('TRUE', 200, ['Content-Type' => 'text/plain']);
    }
}
