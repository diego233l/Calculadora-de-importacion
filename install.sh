#!/usr/bin/env bash
#
# install.sh — instala la Calculadora de importación en esta máquina (nginx + PHP-FPM) sin tocar nada más.
#
# Uso (en la VM, con un usuario que tenga sudo):
#   bash install.sh                  # comprueba, enseña qué va a crear, pregunta y lo instala
#   bash install.sh --solo-comprobar # solo mira cómo está la máquina; no cambia nada
#   bash install.sh --si             # no pregunta (para repetir la instalación o automatizarla)
#   bash install.sh --instalar-php   # si falta PHP-FPM o alguna extensión, permite instalarlos con apt SIN preguntar (solo paquetes nuevos)
#   bash install.sh --titular        # cambia los datos del titular (aviso legal) sin reinstalar nada
#   bash install.sh --desinstalar    # quita el sitio de nginx y el pool de PHP-FPM (y deja el código y los datos)
#   bash install.sh --desinstalar --borrar-todo   # además borra /opt/apps/calculadora-importacion y /var/lib/calculadora-importacion
#   PUERTO=8083 bash install.sh      # otro puerto distinto de 8082
#
# Datos del titular (aviso legal), opcionales: TITULAR="…" NIF="…" DOMICILIO="…" EMAIL="…" bash install.sh
#
# Garantías (son las reglas de deploy/INSTALL.md, hechas script):
#   · Mira antes de tocar: inventario de la máquina y comprobación del puerto. Si el puerto está ocupado, PARA.
#   · Solo CREA ficheros nuevos de este proyecto: /opt/apps/calculadora-importacion, /var/lib/calculadora-importacion,
#     un sitio de nginx y un pool de PHP-FPM. No modifica, mueve ni borra nada de las otras webs ni la configuración global.
#   · Siempre `nginx -t` y `php-fpm -t` ANTES de recargar; recarga (`reload`), nunca reinicia.
#   · No instala, actualiza ni quita paquetes por su cuenta: si falta PHP-FPM o una extensión, enseña EXACTAMENTE qué paquetes
#     NUEVOS haría falta instalar y solo los instala si tú lo confirmas (nunca actualiza ni quita nada ya instalado).
#   · Apunta el código HTTP de tus otras webs antes y después y avisa si alguno ha cambiado.
#   · Si algo falla antes de recargar, deshace lo que había creado en esta ejecución.
#
# Sistema: Debian/Ubuntu (nginx con sites-available/sites-enabled, PHP-FPM en /etc/php/<versión>/fpm, usuario www-data).

set -Eeuo pipefail

# ------------------------------------------------------------------ parámetros fijos y opciones
readonly NOMBRE="calculadora-importacion"
readonly DIR_APP="/opt/apps/${NOMBRE}"          # fijos: el pool (open_basedir) y la aplicación usan estas rutas
readonly DIR_VAR="/var/lib/${NOMBRE}"
readonly REPO_URL="${REPO_URL:-https://github.com/diego233l/Calculadora-de-importacion.git}"
readonly RAMA="${RAMA:-}"                        # vacío = la rama por defecto del repositorio
readonly PHP_MINIMO="8.1"
ETC_PHP="${ETC_PHP:-/etc/php}"                  # (solo para pruebas del propio script)
readonly EXTENSIONES=(curl dom pdo_sqlite mbstring json)
readonly NGINX_SITIO="/etc/nginx/sites-available/${NOMBRE}"
readonly NGINX_ENLACE="/etc/nginx/sites-enabled/${NOMBRE}"
readonly SOCKET="/run/php/${NOMBRE}.sock"
PUERTO="${PUERTO:-8082}"

MODO="instalar"
PREGUNTAR=1
BORRAR_TODO=0
INSTALAR_PHP=0
for arg in "$@"; do
    case "$arg" in
        --solo-comprobar) MODO="comprobar" ;;
        --desinstalar)    MODO="desinstalar" ;;
        --borrar-todo)    BORRAR_TODO=1 ;;
        --instalar-php)   INSTALAR_PHP=1 ;;
        --titular)        MODO="titular" ;;
        --si|--yes|-y)    PREGUNTAR=0 ;;
        --puerto=*)       PUERTO="${arg#--puerto=}" ;;
        -h|--ayuda|--help) sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Opción desconocida: $arg (usa --ayuda)" >&2; exit 2 ;;
    esac
done

# ------------------------------------------------------------------ mensajes
if [[ -t 1 ]]; then
    ROJO=$'\033[31m'; VERDE=$'\033[32m'; AMARILLO=$'\033[33m'; NEGRITA=$'\033[1m'; FIN=$'\033[0m'
else
    ROJO=""; VERDE=""; AMARILLO=""; NEGRITA=""; FIN=""
fi
titulo() { printf '\n%s== %s ==%s\n' "$NEGRITA" "$*" "$FIN"; }
ok()     { printf '  %s✔%s %s\n' "$VERDE" "$FIN" "$*"; }
aviso()  { printf '  %s!%s %s\n' "$AMARILLO" "$FIN" "$*"; }
info()   { printf '    %s\n' "$*"; }
fallo()  { printf '\n%s✘ %s%s\n' "$ROJO" "$*" "$FIN" >&2; exit 1; }

# ------------------------------------------------------------------ permisos
if [[ $EUID -ne 0 ]]; then
    command -v sudo >/dev/null 2>&1 || fallo "Hace falta ser administrador y no encuentro sudo. Ejecuta este script como root."
    if [[ ! -f "$0" ]]; then
        fallo "Guarda primero el script en un fichero (curl -fsSLO …/install.sh) y ejecútalo con: bash install.sh"
    fi
    echo "Necesito permisos de administrador: te pedirá la contraseña de sudo."
    exec sudo -E bash "$0" "$@"
