# OPNsense Additional Menu

Набор локальных страниц для OPNsense в меню **Дополнительно**.

## Разделы

- **Ethname** — управление `/etc/rc.conf.d/ethname`, установка `ethname` при первом открытии.
- **GeoIP update** — скачивание GeoIP MMDB по трём прямым URL с fallback и конвертация в OPNsense alias ranges.
- **Check VPN status** — проверка WireGuard и Tailscale, статус Cron.
- **Check WAN** — контроль потерь WAN gateway и переключение priority.
- **udp2raw** — управление udp2raw client/server instances без правки WireGuard service files.
- **Scheduler** — единый планировщик для всех задач меню через один OPNsense Cron.
- **Update** — проверка и установка новых версий этого меню из GitHub Releases.

## Установка

Скопировать архив на OPNsense, например в `/root`, затем:

```sh
cd /
unzip -o /root/opnsense-additional-menu-v0.1.46-root.zip
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

- tag: `v0.1.46`
- asset: `opnsense-additional-menu-v0.1.46-root.zip`

В самой странице **Update** укажите:

```text
Repository URL: https://github.com/OWNER/REPO
Release asset name: opnsense-additional-menu-v0.1.46-root.zip
```

Если поле **Release asset name** оставить пустым, updater попробует установить GitHub source ZIP latest release. Это тоже поддерживается, если в корне репозитория есть `install.sh`.

## Версия

Текущая версия хранится в:

```text
/usr/local/opnsense/scripts/additional/VERSION
```

Для новой версии:

1. Измените файл `usr/local/opnsense/scripts/additional/VERSION`.
2. Создайте новый git tag, например `v0.1.46`.
3. Соберите новый root ZIP.
4. Загрузите ZIP в GitHub Release.

## Scheduler Cron

Страница **Дополнительно → Scheduler** проверяет наличие задания Cron **Additional Scheduler**. Если задания нет, на странице доступна кнопка создания задания.


## Root cleanup

После установки служебные файлы `install.sh`, `README.md`, `README_INSTALL.txt`, `.gitignore` удаляются из корня `/` и сохраняются в `/usr/local/opnsense/scripts/additional/package/`.


## v0.1.46

- GeoIP MMDB теперь не только скачивается, но и конвертируется в OPNsense alias-файлы `/usr/local/share/GeoIP/alias/<COUNTRY>-IPv4|IPv6`.
- Сохранены три fallback-источника MMDB; если источник не скачался или не конвертируется, используется следующий URL.
- После конвертации запускается refresh firewall aliases, поэтому `Alias ranges` должен стать больше 0.
- Добавлен dependency-free конвертер `mmdb_to_geoip_alias.py`.

## v0.1.44

- Дефолтные MMDB fallback-источники изменены на три URL:
  1. `https://raw.githubusercontent.com/runetfreedom/russia-blocked-geoip/release/Country.mmdb`
  2. `https://git.io/GeoLite2-Country.mmdb`
  3. `https://github.com/P3TERX/GeoLite.mmdb/raw/download/GeoLite2-Country.mmdb`
- Installer сохраняет пользовательские источники и заполняет пустые fallback-слоты дефолтными URL.

## v0.1.43

- GeoIP update переведён в режим MMDB-only.
- В настройках оставлены три прямые ссылки на `.mmdb`: основной источник и два резервных.
- Updater пробует источники по очереди и устанавливает первый успешно скачанный файл в `/usr/local/share/GeoIP/runetfreedom-Country.mmdb`.
- Старые настройки `base_url`, `mmdb_url`, `download_mmdb` автоматически мигрируют в новый формат `mmdb_urls`.

## v0.1.40

- WireGuard check больше не требует API key/secret пользователя root.
- Список WireGuard клиентов читается локально из `/conf/config.xml`.
- Маршруты читаются через `netstat`, перезапуск WireGuard выполняется локальными командами `configctl`/`service`.

## v0.1.40

- На странице Update увеличена ширина полей Repository URL и Release asset name.

## v0.1.40

- Исправлен статус на странице Update после успешного обновления: если текущая версия совпадает с latest release, больше не отображается `Доступно обновление`.
- Scheduler task `Update check` только проверяет наличие новой версии и не устанавливает обновление автоматически.

## v0.1.40

