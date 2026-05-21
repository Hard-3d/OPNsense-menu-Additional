# OPNsense Additional Menu

Набор локальных страниц для OPNsense в меню **Дополнительно**.

## Разделы

- **Ethname** — управление `/etc/rc.conf.d/ethname`, установка `ethname` при первом открытии.
- **GeoIP update** — обновление GeoIP alias-баз из GitHub release/download URL.
- **Check VPN status** — проверка WireGuard и Tailscale, статус Cron.
- **Check WAN** — контроль потерь WAN gateway и переключение priority.
- **Update** — проверка и установка новых версий этого меню из GitHub Releases.

## Установка

Скопировать архив на OPNsense, например в `/root`, затем:

```sh
cd /
unzip -o /root/opnsense-additional-menu-v0.1.2-root.zip
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

- tag: `v0.1.2`
- asset: `opnsense-additional-menu-v0.1.2-root.zip`

В самой странице **Update** укажите:

```text
Repository URL: https://github.com/OWNER/REPO
Release asset name: opnsense-additional-menu-v0.1.2-root.zip
```

Если поле **Release asset name** оставить пустым, updater попробует установить GitHub source ZIP latest release. Это тоже поддерживается, если в корне репозитория есть `install.sh`.

## Версия

Текущая версия хранится в:

```text
/usr/local/opnsense/scripts/additional/VERSION
```

Для новой версии:

1. Измените файл `usr/local/opnsense/scripts/additional/VERSION`.
2. Создайте новый git tag, например `v0.1.2`.
3. Соберите новый root ZIP.
4. Загрузите ZIP в GitHub Release.
