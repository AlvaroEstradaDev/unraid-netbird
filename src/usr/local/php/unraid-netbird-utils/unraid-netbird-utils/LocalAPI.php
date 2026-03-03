<?php

/*
    Copyright (C) 2026  Alvaro Estrada

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace Netbird;

class LocalAPI
{
    private Utils $utils;
    private const SOCKET_PATH = '/var/run/netbird.sock';

    public function __construct()
    {
        if (!defined(__NAMESPACE__ . "\PLUGIN_ROOT") || !defined(__NAMESPACE__ . "\PLUGIN_NAME")) {
            throw new \RuntimeException("Common file not loaded.");
        }
        $this->utils = new Utils(PLUGIN_NAME);
    }

    public function isSocketAvailable(): bool
    {
        if (!file_exists(self::SOCKET_PATH)) {
            return false;
        }
        $perms = fileperms(self::SOCKET_PATH);
        return $perms !== false && ($perms & 0170000) === 0140000;
    }

    private function decodeJSONResponse(string $response): \stdClass
    {
        $decoded = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to decode JSON response: " . json_last_error_msg());
        }

        return (object) $decoded;
    }

    public function isReady(): bool
    {
        if (!$this->isSocketAvailable()) {
            return false;
        }
        try {
            $status = $this->getStatus();
            return isset($status->Self);
        } catch (\RuntimeException $e) {
            // No need to log here, as this is just a readiness check
        }
        return false;
    }

    public function getStatus(): \stdClass
    {
        if (!$this->isSocketAvailable()) {
            return new \stdClass();
        }
        try {
            $output = Utils::runwrap('timeout 5 netbird status --json 2>/dev/null', false, false);
            $raw = $this->decodeJSONResponse(implode("\n", $output));

            // Map Netbird JSON to expected format
            $mapped = new \stdClass();
            $mapped->Version = $raw->daemonVersion ?? '';
            $mapped->Self = new \stdClass();
            $mapped->Self->Online = $raw->management->connected ?? false;
            $mapped->Self->HostName = explode('.', $raw->fqdn ?? '')[0];
            $mapped->Self->DNSName = $raw->fqdn ?? '';
            $ips = [];
            if (isset($raw->netbirdIp)) {
                $ips[] = explode('/', $raw->netbirdIp)[0];
            }
            $mapped->NetbirdIPs = $ips;
            $mapped->Self->NetbirdIPs = $ips;

            $mapped->Peer = new \stdClass();
            if (isset($raw->peers->details)) {
                foreach ($raw->peers->details as $peer) {
                    $p = new \stdClass();
                    $p->DNSName = $peer->fqdn;
                    $p->NetbirdIPs = [$peer->netbirdIp];
                    $p->Online = $peer->status === "Connected";
                    $p->Active = $peer->status === "Connected";
                    $p->Relay = isset($peer->relayAddress) && isset($peer->connectionType) && $peer->connectionType === "Relayed" ? $peer->relayAddress : "";
                    $p->CurAddr = $peer->iceCandidateEndpoint->remote ?? "";
                    $p->TxBytes = $peer->transferSent ?? 0;
                    $p->RxBytes = $peer->transferReceived ?? 0;
                    $p->ExitNode = false;
                    $p->ExitNodeOption = false;
                    $p->Tags = [];

                    $mapped->Peer->{$peer->publicKey} = $p;
                }
            }

            return $mapped;
        } catch (\RuntimeException $e) {
            Utils::logwrap("Failed to get status: " . $e->getMessage());
            return new \stdClass();
        }
    }

    /**
     * @return array<string, bool>
     */
    public function getRoutes(): array
    {
        if (!$this->isSocketAvailable()) {
            return [];
        }
        try {
            $output = Utils::runwrap('timeout 5 netbird routes list 2>/dev/null', false, false);
            $routes = [];

            foreach ($output as $line) {
                $line = trim($line);
                if (empty($line) || $line === '-') {
                    continue;
                }

                // Match network CIDR patterns (IPv4 or IPv6)
                if (preg_match('/([\d.]+\/\d+|[\da-f:]+\/\d+)/i', $line, $matches)) {
                    $network = $matches[1];
                    $selected = stripos($line, 'Selected') !== false;
                    $routes[$network] = $selected;
                }
            }

            return $routes;
        } catch (\RuntimeException $e) {
            return [];
        }
    }

    public function postLoginInteractive(): void
    {
        try {
            // Remove previous login log
            if (file_exists('/tmp/netbird-login.log')) {
                unlink('/tmp/netbird-login.log');
            }
            // Run netbird up in background and capture output
            Utils::runwrap('nohup timeout 30 netbird up --no-browser > /tmp/netbird-login.log 2>&1 &', false, false);
        } catch (\RuntimeException $e) {
            Utils::logwrap("Failed to post login interactive: " . $e->getMessage());
        }
    }

    public function loginWithSetupKey(string $managementUrl, string $setupKey): bool
    {
        try {
            if (file_exists('/tmp/netbird-login.log')) {
                unlink('/tmp/netbird-login.log');
            }

            $escapedUrl = escapeshellarg($managementUrl);
            $escapedSetupKey = escapeshellarg($setupKey);

            $command = "nohup timeout 30 netbird up --management-url {$escapedUrl} --setup-key {$escapedSetupKey} > /tmp/netbird-login.log 2>&1 &";
            Utils::runwrap($command, false, false);

            return true;
        } catch (\RuntimeException $e) {
            Utils::logwrap("Failed to login with setup key: " . $e->getMessage());
            return false;
        }
    }
}
