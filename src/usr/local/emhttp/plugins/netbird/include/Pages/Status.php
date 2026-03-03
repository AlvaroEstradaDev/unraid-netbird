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
?>

<script src="/webGui/javascript/jquery.tablesorter.widgets.js"></script>

<script>

    function controlsDisabled(val)
    {
        $('#statusTable_refresh').prop('disabled', val);
        $('input.ping').prop('disabled', val);
        $('input.ssh').prop('disabled', val);
        $('input.copy-ip').prop('disabled', val);
    }
    function showStatus()
    {
        controlsDisabled(true);
        $.post('/plugins/netbird/include/data/Status.php', { action: 'get', offline: $("#statusTable_offline").prop('checked'), routers: $("#statusTable_routers").prop('checked') }, function (data)
        {
            clearTimeout(timers.refresh);
            $("#statusTable").trigger("destroy");
            $('#statusTable').html(data.html);
            $('#statusTable').tablesorter({
                widthFixed: true,
                sortList: [[0, 0]],
                sortAppend: [[0, 0]],
                widgets: ['stickyHeaders', 'filter', 'zebra'],
                widgetOptions: {
                    // on black and white, offset is height of #menu
                    // on azure and gray, offset is height of #header
                    stickyHeaders_offset: ($('#menu').height() < 50) ? $('#menu').height() : $('#header').height(),
                    filter_columnFilters: true,
                    filter_reset: '.reset',
                    filter_liveSearch: true,

                    zebra: ["normal-row", "alt-row"]
                }
            });
            $('div.spinner.fixed').hide('fast');
            controlsDisabled(false);
        }, "json");
    }
    async function pingHost(host)
    {
        $('div.spinner.fixed').show('fast');
        controlsDisabled(true);
        var res = await $.post('/plugins/netbird/include/data/Status.php', { action: 'ping', host: host });
        $("#status_pingout").html("<strong>Ping response:</strong><br>" + res);
        showStatus();
    }
    function sshPeer(hostname)
    {
        var username = prompt("<?= $tr->tr('status_page.ssh_prompt'); ?>");
        if (username === null) return; // Cancelled
        
        var sshCmd = username ? 
            'netbird ssh ' + username + '@' + hostname :
            'netbird ssh ' + hostname;
        
        copyToClipboard(sshCmd);
        alert("<?= $tr->tr('status_page.ssh_copied'); ?>\n\n" + sshCmd + "\n\n<?= $tr->tr('status_page.ssh_instruction'); ?>");
    }
    function copyToClipboard(text)
    {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }
    function copyIP(ip)
    {
        copyToClipboard(ip);
        // Brief visual feedback - flash the button
        var btn = event.target;
        var originalValue = btn.value;
        btn.value = "<?= $tr->tr('copied'); ?>";
        setTimeout(function() { btn.value = originalValue; }, 1000);
    }
    showStatus();
</script>

<table id='statusTable' class="unraid statusTable tablesorter">
    <tr>
        <td>
            <div class="spinner"></div>
        </td>
    </tr>
</table><br>
<table>
    <tr>
        <td style="vertical-align: top">
            <input type="button" id="statusTable_refresh" value="Refresh" onclick="showStatus()">
            <button type="button" class="reset">Reset Filters</button>
            <input type="checkbox" id="statusTable_offline" onChange="showStatus()"><?= $tr->tr('status_page.show_offline'); ?>
            <input type="checkbox" id="statusTable_routers" onChange="showStatus()"><?= $tr->tr('status_page.show_routers'); ?>
        </td>
        <td>
            <div id="status_pingout" style="float: right;"></div>
        </td>
    </tr>
</table>