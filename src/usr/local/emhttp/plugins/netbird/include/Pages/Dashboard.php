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

if (!defined(__NAMESPACE__ . '\PLUGIN_ROOT') || !defined(__NAMESPACE__ . '\PLUGIN_NAME')) {
    throw new \RuntimeException("Common file not loaded.");
}

$tr = $tr ?? new Translator(PLUGIN_ROOT);

$netbirdConfig = $netbirdConfig ?? new Config();

$netbird_dashboard = "<tr><td>" . $tr->tr("netbird_disabled") . "</td></tr>";

if ($netbirdConfig->Enable) {
    $localAPI = $localAPI ?? new LocalAPI();
    if (!$localAPI->isSocketAvailable()) {
        $netbird_dashboard = "<tr><td>" . $tr->tr("warnings.not_ready") . "</td></tr>";
    } elseif (!$localAPI->isReady()) {
        $netbird_dashboard = "<tr><td>" . $tr->tr("warnings.not_ready") . "</td></tr>";
    } else {
        $netbirdInfo = $netbirdInfo ?? new Info($tr);
        $netbirdDashInfo = $netbirdInfo->getDashboardInfo();

        $netbird_dashboard = Utils::printDash($tr->tr("info.online"), $netbirdDashInfo->Online);
        $netbird_dashboard .= Utils::printDash($tr->tr("info.hostname"), $netbirdDashInfo->HostName);
        $netbird_dashboard .= Utils::printDash($tr->tr("info.dns"), $netbirdDashInfo->DNSName);
        $netbird_dashboard .= Utils::printDash($tr->tr("info.ip"), implode("<br><span class='w26'>&nbsp;</span>", $netbirdDashInfo->NetbirdIPs));
    }
}

echo <<<EOT
    <tbody title="Netbird">
    <tr>
        <td>
            <div class='tile-header' id='netbird-dashboard-card'>
                <div class='tile-header-left'>
                    <img style="margin-right: 8px; width: 32px; height: 32px" src="/plugins/netbird/netbird.png" alt="Netbird">
                    <div class='section'>
                        <h3 class='tile-header-main' id='netbird-dashboard-title'>Netbird</h3>
                    </div>
                </div>
                <div class='tile-header-right'>
                    <div class='tile-header-right-controls'>
                        <a id="netbird-settings-button" href="/Settings/Netbird" title="_(Settings)_"><i class="fa fa-fw fa-cog control"></i></a></div>
                </div>
            </div>
        </td>
    </tr>
            
    {$netbird_dashboard}
    </tbody>
    EOT;

$isResponsiveWebgui = version_compare(parse_ini_file('/etc/unraid-version')['version'] ?? "", '7.2', '>=');
if (!$isResponsiveWebgui) {
    echo <<<EOT
        <script>
            $(function() {
                $('#netbird-dashboard-title').replaceWith(function() {
                    return $(this).text() + "<br>";
                });
                $('#netbird-settings-button').prependTo('#netbird-dashboard-card');
            });
        </script>
        EOT;
}
