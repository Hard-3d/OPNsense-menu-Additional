#!/usr/local/bin/python3
"""Convert a MaxMind MMDB Country database into OPNsense GeoIP alias files.

This script is intentionally dependency-free. It reads the MaxMind DB search
structure directly, enumerates country records and writes files compatible with
OPNsense's /usr/local/share/GeoIP/alias/<CC>-IPv4|IPv6 format.
"""

import argparse
import datetime
import ipaddress
import json
import os
import re
import shutil
import struct
import sys
from typing import Any, Dict, Iterable, Optional, Tuple


class MMDBError(RuntimeError):
    pass


class MMDBReader:
    METADATA_MARKER = b"\xab\xcd\xefMaxMind.com"
    DATA_SECTION_SEPARATOR_SIZE = 16

    TYPE_POINTER = 1
    TYPE_UTF8_STRING = 2
    TYPE_DOUBLE = 3
    TYPE_BYTES = 4
    TYPE_UINT16 = 5
    TYPE_UINT32 = 6
    TYPE_MAP = 7
    TYPE_INT32 = 8
    TYPE_UINT64 = 9
    TYPE_UINT128 = 10
    TYPE_ARRAY = 11
    TYPE_END_MARKER = 13
    TYPE_BOOLEAN = 14
    TYPE_FLOAT = 15

    def __init__(self, path: str) -> None:
        self.path = path
        with open(path, "rb") as handle:
            self.buffer = handle.read()

        marker_offset = self.buffer.rfind(self.METADATA_MARKER)
        if marker_offset < 0:
            raise MMDBError("metadata marker not found; file is not a valid MMDB")

        metadata_offset = marker_offset + len(self.METADATA_MARKER)
        metadata, _ = self.decode(metadata_offset)
        if not isinstance(metadata, dict):
            raise MMDBError("invalid MMDB metadata")

        self.metadata = metadata
        self.node_count = int(metadata.get("node_count", 0))
        self.record_size = int(metadata.get("record_size", 0))
        self.ip_version = int(metadata.get("ip_version", 0))

        if self.node_count <= 0:
            raise MMDBError("metadata node_count is missing or invalid")
        if self.record_size not in (24, 28, 32):
            raise MMDBError("unsupported MMDB record_size: %s" % self.record_size)
        if self.ip_version not in (4, 6):
            raise MMDBError("unsupported MMDB ip_version: %s" % self.ip_version)

        self.node_byte_size = self.record_size // 4
        self.search_tree_size = self.node_count * self.node_byte_size
        self.data_section_start = self.search_tree_size + self.DATA_SECTION_SEPARATOR_SIZE
        self._record_cache: Dict[int, Any] = {}

    def _read_uint(self, offset: int, size: int) -> int:
        if offset < 0 or offset + size > len(self.buffer):
            raise MMDBError("read outside MMDB buffer")
        return int.from_bytes(self.buffer[offset:offset + size], "big", signed=False)

    def read_node(self, node_number: int) -> Tuple[int, int]:
        if node_number < 0 or node_number >= self.node_count:
            raise MMDBError("node out of range: %s" % node_number)

        offset = node_number * self.node_byte_size
        data = self.buffer[offset:offset + self.node_byte_size]

        if self.record_size == 24:
            left = int.from_bytes(data[0:3], "big")
            right = int.from_bytes(data[3:6], "big")
        elif self.record_size == 28:
            left = (data[0] << 20) | (data[1] << 12) | (data[2] << 4) | (data[3] >> 4)
            right = ((data[3] & 0x0F) << 24) | (data[4] << 16) | (data[5] << 8) | data[6]
        else:
            left = int.from_bytes(data[0:4], "big")
            right = int.from_bytes(data[4:8], "big")

        return left, right

    def _decode_size(self, size: int, offset: int) -> Tuple[int, int]:
        if size < 29:
            return size, offset
        if size == 29:
            return 29 + self.buffer[offset], offset + 1
        if size == 30:
            return 285 + self._read_uint(offset, 2), offset + 2
        return 65821 + self._read_uint(offset, 3), offset + 3

    def _decode_pointer(self, size: int, offset: int) -> Tuple[Any, int]:
        pointer_size = ((size >> 3) & 0x03) + 1
        prefix = size & 0x07

        if pointer_size == 1:
            pointer = prefix * 256 + self._read_uint(offset, 1)
            offset += 1
        elif pointer_size == 2:
            pointer = prefix * 65536 + self._read_uint(offset, 2) + 2048
            offset += 2
        elif pointer_size == 3:
            pointer = prefix * 16777216 + self._read_uint(offset, 3) + 526336
            offset += 3
        else:
            pointer = self._read_uint(offset, 4)
            offset += 4

        value, _ = self.decode(self.data_section_start + pointer)
        return value, offset

    def decode(self, offset: int) -> Tuple[Any, int]:
        if offset < 0 or offset >= len(self.buffer):
            raise MMDBError("decode offset outside MMDB buffer")

        control = self.buffer[offset]
        offset += 1
        type_id = control >> 5
        size = control & 0x1F

        if type_id == 0:
            if offset >= len(self.buffer):
                raise MMDBError("truncated extended type")
            type_id = self.buffer[offset] + 7
            offset += 1

        if type_id == self.TYPE_POINTER:
            return self._decode_pointer(size, offset)

        size, offset = self._decode_size(size, offset)

        if type_id == self.TYPE_UTF8_STRING:
            raw = self.buffer[offset:offset + size]
            return raw.decode("utf-8", errors="replace"), offset + size
        if type_id == self.TYPE_DOUBLE:
            if size != 8:
                raise MMDBError("invalid double size")
            return struct.unpack(">d", self.buffer[offset:offset + 8])[0], offset + 8
        if type_id == self.TYPE_FLOAT:
            if size != 4:
                raise MMDBError("invalid float size")
            return struct.unpack(">f", self.buffer[offset:offset + 4])[0], offset + 4
        if type_id == self.TYPE_BYTES:
            return self.buffer[offset:offset + size], offset + size
        if type_id in (self.TYPE_UINT16, self.TYPE_UINT32, self.TYPE_UINT64, self.TYPE_UINT128):
            return self._read_uint(offset, size), offset + size
        if type_id == self.TYPE_INT32:
            return int.from_bytes(self.buffer[offset:offset + size], "big", signed=True), offset + size
        if type_id == self.TYPE_MAP:
            result: Dict[Any, Any] = {}
            for _ in range(size):
                key, offset = self.decode(offset)
                value, offset = self.decode(offset)
                result[key] = value
            return result, offset
        if type_id == self.TYPE_ARRAY:
            result = []
            for _ in range(size):
                value, offset = self.decode(offset)
                result.append(value)
            return result, offset
        if type_id == self.TYPE_BOOLEAN:
            return bool(size), offset
        if type_id == self.TYPE_END_MARKER:
            return None, offset

        # Unknown data type. Skip its payload to avoid crashing on unused fields.
        return None, offset + size

    def data_for_pointer(self, pointer: int) -> Any:
        if pointer in self._record_cache:
            return self._record_cache[pointer]

        offset = self.search_tree_size + pointer - self.node_count
        value, _ = self.decode(offset)
        self._record_cache[pointer] = value
        return value

    def ipv4_start_node(self) -> Optional[int]:
        if self.ip_version == 4:
            return 0

        node = 0
        for _ in range(96):
            if node >= self.node_count:
                return None
            node = self.read_node(node)[0]
            if node == self.node_count:
                return None
            if node > self.node_count:
                return None
        return node if node < self.node_count else None