fi
DUENO="${SUDO_USER:-root}"        # quien clonará el repositorio y hará los git pull
[[ "$DUENO" == "root" ]] || id "$DUENO" >/dev/null 2>&1 || DUENO="root"
GRUPO_DUENO="$(id -gn "$DUENO")"

como_dueno() {                    # ejecuta un comando como el usuario que hará los git pull
    if [[ "$DUENO" == "root" ]]; then "$@"; else runuser -u "$DUENO" -- "$@"; fi
}

# ------------------------------------------------------------------ lo que se crea (para poder deshacerlo)
CREADO_DIR_APP=0; CREADO_DIR_VAR=0; CREADO_POOL=0; CREADO_SITIO=0; CREADO_ENLACE=0; FPM_RECARGADO=0; NGINX_RECARGADO=0
POOL=""; V_FPM=""; INSTALACION_COMPLETA=0
COPIA_POOL=""; COPIA_SITIO=""; ULTIMA_COPIA=""
TMP="$(mktemp -d)"

# ------------------------------------------------------------------ utilidades
puerto_en_uso() {                 # ¿alguien escucha en el puerto $1 (cualquier dirección)? Lee /proc/net, sin depender de ss
    local hex f
    hex="$(printf '%04X' "$1")"
    for f in /proc/net/tcp /proc/net/tcp6; do          # tcp6 no existe si el kernel no tiene IPv6
        [[ -r "$f" ]] || continue
        if awk -v h="$hex" 'FNR > 1 && $4 == "0A" { n = split($2, a, ":"); if (a[n] == h) { found = 1 } } END { exit !found }' "$f"; then
            return 0
        fi
    done
    return 1
}

quien_usa_puerto() {
    if command -v ss >/dev/null 2>&1; then ss -tlnp 2>/dev/null | grep -E "[:.]$1[[:space:]]" || true; fi
}

recargar() {                      # recargar nginx | php-fpm (nunca reiniciar)
    local servicio="$1"
    case "$servicio" in
        nginx)
            if [[ -d /run/systemd/system ]]; then systemctl reload nginx
            elif command -v service >/dev/null 2>&1 && service nginx status >/dev/null 2>&1; then service nginx reload
            else nginx -s reload; fi ;;
        fpm)
            if [[ -d /run/systemd/system ]]; then systemctl reload "php${V_FPM}-fpm"
            elif command -v service >/dev/null 2>&1 && service "php${V_FPM}-fpm" status >/dev/null 2>&1; then service "php${V_FPM}-fpm" reload
            else kill -USR2 "$(cat "/run/php/php${V_FPM}-fpm.pid")"; fi ;;
    esac
}

confirmar() {                     # confirmar "pregunta": sí con s/S/y/Y; con --si no pregunta
    [[ $PREGUNTAR -eq 0 ]] && return 0
    [[ -t 0 ]] || fallo "No hay terminal para preguntar. Repite con --si si quieres seguir sin confirmación."
    local r
    read -r -p "$1 [s/N] " r
    [[ "$r" =~ ^[sSyY]$ ]]
}

