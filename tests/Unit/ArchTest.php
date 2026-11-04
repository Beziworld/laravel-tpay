<?php

declare(strict_types=1);

arch('every source file declares strict types')
    ->expect('Beziworld\Tpay')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('data objects are immutable')
    ->expect('Beziworld\Tpay\Data')
    ->toBeReadonly();

arch('exceptions share the package base exception')
    ->expect('Beziworld\Tpay\Exceptions')
    ->toExtend('Beziworld\Tpay\Exceptions\TpayException')
    ->ignoring('Beziworld\Tpay\Exceptions\TpayException');
