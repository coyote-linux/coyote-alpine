#!/bin/sh

set -eu

show_usage() {
    echo "Usage: $0 [-n] [make-target ...]"
    echo ""
    echo "Options:"
    echo "  -n    Rebuild without bumping build number"
    echo ""
    echo "Examples:"
    echo "  $0              # same as: make firmware"
    echo "  $0 -n           # same as: make firmware NO_BUMP=1"
    echo "  $0 -n iso       # same as: make iso NO_BUMP=1"
}

NO_BUMP=0

while getopts ":nh" opt; do
    case "$opt" in
        n)
            NO_BUMP=1
            ;;
        h)
            show_usage
            exit 0
            ;;
        *)
            show_usage
            exit 1
            ;;
    esac
done

shift $((OPTIND - 1))

if [ "$#" -eq 0 ]; then
    set -- firmware
fi

if [ "$NO_BUMP" -eq 1 ]; then
    exec make NO_BUMP=1 "$@"
fi

exec make "$@"
