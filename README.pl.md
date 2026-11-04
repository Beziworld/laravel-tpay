# Laravel Tpay

Integracja Laravela z OpenAPI [Tpay](https://tpay.com): transakcje, BLIK, zwroty i notyfikacje weryfikowane
zarówno sumą md5, jak i podpisem JWS.

[English documentation](README.md)

## Możliwości

- Token OAuth pobierany na żądanie, cache'owany i odświeżany automatycznie po odpowiedzi 401
- Transakcje z przekierowaniem na bramkę, z wybranym bankiem albo BLIK level 0
- Zwroty (pełne i częściowe), anulowanie, ponowna płatność (`pay`, `payWithBlik`)
- Grupy banków i kanały płatności do własnego wyboru metody
- Endpoint notyfikacji weryfikujący md5sum **i** podpis JWS RS256 względem łańcucha certyfikatów Tpay;
  odpowiada `TRUE` i wysyła eventy `TransactionPaid` / `TransactionChargedBack`
- Ogólne `Tpay::call()` dla endpointów bez dedykowanej metody
- Sandbox i produkcja, PHP 8.3+, Laravel 12 i 13, PHPStan level 8

## Instalacja

```bash
composer require beziworld/laravel-tpay
php artisan vendor:publish --tag=tpay-config   # opcjonalnie
```

```dotenv
TPAY_CLIENT_ID=01G...-xxxx
TPAY_CLIENT_SECRET=sekret
TPAY_SECURITY_CODE=kod-bezpieczenstwa          # Panel > Ustawienia > Powiadomienia
TPAY_MERCHANT_ID=12345                         # opcjonalnie
TPAY_SANDBOX=true
TPAY_SUCCESS_URL=https://sklep.pl/platnosc/sukces
TPAY_ERROR_URL=https://sklep.pl/platnosc/blad
```

## Przepływ płatności

### 1. Utworzenie transakcji

```php
use Beziworld\Tpay\Data\TransactionRequest;
use Beziworld\Tpay\Facades\Tpay;

$transaction = Tpay::createTransaction(new TransactionRequest(
    amount: $order->total,                     // "123.45", 123.45 albo 123
    description: "Zamówienie {$order->number}",
    payerEmail: $order->email,
    payerName: $order->customer_name,
    hiddenDescription: (string) $order->id,    // wraca w notyfikacji jako tr_crc
));

$order->update(['tpay_transaction_id' => $transaction->transactionId]);

return redirect()->away($transaction->paymentUrl);
```

### BLIK level 0 (kod wpisywany w sklepie)

```php
$transaction = Tpay::createTransaction(new TransactionRequest(
    amount: '49.99',
    description: 'Abonament',
    payerEmail: $user->email,
    payerName: $user->name,
    blikCode: $request->input('blik_code'),   // sześć cyfr
));

// Błędny kod? Ponów na tej samej transakcji:
Tpay::payWithBlik($transaction->transactionId, $nowyKod);
```

### 2. Obsługa notyfikacji

Pakiet rejestruje trasę `POST /tpay/notification` i przekazuje jej adres w `callbacks.notification.url`. Tpay
użyje go tylko przy włączonej opcji *Zezwalaj na nadpisywanie* w panelu; w przeciwnym razie ustaw ten adres
w panelu.

```php
use Beziworld\Tpay\Events\TransactionPaid;

Event::listen(function (TransactionPaid $event) {
    $n = $event->notification;
    $order = Order::findOrFail($n->crc);

    if ($order->isPaid()) {
        return;                                   // notyfikacje mogą się powtarzać
    }

    if (bccomp($n->paid, (string) $order->total, 2) === 0) {
        $order->markAsPaid(tpayTitle: $n->transactionTitle);
    }
});
```

## Zwroty i inne wywołania

```php
Tpay::refund($transactionId);              // pełny zwrot
Tpay::refund($transactionId, '20.00');     // zwrot częściowy
Tpay::cancel($transactionId);
Tpay::transaction($transactionId)->isPaid();
Tpay::bankGroups(onlyOnline: true);
Tpay::channels();
Tpay::call('GET', '/refunds');             // dowolny inny endpoint OpenAPI
```

## Bezpieczeństwo

- **md5sum** jest liczona z `TPAY_SECURITY_CODE`. Bez kodu każda notyfikacja jest odrzucana, chyba że jawnie
  wyłączysz kontrolę (`TPAY_VERIFY_MD5=false`, niezalecane).
- **JWS**: nagłówek `X-JWS-Signature` jest weryfikowany algorytmem RS256 na surowej treści żądania. Certyfikat musi
  pochodzić z `https://secure.tpay.com/` (lub sandboksa) i być wystawiony przez root CA Tpay. Jest cache'owany,
  a po nieudanej weryfikacji pobierany ponownie raz (obsługa rotacji kluczy).
- Odrzucone notyfikacje dostają HTTP 400 i treść `FALSE - <powód>`.
- md5sum nie obejmuje `tr_status` ani `tr_paid`; całą treść chroni dopiero podpis JWS. Na produkcji zostaw
  `TPAY_VERIFY_JWS=true`.
- Po nieudanej weryfikacji certyfikat jest pobierany ponownie najwyżej raz na 5 minut, a root CA nie jest usuwany
  z cache. Wygasłe certyfikaty są odrzucane.
- Przy `TPAY_SANDBOX=false` notyfikacje z `test_mode=1` są potwierdzane, ale nie wysyłają `TransactionPaid`.
- Tpay ponawia notyfikacje do otrzymania `TRUE`, więc listenery muszą być idempotentne (klucz: `tr_id`).
- Przy tworzeniu transakcji z kolejki lub konsoli adres notyfikacji powstaje z `APP_URL`; ustaw go poprawnie albo
  podaj `TPAY_NOTIFICATION_URL`.

## Autor

Pakiet tworzy i rozwija **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Zgłoszenia błędów: [GitHub issues](https://github.com/beziworld/laravel-tpay/issues)
- Podatności bezpieczeństwa: [SECURITY.md](SECURITY.md)

Potrzebujesz wdrożenia płatności w sklepie albo projektu w Laravelu? Zapraszam do kontaktu przez
[beziworld.eu](https://beziworld.eu).

### Inne pakiety autora

- [beziworld/laravel-przelewy24](https://github.com/beziworld/laravel-przelewy24): płatności Przelewy24
- [beziworld/laravel-smsapi](https://github.com/beziworld/laravel-smsapi): SMS przez SMSAPI
- [beziworld/laravel-directadmin](https://github.com/beziworld/laravel-directadmin): API panelu DirectAdmin

## Licencja

MIT. Pakiet nie jest powiązany z Krajowym Integratorem Płatności S.A. (Tpay).