json_texto() {                    # escapa un texto para meterlo entre comillas en JSON
    printf '%s' "$1" | tr -d '\000-\037' | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

# ------------------------------------------------------------------ deshacer si algo falla antes de terminar
deshacer() {
    local codigo=$?
    trap - ERR
    if [[ $INSTALACION_COMPLETA -eq 0 && $codigo -ne 0 ]]; then
        if (( CREADO_DIR_APP + CREADO_DIR_VAR + CREADO_POOL + CREADO_SITIO + CREADO_ENLACE + FPM_RECARGADO + NGINX_RECARGADO > 0 )); then
            printf '%sDeshago lo que esta ejecución había creado (no se toca nada más).%s\n' "$AMARILLO" "$FIN" >&2
        fi
        if [[ $CREADO_ENLACE -eq 1 ]]; then rm -f "$NGINX_ENLACE"; fi
        if [[ $CREADO_SITIO -eq 1 ]]; then rm -f "$NGINX_SITIO"; elif [[ -n "$COPIA_SITIO" ]]; then cp -p "$COPIA_SITIO" "$NGINX_SITIO"; fi
        if [[ $NGINX_RECARGADO -eq 1 ]] && nginx -t >/dev/null 2>&1; then recargar nginx || true; fi
        if [[ -n "$POOL" ]]; then
            if [[ $CREADO_POOL -eq 1 ]]; then rm -f "$POOL"; elif [[ -n "$COPIA_POOL" ]]; then cp -p "$COPIA_POOL" "$POOL"; fi
            if [[ $FPM_RECARGADO -eq 1 ]] && "php-fpm${V_FPM}" -t >/dev/null 2>&1; then recargar fpm || true; fi
        fi
    fi
    # Si esta ejecución creó el directorio del código y no llegó a instalarse nada, se retira (si tiene contenido, solo si es el clon de esta ejecución)
    if [[ $INSTALACION_COMPLETA -eq 0 && $codigo -ne 0 && $CREADO_DIR_APP -eq 1 ]]; then rm -rf "$DIR_APP"; fi
    # La carpeta de datos creada en esta ejecución se quita solo si sigue vacía (nunca se borra contenido)
    if [[ $INSTALACION_COMPLETA -eq 0 && $codigo -ne 0 && $CREADO_DIR_VAR -eq 1 ]]; then rmdir "$DIR_VAR" 2>/dev/null || true; fi
    rm -rf "$TMP"
}
trap deshacer EXIT
error_inesperado() {              # un comando que falla dentro de $(...) o de un subproceso no es un error del script: solo el proceso principal
    [[ "$BASHPID" == "$$" ]] || return 0
    fallo "Error inesperado en la línea $1."
}
trap 'error_inesperado $LINENO' ERR

# ------------------------------------------------------------------ códigos HTTP de las otras webs (antes y después)
# Recorre las configuraciones de nginx (nginx -T) y para cada server con nombre normal pide «/» a 127.0.0.1 con su Host.
snapshot_webs() {
    local salida="$1" lineas nombres puertos ssl p n esquema codigo
    : > "$salida"
    # Se parte cada instrucción en su línea (hay quien escribe «server { listen 80; … }» en una sola) y se quitan los comentarios
    lineas="$(nginx -T 2>/dev/null | sed -e 's/#.*$//' -e 's/\([;{}]\)/\1\n/g' | awk '
        { raw = $0; sub(/#.*$/, "", raw) }
        !ins { if (raw ~ /^[ \t]*server[ \t]*\{/) { ins = 1; depth = 0; names = ""; ports = ""; ssl = "" } else next }
        { dd = depth; o = gsub(/\{/, "{", raw); c = gsub(/\}/, "}", raw)
          if (dd == 1) {
            if (raw ~ /^[ \t]*listen[ \t]/) { t = raw; sub(/^[ \t]*listen[ \t]+/, "", t); split(t, a, /[ \t;]+/); addr = a[1]; p = addr; sub(/.*:/, "", p)
                if (addr !~ /^unix:/) { if (p !~ /^[0-9]+$/) p = "80"; ports = ports " " p; if (t ~ /ssl|http2/) ssl = ssl " " p } }
            if (raw ~ /^[ \t]*server_name[ \t]/) { t = raw; sub(/^[ \t]*server_name[ \t]+/, "", t); sub(/;.*/, "", t); names = names " " t }
          }
          depth += o - c
          if (depth <= 0) { print names "|" ports "|" ssl; ins = 0 } }' || true)"
    local pares_puerto="" q
    while IFS='|' read -r nombres puertos ssl; do
        [[ -n "${puertos// /}" ]] || continue
        # Cada puerto en el que escucha algún sitio se comprueba tal cual (así también cuentan los sitios sin nombre o con «server_name _»)
        for q in $puertos; do
            esquema="http"
            for n in $ssl; do [[ "$n" == "$q" ]] && esquema="https"; done
            [[ "$q" != "$PUERTO" ]] && pares_puerto+="${esquema}:${q}"$'\n'
        done
        # Y, además, cada sitio por su nombre (el primer puerto), para ver si responde el sitio correcto
        # shellcheck disable=SC2086
        p="$(printf '%s\n' $puertos | sort -un | head -1)"
        [[ -n "$p" && "$p" != "$PUERTO" ]] || continue
        esquema="http"
        for n in $ssl; do [[ "$n" == "$p" ]] && esquema="https"; done
        for n in $nombres; do
            [[ "$n" == "_" || "$n" == "localhost" || "$n" == *"*"* || "$n" == "~"* || "$n" == "$NOMBRE" ]] && continue
            [[ "$n" =~ ^[A-Za-z0-9.-]+$ ]] || continue
            codigo="$(curl -sk -o /dev/null -m 8 -w '%{http_code}' --resolve "${n}:${p}:127.0.0.1" "${esquema}://${n}:${p}/" 2>/dev/null || true)"
            printf '%s://%s:%s/ %s\n' "$esquema" "$n" "$p" "${codigo:-000}" >> "$salida"
        done
    done <<< "$lineas"
    while IFS=: read -r esquema q; do
        [[ -n "$q" ]] || continue
        codigo="$(curl -sk -o /dev/null -m 8 -w '%{http_code}' "${esquema}://127.0.0.1:${q}/" 2>/dev/null || true)"
        printf '%s://127.0.0.1:%s/ %s\n' "$esquema" "$q" "${codigo:-000}" >> "$salida"
    done < <(printf '%s' "$pares_puerto" | sort -u)
    sort -u -o "$salida" "$salida"
    # Nuestra propia web, si ya estaba, no cuenta como «otra web»
    grep -v -F ":${PUERTO}/" "$salida" > "$salida.tmp" || true
    mv "$salida.tmp" "$salida"
}

# ------------------------------------------------------------------ PHP-FPM: buscar, diagnosticar y (si tú quieres) instalar
V_FPM_PARADO=""; PAQUETES_INSTALADOS=0
buscar_fpm() {                    # pone V_FPM con la versión más alta EN MARCHA; V_FPM_PARADO si solo hay instaladas pero paradas
    local v
    V_FPM=""; V_FPM_PARADO=""
    while read -r v; do
        [[ -n "$v" ]] || continue
        command -v "php-fpm${v}" >/dev/null 2>&1 || continue
        if systemctl is-active --quiet "php${v}-fpm" 2>/dev/null || pgrep -x "php-fpm${v}" >/dev/null 2>&1; then
            V_FPM="$v"
            return 0
        fi
        [[ -n "$V_FPM_PARADO" ]] || V_FPM_PARADO="$v"
    done < <(find "$ETC_PHP" -mindepth 2 -maxdepth 2 -name fpm -printf '%h\n' 2>/dev/null | sed 's#.*/##' | sort -rV)
    return 1
}

diagnostico_php() {
    local cli=""
    if command -v php >/dev/null 2>&1; then cli="$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"; fi
    info "Lo que hay de PHP en esta máquina:"
    info "  · php (línea de comandos): ${cli:-no instalado}"
    info "  · carpetas ${ETC_PHP}/<versión>/fpm: $(find "$ETC_PHP" -mindepth 2 -maxdepth 2 -name fpm -printf '%h\n' 2>/dev/null | sed 's#.*/##' | tr '\n' ' ')"
    info "  · binarios php-fpm: $(compgen -c php-fpm 2>/dev/null | sort -u | tr '\n' ' ')"
    info "  · servicios php*-fpm: $(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1 "(" $2 ")"}' | tr '\n' ' ')"
    info "  · procesos php-fpm en marcha: $(pgrep -c php-fpm 2>/dev/null || true)"
}

# Extensión de PHP -> paquete de Debian/Ubuntu (json viene dentro de PHP 8)
paquete_de_extension() {
    case "$1" in
        curl) echo curl ;; dom) echo xml ;; pdo_sqlite) echo sqlite3 ;; mbstring) echo mbstring ;;
        *) echo "" ;;
    esac
}