- Добавлена галочка автообновления на странице Update.
- Если автообновление включено, задача Scheduler `Update check` при обнаружении новой версии автоматически запускает установку обновления.
- Если галочка выключена, Scheduler только проверяет наличие новой версии.

## v0.1.40

- На странице Update добавлен Repository URL по умолчанию: `https://github.com/Hard-3d/OPNsense-menu-Additional`.
- Default URL также используется скриптом обновления, если файл настроек ещё не создан.

## v0.1.40

- udp2raw: удалено создание совместимой копии `/usr/local/opnsense/scripts/udp2raw/udp2raw_wireguard`.
- Используется только основной бинарник `/usr/local/opnsense/scripts/additional/bin/udp2raw_freebsd`.

## v0.1.40

- udp2raw: таблица instances заменена на карточки с адаптивной сеткой, чтобы настройки помещались без горизонтальной прокрутки.

## v0.1.40

- udp2raw: разделены кнопки сохранения. В блоке запуска теперь отдельная кнопка для общих настроек, в блоке Instances — отдельная кнопка для настроек instances.

## v0.1.40

- udp2raw: исправлено определение статуса после обновления страницы. Теперь PID определяется не только по pid-файлу, но и по реальному процессу udp2raw в `ps axww`.
- После запуска manager проверяет, что рабочий процесс действительно остался запущенным.

## v0.1.40

- udp2raw: кнопки Start/Restart теперь сначала сохраняют текущие значения формы, затем запускают процесс.
- udp2raw: для server mode добавлена явная проверка Dev (--dev), чтобы ошибка была понятной до запуска бинарника.

## v0.1.40

- udp2raw: поле Dev (--dev) теперь отображается только в режиме server.
- udp2raw: Dev выбирается из выпадающего списка интерфейсов OPNsense, а не вводится вручную.

## v0.1.40

- udp2raw: в client mode Dev (--dev) автоматически определяется по маршруту до Remote через `route -n get`.
- udp2raw: это исправляет ошибку `unknown pcap link type : 109`, когда бинарник сам выбирал неподходящий pcap-интерфейс.
- udp2raw: ANSI-коды из лога очищаются перед выводом ошибки в Web UI.

## v0.1.40

- udp2raw: улучшен автодетект Dev в client mode. Если маршрут ведёт через lo/tun/wg/tailscale и т.п., используется fallback на физический default route interface.
- udp2raw: добавлен ручной выбор Dev для client mode через чекбокс `Client Dev → Задать Dev вручную`.
- udp2raw: лог instance очищается перед новой попыткой запуска, чтобы в ошибке не смешивались старые строки.

## v0.1.40

- udp2raw: убран пункт `Client Dev`. В client mode Dev больше не отображается и не настраивается вручную.
- udp2raw: Dev по-прежнему показывается только для server mode, а в client mode определяется автоматически.

## v0.1.40

- Rollback of v0.1.40: removed automatic udp2raw restart before WireGuard restart in Status WireGuard.
- WireGuard check behavior returned to v0.1.40 logic: on degradation it restarts WireGuard only.

## v0.1.40

- udp2raw: добавлена диагностика `socket bind error`.
- udp2raw: статус теперь ищет процесс не только по полной команде, но и по Listen (-l), чтобы старый процесс с тем же портом корректно определялся как запущенный.
- udp2raw: если Listen-порт занят другим процессом, Web UI показывает понятную ошибку и строки sockstat.

## v0.1.40

- udp2raw: добавлено включаемое логирование подключений/работы udp2raw.
- udp2raw: добавлена обязательная ротация логов по размеру с настраиваемым количеством архивов.
- Ротация выполняется manager-скриптом методом copytruncate, чтобы работающий udp2raw не останавливался.

## v0.1.40

- udp2raw: логирование перенесено из общих настроек в каждый instance.
- udp2raw: имя лог-файла теперь строится по имени подключения: `/var/log/additional_udp2raw_<connection-name>.log`.
- udp2raw: в статусе instance отображается метка лог-файла и его размер, если логирование включено.

## v0.1.40

- udp2raw: подпись `Логирование` в карточке instance заменена на короткую `Log`.
- udp2raw: поле `Log level` теперь скрывается, если для instance не включена галочка `Log`.

## v0.1.40

