<?php
/*
|--------------------------------------------------------------------------
| Marketplace Domain Helpers
|--------------------------------------------------------------------------
| Small shared helpers for values used in more than one page.
| PHP 7.1 compatible.
|--------------------------------------------------------------------------
*/

if (!function_exists('fm_product_units')) {
    function fm_product_units()
    {
        return array(
            'kg' => 'kg',
            'viss' => 'viss',
            'piece' => 'piece',
            'pack' => 'pack',
            'bunch' => 'bunch',
            'bag' => 'bag',
            'basket' => 'basket'
        );
    }
}

if (!function_exists('fm_normalize_product_unit')) {
    function fm_normalize_product_unit($unit)
    {
        $unit = strtolower(trim((string) $unit));
        $units = fm_product_units();

        return isset($units[$unit]) ? $unit : 'piece';
    }
}

if (!function_exists('fm_unit_label')) {
    function fm_unit_label($unit)
    {
        $unit = fm_normalize_product_unit($unit);
        $labels = array(
            'kg' => 'kg',
            'viss' => 'viss',
            'piece' => 'piece',
            'pack' => 'pack',
            'bunch' => 'bunch',
            'bag' => 'bag',
            'basket' => 'basket'
        );

        return $labels[$unit];
    }
}

if (!function_exists('fm_fulfillment_label')) {
    function fm_fulfillment_label($type)
    {
        return strtolower(trim((string) $type)) === 'delivery'
            ? 'Delivery'
            : 'Market Pickup';
    }
}

if (!function_exists('fm_payment_label')) {
    function fm_payment_label($method)
    {
        $method = strtolower(trim((string) $method));

        if ($method === 'kbzpay') {
            return 'KBZPay';
        }

        if ($method === 'wave_money') {
            return 'Wave Money';
        }

        return 'Not selected';
    }
}