# Enseña qué paquetes NUEVOS se instalarían (simulación, no cambia nada) y, si se confirma, los instala.
# Nunca actualiza ni quita nada: si la simulación lo exige, se detiene.
ofrecer_instalar() {              # ofrecer_instalar "motivo" paquete…
    local motivo="$1"; shift
    local paquetes=("$@") sim nuevos quitar subir p politica
    command -v apt-get >/dev/null 2>&1 || fallo "No encuentro apt-get: instala tú ${paquetes[*]}."
    for p in "${paquetes[@]}"; do
        politica="$(LC_ALL=C apt-cache policy "$p" 2>/dev/null || true)"          # en una variable: grep -q cerrando la tubería daría SIGPIPE con pipefail
        grep -q 'Candidate: [^(]' <<< "$politica" \
            || fallo "El paquete $p no está disponible en tus repositorios (quizá hace falta un «apt update» o un repositorio de PHP más nuevo). No sigo."
    done
    sim="$(LC_ALL=C apt-get -s install --no-install-recommends "${paquetes[@]}" 2>&1)" || { printf '%s\n' "$sim" | tail -8 | sed 's/^/    /'; fallo "apt no puede resolver la instalación de ${paquetes[*]}."; }
    quitar="$(grep -c '^Remv ' <<< "$sim" || true)"
    # «Inst paquete [versión-vieja] (nueva …)» es una ACTUALIZACIÓN; «Inst paquete (nueva … [arq])» es un paquete nuevo
    subir="$(grep -cE '^Inst [^ ]+ \[' <<< "$sim" || true)"
    nuevos="$(grep -E '^Inst ' <<< "$sim" | grep -vE '^Inst [^ ]+ \[' | awk '{print $2}' | tr '\n' ' ')"
    echo "  $motivo"
    info "Paquetes NUEVOS que se instalarían: ${nuevos:-ninguno}"
    if [[ "${quitar:-0}" -gt 0 || "${subir:-0}" -gt 0 ]]; then
        grep -E '^(Remv|Inst [^ ]+ \[)' <<< "$sim" | head -10 | sed 's/^/    /'
        fallo "Instalarlos exigiría quitar ($quitar) o actualizar ($subir) paquetes que ya tienes. Eso lo decides tú, no este script: no sigo."
    fi
    info "No se actualiza ni se quita ningún paquete que ya tengas."
    [[ "$MODO" != "comprobar" ]] || return 1             # en --solo-comprobar solo se enseña el plan
    if [[ $INSTALAR_PHP -eq 0 ]]; then
        [[ $PREGUNTAR -eq 1 && -t 0 ]] || fallo "Para que el script los instale sin preguntar, repite con --instalar-php. No he cambiado nada."
        confirmar "  ¿Instalo esos paquetes con apt?" || fallo "Cancelado: no se ha instalado nada. Si prefieres hacerlo tú:  sudo apt install ${paquetes[*]}"
    fi
    # NEEDRESTART_SUSPEND: en Ubuntu, needrestart puede reiniciar OTROS servicios tras un apt; aquí se evita
    LC_ALL=C DEBIAN_FRONTEND=noninteractive NEEDRESTART_SUSPEND=1 apt-get install -y --no-install-recommends \
        -o Dpkg::Options::=--force-confold "${paquetes[@]}" 2>&1 | tail -n 5 | sed 's/^/    /'
    [[ "${PIPESTATUS[0]}" -eq 0 ]] || fallo "apt ha fallado instalando ${paquetes[*]}. Mira el error y dímelo."
    ok "Paquetes instalados"
    PAQUETES_INSTALADOS=1
    return 0
}

