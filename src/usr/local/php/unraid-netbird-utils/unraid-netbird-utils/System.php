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

enum NotificationType: string
{
    case NORMAL  = 'normal';
    case WARNING = 'warning';
    case ALERT   = 'alert';
}

class System extends \EDACerton\PluginUtils\System
{
    public const RESTART_COMMAND = "/usr/local/emhttp/webGui/scripts/reload_services";
    public const NOTIFY_COMMAND  = "/usr/local/emhttp/webGui/scripts/notify";

    public static function addToHostFile(\stdClass $status): void
    {
        // Add self to /etc/hosts
        if (isset($status->Self->DNSName) && isset($status->Self->NetbirdIPs) && is_array($status->Self->NetbirdIPs)) {
            foreach ($status->Self->NetbirdIPs as $ip) {
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    Utils::logwrap("Adding self {$status->Self->DNSName} with IP {$ip} to hosts file");
                    self::updateHostsFile(rtrim($status->Self->DNSName, '.'), $ip);
                }
            }
        } else {
            Utils::logwrap("Self DNSName or NetbirdIPs not found, skipping self addition to hosts file.");
        }

        // Add all peers to /etc/hosts, except those with the tag 'tag:mullvad-exit-node'
        if (isset($status->Peer) && is_object($status->Peer)) {
            foreach ((array) $status->Peer as $k => $peer) {
                if ( ! ($peer instanceof \stdClass)) {
                    continue;
                }
                if (isset($peer->Tags) && is_array($peer->Tags) && in_array('tag:mullvad-exit-node', $peer->Tags, true)) {
                    continue;
                }
                if (isset($peer->DNSName) && isset($peer->NetbirdIPs) && is_array($peer->NetbirdIPs)) {
                    foreach ($peer->NetbirdIPs as $ip) {
                        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            Utils::logwrap("Adding peer {$peer->DNSName} with IP {$ip} to hosts file");
                            self::updateHostsFile(rtrim($peer->DNSName, '.'), $ip);
                        }
                    }
                }
            }
        } else {
            Utils::logwrap("No peers found to add to hosts file.");
        }
    }

    public static function fixLocalSubnetRoutes(): void
    {
        $ips = parse_ini_file("/boot/config/network.cfg") ?: array();
        if (array_key_exists(('IPADDR'), $ips)) {
            $route_table = Utils::runwrap("ip route list table 52", false, false);

            $ipaddr = is_array($ips['IPADDR']) ? $ips['IPADDR'] : array($ips['IPADDR']);

            foreach ($ipaddr as $ip) {
                foreach ($route_table as $route) {
                    $net = explode(' ', $route)[0];
                    if (Utils::ip4_in_network($ip, $net)) {
                        Utils::logwrap("Detected local IP {$ip} in Netbird route {$net}, removing");
                        Utils::runwrap("ip route del '{$net}' dev netbird1 table 52");
                    }
                }
            }
        }
    }

    public static function checkWebgui(Config $config, string $netbird_ipv4, bool $allowRestart): bool
    {
        // Make certain that the WebGUI is listening on the Netbird interface
        if ($config->IncludeInterface) {
            $ident_config = parse_ini_file("/boot/config/ident.cfg") ?: array();

            $connection = @fsockopen($netbird_ipv4, $ident_config['PORT']);

            if (is_resource($connection)) {
                Utils::logwrap("WebGUI listening on {$netbird_ipv4}:{$ident_config['PORT']}", false, true);
            } else {
                if ( ! $allowRestart) {
                    Utils::logwrap("WebGUI not listening on {$netbird_ipv4}:{$ident_config['PORT']}, waiting for next check");
                    return true;
                }

                Utils::logwrap("WebGUI not listening on {$netbird_ipv4}:{$ident_config['PORT']}, terminating and restarting");
                Utils::runwrap("/etc/rc.d/rc.nginx term");
                sleep(5);
                Utils::runwrap("/etc/rc.d/rc.nginx start");
            }
        }

        return false;
    }

    public static function restartSystemServices(Config $config): void
    {
        if ($config->IncludeInterface) {
            Utils::runwrap(self::RESTART_COMMAND);
        }
    }

    public static function enableIPForwarding(Config $config): void
    {
        if ($config->Enable) {
            Utils::logwrap("Enabling IP forwarding");
            $sysctl = "net.ipv4.ip_forward = 1" . PHP_EOL . "net.ipv6.conf.all.forwarding = 1";
            file_put_contents('/etc/sysctl.d/99-netbird.conf', $sysctl);
            Utils::runwrap("sysctl -p /etc/sysctl.d/99-netbird.conf", true);
        }
    }

    public static function applyGRO(): void
    {
        /** @var array<int, array<string>> $ip_route */
        $ip_route = (array) json_decode(implode(Utils::runwrap('ip -j route get 8.8.8.8')), true);

        // Check if a device was returned
        if ( ! isset($ip_route[0]['dev'])) {
            Utils::logwrap("Default interface could not be detected.");
            return;
        }

        $dev = $ip_route[0]['dev'];

        /** @var array<string, array<string>> $ethtool */
        $ethtool = ((array) json_decode(implode(Utils::runwrap("ethtool --json -k {$dev}")), true))[0];

        if (isset($ethtool['rx-udp-gro-forwarding']) && ! $ethtool['rx-udp-gro-forwarding']['active']) {
            Utils::runwrap("ethtool -K {$dev} rx-udp-gro-forwarding on");
        }

        if (isset($ethtool['rx-gro-list']) && $ethtool['rx-gro-list']['active']) {
            Utils::runwrap("ethtool -K {$dev} rx-gro-list off");
        }
    }

    public static function notifyOnKeyExpiration(): void
    {
        $localAPI = new LocalAPI();
        $status   = $localAPI->getStatus();

        if (isset($status->Self->KeyExpiry)) {
            $expiryTime = new \DateTime($status->Self->KeyExpiry);
            $expiryTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $interval = $expiryTime->diff(new \DateTime('now'));

            $expiryPrint   = $expiryTime->format(\DateTimeInterface::RFC7231);
            $intervalPrint = $interval->format('%a');

            $message = "The Netbird key will expire in {$intervalPrint} days on {$expiryPrint}.";
            Utils::logwrap($message);

            switch (true) {
                case $interval->days <= 7:
                    $priority = NotificationType::ALERT;
                    break;
                case $interval->days <= 30:
                    $priority = NotificationType::WARNING;
                    break;
                default:
                    return;
            }

            $event = "Netbird Key Expiration - {$priority->value} - {$expiryTime->format('Ymd')}";
            Utils::logwrap("Sending notification for key expiration: {$event}");
            self::sendNotification($event, "Netbird key is expiring", $message, $priority);
        } else {
            Utils::logwrap("Netbird key expiration is not set.");
        }
    }

    public static function sendNotification(string $event, string $subject, string $message, NotificationType $priority): void
    {
        $command = self::NOTIFY_COMMAND . " -l '/Settings/Netbird' -e " . escapeshellarg($event) . " -s " . escapeshellarg($subject) . " -d " . escapeshellarg("{$message}") . " -i \"{$priority->value}\" -x 2>/dev/null";
        exec($command);
    }

    public static function setExtraInterface(Config $config): void
    {
        if (file_exists(self::RESTART_COMMAND)) {
            $include_array      = array();
            $exclude_interfaces = "";
            $write_file         = true;
            $network_extra_file = '/boot/config/network-extra.cfg';
            $ifname             = 'netbird1';

            if (file_exists($network_extra_file)) {
                $netExtra = parse_ini_file($network_extra_file);
                if ($netExtra['include_interfaces'] ?? false) {
                    $include_array = explode(' ', $netExtra['include_interfaces']);
                }
                if ($netExtra['exclude_interfaces'] ?? false) {
                    $exclude_interfaces = $netExtra['exclude_interfaces'];
                }
                $write_file = false;
            }

            $in_array = in_array($ifname, $include_array);

            if ($in_array != $config->IncludeInterface) {
                if ($config->IncludeInterface) {
                    $include_array[] = $ifname;
                    Utils::logwrap("{$ifname} added to include_interfaces");
                } else {
                    $include_array = array_diff($include_array, [$ifname]);
                    Utils::logwrap("{$ifname} removed from include_interfaces");
                }
                $write_file = true;
            }

            if ($write_file) {
                $include_interfaces = implode(' ', $include_array);

                $file = <<<END
                    include_interfaces="{$include_interfaces}"
                    exclude_interfaces="{$exclude_interfaces}"

                    END;

                file_put_contents($network_extra_file, $file);
                Utils::logwrap("Updated network-extra.cfg");
            }
        }
    }

    public static function updateNetbirdConfig(Config $config): void
    {
        $configFile = '/boot/config/plugins/netbird/config.json';

        if ( ! file_exists($configFile)) {
            Utils::logwrap("Netbird config.json not found, skipping update");
            return;
        }

        $content = file_get_contents($configFile);
        if ($content === false) {
            Utils::logwrap("Failed to read netbird config.json");
            return;
        }

        /** @var array<string, mixed> $json */
        $json = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Utils::logwrap("Failed to parse netbird config.json: " . json_last_error_msg());
            return;
        }

        $json['DisableDNS']          = ! $config->AllowDNS;
        $json['DisableClientRoutes'] = ! $config->AllowRoutes;
        $json['ServerSSHAllowed']    = $config->SSH;

        if ($config->WgPort > 0 && $config->WgPort <= 65535) {
            $json['WgPort'] = $config->WgPort;
        }

        $newContent = json_encode($json, JSON_PRETTY_PRINT);
        if ($newContent === false) {
            Utils::logwrap("Failed to encode netbird config.json");
            return;
        }

        file_put_contents($configFile, $newContent);

        $disableDNS          = $json['DisableDNS'] ? 'true' : 'false';
        $disableClientRoutes = $json['DisableClientRoutes'] ? 'true' : 'false';
        $serverSSHAllowed    = $json['ServerSSHAllowed'] ? 'true' : 'false';
        Utils::logwrap("Updated netbird config.json: DisableDNS={$disableDNS}, DisableClientRoutes={$disableClientRoutes}, ServerSSHAllowed={$serverSSHAllowed}");
    }

    public static function createNetbirdParamsFile(Config $config): void
    {
        file_put_contents('/usr/local/emhttp/plugins/netbird/custom-params.sh', 'NETBIRD_CUSTOM_PARAMS=""');
    }
}
