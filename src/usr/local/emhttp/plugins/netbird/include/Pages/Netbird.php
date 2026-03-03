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

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "{$docroot}/plugins/netbird/include/common.php";

if ( ! defined(__NAMESPACE__ . '\PLUGIN_ROOT') || ! defined(__NAMESPACE__ . '\PLUGIN_NAME')) {
    throw new \RuntimeException("Common file not loaded.");
}

$tr = $tr ?? new Translator(PLUGIN_ROOT);

if ( ! Utils::pageChecks($tr)) {
    return;
}

$netbirdInfo = $netbirdInfo ?? new Info($tr);
?>
<link type="text/css" rel="stylesheet" href="/plugins/netbird/style.css">
<script src="/webGui/javascript/jquery.tablesorter.widgets.js"></script>

<script src="/plugins/netbird/lib/select2/select2.min.js"></script>
<link href="/plugins/netbird/lib/select2/select2.min.css" rel="stylesheet" />

<script src="/plugins/netbird/lib/ipaddr.min.js"></script>

<style>
    .select2-container {
        margin: 10px 12px 10px 0;
    }
</style>

<script>

    function netbirdControlsDisabled(val)
    {
        $('#configTable_refresh').prop('disabled', val);
        $('#configTable input[type="button"]').prop('disabled', val);
        $('#routesTable input[type="button"]').prop('disabled', val);
        $('#addNetbirdRoute').prop('disabled', val || !$('#addNetbirdRoute').data('valid'));
    }
    function showNetbirdConfig()
    {
        netbirdControlsDisabled(true);
        $.post('/plugins/netbird/include/data/Config.php', { action: 'get' }, function (data)
        {
            clearTimeout(timers.refresh);
            $("#configTable").trigger("destroy");
            $('#configTable').html(data.config);
            $("#routesTable").trigger("destroy");
            $('#routesTable').html(data.routes);
            $("#connectionTable").trigger("destroy");
            $('#connectionTable').html(data.connection);
            $('div.spinner.fixed').hide('fast');
            netbirdControlsDisabled(false);
            validateNetbirdRoute();
        }, "json");
    }
    
    function toggleSetting(setting, value)
    {
        netbirdControlsDisabled(true);
        $('#netbirdRestartStatus').html('<span style="color: orange;"><i class="fa fa-spinner fa-spin"></i> <?= $tr->tr("settings.restarting"); ?></span>').show();
        
        $.post('/plugins/netbird/include/data/Config.php', { action: 'toggle', setting: setting, value: value }, function (data)
        {
            if (data.success) {
                setTimeout(function() {
                    $('#netbirdRestartStatus').html('<span style="color: green;"><i class="fa fa-check"></i> <?= $tr->tr("settings.restart_complete"); ?></span>');
                    setTimeout(function() {
                        $('#netbirdRestartStatus').fadeOut('slow');
                        showNetbirdConfig();
                    }, 500);
                }, 7000);
            } else {
                $('#netbirdRestartStatus').html('<span style="color: red;"><i class="fa fa-exclamation-triangle"></i> ' + (data.error || 'Unknown error') + '</span>');
                netbirdControlsDisabled(false);
            }
        }, "json");
    }


    const CIDRResult = Object.freeze({
        VALID: 'valid',
        INVALID: 'invalid',
        EMPTY: 'empty',
        HOSTBITS_SET: 'hostbits_set'
    });

    function isValidCIDR(ip)
    {
        if (ip === undefined || ip.trim() === '')
        {
            return CIDRResult.EMPTY;
        }

        try
        {
            // Parse and validate CIDR notation
            const [addr, prefix] = ipaddr.parseCIDR(ip);

            // Get the network address with host bits cleared
            let networkAddr;
            if (addr.kind() === 'ipv4')
            {
                networkAddr = ipaddr.IPv4.networkAddressFromCIDR(ip);
            } else
            {
                networkAddr = ipaddr.IPv6.networkAddressFromCIDR(ip);
            }

            // Check if the original address matches the network address
            if (addr.toString() !== networkAddr.toString())
            {
                return CIDRResult.HOSTBITS_SET;
            }

            return CIDRResult.VALID;
        } catch (e)
        {
            return CIDRResult.INVALID;
        }
    }

    function validateNetbirdRoute()
    {
        switch (isValidCIDR($('#netbirdRoute').val()))
        {
            case CIDRResult.VALID:
                $('#netbirdRouteValidation').text('');
                $('#addNetbirdRoute').prop('disabled', false);
                break;
            case CIDRResult.HOSTBITS_SET:
                $('#netbirdRouteValidation').text('Invalid CIDR: Host bits may not be set').css('color', 'red');
                $('#addNetbirdRoute').prop('disabled', true);
                break;
            case CIDRResult.EMPTY:
                $('#netbirdRouteValidation').text('');
                $('#addNetbirdRoute').prop('disabled', true);
                break;
            case CIDRResult.INVALID:
            default:
                $('#netbirdRouteValidation').text('Invalid CIDR').css('color', 'red');
                $('#addNetbirdRoute').prop('disabled', true);
                break;
        }
    }

    showNetbirdConfig();
</script>

<!-- TODO: Get these warnings with the table -->
<?= Utils::formatWarning($netbirdInfo->getNetbirdNetbiosWarning()); ?>
<?= Utils::formatWarning($netbirdInfo->getKeyExpirationWarning()); ?>

<table id='connectionTable' class="unraid statusTable tablesorter">
    <tr>
        <td>
            <div class="spinner"></div>
        </td>
    </tr>
</table><br>
<table id='configTable' class="unraid statusTable tablesorter">
    <tr>
        <td>
            <div class="spinner"></div>
        </td>
    </tr>
</table><br>
<table id='routesTable' class="unraid statusTable tablesorter">
    <tr>
        <td>
            <div class="spinner"></div>
        </td>
    </tr>
</table><br>
<table>
    <tr>
        <td style="vertical-align: top">
            <input type="button" id="configTable_refresh" value="Refresh" onclick="showNetbirdConfig()">
            <span id="netbirdRestartStatus" style="margin-left: 10px; display: none;"></span>
        </td>
    </tr>
</table>