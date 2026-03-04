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

use EDACerton\PluginUtils\Translator;

class Info
{
    private string $useNetbios;
    private string $smbEnabled;
    private ?Translator $tr;
    private LocalAPI $localAPI;
    private \stdClass $status;
    /** @var array<string, bool> */
    private array $routes;
    /** @var array<string, string> */
    private array $pluginConfig;

    public function __construct(?Translator $tr)
    {
        $share_config = parse_ini_file("/boot/config/share.cfg") ?: array();
        $ident_config = parse_ini_file("/boot/config/ident.cfg") ?: array();

        $this->localAPI = new LocalAPI();

        $this->tr         = $tr;
        $this->smbEnabled = $share_config['shareSMBEnabled'] ?? "";
        $this->useNetbios = $ident_config['USE_NETBIOS']     ?? "";
        $this->status     = $this->localAPI->getStatus();
        $this->routes     = $this->localAPI->getRoutes();

        $plugin_config_file = '/boot/config/plugins/netbird/netbird.cfg';
        $this->pluginConfig = file_exists($plugin_config_file)
            ? (parse_ini_file($plugin_config_file) ?: [])
            : [];
    }

    public function getStatus(): \stdClass
    {
        return $this->status;
    }

    private function tr(string $message): string
    {
        if ($this->tr === null) {
            return $message;
        }

        return $this->tr->tr($message);
    }

    public function getStatusInfo(): StatusInfo
    {
        $status = $this->status;

        $statusInfo = new StatusInfo();

        $statusInfo->TsVersion = isset($status->Version) ? $status->Version : $this->tr("unknown");
        $statusInfo->Online    = isset($status->Self->Online) ? ($status->Self->Online ? $this->tr("yes") : $this->tr("no")) : $this->tr("unknown");
        $statusInfo->LoggedIn  = $this->isOnline() ? $this->tr("yes") : $this->tr("no");

        return $statusInfo;
    }

    public function getConnectionInfo(): ConnectionInfo
    {
        $status = $this->status;

        $info = new ConnectionInfo();

        $info->HostName     = isset($status->Self->HostName) ? $status->Self->HostName : $this->tr("unknown");
        $info->DNSName      = isset($status->Self->DNSName) ? $status->Self->DNSName : $this->tr("unknown");
        $info->NetbirdIPs   = isset($status->NetbirdIPs) ? implode("<br>", $status->NetbirdIPs) : $this->tr("unknown");
        $info->AcceptRoutes = $this->acceptsRoutes() ? $this->tr("yes") : $this->tr("no");
        $info->AcceptDNS    = $this->acceptsDNS() ? $this->tr("yes") : $this->tr("no");
        $info->RunSSH       = $this->runsSSH() ? $this->tr("yes") : $this->tr("no");

        return $info;
    }

    public function getDashboardInfo(): DashboardInfo
    {
        $status = $this->status;

        $info = new DashboardInfo();

        $info->HostName   = isset($status->Self->HostName) ? $status->Self->HostName : $this->tr("Unknown");
        $info->DNSName    = isset($status->Self->DNSName) ? $status->Self->DNSName : $this->tr("Unknown");
        $info->NetbirdIPs = isset($status->NetbirdIPs) ? $status->NetbirdIPs : array();
        $info->Online     = isset($status->Self->Online) ? ($status->Self->Online ? $this->tr("yes") : $this->tr("no")) : $this->tr("unknown");

        return $info;
    }

    public function getNetbirdNetbiosWarning(): ?Warning
    {
        if (($this->useNetbios == "yes") && ($this->smbEnabled != "no")) {
            return new Warning($this->tr("warnings.netbios"), "warn");
        }
        return null;
    }

    /**
     * @return array<int, PeerStatus>
     */
    public function getPeerStatus(): array
    {
        $result = array();

        foreach ($this->status->Peer as $node => $status) {
            $peer = new PeerStatus();

            $peer->Name = trim($status->DNSName, ".");
            $peer->IP   = $status->NetbirdIPs;

            $peer->LoginName = (isset($this->status->User) && isset($status->UserID))
                ? ($this->status->User->{$status->UserID}->LoginName ?? "")
                : "";
            $peer->Networks = $status->Networks ?? [];

            if ($status->TxBytes > 0 || $status->RxBytes > 0) {
                $peer->Traffic = true;
                $peer->TxBytes = $status->TxBytes;
                $peer->RxBytes = $status->RxBytes;
            }

            if ( ! $status->Online) {
                $peer->Online = false;
                $peer->Active = false;
            } elseif ( ! $status->Active) {
                $peer->Online = true;
                $peer->Active = false;
            } else {
                $peer->Online = true;
                $peer->Active = true;

                if (($status->Relay != "") && ($status->CurAddr == "")) {
                    $peer->Relayed = true;
                    $peer->Address = $status->Relay;
                } elseif ($status->CurAddr != "") {
                    $peer->Relayed = false;
                    $peer->Address = $status->CurAddr;
                }
            }

            $result[] = $peer;
        }

        return $result;
    }

    public function acceptsDNS(): bool
    {
        return boolval($this->pluginConfig['ACCEPT_DNS'] ?? '0');
    }

    public function acceptsRoutes(): bool
    {
        return boolval($this->pluginConfig['ACCEPT_ROUTES'] ?? '0');
    }

    public function runsSSH(): bool
    {
        return boolval($this->pluginConfig['SSH'] ?? '0');
    }

    public function isOnline(): bool
    {
        return $this->status->Self->Online ?? false;
    }

    public function getAuthURL(): string
    {
        if (file_exists('/tmp/netbird-login.log')) {
            $content = file_get_contents('/tmp/netbird-login.log');
            if ($content !== false && preg_match('/(https:\/\/\S+?(?:auth|login)\S*)/i', $content, $matches)) {
                return $matches[1];
            }
        }
        return $this->status->AuthURL ?? "";
    }

    public function needsLogin(): bool
    {
        return ! $this->isOnline();
    }

    /**
     * @return array<int, string>
     */
    public function getAdvertisedRoutes(): array
    {
        return array_keys($this->routes);
    }

    public function isApprovedRoute(string $route): bool
    {
        // In Netbird, a route is "approved" if it's selected
        return $this->routes[$route] ?? false;
    }

    public function connectedViaTS(): bool
    {
        return in_array($_SERVER['SERVER_ADDR'] ?? "", $this->status->NetbirdIPs ?? array());
    }
}
