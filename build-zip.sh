#!/bin/sh
set -e
cd "$(dirname "$0")"
rm -f wsystem_einvoice.zip
zip -qr wsystem_einvoice.zip wsystem_einvoice -x '*.DS_Store'
sha256sum wsystem_einvoice.zip
