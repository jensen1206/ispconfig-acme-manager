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

    // Required before local access or mutation: ISPConfig ID of THIS webserver.
    'local_server_id' => 0,
    'installation_timeout' => 180,
    'poll_interval' => 5,
    'network_timeout' => 15,
    'retry_cooldown' => 86400,
    'state_directory' => '/var/lib/ispconfig-acme-manager',
    'acme_home' => '/root/.acme.sh',
    'acme_config_home' => '/root/.acme.sh',
    'vhost_directory' => '/etc/apache2/sites-available',
    // Enable only for sites whose public TLS terminates on this webserver.
    'automatic_repair' => false,
    // dashboard:renew only: run on the actual panel server, not a remote web node.
    'dashboard_certificate' => '/usr/local/ispconfig/interface/ssl/ispserver.crt',
    'dashboard_key' => '/usr/local/ispconfig/interface/ssl/ispserver.key',
    'dashboard_reload_command' => 'systemctl reload apache2',
    // Optional trust store, otherwise use the OpenSSL system trust store:
    // 'ca_file' => '/etc/ssl/certs/ca-certificates.crt',

    'managed_nameservers' => [
        'ns1.example.com',
        'ns2.example.com',
    ],
];
