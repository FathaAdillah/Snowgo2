#!/bin/sh
# PHP-FPM liveness via FastCGI ping (no HTTP stack needed inside the container).
SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
  cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
