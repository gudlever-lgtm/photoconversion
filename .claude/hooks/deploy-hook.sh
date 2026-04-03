#!/bin/bash
# Reload lighttpd after a push — no-op if systemd is not available (sandbox/CI).
if command -v systemctl >/dev/null 2>&1 && systemctl is-system-running >/dev/null 2>&1; then
    if systemctl is-active --quiet lighttpd; then
        systemctl reload lighttpd
    fi
fi
exit 0
