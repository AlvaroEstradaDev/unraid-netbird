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

if ( ! defined(__NAMESPACE__ . '\PLUGIN_ROOT') || ! defined(__NAMESPACE__ . '\PLUGIN_NAME')) {
    throw new \RuntimeException("Common file not loaded.");
}

$tr = $tr ?? new Translator(PLUGIN_ROOT);

if ( ! Utils::pageChecks($tr)) {
    return;
}

$netbirdInfo       = $netbirdInfo ?? new Info($tr);
$netbirdStatusInfo = $netbirdInfo->getStatusInfo();
?>
<table class="unraid t1">
    <thead>
        <tr>
            <td><?= $tr->tr('status'); ?></td>
            <td>&nbsp;</td>
        </tr>
    </thead>
    <tbody>
        <?php
        echo Utils::printRow($tr->tr("info.version"), $netbirdStatusInfo->TsVersion);
echo Utils::printRow($tr->tr("info.login"), $netbirdStatusInfo->LoggedIn);
echo Utils::printRow($tr->tr("info.online"), $netbirdStatusInfo->Online);
echo Utils::printRow($tr->tr("info.connected_via"), $netbirdInfo->connectedViaTS() ? $tr->tr("yes") : $tr->tr("no"));
?>
    </tbody>
</table>