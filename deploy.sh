#!/usr/bin/env bash
#
# Deploy del filtro al Moodle del cliente: Bitnami en 54.77.205.96.
#
#   ./deploy.sh            # copia el arbol y purga las caches de Moodle
#   ./deploy.sh -n         # preflight: comprueba SSH y cuenta lo que copiaria
#
# Copia TODO el arbol menos .git y este script sobre
# ~/htdocs/moodle/public/filter/impronta. Sin --delete a proposito: en
# produccion no borramos nada que no sea por decision del operador; si un
# fichero se renombra en el repo, hay que borrarlo a mano en el servidor.
#
# El arbol remoto es de daemon:daemon (el usuario de Apache en Bitnami) y el
# usuario bitnami no puede escribir en el: por eso se extrae con sudo y se
# devuelve la propiedad. Sin el chown, los ficheros nuevos quedarian de root
# y el siguiente deploy tampoco podria tocarlos.
#
# La purga de caches no es opcional conceptualmente, pero si operativa: sin
# ella Moodle puede seguir sirviendo JS y cadenas de idioma viejas. El
# version.php lo resuelve para los assets (?v=), el purge para el resto.
set -euo pipefail

HOST=bitnami@54.77.205.96
# Con comillas simples: el ~ lo expande el shell REMOTO, no el local
DEST='~/htdocs/moodle/public/filter/impronta'
PHP=/opt/bitnami/php/bin/php
# Moodle 5.x: el docroot es moodle/public, pero el CLI vive en la raiz del
# codigo (moodle/admin/cli), que no es servible por HTTP a proposito.
PURGE_CLI=/home/bitnami/htdocs/moodle/admin/cli/purge_caches.php

DRY=0
[ "${1:-}" = "-n" ] && DRY=1

ssh -o BatchMode=yes -o ConnectTimeout=10 "$HOST" "test -d $DEST" \
  || { echo "ERROR: no existe $DEST en $HOST (o falla la clave SSH)" >&2; exit 1; }

if [ "$DRY" = 1 ]; then
  n=$(tar czf - --exclude=.git --exclude=deploy.sh . | wc -c)
  echo "preflight OK: $(find . -type f -not -path './.git/*' -not -name deploy.sh | wc -l) ficheros ($n bytes comprimidos)"
  exit 0
fi

echo "==> copiando a $HOST:$DEST"
tar czf - --exclude=.git --exclude=deploy.sh . \
  | ssh "$HOST" "sudo tar xzf - -C $DEST && sudo chown -R daemon:daemon $DEST"

# Reiniciar php-fpm NO es opcional: opcache revalida cada 60 s (revalidate_freq),
# y un worker que cargó la clase un minuto antes del deploy la sigue sirviendo
# vieja. Así se degradó el primer deploy del registro: el fichero nuevo en disco,
# la clase vieja en memoria, y el mediabase sin guardar sin ningún error aparente.

echo "==> version desplegada: $(grep -o '20260[0-9]*' version.php | head -1)"

# Orden: reiniciar ANTES de purgar — el purge limpia MUC, pero si el worker
# sigue con el PHP viejo, la siguiente petición volvería a reconstruir la caché
# con el código antiguo.
echo "==> reiniciando php-fpm (descarga opcache)"
ssh "$HOST" "sudo -n /opt/bitnami/ctlscript.sh restart php-fpm" \
  || echo "    AVISO: no pude reiniciar php-fpm; hazlo a mano o espera 60 s" >&2

echo "==> purgando caches de Moodle"
if ssh "$HOST" "sudo -n $PHP $PURGE_CLI"; then
  echo "    caches purgadas"
else
  echo "    AVISO: no pude purgar (sudo sin password no disponible)." \
       "Purgalas a mano: Site administration > Development > Purge all caches" >&2
fi

echo "==> hecho. Recuerda: el ajuste 'mediabase' del tenant debe apuntar a su CDN."
