<?php

return [
    // Exact proxy IPs only; an empty value trusts no proxy.
    'proxies' => array_values(array_filter(
        array_map(trim(...), explode(',', (string) env('TRUSTED_PROXY_IPS', ''))),
        fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false,
    )),
];