def sanitize_country_code(value: str) -> Optional[str]:
    code = str(value).strip().upper()
    if not code:
        return None
    code = re.sub(r"[^A-Z0-9_]+", "_", code)
    if not re.match(r"^[A-Z0-9_]{2,32}$", code):
        return None
    return code


def find_country_code(record: Any) -> Optional[str]:
    if not isinstance(record, dict):
        return None

    preferred_paths = [
        ("country", "iso_code"),
        ("registered_country", "iso_code"),
        ("represented_country", "iso_code"),
    ]

    for first, second in preferred_paths:
        value = record.get(first)
        if isinstance(value, dict) and second in value:
            code = sanitize_country_code(str(value[second]))
            if code:
                return code

    stack = [record]
    while stack:
        current = stack.pop()
        if isinstance(current, dict):
            for key, value in current.items():
                if key in ("iso_code", "country_code", "code") and isinstance(value, str):
                    code = sanitize_country_code(value)
                    if code:
                        return code
                if isinstance(value, (dict, list)):
                    stack.append(value)
        elif isinstance(current, list):
            stack.extend(current)

    return None


class AliasWriter:
    def __init__(self, alias_dir: str) -> None:
        self.alias_dir = alias_dir
        self.handles: Dict[Tuple[str, str], Any] = {}
        self.file_count = 0
        self.address_count = 0
        os.makedirs(alias_dir, exist_ok=True)

    def write(self, country_code: str, proto: str, network: str) -> None:
        key = (country_code, proto)
        if key not in self.handles:
            path = os.path.join(self.alias_dir, "%s-%s" % key)
            self.handles[key] = open(path, "w", encoding="ascii")
            self.file_count += 1
        self.handles[key].write(network + "\n")
        self.address_count += 1

    def close(self) -> None:
        for handle in self.handles.values():
            handle.close()
        self.handles.clear()


