<?php

declare(strict_types=1);

namespace App\ISPConfig;

use App\Config\Configuration;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApiClient
{
    private ?Session $session = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Configuration $configuration,
    ) {
    }

    public function login(): Session
    {
        if ($this->session instanceof Session) {
            return $this->session;
        }

        $response = $this->post('login', [
            'username' => $this->configuration->ispconfigUsername,
            'password' => $this->configuration->ispconfigPassword,
        ], includeSession: false);

        if (!is_string($response) || trim($response) === '') {
            throw new ApiException('ISPConfig hat keine gültige Session-ID zurückgegeben.');
        }

        return $this->session = new Session($response);
    }

    public function logout(): void
    {
        if (!$this->session instanceof Session) {
            return;
        }

        try {
            $this->post('logout');
        } finally {
            $this->session = null;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function request(string $method, array $parameters = []): mixed
    {
        $this->login();

        return $this->post($method, $parameters);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function post(
        string $method,
        array $parameters = [],
        bool $includeSession = true,
    ): mixed {
        if ($includeSession) {
            $session = $this->session ?? $this->login();

            $parameters = [
                'session_id' => $session->id(),
                ...$parameters,
            ];
        }

        $url = $this->apiUrl($method);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => $parameters,
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new ApiException(
                sprintf('ISPConfig-API-Aufruf "%s" ist fehlgeschlagen.', $method),
                previous: $exception,
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ApiException(sprintf(
                'ISPConfig-API-Aufruf "%s" lieferte HTTP %d: %s',
                $method,
                $statusCode,
                $content,
            ));
        }

        try {
            $decoded = json_decode(
                $content,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new ApiException(
                sprintf('Ungültige JSON-Antwort bei "%s": %s', $method, $content),
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new ApiException(sprintf(
                'Unerwartete Antwortstruktur bei "%s".',
                $method,
            ));
        }

        $code = $decoded['code'] ?? null;
        $message = $decoded['message'] ?? null;

        if (!is_string($code) || $code !== 'ok') {
            throw new ApiException(sprintf(
                'ISPConfig-API-Fehler bei "%s": %s',
                $method,
                is_string($message) ? $message : 'Unbekannter Fehler',
            ));
        }

        return $decoded['response'] ?? null;
    }

    private function apiUrl(string $method): string
    {
        return sprintf(
            '%s/remote/json.php?%s',
            rtrim($this->configuration->ispconfigServer, '/'),
            rawurlencode($method),
        );
    }
}