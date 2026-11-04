<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Notifications;

use Beziworld\Tpay\Data\TransactionNotification;
use Beziworld\Tpay\Exceptions\InvalidNotification;
use Beziworld\Tpay\TpayConfig;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Authenticates an incoming notification: merchant id, md5sum and JWS signature.
 *
 * Usable outside the bundled route, e.g. in a custom controller:
 * $notification = app(NotificationValidator::class)->validate($request->getContent(), $request->request->all(), $request->header('X-JWS-Signature'));
 */
class NotificationValidator
{
    public function __construct(
        private readonly TpayConfig $config,
        private readonly JwsVerifier $jws,
        private readonly Config $settings,
    ) {}

    /**
     * @param array<string, mixed> $fields Parsed form fields of the request.
     *
     * @throws InvalidNotification When the notification cannot be authenticated.
     */
    public function validate(string $rawBody, array $fields, ?string $jwsSignature): TransactionNotification
    {
        $notification = TransactionNotification::fromArray($fields);

        if ($this->config->merchantId !== null && $notification->merchantId !== $this->config->merchantId) {
            throw InvalidNotification::foreignMerchant($notification->merchantId);
        }

        if ((bool) $this->settings->get('tpay.notifications.verify_md5', true)) {
            if ($this->config->securityCode === null) {
                throw InvalidNotification::missingSecurityCode();
            }

            if (! $notification->hasValidChecksum($this->config->securityCode)) {
                throw InvalidNotification::invalidChecksum();
            }
        }

        if ((bool) $this->settings->get('tpay.notifications.verify_jws', true)) {
            $this->jws->verify($rawBody, $jwsSignature);
        }

        return $notification;
    }
}