def reset_alias_dir(alias_dir: str) -> None:
    os.makedirs(alias_dir, exist_ok=True)
    for name in os.listdir(alias_dir):
        path = os.path.join(alias_dir, name)
        if os.path.isfile(path) and re.match(r"^[A-Z0-9_]{2,32}-IPv[46]$", name):
            os.unlink(path)


def iter_networks(reader: MMDBReader, start_node: int, bits: int) -> Iterable[Tuple[int, int, int]]:
    stack = [(start_node, 0, 0)]

    while stack:
        node, prefix, prefix_len = stack.pop()
        if node >= reader.node_count or prefix_len >= bits:
            continue

        left, right = reader.read_node(node)
        children = [(1, right), (0, left)]

        for bit, pointer in children:
            child_prefix = (prefix << 1) | bit
            child_prefix_len = prefix_len + 1

            if pointer == reader.node_count:
                continue
            if pointer < reader.node_count:
                stack.append((pointer, child_prefix, child_prefix_len))
            else:
                yield pointer, child_prefix, child_prefix_len


def convert(mmdb_file: str, alias_dir: str) -> Dict[str, Any]:
    reader = MMDBReader(mmdb_file)
    reset_alias_dir(alias_dir)
    writer = AliasWriter(alias_dir)

    try:
        if reader.ip_version == 6:
            for pointer, prefix, prefix_len in iter_networks(reader, 0, 128):
                if prefix_len >= 96 and (prefix >> (prefix_len - 96)) == 0:
                    continue
                record = reader.data_for_pointer(pointer)
                country_code = find_country_code(record)
                if not country_code:
                    continue
                network_int = prefix << (128 - prefix_len)
                network = str(ipaddress.IPv6Network((network_int, prefix_len), strict=False))
                writer.write(country_code, "IPv6", network)

        ipv4_start = reader.ipv4_start_node()
        if ipv4_start is not None:
            for pointer, prefix, prefix_len in iter_networks(reader, ipv4_start, 32):
                record = reader.data_for_pointer(pointer)
                country_code = find_country_code(record)
                if not country_code:
                    continue
                network_int = prefix << (32 - prefix_len)
                network = str(ipaddress.IPv4Network((network_int, prefix_len), strict=False))
                writer.write(country_code, "IPv4", network)
    finally:
        writer.close()

    if writer.address_count <= 0:
        raise MMDBError("MMDB converted successfully, but no country ranges were found")

    return {
        "address_count": writer.address_count,
        "file_count": writer.file_count,
        "timestamp": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "locations_filename": os.path.basename(mmdb_file),
        "address_sources": {
            "IPv4": os.path.basename(mmdb_file),
            "IPv6": os.path.basename(mmdb_file),
        },
        "source_mode": "mmdb_direct_fallback_converted",
        "database_type": reader.metadata.get("database_type", ""),
        "ip_version": reader.ip_version,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Convert MMDB Country database to OPNsense GeoIP alias files")
    parser.add_argument("--input", required=True, help="Input .mmdb file")
    parser.add_argument("--alias-dir", default="/usr/local/share/GeoIP/alias", help="Alias output directory")
    parser.add_argument("--stats-file", default="/usr/local/share/GeoIP/alias.stats", help="Stats JSON output")
    parser.add_argument("--source-url", default="", help="Source URL used to download the MMDB")
    parser.add_argument("--source-index", default="", help="Source index used by fallback downloader")
    args = parser.parse_args()

    try:
        stats = convert(args.input, args.alias_dir)
        size_bytes = os.path.getsize(args.input)
        stats["source_base_url"] = args.source_url
        stats["mmdb"] = {
            "file": args.input,
            "size_bytes": size_bytes,
            "url": args.source_url,
            "source_index": int(args.source_index) if str(args.source_index).isdigit() else None,
            "timestamp": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        }
        os.makedirs(os.path.dirname(args.stats_file), exist_ok=True)
        with open(args.stats_file, "w", encoding="utf-8") as handle:
            json.dump(stats, handle, ensure_ascii=False, separators=(",", ":"))
        os.chmod(args.stats_file, 0o644)
        print(json.dumps(stats, ensure_ascii=False, separators=(",", ":")))
        return 0
    except Exception as error:
        print("ERROR: %s" % error, file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
