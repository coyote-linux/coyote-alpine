#!/bin/sh

make clean
make
make initramfs
make installer
make iso
