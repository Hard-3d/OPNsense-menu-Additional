#!/bin/sh

set -e

echo "Installing OPNsense Additional Menu v0.1.13..."

# ownership
chown -R root:wheel /usr/local/opnsense/mvc/app/models/OPNsense/Additional 2>/dev/null || true
chown -R root:wheel /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional 2>/dev/null || true
chown -R root:wheel /usr/local/opnsense/mvc/app/views/OPNsense/Additional 2>/dev/null || true
chown -R root:wheel /usr/local/opnsense/scripts/additional 2>/dev/null || true

chown root:wheel /usr/local/opnsense/service/conf/actions.d/actions_additional_geoip.conf 2>/dev/null || true
chown root:wheel /usr/local/opnsense/service/conf/actions.d/actions_additional_check_status.conf 2>/dev/null || true
chown root:wheel /usr/local/opnsense/service/conf/actions.d/actions_additional_check_wan.conf 2>/dev/null || true
chown root:wheel /usr/local/opnsense/service/conf/actions.d/actions_additional_updater.conf 2>/dev/null || true
chown root:wheel /usr/local/opnsense/service/conf/actions.d/actions_additional_udp2raw.conf 2>/dev/null || true
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
chmod 755 /usr/local/opnsense/scripts/additional/udp2raw-manager.php 2>/dev/null || true
chmod 755 /usr/local/opnsense/scripts/additional/bin/udp2raw_freebsd 2>/dev/null || true
chmod 755 /usr/local/etc/rc.syshook.d/start/92-additional-udp2raw 2>/dev/null || true
chmod 755 /usr/local/etc/rc.syshook.d/stop/92-additional-udp2raw 2>/dev/null || true

chmod 644 /usr/local/opnsense/service/conf/actions.d/actions_additional_geoip.conf 2>/dev/null || true
chmod 644 /usr/local/opnsense/service/conf/actions.d/actions_additional_check_status.conf 2>/dev/null || true
chmod 644 /usr/local/opnsense/service/conf/actions.d/actions_additional_check_wan.conf 2>/dev/null || true
chmod 644 /usr/local/opnsense/service/conf/actions.d/actions_additional_updater.conf 2>/dev/null || true
chmod 644 /usr/local/opnsense/service/conf/actions.d/actions_additional_udp2raw.conf 2>/dev/null || true


# udp2raw binary compatibility path for old scripts
chown root:wheel /usr/local/etc/rc.syshook.d/start/92-additional-udp2raw 2>/dev/null || true
chown root:wheel /usr/local/etc/rc.syshook.d/stop/92-additional-udp2raw 2>/dev/null || true

# syntax checks
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/IndexController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/EthnameController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/GeoipController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/CheckstatusController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/CheckwanController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/UpdaterController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/SchedulerController.php
php -l /usr/local/opnsense/mvc/app/controllers/OPNsense/Additional/Api/Udp2rawController.php

php -l /usr/local/opnsense/scripts/additional/lib.php
php -l /usr/local/opnsense/scripts/additional/updategeoip.php
php -l /usr/local/opnsense/scripts/additional/check-wg-status.php
php -l /usr/local/opnsense/scripts/additional/check-tailscale-status.php
php -l /usr/local/opnsense/scripts/additional/check-wan-gateway-loss.php
php -l /usr/local/opnsense/scripts/additional/additional-updater.php
php -l /usr/local/opnsense/scripts/additional/additional-scheduler.php
php -l /usr/local/opnsense/scripts/additional/udp2raw-manager.php

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


# keep repository/service files inside module directory, not in filesystem root
MODULE_PACKAGE_DIR="/usr/local/opnsense/scripts/additional/package"
mkdir -p "${MODULE_PACKAGE_DIR}"

[ -f "/install.sh" ] && cp "/install.sh" "${MODULE_PACKAGE_DIR}/install.sh" || true
[ -f "/README.md" ] && cp "/README.md" "${MODULE_PACKAGE_DIR}/README.md" || true
[ -f "/README_INSTALL.txt" ] && cp "/README_INSTALL.txt" "${MODULE_PACKAGE_DIR}/README_INSTALL.txt" || true
[ -f "/.gitignore" ] && cp "/.gitignore" "${MODULE_PACKAGE_DIR}/.gitignore" || true

chown -R root:wheel "${MODULE_PACKAGE_DIR}" 2>/dev/null || true
find "${MODULE_PACKAGE_DIR}" -type d -exec chmod 755 {} \; 2>/dev/null || true
find "${MODULE_PACKAGE_DIR}" -type f -exec chmod 644 {} \; 2>/dev/null || true
chmod 755 "${MODULE_PACKAGE_DIR}/install.sh" 2>/dev/null || true

# remove temporary files from filesystem root after installation
rm -f "/README.md" "/README_INSTALL.txt" "/.gitignore"
rm -f "/install.sh"


echo "Done. Logout and login again."
