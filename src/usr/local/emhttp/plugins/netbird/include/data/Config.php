<?php

namespace Netbird;

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

use EDACerton\PluginUtils\Translator;

try {
    require_once dirname(dirname(__FILE__)) . "/common.php";

    if ( ! defined(__NAMESPACE__ . '\PLUGIN_ROOT') || ! defined(__NAMESPACE__ . '\PLUGIN_NAME')) {
        throw new \RuntimeException("Common file not loaded.");
    }

    $tr    = $tr    ?? new Translator(PLUGIN_ROOT);
    $utils = $utils ?? new Utils(PLUGIN_NAME);

    $netbirdConfig = $netbirdConfig ?? new Config();

    if ( ! $netbirdConfig->Enable) {
        echo("{}");
        return;
    }

    $netbirdInfo = $netbirdInfo ?? new Info($tr);

    switch ($_POST['action']) {
        case 'get':
            $connectionRows = "";
            $configRows     = "";
            $routes         = "<table id='routesTable' class='unraid statusTable'></table>";
            $config         = "<table id='configTable' class='unraid statusTable'></table>";

            if ($netbirdInfo->needsLogin()) {
                $connectionRows = "<tr><td>{$tr->tr("needs_login")}</td><td><input type='button' value='{$tr->tr("login")}' onclick='netbirdUp()'></td><td><a id='netbirdUpLink' href='#'></a></td></tr>";
            } else {
                $netbirdStatusInfo = $netbirdInfo->getStatusInfo();
                $netbirdConInfo    = $netbirdInfo->getConnectionInfo();

                $acceptDNSButton = $netbirdInfo->acceptsDNS()
                    ? "<input type='button' value='{$tr->tr("remove")}' onclick='toggleSetting(\"accept_dns\", false)'>"
                    : "<input type='button' value='{$tr->tr("enable")}' onclick='toggleSetting(\"accept_dns\", true)'>";
                $acceptRoutesButton = $netbirdInfo->acceptsRoutes()
                    ? "<input type='button' value='{$tr->tr("remove")}' onclick='toggleSetting(\"accept_routes\", false)'>"
                    : "<input type='button' value='{$tr->tr("enable")}' onclick='toggleSetting(\"accept_routes\", true)'>";

                $connectionRows = <<<EOT
                    <tr><td>{$tr->tr("info.hostname")}</td><td>{$netbirdConInfo->HostName}</td><td></td></tr>
                    <tr><td>{$tr->tr("info.dns")}</td><td>{$netbirdConInfo->DNSName}</td><td></td></tr>
                    <tr><td>{$tr->tr("info.ip")}</td><td>{$netbirdConInfo->NetbirdIPs}</td><td></td></tr>
                    EOT;

                $configRows = <<<EOT
                    <tr><td>{$tr->tr("info.accept_routes")}</td><td>{$netbirdConInfo->AcceptRoutes}</td><td style="text-align: right;">{$acceptRoutesButton}</td></tr>
                    <tr><td>{$tr->tr("info.accept_dns")}</td><td>{$netbirdConInfo->AcceptDNS}</td><td style="text-align: right;">{$acceptDNSButton}</td></tr>

                    EOT;

                $routesRows = "";

                foreach ($netbirdInfo->getAdvertisedRoutes() as $route) {
                    $approved = $netbirdInfo->isApprovedRoute($route) ? "" : $tr->tr("info.unapproved");
                    $routesRows .= "<tr><td>{$route}</td><td>{$approved}</td><td style='text-align: right;'><input type='button' value='{$tr->tr("remove")}' disabled></td></tr>";
                }

                $routes = <<<EOT
                    <table id="routesTable" class="unraid statusTable">
                        <thead>
                            <tr>
                                <th style="width: 40%" class="filter-false">{$tr->tr('info.routes')}</th>
                                <th style="width: 40%" class="filter-false">&nbsp;</th>
                                <th class="filter-false">&nbsp;</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$routesRows}
                            <tr><td><input type="text" id="netbirdRoute" name="netbirdRoute" oninput='validateNetbirdRoute()'></td><td><span id="netbirdRouteValidation"></span></td><td style="text-align: right;"><input type='button' id="addNetbirdRoute" value='{$tr->tr("add")}' disabled></td></tr>
                        </tbody>
                    </table>
                    EOT;

                $config = <<<EOT
                    <table id="configTable" class="unraid statusTable">
                        <thead>
                            <tr>
                                <th style="width: 40%" class="filter-false">{$tr->tr('configuration')}</th>
                                <th style="width: 40%" class="filter-false">&nbsp;</th>
                                <th class="filter-false">&nbsp;</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$configRows}
                        </tbody>
                    </table>
                    EOT;
            }

            $connection = <<<EOT
                <table id="connectionTable" class="unraid statusTable">
                    <thead>
                        <tr>
                            <th style="width: 40%" class="filter-false">{$tr->tr('connection')}</th>
                            <th style="width: 40%" class="filter-false">&nbsp;</th>
                            <th class="filter-false">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$connectionRows}
                    </tbody>
                </table>
                EOT;

            $rtn               = array();
            $rtn['config']     = $config;
            $rtn['routes']     = $routes;
            $rtn['connection'] = $connection;

            echo json_encode($rtn);
            break;
        case 'up':
            $managementUrl = $_POST['management_url'] ?? $netbirdConfig->ManagementUrl;
            $setupKey      = $_POST['setup_key']      ?? $netbirdConfig->SetupKey;

            if ( ! empty($managementUrl) && ! empty($setupKey)) {
                $utils->logmsg("Logging in with setup key");
                $localAPI = new LocalAPI();
                $result   = $localAPI->loginWithSetupKey($managementUrl, $setupKey);
                echo $result ? "setup_key_success" : "setup_key_failed";
            } else {
                $utils->logmsg("Getting Auth URL");
                $authURL = $netbirdInfo->getAuthURL();
                if ($authURL == "") {
                    $localAPI = new LocalAPI();
                    $localAPI->postLoginInteractive();
                    $retries = 0;
                    while ($retries < 60) {
                        $netbirdInfo = new Info($tr);
                        $authURL     = $netbirdInfo->getAuthURL();
                        if ($authURL != "") {
                            break;
                        }
                        usleep(500000);
                        $retries++;
                    }
                }
                echo $authURL;
            }
            break;
        case 'toggle':
            $setting = $_POST['setting'] ?? '';
            $value   = filter_var($_POST['value'] ?? '', FILTER_VALIDATE_BOOLEAN);

            $configFile = '/boot/config/plugins/netbird/netbird.cfg';
            $config     = file_exists($configFile) ? (parse_ini_file($configFile) ?: []) : [];

            $settingMap = [
                'accept_dns'    => 'ACCEPT_DNS',
                'accept_routes' => 'ACCEPT_ROUTES'
            ];

            if (isset($settingMap[$setting])) {
                $configKey          = $settingMap[$setting];
                $config[$configKey] = $value ? '1' : '0';

                $content = '';
                foreach ($config as $key => $val) {
                    $content .= "{$key}=\"{$val}\"\n";
                }
                file_put_contents($configFile, $content);

                $newConfig = new Config();
                System::createNetbirdParamsFile($newConfig);
                System::updateNetbirdConfig($newConfig);

                Utils::runwrap('/usr/local/emhttp/plugins/netbird/restart.sh');

                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid setting']);
            }
            break;
    }
} catch (\Throwable $e) {
    file_put_contents("/var/log/netbird-error.log", print_r($e, true) . PHP_EOL, FILE_APPEND);
    echo "{}";
}
