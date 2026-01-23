#!/bin/sh
#
# 04-recovery-prompt.sh - Brief window for recovery menu access
#

RECOVERY_TIMEOUT=3
RECOVERY_KEY="r"

log "Press '${RECOVERY_KEY}' within ${RECOVERY_TIMEOUT} seconds for recovery menu..."

# Read with timeout
read_key=""
if read -t ${RECOVERY_TIMEOUT} -n 1 read_key 2>/dev/null; then
    if [ "$read_key" = "$RECOVERY_KEY" ] || [ "$read_key" = "R" ]; then
        log "Entering recovery menu..."
        /recovery/recovery-menu.sh
    fi
fi

log "Continuing normal boot..."
