#!/bin/sh
# ============================================================
# entrypoint.sh — make Apache listen on Render's assigned port
#
# Render forwards traffic to $PORT (default 10000), while the stock
# php:apache image listens on 80. Rewrite both the global Listen
# directive and the vhost before handing control to Apache.
# ============================================================
set -eu

PORT="${PORT:-10000}"

# Only the FIRST Listen line (port 80) is rewritten. The file also has
# an inactive "Listen 443" inside an IfModule, which must not be touched.
# The 0,/re/ address also makes a restart with a different $PORT work.
sed -ri "0,/^Listen [0-9]+\$/s//Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec "$@"
