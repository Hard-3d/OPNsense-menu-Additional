OPNsense Additional Menu v0.1.64

Рекомендуемая установка теперь выполняется с OPNsense Central Controller:

fetch -o /tmp/install-opnsense-additional-menu.sh "http://CONTROLLER:81/api/public/bootstrap/opnsense-additional-menu.sh"
sh /tmp/install-opnsense-additional-menu.sh

Ручная установка zip:

fetch -o /tmp/opnsense-additional-menu-v0.1.64-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.64-root.zip -d /
chmod +x /install.sh
/install.sh
