#!/bin/bash

. /usr/local/emhttp/plugins/netbird/log.sh

log "Stopping Netbird"
/etc/rc.d/rc.netbird stop

log "Erasing Configuration"
rm -f /boot/config/plugins/netbird/netbird.cfg
rm -f /boot/config/plugins/netbird/config.json

log "Restarting Netbird"
echo "sleep 5 ; /usr/local/emhttp/plugins/netbird/restart.sh" | at now 2>/dev/null