#!/bin/sh

set -e

echo "Installing OPNsense Additional Menu v0.1.4..."

# ownership
chown -R root:wheel /usr/local/opnsense/mvc/app/models/OPNsense/Additional 2>/dev/null || true
chown -R root:wheel /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional 2>/dev/null || true
chown -R root:wheel /usr/local/opnsense/mvc/app/views/OPNsense/Additional 2>/dev/null || true
chown -R root:wheel /usr/local/opnsense/scripts/additional 2>/dev/null || true

chown root:wheel /usr/local/opnsense/service/conf/actions.d/actions_additional_geoip.conf 2>/dev/null || true
chown root:wheel /usr/local/opnsense/service/conf/actions.d/actions_additional_check_status.conf 2>/dev/null || true
chown root:wheel /usr/local/opnsense/service/conf/actions.d/actions_additional_check_wan.conf 2>/dev/null || true
chown root:wheel /usr/local/opnsense/service/conf/actions.d/actions_additional_updater.conf 2>/dev/null || true
chmod 644 /usr/local/opnsense/service/conf/actions.d/actions_additional_scheduler.conf 2>/dev/null || true
chown root:wheel /usr/local/opnsense/service/conf/actions.d/actions_additional_scheduler.conf 2>/dev/null || true

# permissions
find /usr/local/opnsense/mvc/app/models/OPNsense/Additional -type d -exec chmod 755 {} \; 2>/dev/null || true
find /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional -type d -exec chmod 755 {} \; 2>/dev/null || true
find /usr/local/opnsense/mvc/app/views/OPNsense/Additional -type d -exec chmod 755 {} \; 2>/dev/null || true
find /usr/local/opnsense/scripts/additional -type d -exec chmod 755 {} \; 2>/dev/null || true

find /usr/local/opnsense/mvc/app/models/OPNsense/Additional -type f -exec chmod 644 {} \; 2>/dev/null || true
find /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional -type f -exec chmod 644 {} \; 2>/dev/null || true
find /usr/local/opnsense/mvc/app/views/OPNsense/Additional -type f -exec chmod 644 {} \; 2>/dev/null || true
find /usr/local/opnsense/scripts/additional -type f -exec chmod 644 {} \; 2>/dev/null || true

chmod 755 /usr/local/opnsense/scripts/additional/updategeoip.php 2>/dev/null || true
chmod 755 /usr/local/opnsense/scripts/additional/check-wg-status.php 2>/dev/null || true
chmod 755 /usr/local/opnsense/scripts/additional/check-tailscale-status.php 2>/dev/null || true
chmod 755 /usr/local/opnsense/scripts/additional/check-wan-gateway-loss.php 2>/dev/null || true
chmod 755 /usr/local/opnsense/scripts/additional/additional-updater.php 2>/dev/null || true
chmod 755 /usr/local/opnsense/scripts/additional/additional-scheduler.php 2>/dev/null || true

chmod 644 /usr/local/opnsense/service/conf/actions.d/actions_additional_geoip.conf 2>/dev/null || true
chmod 644 /usr/local/opnsense/service/conf/actions.d/actions_additional_check_status.conf 2>/dev/null || true
chmod 644 /usr/local/opnsense/service/conf/actions.d/actions_additional_check_wan.conf 2>/dev/null || true
chmod 644 /usr/local/opnsense/service/conf/actions.d/actions_additional_updater.conf 2>/dev/null || true

# syntax checks
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/IndexController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/EthnameController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/GeoipController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/CheckstatusController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/CheckwanController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/UpdaterController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/SchedulerController.php

php -l /usr/local/opnsense/scripts/additional/lib.php
php -l /usr/local/opnsense/scripts/additional/updategeoip.php
php -l /usr/local/opnsense/scripts/additional/check-wg-status.php
php -l /usr/local/opnsense/scripts/additional/check-tailscale-status.php
php -l /usr/local/opnsense/scripts/additional/check-wan-gateway-loss.php
php -l /usr/local/opnsense/scripts/additional/additional-updater.php
php -l /usr/local/opnsense/scripts/additional/additional-scheduler.php

php -r 'simplexml_load_file("/usr/local/opnsense/mvc/app/models/OPNsense/Additional/Menu/Menu.xml") === false ? exit(1) : print("Menu.xml OK\n");'
php -r 'simplexml_load_file("/usr/local/opnsense/mvc/app/models/OPNsense/Additional/ACL/ACL.xml") === false ? exit(1) : print("ACL.xml OK\n");'

# rebuild caches / services
rm -f /tmp/opnsense_menu_cache.xml
rm -f /tmp/opnsense_acl_cache.json
rm -rf /tmp/volt/*

/usr/local/etc/rc.configure_plugins || true
service configd restart || true

if [ "${ADDITIONAL_UPDATER_MODE:-0}" = "1" ]; then
    echo "Updater mode: webgui restart skipped. Reload the page after update."
else
    configctl webgui restart || true
fi

echo "Done. Logout and login again."
