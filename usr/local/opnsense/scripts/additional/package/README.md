# OPNsense Additional menu v0.1.59

Исправительная версия после v0.1.58.

## Что исправлено

- Агент v0.1.59.
- Улучшено определение WireGuard runtime-интерфейсов `wg0/wg1/...`.
- `wg show all dump` теперь разбирается и по TAB, и по обычным пробелам.
- Сопоставление WireGuard instances с runtime-интерфейсами делается по listen port, затем по порядку runtime-интерфейсов.

## Обновление

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.59-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.59-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```

После обновления выполнить:

```text
Дополнительно -> Controller connect -> Run once
```
