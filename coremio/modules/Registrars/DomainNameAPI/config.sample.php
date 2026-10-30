<?php

return [
    'meta'     => [
        'name'    => 'DomainNameAPI',
        'version' => '4.0.5',
        'logo'    => 'logo.png',
    ],
    'settings' => [
        'whois-types'      => true,
        'dns-record-types' => [
            'A',
            'AAAA',
            'ALIAS',
            'CAA',
            'CNAME',
            'DS',
            'MX',
            'NAPTR',
            'PTR',
            'SRV',
            'TLSA',
            'TXT',
        ],
        'username'         => '',
        'password'         => '',
        'test-mode'        => 0,
        'error-reporting'  => 0,
        'adp'              => false,
        'cost-currency'    => 4,
        'exclude'          => '',
    ],
];
