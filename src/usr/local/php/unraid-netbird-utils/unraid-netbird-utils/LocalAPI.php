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
    private Config $config;
    private const SOCKET_PATH   = '/var/run/netbird.sock';
    private const CACHE_TTL     = 30;
    private const CACHE_FILE    = '/tmp/netbird-status-cache.json';
    private const TIMEOUT_SHORT = 2;
    private const TIMEOUT_LONG  = 5;

    private static ?\stdClass $cachedStatus = null;
    private static ?int $cacheTime          = null;

    public function __construct()
    {
        if ( ! defined(__NAMESPACE__ . "\PLUGIN_ROOT") || ! defined(__NAMESPACE__ . "\PLUGIN_NAME")) {
            throw new \RuntimeException("Common file not loaded.");
        }
        $this->utils  = new Utils(PLUGIN_NAME);
        $this->config = new Config();
    }

    public function isSocketAvailable(): bool
    {
        return file_exists(self::SOCKET_PATH) && (fileperms(self::SOCKET_PATH) & 0170000) === 0140000;
    }

    public function isDaemonRunning(): bool
    {
        $pid = shell_exec('pgrep -x netbird 2>/dev/null');
        return ! empty(trim(is_string($pid) ? $pid : ''));
    }

    public function isReady(): bool
    {
        return $this->isDaemonRunning() && $this->isSocketAvailable();
    }

    public function hasIP(): bool
    {
        $interfaces = @net_get_interfaces();
        if ( ! is_array($interfaces)) {
            return false;
        }
        return isset($interfaces[$this->config->InterfaceName]["unicast"]);
    }

    private function decodeJSONResponse(string $response): \stdClass
    {
        if (empty(trim($response))) {
            return new \stdClass();
        }

        $decoded = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE || ! $decoded instanceof \stdClass) {
            return new \stdClass();
        }

        return $decoded;
    }

    private function getCachedStatus(): ?\stdClass
    {
        if (self::$cachedStatus !== null && self::$cacheTime !== null) {
            if ((time() - self::$cacheTime) < self::CACHE_TTL) {
                return self::$cachedStatus;
            }
        }

        if (file_exists(self::CACHE_FILE)) {
            $cacheContent = file_get_contents(self::CACHE_FILE);
            if ($cacheContent !== false) {
                $cache = json_decode($cacheContent);
                if (is_object($cache) && isset($cache->time, $cache->status)) {
                    if ((time() - $cache->time) < self::CACHE_TTL) {
                        self::$cachedStatus = $cache->status;
                        self::$cacheTime    = $cache->time;
                        return self::$cachedStatus;
                    }
                }
            }
        }

        return null;
    }

    private function setCachedStatus(\stdClass $status): void
    {
        self::$cachedStatus = $status;
        self::$cacheTime    = time();

        $cache = [
            'time'   => self::$cacheTime,
            'status' => $status
        ];

        @file_put_contents(self::CACHE_FILE, json_encode($cache));
    }

    public function getStatus(bool $useCache = true): \stdClass
    {
        if ( ! $this->isDaemonRunning()) {
            return new \stdClass();
        }

        if ( ! $this->isSocketAvailable()) {
            return new \stdClass();
        }

        if ($useCache) {
            $cached = $this->getCachedStatus();
            if ($cached !== null) {
                return $cached;
            }
        }

        $command = sprintf(
            'timeout %d netbird status --json 2>/dev/null',
            $useCache ? self::TIMEOUT_SHORT : self::TIMEOUT_LONG
        );

        $output     = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return new \stdClass();
        }

        $raw = $this->decodeJSONResponse(implode("\n", $output));

        if ( ! isset($raw->management)) {
            return new \stdClass();
        }

        $mapped                 = new \stdClass();
        $mapped->Version        = $raw->daemonVersion ?? '';
        $mapped->Self           = new \stdClass();
        $mapped->Self->Online   = $raw->management->connected ?? false;
        $mapped->Self->HostName = explode('.', $raw->fqdn ?? '')[0];
        $mapped->Self->DNSName  = $raw->fqdn ?? '';

        $ips = [];
        if (isset($raw->netbirdIp)) {
            $ips[] = explode('/', $raw->netbirdIp)[0];
        }
        $mapped->NetbirdIPs       = $ips;
        $mapped->Self->NetbirdIPs = $ips;

        $mapped->Peer = new \stdClass();
        if (isset($raw->peers->details) && is_array($raw->peers->details)) {
            foreach ($raw->peers->details as $peer) {
                if ( ! is_object($peer) || ! isset($peer->publicKey)) {
                    continue;
                }

                $p             = new \stdClass();
                $p->DNSName    = $peer->fqdn ?? '';
                $p->NetbirdIPs = isset($peer->netbirdIp) ? [$peer->netbirdIp] : [];
                $p->Online     = ($peer->status ?? '') === "Connected";
                $p->Active     = $p->Online;
                $p->Relay      = "";
                $p->CurAddr    = "";
                $p->TxBytes    = $peer->transferSent     ?? 0;
                $p->RxBytes    = $peer->transferReceived ?? 0;
                $p->Tags       = [];
                $p->Networks   = $peer->networks ?? [];

                if (isset($peer->relayAddress) && isset($peer->connectionType) && $peer->connectionType === "Relayed") {
                    $p->Relay = $peer->relayAddress;
                }

                if (isset($peer->iceCandidateEndpoint->remote)) {
                    $p->CurAddr = $peer->iceCandidateEndpoint->remote;
                }

                $mapped->Peer->{$peer->publicKey} = $p;
            }
        }

        $this->setCachedStatus($mapped);

        return $mapped;
    }

    /**
     * @return array<string, bool>
     */
    public function getRoutes(): array
    {
        if ( ! $this->isDaemonRunning()) {
            return [];
        }

        if ( ! $this->isSocketAvailable()) {
            return [];
        }

        $command = sprintf('timeout %d netbird routes list 2>/dev/null', self::TIMEOUT_SHORT);

        $output     = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return [];
        }

        $routes = [];

        foreach ($output as $line) {
            $line = trim($line);
            if (empty($line) || $line === '-') {
                continue;
            }

            if (preg_match('/([\d.]+\/\d+|[\da-f:]+\/\d+)/i', $line, $matches)) {
                $network          = $matches[1];
                $selected         = stripos($line, 'Selected') !== false;
                $routes[$network] = $selected;
            }
        }

        return $routes;
    }

    public function postLoginInteractive(): void
    {
        if (file_exists('/tmp/netbird-login.log')) {
            unlink('/tmp/netbird-login.log');
        }

        $ifName  = escapeshellarg($this->config->InterfaceName);
        $ifPort  = escapeshellarg(strval($this->config->WgPort));
        $command = "nohup timeout 30 netbird up --interface-name {$ifName} --wireguard-port {$ifPort} --no-browser > /tmp/netbird-login.log 2>&1 &";
        exec($command);
    }

    public function loginWithSetupKey(string $managementUrl, string $setupKey): bool
    {
        if (file_exists('/tmp/netbird-login.log')) {
            unlink('/tmp/netbird-login.log');
        }

        $escapedUrl      = escapeshellarg($managementUrl);
        $escapedSetupKey = escapeshellarg($setupKey);

        $escapedIfName = escapeshellarg($this->config->InterfaceName);
        $escapedIfPort = escapeshellarg(strval($this->config->WgPort));
        $command       = sprintf(
            'nohup timeout 30 netbird up --interface-name %s --wireguard-port %s --management-url %s --setup-key %s > /tmp/netbird-login.log 2>&1 &',
            $escapedIfName,
            $escapedIfPort,
            $escapedUrl,
            $escapedSetupKey
        );
        exec($command);

        return true;
    }
}
