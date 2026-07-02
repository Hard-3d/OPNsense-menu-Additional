OPNsense Additional Menu v0.1.53

cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.53-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.53-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
