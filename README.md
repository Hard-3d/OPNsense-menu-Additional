# OPNsense Additional Menu

Набор локальных страниц для OPNsense в меню **Дополнительно**.

## Разделы

- **Ethname** — управление `/etc/rc.conf.d/ethname`, установка `ethname` при первом открытии.
- **GeoIP update** — обновление GeoIP alias-баз из GitHub release/download URL.
- **Check VPN status** — проверка WireGuard и Tailscale, статус Cron.
- **Check WAN** — контроль потерь WAN gateway и переключение priority.
- **Scheduler** — единый планировщик для всех задач меню через один OPNsense Cron.
- **Update** — проверка и установка новых версий этого меню из GitHub Releases.

## Установка

Скопировать архив на OPNsense, например в `/root`, затем:

```sh
cd /
unzip -o /root/opnsense-additional-menu-v0.1.8-root.zip
chmod 755 /install.sh
/install.sh
```

После установки:

```text
Logout → Login
```

## Структура репозитория

Репозиторий должен хранить файлы от корня OPNsense-архива:

```text
install.sh
README.md
usr/local/opnsense/...
```

## Releases

Для работы страницы **Дополнительно → Update** рекомендуется публиковать GitHub Release.

Пример:

- tag: `v0.1.8`
- asset: `opnsense-additional-menu-v0.1.8-root.zip`

В самой странице **Update** укажите:

```text
Repository URL: https://github.com/OWNER/REPO
Release asset name: opnsense-additional-menu-v0.1.8-root.zip
```

Если поле **Release asset name** оставить пустым, updater попробует установить GitHub source ZIP latest release. Это тоже поддерживается, если в корне репозитория есть `install.sh`.

## Версия

Текущая версия хранится в:

```text
/usr/local/opnsense/scripts/additional/VERSION
```

Для новой версии:

1. Измените файл `usr/local/opnsense/scripts/additional/VERSION`.
2. Создайте новый git tag, например `v0.1.8`.
3. Соберите новый root ZIP.
4. Загрузите ZIP в GitHub Release.

## Scheduler Cron

Страница **Дополнительно → Scheduler** проверяет наличие задания Cron **Additional Scheduler**. Если задания нет, на странице доступна кнопка создания задания.


## Root cleanup

После установки служебные файлы `install.sh`, `README.md`, `README_INSTALL.txt`, `.gitignore` удаляются из корня `/` и сохраняются в `/usr/local/opnsense/scripts/additional/package/`.

## v0.1.8

- WireGuard check больше не требует API key/secret пользователя root.
- Список WireGuard клиентов читается локально из `/conf/config.xml`.
- Маршруты читаются через `netstat`, перезапуск WireGuard выполняется локальными командами `configctl`/`service`.

## v0.1.8

- На странице Update увеличена ширина полей Repository URL и Release asset name.
