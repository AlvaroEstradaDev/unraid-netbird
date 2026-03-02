( cd etc/cron.daily ; rm -rf netbird-daily )
( cd etc/cron.daily ; rm -rf check-netbird-key )
( cd etc/cron.daily ; ln -sf /usr/local/php/unraid-netbird-utils/daily.sh netbird-daily )
( cd usr/local/emhttp/plugins/netbird/event ; rm -rf array_started )
( cd usr/local/emhttp/plugins/netbird/event ; ln -sf ../restart.sh array_started )
( cd usr/local/emhttp/plugins/netbird/event ; rm -rf stopped )
( cd usr/local/emhttp/plugins/netbird/event ; ln -sf ../restart.sh stopped )

chmod 0644 /etc/logrotate.d/netbird
chown root:root /etc/logrotate.d/netbird