# OPNsense Additional Menu

Набор локальных страниц для OPNsense в меню **Дополнительно**.

## Разделы

- **Ethname** — управление `/etc/rc.conf.d/ethname`, установка `ethname` при первом открытии.
- **GeoIP update** — обновление GeoIP alias-баз из GitHub release/download URL.
- **Check VPN status** — проверка WireGuard и Tailscale, статус Cron.
- **Check WAN** — контроль потерь WAN gateway и переключение priority.
- **udp2raw** — управление udp2raw client/server instances без правки WireGuard service files.
- **Scheduler** — единый планировщик для всех задач меню через один OPNsense Cron.
- **Update** — проверка и установка новых версий этого меню из GitHub Releases.

## Установка

Скопировать архив на OPNsense, например в `/root`, затем:

```sh
cd /
unzip -o /root/opnsense-additional-menu-v0.1.22-root.zip
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

- tag: `v0.1.22`
- asset: `opnsense-additional-menu-v0.1.22-root.zip`

В самой странице **Update** укажите:

```text
Repository URL: https://github.com/OWNER/REPO
Release asset name: opnsense-additional-menu-v0.1.22-root.zip
```

Если поле **Release asset name** оставить пустым, updater попробует установить GitHub source ZIP latest release. Это тоже поддерживается, если в корне репозитория есть `install.sh`.

## Версия

Текущая версия хранится в:

```text
/usr/local/opnsense/scripts/additional/VERSION
```

Для новой версии:

1. Измените файл `usr/local/opnsense/scripts/additional/VERSION`.
2. Создайте новый git tag, например `v0.1.22`.
3. Соберите новый root ZIP.
4. Загрузите ZIP в GitHub Release.

## Scheduler Cron

Страница **Дополнительно → Scheduler** проверяет наличие задания Cron **Additional Scheduler**. Если задания нет, на странице доступна кнопка создания задания.


## Root cleanup

После установки служебные файлы `install.sh`, `README.md`, `README_INSTALL.txt`, `.gitignore` удаляются из корня `/` и сохраняются в `/usr/local/opnsense/scripts/additional/package/`.

## v0.1.22

- WireGuard check больше не требует API key/secret пользователя root.
- Список WireGuard клиентов читается локально из `/conf/config.xml`.
- Маршруты читаются через `netstat`, перезапуск WireGuard выполняется локальными командами `configctl`/`service`.

## v0.1.22

- На странице Update увеличена ширина полей Repository URL и Release asset name.

## v0.1.22

- Исправлен статус на странице Update после успешного обновления: если текущая версия совпадает с latest release, больше не отображается `Доступно обновление`.
- Scheduler task `Update check` только проверяет наличие новой версии и не устанавливает обновление автоматически.

## v0.1.22

- Добавлена галочка автообновления на странице Update.
- Если автообновление включено, задача Scheduler `Update check` при обнаружении новой версии автоматически запускает установку обновления.
- Если галочка выключена, Scheduler только проверяет наличие новой версии.

## v0.1.22

- На странице Update добавлен Repository URL по умолчанию: `https://github.com/Hard-3d/OPNsense-menu-Additional`.
- Default URL также используется скриптом обновления, если файл настроек ещё не создан.

## v0.1.22

- udp2raw: удалено создание совместимой копии `/usr/local/opnsense/scripts/udp2raw/udp2raw_wireguard`.
- Используется только основной бинарник `/usr/local/opnsense/scripts/additional/bin/udp2raw_freebsd`.

## v0.1.22

- udp2raw: таблица instances заменена на карточки с адаптивной сеткой, чтобы настройки помещались без горизонтальной прокрутки.

## v0.1.22

- udp2raw: разделены кнопки сохранения. В блоке запуска теперь отдельная кнопка для общих настроек, в блоке Instances — отдельная кнопка для настроек instances.

## v0.1.22

- udp2raw: исправлено определение статуса после обновления страницы. Теперь PID определяется не только по pid-файлу, но и по реальному процессу udp2raw в `ps axww`.
- После запуска manager проверяет, что рабочий процесс действительно остался запущенным.

## v0.1.22

- udp2raw: кнопки Start/Restart теперь сначала сохраняют текущие значения формы, затем запускают процесс.
- udp2raw: для server mode добавлена явная проверка Dev (--dev), чтобы ошибка была понятной до запуска бинарника.

## v0.1.22

- udp2raw: поле Dev (--dev) теперь отображается только в режиме server.
- udp2raw: Dev выбирается из выпадающего списка интерфейсов OPNsense, а не вводится вручную.

## v0.1.22

- udp2raw: в client mode Dev (--dev) автоматически определяется по маршруту до Remote через `route -n get`.
- udp2raw: это исправляет ошибку `unknown pcap link type : 109`, когда бинарник сам выбирал неподходящий pcap-интерфейс.
- udp2raw: ANSI-коды из лога очищаются перед выводом ошибки в Web UI.

## v0.1.22

- udp2raw: улучшен автодетект Dev в client mode. Если маршрут ведёт через lo/tun/wg/tailscale и т.п., используется fallback на физический default route interface.
- udp2raw: добавлен ручной выбор Dev для client mode через чекбокс `Client Dev → Задать Dev вручную`.
- udp2raw: лог instance очищается перед новой попыткой запуска, чтобы в ошибке не смешивались старые строки.

## v0.1.22

- udp2raw: убран пункт `Client Dev`. В client mode Dev больше не отображается и не настраивается вручную.
- udp2raw: Dev по-прежнему показывается только для server mode, а в client mode определяется автоматически.

## v0.1.22

- Check VPN status / WireGuard: если при деградации WireGuard требуется перезапуск и udp2raw запущен, сначала перезапускается udp2raw, затем WireGuard.
- Если udp2raw не запущен, WireGuard перезапускается как раньше.
- Результат перезапуска udp2raw сохраняется в статус WireGuard check.
