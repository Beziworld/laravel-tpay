# Laravel Tpay

[![tests](https://github.com/beziworld/laravel-tpay/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-tpay/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-tpay.svg)](https://packagist.org/packages/beziworld/laravel-tpay)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-tpay.svg)](LICENSE.md)

Laravel integration for the [Tpay](https://tpay.com) OpenAPI: transactions, BLIK, refunds and notifications
verified with both the md5 checksum and the JWS signature.

[Polska wersja dokumentacji](README.pl.md)

## Features

- OAuth access token fetched on demand, cached and refreshed automatically after a 401
- Transactions with redirect to the payment page, a preselected bank or BLIK level 0
- Refunds (full or partial), cancellation, repeated payment attempts (`pay`, `payWithBlik`)
- Bank groups and channels for your own payment method picker
- Notification endpoint verifying the md5sum **and** the RS256 JWS signature against the Tpay certificate chain,
  answering `TRUE` and dispatching `TransactionPaid` / `TransactionChargedBack`
- Generic `Tpay::call()` for endpoints without a dedicated method
- Sandbox and production, PHP 8.3+, Laravel 12 and 13, PHPStan level 8

## Installation

```bash
composer require beziworld/laravel-tpay
php artisan vendor:publish --tag=tpay-config   # optional
```

```dotenv
TPAY_CLIENT_ID=01G...-xxxx
TPAY_CLIENT_SECRET=secret
TPAY_SECURITY_CODE=notification-security-code   # Merchant panel > Settings > Notifications
TPAY_MERCHANT_ID=12345                          # optional, rejects notifications for other accounts
TPAY_SANDBOX=true
TPAY_SUCCESS_URL=https://shop.test/payment/success
TPAY_ERROR_URL=https://shop.test/payment/error
```

OpenAPI keys are generated in the merchant panel (Integration > API). Sandbox accounts can be created at
[register.sandbox.tpay.com](https://register.sandbox.tpay.com).

## Payment flow

### 1. Create the transaction

```php
use Beziworld\Tpay\Data\TransactionRequest;
use Beziworld\Tpay\Exceptions\ApiException;
use Beziworld\Tpay\Facades\Tpay;

public function pay(Order $order): RedirectResponse
{
    try {
        $transaction = Tpay::createTransaction(new TransactionRequest(
            amount: $order->total,                  // "123.45", 123.45 or 123 are all accepted
            description: "Order {$order->number}",
            payerEmail: $order->email,
            payerName: $order->customer_name,
            hiddenDescription: (string) $order->id, // returned in notifications as tr_crc
        ));
    } catch (ApiException $e) {
        report($e);

        return back()->withErrors('The payment could not be started.');
    }

    $order->update(['tpay_transaction_id' => $transaction->transactionId, 'tpay_title' => $transaction->title]);

    return redirect()->away($transaction->paymentUrl);
}
```

Options: `groupId` (preselected bank from `Tpay::bankGroups()`), `channelId`, `lang`, `successUrl`, `errorUrl`,
`notificationUrl`, `payer` (phone, address, taxId, ip ...), or a raw `pay` object.

### BLIK level 0 (code entered in your shop)

```php
$transaction = Tpay::createTransaction(new TransactionRequest(
    amount: '49.99',
    description: 'Subscription',
    payerEmail: $user->email,
    payerName: $user->name,
    hiddenDescription: "sub-{$subscription->id}",
    blikCode: $request->input('blik_code'),     // six digits
));

// The customer confirms in the banking app; the result arrives as a notification.
// Wrong code? Retry on the same transaction:
Tpay::payWithBlik($transaction->transactionId, $newCode);
```

### 2. Handle the notification

The package registers `POST /tpay/notification` and passes its URL in `callbacks.notification.url`. Tpay uses it
only when *Allow override* is enabled in the merchant panel; otherwise set the same URL in the panel.

```php
use Beziworld\Tpay\Events\TransactionPaid;

class MarkOrderAsPaid
{
    public function handle(TransactionPaid $event): void
    {
        $notification = $event->notification;
        $order = Order::findOrFail($notification->crc);       // hiddenDescription

        if ($order->isPaid()) {
            return;                                           // notifications can be repeated
        }

        if (bccomp($notification->paid, (string) $order->total, 2) !== 0) {
            Log::alert('Tpay amount mismatch', ['order' => $order->id]);

            return;
        }

        $order->markAsPaid(tpayTitle: $notification->transactionTitle);
    }
}
```

Other events: `TransactionChargedBack` (full refund) and `NotificationReceived` (every authenticated notification).
Optional fields such as `card_token` or `tr_channel` are available through `$notification->get('card_token')`.

## Refunds and other calls

```php
Tpay::refund($order->tpay_transaction_id);              // full refund
Tpay::refund($order->tpay_transaction_id, '20.00');     // partial refund
Tpay::cancel($transactionId);                           // cancel a pending transaction
Tpay::transaction($transactionId)->isPaid();            // status check
Tpay::bankGroups(onlyOnline: true);                     // list<BankGroup> with logos
Tpay::channels();                                       // list<PaymentChannel> with constraints
Tpay::call('GET', '/refunds');                          // any other OpenAPI endpoint
```

## Security

- **md5sum** is checked with `TPAY_SECURITY_CODE`. Without the code every notification is rejected unless you
  disable the check explicitly (`TPAY_VERIFY_MD5=false`, not recommended).
- **JWS**: the `X-JWS-Signature` header is verified with RS256 over the raw request body. The signing certificate
  must be served from `https://secure.tpay.com/` (or the sandbox origin), must be issued by the Tpay root CA and is
  cached for `TPAY_CERTIFICATE_TTL` seconds; a failed check refreshes the certificates once, so key rotation is
  handled.
- The route uses the `api` middleware group (no CSRF). Rejected notifications get HTTP 400 and `FALSE - <reason>`.
- The md5sum does not cover `tr_status` or `tr_paid`; only the JWS signature protects the whole body. Keep
  `TPAY_VERIFY_JWS=true` in production, otherwise a captured notification could be replayed with a changed status.
- Certificates are re-downloaded after a failed verification at most once every 5 minutes, and the root CA is never
  evicted, so forged requests cannot flood Tpay with certificate downloads. Expired certificates are rejected.
- With `TPAY_SANDBOX=false`, notifications flagged `test_mode=1` are acknowledged but do not dispatch
  `TransactionPaid`.
- Tpay repeats notifications until it receives `TRUE`, so make listeners idempotent (key on `tr_id`).
- When transactions are created from queued jobs or console commands, the notification URL is generated from
  `APP_URL`. Set `APP_URL` correctly or configure `TPAY_NOTIFICATION_URL` explicitly.
- Creating transactions, refunds and payments are never retried automatically after a timeout.

## Exceptions

All exceptions extend `TpayException`: `ApiException` (with `httpStatus()`, `errors()` for field level errors and
`payload()`), `InvalidNotification`, `InvalidConfiguration`.

## Development

```bash
composer install
composer ci   # pint --test, phpstan (level 8), pest
```

## Author

Created and maintained by **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Issues and feature requests: [GitHub issues](https://github.com/beziworld/laravel-tpay/issues)
- Security vulnerabilities: see [SECURITY.md](SECURITY.md)

Need help integrating payments in your shop or a custom Laravel project? Get in touch through
[beziworld.eu](https://beziworld.eu).

### Other packages by the author

- [beziworld/laravel-przelewy24](https://github.com/beziworld/laravel-przelewy24): Przelewy24 payments
- [beziworld/laravel-smsapi](https://github.com/beziworld/laravel-smsapi): SMSAPI messages and notifications
- [beziworld/laravel-directadmin](https://github.com/beziworld/laravel-directadmin): DirectAdmin hosting panel API

## License

MIT. See [LICENSE.md](LICENSE.md). This package is not affiliated with Krajowy Integrator Płatności S.A. (Tpay).
