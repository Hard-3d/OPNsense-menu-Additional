OPNsense Additional Menu v0.1.39

Установка:
cd /
unzip -o /root/opnsense-additional-menu-v0.1.39-root.zip
chmod 755 /install.sh
/install.sh

После установки:
Logout -> Login

Меню:
Дополнительно
- Ethname
- GeoIP update
- Check VPN status
- Check WAN
- udp2raw
- Scheduler
- Update

Для GitHub:
- распакуйте архив в локальную папку репозитория;
- сделайте git add/commit/push;
- создайте Release v0.1.39;
- загрузите этот же root ZIP как release asset.


## Root cleanup

После установки служебные файлы `install.sh`, `README.md`, `README_INSTALL.txt`, `.gitignore` удаляются из корня `/` и сохраняются в `/usr/local/opnsense/scripts/additional/package/`.

После выполнения install.sh служебные файлы будут перенесены в `/usr/local/opnsense/scripts/additional/package/` и удалены из корня `/`.
