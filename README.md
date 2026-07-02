# OPNsense Additional menu v0.1.58

Исправительная версия после v0.1.57.

## Главное

- Агент v0.1.58 отправляет свою версию при heartbeat.
- WireGuard interfaces/instances сопоставляются с runtime `wg0/wg1/...` по listen port из `wg show all dump`.
- Если OPNsense не хранит имя `wgX` в `config.xml`, агент подставляет runtime interface автоматически.

## Обновление

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.58-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.58-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```

После обновления открой:

```text
Дополнительно -> Controller connect -> Run once
```