preparar_php() {
    local e faltan modulos paquetes version_apt
    if ! buscar_fpm; then
        diagnostico_php
        if [[ -n "$V_FPM_PARADO" ]]; then
            fallo "Hay PHP-FPM $V_FPM_PARADO instalado pero PARADO. Arrancarlo es decisión tuya (¿por qué está parado?):  sudo systemctl start php${V_FPM_PARADO}-fpm  y repite."
        fi
        aviso "No hay ningún PHP-FPM instalado (el pool propio de esta web necesita uno)."
        command -v apt-cache >/dev/null 2>&1 || fallo "No encuentro apt. Instala PHP-FPM $PHP_MINIMO o superior y las extensiones ${EXTENSIONES[*]}, y repite."
        version_apt="$(LC_ALL=C apt-cache depends php-fpm 2>/dev/null | sed -n 's/^ *Depends: php\([0-9]\.[0-9]\)-fpm$/\1/p' | head -1)"
        [[ -n "$version_apt" ]] || fallo "No sé qué versión de PHP trae tu sistema (apt-cache depends php-fpm no dice nada). Instala PHP-FPM tú y repite."
        if [[ "$(printf '%s\n%s\n' "$PHP_MINIMO" "$version_apt" | sort -V | head -1)" != "$PHP_MINIMO" ]]; then
            fallo "Los repositorios de tu sistema traen PHP $version_apt y hace falta $PHP_MINIMO o superior. Hay que añadir un repositorio de PHP (por ejemplo el de Ondřej Surý) y eso lo decides tú; dímelo y lo vemos."
        fi
        paquetes=("php${version_apt}-fpm")
        for e in "${EXTENSIONES[@]}"; do
            [[ -n "$(paquete_de_extension "$e")" ]] && paquetes+=("php${version_apt}-$(paquete_de_extension "$e")")
        done
        ofrecer_instalar "Se puede instalar PHP ${version_apt} con PHP-FPM y las extensiones necesarias (la versión que trae tu sistema)." "${paquetes[@]}" \
            || fallo "Falta PHP-FPM. Con  bash install.sh  te ofrecerá instalarlo (enseñando antes los paquetes); o instálalo tú y repite."
        buscar_fpm || {
            systemctl start "php${version_apt}-fpm" 2>/dev/null || true       # servicio recién instalado por este script
            buscar_fpm || fallo "He instalado PHP ${version_apt} pero su servicio no arranca. Mira:  systemctl status php${version_apt}-fpm"
        }
    fi
    if [[ "$(printf '%s\n%s\n' "$PHP_MINIMO" "$V_FPM" | sort -V | head -1)" != "$PHP_MINIMO" ]]; then
        fallo "PHP-FPM $V_FPM es demasiado antiguo: hace falta $PHP_MINIMO o superior."
    fi
    ok "PHP-FPM $V_FPM en marcha"
    POOL="${ETC_PHP}/${V_FPM}/fpm/pool.d/${NOMBRE}.conf"

    modulos="$("php-fpm${V_FPM}" -m 2>/dev/null | tr '[:upper:]' '[:lower:]')"
    faltan=()
    for e in "${EXTENSIONES[@]}"; do
        grep -qx "$e" <<< "$modulos" || faltan+=("$e")
    done
    if [[ ${#faltan[@]} -gt 0 ]]; then
        aviso "A PHP-FPM $V_FPM le faltan extensiones: ${faltan[*]}"
        paquetes=()
        for e in "${faltan[@]}"; do
            [[ -n "$(paquete_de_extension "$e")" ]] || fallo "Falta la extensión $e y no sé qué paquete la trae. Revisa tu PHP."
            paquetes+=("php${V_FPM}-$(paquete_de_extension "$e")")
        done
        ofrecer_instalar "Se pueden instalar las extensiones que faltan." "${paquetes[@]}" \
            || fallo "Faltan extensiones de PHP. Con  bash install.sh  te ofrecerá instalarlas; o instálalas tú y repite."
        # Un PHP-FPM ya en marcha no ve las extensiones nuevas hasta recargar (reload, sin cortar peticiones)
        recargar fpm || true
        modulos="$("php-fpm${V_FPM}" -m 2>/dev/null | tr '[:upper:]' '[:lower:]')"
        for e in "${faltan[@]}"; do
            grep -qx "$e" <<< "$modulos" || fallo "La extensión $e sigue sin aparecer en PHP-FPM $V_FPM tras instalarla."
        done
    fi
    ok "Extensiones de PHP: ${EXTENSIONES[*]}"
}

# ------------------------------------------------------------------ 0. comprobaciones previas
requisitos() {
    titulo "Comprobando la máquina (solo lectura)"
    [[ -d /etc/nginx/sites-available && -d /etc/nginx/sites-enabled ]] \
        || fallo "No encuentro /etc/nginx/sites-available y sites-enabled. Este script es para Debian/Ubuntu; si tu nginx usa conf.d, dímelo y lo adaptamos."
    command -v nginx >/dev/null 2>&1 || fallo "No encuentro nginx."
    id www-data >/dev/null 2>&1 || fallo "No existe el usuario www-data."
    command -v git >/dev/null 2>&1 || fallo "No encuentro git (y este script no instala git). Instálalo tú: sudo apt install git."
    command -v curl >/dev/null 2>&1 || fallo "No encuentro curl (y este script no instala curl). Instálalo tú: sudo apt install curl."
    ok "nginx, git y curl presentes; usuario www-data presente"

    info "Sitios de nginx activos:     $(find /etc/nginx/sites-enabled -mindepth 1 -maxdepth 1 -printf '%f ' 2>/dev/null || true)"
    info "En /opt/apps:                $(find /opt/apps -mindepth 1 -maxdepth 1 -printf '%f ' 2>/dev/null || true)"
    info "Pools de PHP-FPM existentes: $(find "$ETC_PHP" -path '*/fpm/pool.d/*.conf' -printf '%f ' 2>/dev/null || true)"

    preparar_php

    [[ "$PUERTO" =~ ^[0-9]+$ && "$PUERTO" -ge 1024 && "$PUERTO" -le 65535 ]] || fallo "Puerto no válido: $PUERTO (debe estar entre 1024 y 65535)."
    if puerto_en_uso "$PUERTO"; then
        if [[ -L "$NGINX_ENLACE" ]] && grep -q nginx <<< "$(quien_usa_puerto "$PUERTO")"; then
            ok "El puerto $PUERTO lo usa nuestro propio sitio de nginx (instalación previa): se actualizará"
        else
            quien_usa_puerto "$PUERTO" | sed 's/^/    /'
            fallo "El puerto $PUERTO está OCUPADO por otro programa. No sigo. Elige otro puerto libre y repite, por ejemplo:  PUERTO=8083 bash install.sh"
        fi
    else
        ok "El puerto $PUERTO está libre"
    fi

    if [[ -e "$DIR_APP" && ! -d "$DIR_APP/.git" && -n "$(ls -A "$DIR_APP" 2>/dev/null)" ]]; then
        fallo "$DIR_APP existe, no está vacío y no es un repositorio git. No lo toco: revísalo."
    fi
}

# ------------------------------------------------------------------ 1. instalar
paso_codigo() {
    titulo "Código en ${DIR_APP}"
    if [[ -d "$DIR_APP/.git" ]]; then
        local remoto
        remoto="$(como_dueno git -C "$DIR_APP" remote get-url origin 2>/dev/null || true)"
        [[ "${remoto%.git}" == "${REPO_URL%.git}" ]] || fallo "$DIR_APP es un repositorio distinto ($remoto). No lo toco."
        como_dueno git -C "$DIR_APP" pull --ff-only \
            || fallo "git pull ha fallado en $DIR_APP (¿cambios locales o sin conexión?). No sigo; no he tocado nada más."
        ok "Repositorio ya clonado: actualizado con git pull"
    else
        [[ -e "$DIR_APP" ]] || CREADO_DIR_APP=1
        install -d -o "$DUENO" -g "$GRUPO_DUENO" "$DIR_APP"
        # shellcheck disable=SC2086
        como_dueno git clone ${RAMA:+--branch "$RAMA"} "$REPO_URL" "$DIR_APP" \
            || fallo "No he podido descargar el repositorio ($REPO_URL). Comprueba la conexión a internet y que el repositorio es público; no he tocado nada más."
        ok "Repositorio clonado (propietario: $DUENO)"
    fi
    info "Versión: $(como_dueno git -C "$DIR_APP" log --oneline -1)"
    for f in deploy/php-fpm-calculadora-importacion.conf deploy/nginx-calculadora-importacion.conf public/index.php; do
        [[ -f "$DIR_APP/$f" ]] || fallo "Falta $f en el repositorio clonado."
    done
    runuser -u www-data -- test -r "$DIR_APP/public/index.php" \
        || { namei -m "$DIR_APP/public/index.php" || true; fallo "www-data no puede leer $DIR_APP/public/index.php (mira los permisos de arriba: alguna carpeta del camino no deja pasar a «otros»). No cambio permisos de carpetas que pueden ser de otras apps."; }
    ok "www-data puede leer el código"
}

paso_datos() {
    titulo "Carpeta de datos ${DIR_VAR}"
    [[ -e "$DIR_VAR" ]] || CREADO_DIR_VAR=1
    install -d -o www-data -g www-data -m 0750 "$DIR_VAR"
    ok "$(stat -c '%A %U:%G %n' "$DIR_VAR")"
}

instalar_fichero() {              # instalar_fichero origen destino -> 0 si cambió algo, 1 si ya era igual; guarda copia del anterior
    local origen="$1" destino="$2"
    if [[ -f "$destino" ]] && cmp -s "$origen" "$destino"; then return 1; fi
    ULTIMA_COPIA=""
    if [[ -f "$destino" ]]; then
        ULTIMA_COPIA="${destino}.anterior-$(date +%Y%m%d-%H%M%S)"
        cp -p "$destino" "$ULTIMA_COPIA"
    fi
    install -m 0644 "$origen" "$destino"
}

paso_fpm() {
    titulo "Pool de PHP-FPM propio (${POOL})"
    local existia=0
    [[ -f "$POOL" ]] && existia=1
    if instalar_fichero "$DIR_APP/deploy/php-fpm-calculadora-importacion.conf" "$POOL"; then
        COPIA_POOL="$ULTIMA_COPIA"
        [[ $existia -eq 1 ]] || CREADO_POOL=1
        ok "Fichero del pool escrito"
    else
        ok "El fichero del pool ya estaba igual"
    fi
    if ! "php-fpm${V_FPM}" -t >"$TMP/fpm-t.txt" 2>&1; then
        sed 's/^/    /' "$TMP/fpm-t.txt" >&2
        fallo "php-fpm -t ha fallado: no recargo y retiro el pool. Tus otras webs siguen exactamente igual."
    fi
    sed 's/^/    /' "$TMP/fpm-t.txt"
    ok "php-fpm -t correcto"
    recargar fpm
    FPM_RECARGADO=1
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        [[ -S "$SOCKET" ]] && break
        sleep 1
    done
    [[ -S "$SOCKET" ]] || fallo "PHP-FPM no ha creado el socket $SOCKET tras recargar."
    ok "Socket $SOCKET creado (reload, sin reiniciar)"
}

paso_nginx() {
    titulo "Sitio de nginx (${NGINX_SITIO}, puerto ${PUERTO})"
    sed -e "s/^\(\s*listen\s\+\)8082;/\1${PUERTO};/" -e "s/^\(\s*# listen \[::\]:\)8082;/\1${PUERTO};/" \
        "$DIR_APP/deploy/nginx-calculadora-importacion.conf" > "$TMP/nginx.conf"
    grep -q "listen ${PUERTO};" "$TMP/nginx.conf" || fallo "No he podido poner el puerto ${PUERTO} en la configuración de nginx."
    local existia=0
    [[ -f "$NGINX_SITIO" ]] && existia=1
    if instalar_fichero "$TMP/nginx.conf" "$NGINX_SITIO"; then
        COPIA_SITIO="$ULTIMA_COPIA"
        [[ $existia -eq 1 ]] || CREADO_SITIO=1
        ok "Fichero del sitio escrito"
    else
        ok "El fichero del sitio ya estaba igual"
    fi
    if [[ ! -e "$NGINX_ENLACE" ]]; then
        ln -s "$NGINX_SITIO" "$NGINX_ENLACE"
        CREADO_ENLACE=1
        ok "Sitio activado (enlace en sites-enabled)"
    fi
    if ! nginx -t 2>"$TMP/nginx-t.txt"; then
        sed 's/^/    /' "$TMP/nginx-t.txt" >&2
        fallo "nginx -t ha fallado: NO recargo y retiro lo que he añadido. Tus otras webs siguen exactamente igual."
    fi
    sed 's/^/    /' "$TMP/nginx-t.txt"
    ok "nginx -t correcto"
    recargar nginx
    NGINX_RECARGADO=1
    ok "nginx recargado (reload, sin reiniciar)"
}

valor_de_sitio() {                # lee un valor del sitio.json que escribió este mismo script (una clave por línea); vacío si no existe
    [[ -f "$DIR_VAR/sitio.json" ]] || return 0
    sed -n "s/^ *\"$1\": *\"\(.*\)\",\{0,1\}\$/\1/p" "$DIR_VAR/sitio.json" | head -1 | sed -e 's/\\"/"/g' -e 's/\\\\/\\/g' || true
}

paso_titular() {
    local f="$DIR_VAR/sitio.json" t="${TITULAR:-}" n="${NIF:-}" d="${DOMICILIO:-}" e="${EMAIL:-}" r
    local t0 n0 d0 e0 hay_env=0
    [[ -n "$t$n$d$e" ]] && hay_env=1
    titulo "Datos del titular (aviso legal y privacidad)"
    t0="$(valor_de_sitio titular)"; n0="$(valor_de_sitio nif)"; d0="$(valor_de_sitio domicilio)"; e0="$(valor_de_sitio email)"
    if [[ -f "$f" && $hay_env -eq 0 && "$MODO" != "titular" ]]; then
        ok "Ya existe $f (titular: ${t0:-sin rellenar}). Para cambiar los datos:  bash install.sh --titular"
        return
    fi
    if [[ $hay_env -eq 0 ]]; then
        [[ -t 0 ]] || { aviso "Sin terminal para preguntar. Usa:  TITULAR=… NIF=… DOMICILIO=… EMAIL=… bash install.sh --titular"; return; }
        if [[ "$MODO" != "titular" ]]; then
            echo "  La web los necesita antes de publicarse (si los dejas vacíos, el aviso legal dice «pendiente»)."
            confirmar "  ¿Quieres escribirlos ahora?" || { aviso "Sin rellenar. Cuando quieras:  bash install.sh --titular"; return; }
        else
            echo "  Intro sin escribir nada = dejar el valor actual."
        fi
        read -r -p "    Titular (nombre y apellidos o razón social)${t0:+ [$t0]}: " r; t="${r:-$t0}"
        read -r -p "    NIF/CIF${n0:+ [$n0]}: " r; n="${r:-$n0}"
        read -r -p "    Domicilio${d0:+ [$d0]}: " r; d="${r:-$d0}"
        read -r -p "    Correo de contacto${e0:+ [$e0]}: " r; e="${r:-$e0}"
    else
        t="${t:-$t0}"; n="${n:-$n0}"; d="${d:-$d0}"; e="${e:-$e0}"       # lo no indicado se conserva
    fi
    printf '{\n  "titular": "%s",\n  "nif": "%s",\n  "domicilio": "%s",\n  "email": "%s"\n}\n' \
        "$(json_texto "$t")" "$(json_texto "$n")" "$(json_texto "$d")" "$(json_texto "$e")" > "$TMP/sitio.json"
    install -o www-data -g www-data -m 0640 "$TMP/sitio.json" "$f"
    ok "Escrito $f (fuera del repositorio: git pull nunca lo pisa)"
    local vacios=""
    [[ -n "$t" ]] || vacios+=" titular"; [[ -n "$n" ]] || vacios+=" NIF"; [[ -n "$d" ]] || vacios+=" domicilio"; [[ -n "$e" ]] || vacios+=" correo"
    [[ -z "$vacios" ]] || aviso "Aún sin rellenar:${vacios}. El aviso legal dirá «pendiente» en esos datos hasta que los pongas (bash install.sh --titular)."
}

verificar() {
    titulo "Comprobando que funciona"
    local salud=""
    for _ in 1 2 3 4 5; do
        salud="$(curl -s -m 10 "http://127.0.0.1:${PUERTO}/salud" || true)"
        [[ "$salud" == "ok" ]] && break
        sleep 1
    done
    if [[ "$salud" != "ok" ]]; then
        aviso "/salud no responde «ok». Últimas líneas de los registros:"
        tail -n 8 /var/log/nginx/calculadora-importacion.error.log 2>/dev/null | sed 's/^/    nginx: /' || true
        tail -n 8 "$DIR_VAR/php-errores.log" 2>/dev/null | sed 's/^/    php:   /' || true
        fallo "La web instalada no responde. Mira los registros de arriba. Para quitarla:  bash install.sh --desinstalar"
    fi
    ok "http://127.0.0.1:${PUERTO}/salud → ok"
    local portada cab
    portada="$(curl -s -o /dev/null -m 10 -w '%{http_code}' "http://127.0.0.1:${PUERTO}/" || true)"
    if [[ "$portada" == "200" ]]; then ok "Portada: HTTP 200"; else aviso "Portada: HTTP $portada (esperaba 200)"; fi
    cab="$(curl -sI -m 10 "http://127.0.0.1:${PUERTO}/" || true)"
    if grep -qi '^content-security-policy:' <<< "$cab"; then ok "Cabeceras de seguridad presentes (CSP…)"; else aviso "No veo la cabecera Content-Security-Policy"; fi
    local codigo_oculto
    codigo_oculto="$(curl -s -o /dev/null -m 10 -w '%{http_code}' "http://127.0.0.1:${PUERTO}/.git/config" || true)"
    if [[ "$codigo_oculto" == "404" ]]; then ok "/.git/config → 404 (los ficheros ocultos no se sirven)"; else aviso "/.git/config devuelve $codigo_oculto (esperaba 404)"; fi
}

comparar_webs() {
    titulo "Otras webs de la máquina: antes y después"
    snapshot_webs "$TMP/despues.txt"
    if [[ ! -s "$TMP/antes.txt" && ! -s "$TMP/despues.txt" ]]; then
        aviso "No he encontrado otras webs con nombre en nginx para comparar. Compruébalas tú a mano."
        return
    fi
    if diff -u "$TMP/antes.txt" "$TMP/despues.txt" >"$TMP/diff.txt"; then
        while read -r url codigo; do ok "$url → $codigo (igual que antes)"; done < "$TMP/despues.txt"
    else
        printf '%s!!! Algún código HTTP ha cambiado:%s\n' "$ROJO" "$FIN"
        sed 's/^/    /' "$TMP/diff.txt"
        aviso "Revisa si el cambio es normal (una web que tarda en responder puede variar). Si no lo es, quita lo instalado:  bash install.sh --desinstalar"
    fi
}

# ------------------------------------------------------------------ desinstalar
desinstalar() {
    titulo "Desinstalando (solo lo que creó este script)"
    local pool_a_quitar
    V_FPM="$(find /etc/php -mindepth 4 -maxdepth 4 -path "*/fpm/pool.d/${NOMBRE}.conf" -printf '%h\n' 2>/dev/null | sed -E 's#/etc/php/([0-9.]+)/fpm/.*#\1#' | sort -rV | head -1)"
    confirmar "Se quitará el sitio de nginx${V_FPM:+ y el pool de PHP-FPM $V_FPM}. ¿Seguimos?" || fallo "Cancelado."
    rm -f "$NGINX_ENLACE" "$NGINX_SITIO"
    nginx -t >/dev/null 2>&1 || fallo "nginx -t falla sin nuestro sitio: no recargo. Revisa nginx -t."
    recargar nginx
    ok "Sitio de nginx quitado y nginx recargado"
    if [[ -n "$V_FPM" ]]; then
        pool_a_quitar="/etc/php/${V_FPM}/fpm/pool.d/${NOMBRE}.conf"
        rm -f "$pool_a_quitar"
        "php-fpm${V_FPM}" -t >/dev/null 2>&1 || fallo "php-fpm -t falla sin nuestro pool: no recargo. Revisa php-fpm${V_FPM} -t."
        recargar fpm
        ok "Pool de PHP-FPM quitado y PHP-FPM recargado"
    fi
    if [[ $BORRAR_TODO -eq 1 ]]; then
        confirmar "Se borrarán ${DIR_APP} y ${DIR_VAR} (incluidos los cálculos guardados). ¿Seguro?" || fallo "Cancelado."
        rm -rf "$DIR_APP" "$DIR_VAR"
        ok "Código y datos borrados"
    else
        info "El código (${DIR_APP}) y los datos (${DIR_VAR}) se han dejado. Para borrarlos también: bash install.sh --desinstalar --borrar-todo"
    fi
    INSTALACION_COMPLETA=1
}

# ------------------------------------------------------------------ programa principal
if [[ "$MODO" == "desinstalar" ]]; then
    desinstalar
    exit 0
fi
if [[ "$MODO" == "titular" ]]; then
    [[ -d "$DIR_VAR" ]] || fallo "Todavía no está instalado (no existe $DIR_VAR). Instala primero con  bash install.sh"
    paso_titular
    INSTALACION_COMPLETA=1
    exit 0
fi

requisitos
titulo "Códigos HTTP de tus otras webs (antes)"
snapshot_webs "$TMP/antes.txt"
if [[ -s "$TMP/antes.txt" ]]; then
    while read -r url codigo; do info "$url → $codigo"; done < "$TMP/antes.txt"
else
    info "(no he encontrado otras webs con nombre en nginx)"
fi

if [[ "$MODO" == "comprobar" ]]; then
    titulo "Resultado"
    ok "La máquina está lista para instalar (no se ha cambiado nada). Para instalar:  bash install.sh"
    INSTALACION_COMPLETA=1
    exit 0
fi

titulo "Qué voy a hacer"
info "1. Clonar o actualizar el código en   ${DIR_APP}   (propietario: ${DUENO})"
info "2. Crear la carpeta de datos           ${DIR_VAR}   (www-data, 0750)"
info "3. Añadir el pool de PHP-FPM ${V_FPM}        ${POOL}   (php-fpm -t y reload)"
info "4. Añadir el sitio de nginx            ${NGINX_SITIO}  → puerto ${PUERTO}   (nginx -t y reload)"
if [[ $PAQUETES_INSTALADOS -eq 1 ]]; then
    info "(Los paquetes de PHP ya se han instalado en el paso anterior; de aquí en adelante no se instala nada más.)"
fi
info "No se modifica ninguna otra web ni la configuración global."
confirmar "¿Seguimos?" || fallo "Cancelado: no se ha cambiado nada."

paso_codigo
paso_datos
paso_fpm
paso_nginx
INSTALACION_COMPLETA=1            # a partir de aquí lo instalado se queda aunque la comprobación final avise
paso_titular
verificar
comparar_webs

titulo "Listo"
ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
ok "Abre  http://${ip:-IP-DE-LA-VM}:${PUERTO}/  (si no carga desde fuera pero sí con curl en la VM, es el cortafuegos: abrir el puerto es decisión tuya)"
info "Actualizar:      git -C ${DIR_APP} pull      (con el usuario ${DUENO})"
info "Registros:       /var/log/nginx/calculadora-importacion.error.log · ${DIR_VAR}/php-errores.log · ${DIR_VAR}/registro.log"
info "Quitar:          bash install.sh --desinstalar"
