<?php

return [
    /*
     * ISPConfig Server
     */
    'server' => 'https://server.example.com:8080',

    /*
     * Remote API User
     */
    'username' => 'acme-manager',

    /*
     * Remote API Password
     */
    'password' => 'change-me',

    /*
     * acme.sh
     */
    'acme' => '/root/.acme.sh/acme.sh',

    /*
     * acme.sh DNS Provider
     */
    'dns_provider' => 'dns_ispconfig',

    /*
     * Seconds to wait for DNS propagation
     */
    'dns_sleep' => 120,

    'managed_nameservers' => [
        'ns1.example.com',
        'ns2.example.com',
    ],
];