- udp2raw: исправлено отображение поля `Log` в карточке instance. Убрана лишняя подпись возле checkbox, из-за которой рядом с `Extra args` отображалась буква `В`.

## v0.1.40

- udp2raw: исправлена JS-ошибка формы из v0.1.40, из-за которой не загружались статус бинарника и карточки instances.
- udp2raw: поле `Log` осталось компактным, без лишней подписи возле checkbox.

## v0.1.40

- updater: копируются только изменённые файлы, неизменённые файлы пропускаются.
- updater: если в обновлении меняется `udp2raw_freebsd`, перед заменой бинарника udp2raw автоматически останавливается.
- updater: если udp2raw был запущен до обновления бинарника, после установки он запускается обратно.

## v0.1.40

- Добавлена страница `WireGuard peers`.
- Для peer можно включить режим `2 IP`, указать Primary/Backup IP и включить проверку доступности.
- При недоступности Primary и доступности Backup endpoint peer переключается на Backup, затем WireGuard перезапускается.
- Если Primary снова доступен, endpoint возвращается на Primary.
- Добавлена задача Scheduler `WireGuard peers check`.

## v0.1.40

- Scheduler UI: добавлена строка `WireGuard peers check`.
- Исправлена проблема v0.1.40, где задача была добавлена в backend scheduler, но не была добавлена в `SchedulerController` и `scheduler.volt`, поэтому не отображалась на странице Scheduler.

## v0.1.40

- Ethname: MAC-адрес теперь выбирается из выпадающего списка физических интерфейсов OPNsense.
- Ethname: добавлена проверка дублей — один и тот же MAC нельзя выбрать в двух строках.
- Ethname: интерфейсы для списка берутся из `ifconfig -l`, MAC — из `ifconfig <iface>`, описание — из `/conf/config.xml`.

## v0.1.40

- udp2raw: watchdog для client mode на FreeBSD/OPNsense теперь отслеживает в логе `pcap_breakloop` и `server-->client direction timeout`.
- При обнаружении этих признаков зависания watchdog останавливает instance, ждёт 2 секунды и запускает его заново.
- Watchdog ведёт внутренний state `/var/run/additional_udp2raw_watchdog_state.json`, чтобы не перезапускаться по старым строкам лога.

## v0.1.40

- udp2raw: обновлён бинарник `udp2raw_freebsd`.
- udp2raw: на странице `Дополнительно → udp2raw` добавлен вывод версии бинарника через быстрые ключи `--version`, `--version-full`, `--version-json`.

## v0.1.40

- udp2raw: исправлен вывод строки `Версия бинарника` на странице `Дополнительно → udp2raw`.
- API udp2raw теперь гарантированно возвращает данные версии бинарника в ответе get/set/action.

## v0.1.40

- udp2raw: исправлена обработка бинарника, который не поддерживает `--version` / `--version-json`.
- Цветные ANSI-коды из вывода бинарника очищаются перед показом в Web UI.
- В строку бинарника добавлены размер и короткий sha256 для контроля установленного файла.

## v0.1.40

- udp2raw: в строке `Бинарник` убраны `size` и `sha256`.
- udp2raw: в строке `Версия бинарника` теперь выводится только короткая версия, например `0.1.18`.

## v0.1.40

- Check VPN status / Status WireGuard: при деградации теперь выполняется toggle-reset проблемного WireGuard peer: временно снимается `enabled`, применяется WireGuard, затем `enabled` возвращается и WireGuard применяется ещё раз.
- Если конкретный peer невозможно сопоставить с недоступным IP, выполняется toggle-reset всех активных WireGuard clients.
- Перед изменением `/conf/config.xml` создаётся backup `config.xml.additional-check-wg-status.before-toggle.<timestamp>.bak`.
- Если toggle-reset не удался, используется прежний fallback — обычный restart WireGuard.

## v0.1.40

- Check VPN status: усилен toggle-reset WireGuard peer.
- Теперь matching учитывает не только tunneladdress, но и serveraddress/endpoint peer.
- Если точный peer не найден, при деградации reset выполняется для всех активных WireGuard clients.
- Apply выполняет per-UUID `configctl wireguard stop/start/restart <uuid>` и затем глобальный `reconfigure/restart/reload`, чтобы повторить ручное выключение/включение peer надёжнее.
