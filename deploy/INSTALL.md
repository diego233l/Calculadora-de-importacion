# Instalar la Calculadora de importación en la VM

Todo son comandos para **copiar y pegar** en la terminal de la VM, en orden (o, más corto, el script `install.sh` de la sección siguiente). Esta guía solo **crea ficheros nuevos** de este proyecto
(`/opt/apps/calculadora-importacion`, `/var/lib/calculadora-importacion`, un sitio de nginx y un pool de PHP-FPM) y usa **`reload`, nunca `restart`**.
No modifica, mueve ni borra nada de las otras webs ni la configuración global de nginx o PHP, y no instala ni actualiza paquetes.
Si algún paso no sale como se describe, **para y cuéntamelo antes de seguir**.

Requisitos que ya debería tener la VM: nginx, PHP-FPM 8.1 o superior con las extensiones `curl`, `dom`, `pdo_sqlite`, `mbstring` y `json`, y `git`.

> Los ficheros de configuración y estos pasos se han probado en un contenedor Ubuntu 24.04 con nginx 1.24 y PHP-FPM 8.3: `nginx -t`, `php-fpm -t`, el socket, los
> permisos de `www-data`, las cabeceras, el bloqueo de rutas (`.git`, `.env`, `data/`, `src/`…), el tamaño máximo de cuerpo y que opcache recoge los cambios sin reiniciar nada.
> En ese contenedor no hay systemd, así que `systemctl reload` no se ha podido ejecutar allí (se arrancaron nginx y php-fpm a mano), y tampoco se ha probado en tu VM
> concreta: por eso el paso 0 es mirar antes de tocar.

---

## Forma rápida: el script `install.sh`

Hace **los pasos 0 a 7 de esta guía** (con las mismas reglas de seguridad) y se puede repetir sin problema. Descárgalo, léelo si quieres, mira cómo está la máquina y, si todo va bien, instala:

```bash
curl -fsSLO https://raw.githubusercontent.com/diego233l/Calculadora-de-importacion/claude/elegant-goldberg-8i18f9/install.sh
bash install.sh --solo-comprobar      # solo mira la máquina (inventario, puerto, extensiones de PHP, códigos HTTP de tus otras webs); no cambia nada
bash install.sh                       # enseña qué va a crear, pregunta «¿Seguimos?» y lo instala
```

(La dirección lleva el nombre de la rama del repositorio; si algún día cambia la rama por defecto, se cambia ahí.) Te pedirá la contraseña de `sudo` y, al final, si quieres escribir los datos del titular.

Qué garantiza el script:

- **Para sin tocar nada** si falta nginx, git, curl, PHP-FPM ≥ 8.1 en marcha o alguna extensión (`curl`, `dom`, `pdo_sqlite`, `mbstring`, `json`), y **si el puerto 8082 está ocupado** (te dice qué programa lo usa; con `PUERTO=8083 bash install.sh` eliges otro).
- **Solo crea** `/opt/apps/calculadora-importacion`, `/var/lib/calculadora-importacion`, un sitio de nginx y un pool de PHP-FPM. No modifica, mueve ni borra nada de las otras webs ni la configuración global, y **no instala paquetes**.
- Hace `php-fpm -t` y `nginx -t` **antes** de cada `reload` (nunca `restart`). Si alguna prueba falla, retira lo que había añadido en esa ejecución y no recarga: tus otras webs siguen igual.
- Apunta el **código HTTP de tus otras webs antes y después** (las saca de la configuración de nginx, por nombre y por puerto, también las que no tienen `server_name`) y te avisa si alguno cambia.
- Es repetible: si ya está instalado, actualiza el código con `git pull` y deja los ficheros iguales si no han cambiado.
- Datos del titular: los pregunta al final, o sin preguntas con `TITULAR="…" NIF="…" DOMICILIO="…" EMAIL="…" bash install.sh --si`. Para cambiarlos más tarde sin reinstalar: `bash install.sh --titular` (Intro conserva el valor actual).
- Si falta PHP-FPM o alguna extensión, **te enseña los paquetes nuevos exactos** que instalaría con `apt` (por simulación) y solo los instala si lo confirmas; se niega si hiciera falta quitar o actualizar algo ya instalado.
- Quitarlo: `bash install.sh --desinstalar` (deja el código y los datos) o `bash install.sh --desinstalar --borrar-todo`.

Se ha probado en un contenedor Ubuntu 24.04 con nginx 1.24, PHP-FPM 8.3 y otras dos webs de ejemplo: instalación completa como usuario con `sudo`, repetición, puerto ocupado (para sin crear nada), fallo de `nginx -t` provocado a propósito (retira lo añadido y no recarga), desinstalación y confirmación interactiva. Aun así, **el primer paso en tu VM sigue siendo `--solo-comprobar`**.

Si prefieres hacerlo a mano, o el script se detiene y quieres entender por qué, los pasos están debajo.

---

## Paso 0 · Mirar cómo está la VM (solo lectura, no cambia nada)

```bash
echo "=== sitios de nginx activos ===";   ls /etc/nginx/sites-enabled/
echo "=== puertos en escucha ===";        sudo ss -tlnp
echo "=== PHP ===";                       php -v | head -1
echo "=== extensiones necesarias ===";    php -m | grep -i -E '^(curl|dom|pdo_sqlite|mbstring|json)$'
echo "=== pools de PHP-FPM ===";          ls /etc/php/*/fpm/pool.d/
echo "=== /opt/apps ===";                 ls /opt/apps/ 2>/dev/null || echo "(no existe todavía)"
echo "=== /var/lib ===";                  ls /var/lib/
```

Qué mirar:

- En «extensiones necesarias» deben salir **cinco** líneas (`curl`, `dom`, `json`, `mbstring`, `pdo_sqlite`). Si falta alguna, no sigas: dímelo (habría que instalar un paquete y eso lo decides tú).
- En «pools» debe haber una carpeta `/etc/php/<versión>/fpm/pool.d/` con tu PHP-FPM. Si no hay ninguna, PHP-FPM no está instalado: dímelo.
- Las **otras dos webs**: apunta el código HTTP que devuelven *ahora*, para comprobar al final que no ha cambiado. Con sus dominios reales:

```bash
# sustituye ejemplo1.com y ejemplo2.com por los dominios de tus otras dos webs (con http:// o https:// como las abres tú)
for u in https://ejemplo1.com https://ejemplo2.com; do printf '%s  ' "$u"; curl -s -o /dev/null -m 10 -w '%{http_code}\n' "$u"; done
```

Apunta los dos números (normalmente `200`, o `301`/`302` si redirigen).

## Paso 1 · Comprobar que el puerto 8082 está libre

```bash
sudo ss -tlnp | grep -E ':8082\s' && echo "!!! 8082 OCUPADO: NO sigas, dime qué sale" || echo "8082 libre: puedes seguir"
```

Si sale «8082 OCUPADO», para: elegimos otro puerto (hay que cambiarlo en `deploy/nginx-calculadora-importacion.conf`) antes de seguir.

## Paso 2 · Descargar el código

El repositorio es público. Este paso crea solo la carpeta del proyecto (si `/opt/apps` no existe, la crea; si existe, no la toca):

```bash
sudo install -d -o "$USER" -g "$(id -gn)" /opt/apps/calculadora-importacion
git clone https://github.com/diego233l/Calculadora-de-importacion.git /opt/apps/calculadora-importacion
git -C /opt/apps/calculadora-importacion log --oneline | head -3
```

El código se descarga en la rama que el repositorio tenga por defecto (hoy `claude/elegant-goldberg-8i18f9`, que es la única); `git pull` seguirá esa misma rama.

Comprueba que el usuario del servidor web (`www-data`) puede entrar hasta `public/index.php`:

```bash
sudo -u www-data test -r /opt/apps/calculadora-importacion/public/index.php && echo "www-data puede leer el código" || namei -m /opt/apps/calculadora-importacion/public/index.php
```

Si falla, `namei` enseña los permisos de cada carpeta del camino; el que no tenga `x` para «otros» es el problema. No cambies permisos de `/opt/apps` sin avisarme (puede ser de otras apps): dime qué sale.

## Paso 3 · Carpeta de datos de la aplicación (cálculos guardados, claves, registros)

La aplicación **nunca escribe en el repositorio**: todo lo que genera va aquí, propiedad de `www-data`:

```bash
sudo install -d -o www-data -g www-data -m 0750 /var/lib/calculadora-importacion
ls -ld /var/lib/calculadora-importacion
```

## Paso 4 · Pool de PHP-FPM propio

Es un fichero **nuevo** (no toca los demás pools). Tiene su propio socket y ahí está `opcache.validate_timestamps=1` (solo para este pool, para que `git pull` se aplique solo):

```bash
V=$(ls -d /etc/php/*/fpm | sed -E 's#/etc/php/([0-9.]+)/fpm#\1#' | sort -V | tail -1); echo "PHP-FPM versión $V"
systemctl is-active "php$V-fpm"          # debe decir: active
sudo cp /opt/apps/calculadora-importacion/deploy/php-fpm-calculadora-importacion.conf "/etc/php/$V/fpm/pool.d/calculadora-importacion.conf"
sudo "php-fpm$V" -t                       # debe acabar en: test is successful
```

Solo si el último comando dice `test is successful`, recarga (recargar es suave: los procesos en curso acaban su petición y los otros pools no se interrumpen):

```bash
V=$(ls -d /etc/php/*/fpm | sed -E 's#/etc/php/([0-9.]+)/fpm#\1#' | sort -V | tail -1)
sudo systemctl reload "php$V-fpm"
ls -l /run/php/calculadora-importacion.sock     # debe existir (srw-rw---- www-data www-data)
```

Si en tu VM hay varias versiones de PHP-FPM y las otras webs usan una concreta, da igual cuál use esta (cada una tiene su propio socket); solo importa que el servicio esté `active`.

## Paso 5 · Sitio de nginx

Otro fichero **nuevo**. Primero se prueba y **solo si la prueba es correcta** se recarga:

```bash
sudo cp /opt/apps/calculadora-importacion/deploy/nginx-calculadora-importacion.conf /etc/nginx/sites-available/calculadora-importacion
sudo ln -s /etc/nginx/sites-available/calculadora-importacion /etc/nginx/sites-enabled/calculadora-importacion
sudo nginx -t
```

- Si `nginx -t` dice `test is successful` → recarga con `sudo systemctl reload nginx`.
- Si dice otra cosa, **no recargues**. Lo más probable: «Address family not supported» (la VM no tiene IPv6: el fichero ya no lo pide, así que no debería salir) o un nombre de zona repetido. Para deshacer lo único que has añadido: `sudo rm /etc/nginx/sites-enabled/calculadora-importacion` y dime qué ha salido.
- Si tu nginx no usa `sites-enabled` (usa `conf.d`), dímelo y lo adaptamos.

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## Paso 6 · Comprobar que funciona (y que las otras webs siguen igual)

```bash
curl -s -i http://127.0.0.1:8082/salud | head -3          # HTTP/1.1 200 OK … y debajo «ok»
curl -s -o /dev/null -w 'portada: %{http_code}\n' http://127.0.0.1:8082/
for u in https://ejemplo1.com https://ejemplo2.com; do printf '%s  ' "$u"; curl -s -o /dev/null -m 10 -w '%{http_code}\n' "$u"; done   # los mismos números que en el paso 0
```

Abre `http://IP-DE-LA-VM:8082/` en el navegador. Si no carga desde fuera pero sí con `curl` en la VM, es el cortafuegos (ufw, grupo de seguridad del proveedor…): abrir el puerto 8082 es decisión tuya y no se hace en esta guía.

## Paso 7 · Datos del titular (aviso legal y privacidad)

Hay que rellenarlos **antes de publicar** la web (mientras estén vacíos, las páginas dicen «pendiente de completar»). Van fuera del repositorio, para que `git pull` nunca choque con ellos. Edita el texto entre comillas y pega:

```bash
sudo -u www-data tee /var/lib/calculadora-importacion/sitio.json >/dev/null <<'EOF'
{
  "titular": "Nombre y apellidos o razón social",
  "nif": "00000000X",
  "domicilio": "Calle, número, código postal, localidad",
  "email": "contacto@tudominio.es"
}
EOF
curl -s http://127.0.0.1:8082/aviso-legal | grep -c "Pendiente"      # 0 cuando está todo rellenado
```

## Actualizar a una versión nueva

Solo esto (es la única operación de actualización; no hay scripts ni releases):

```bash
git -C /opt/apps/calculadora-importacion pull
```

Hazlo con el **mismo usuario** que hizo el `git clone`. Los cambios de código se aplican solos en unos 2 segundos (opcache del pool); los de `data/` se leen en cada petición.
Los cálculos guardados y la configuración están en `/var/lib/calculadora-importacion` y no se tocan.

## Mantenimiento anual

Cada año hay que revisar los datos fiscales (Orden del BOE con los precios medios, ITP de cada comunidad, IVTM de los municipios, tasa de la DGT…). El procedimiento está en el [README](../README.md#actualización-anual-de-los-datos). Se hace en el repositorio, no en la VM: después, un `git pull` aquí.

## Dónde mirar si algo falla

| Qué | Dónde |
|---|---|
| Errores de nginx | `/var/log/nginx/calculadora-importacion.error.log` |
| Peticiones | `/var/log/nginx/calculadora-importacion.access.log` |
| Errores de PHP | `/var/lib/calculadora-importacion/php-errores.log` |
| Sucesos de la aplicación (límites, lecturas fallidas por tipo, términos desconocidos; sin IP ni enlaces) | `/var/lib/calculadora-importacion/registro.log` |

Problemas habituales:

- **502 Bad Gateway** → PHP-FPM no está escuchando en el socket: `ls -l /run/php/calculadora-importacion.sock` y `systemctl status php$V-fpm`.
- **403/404 en todo** → `www-data` no puede leer `/opt/apps/calculadora-importacion/public` (repite la comprobación del paso 2).
- **«No podemos guardar datos ahora mismo» (503)** → `/var/lib/calculadora-importacion` no es de `www-data` o no tiene espacio: `ls -ld /var/lib/calculadora-importacion` y `df -h /var/lib`.
- **«mobile.de no deja que nuestro servidor lea el anuncio»** → es esperable: mobile.de bloquea muchas IPs de servidores y la aplicación **no intenta saltarse el bloqueo**. La persona pega el texto del anuncio (la web se lo explica) y todo lo demás funciona igual.

## Quitarlo del todo

Solo borra lo que creó esta guía (no toca nada más):

```bash
sudo rm /etc/nginx/sites-enabled/calculadora-importacion /etc/nginx/sites-available/calculadora-importacion
sudo nginx -t && sudo systemctl reload nginx
V=$(ls -d /etc/php/*/fpm | sed -E 's#/etc/php/([0-9.]+)/fpm#\1#' | sort -V | tail -1)
sudo rm "/etc/php/$V/fpm/pool.d/calculadora-importacion.conf" && sudo "php-fpm$V" -t && sudo systemctl reload "php$V-fpm"
sudo rm -rf /opt/apps/calculadora-importacion /var/lib/calculadora-importacion      # esto borra también los cálculos guardados
```

## Notas

- **HTTPS y dominio**: la web escucha en `http://…:8082`. Para publicarla con un dominio y HTTPS, lo normal es poner delante un `server` de nginx con certificado (certbot) que haga `proxy_pass http://127.0.0.1:8082`, o cambiar el `listen` por uno con `ssl`. Si pones un proxy delante, la IP que verá la aplicación (para los límites de uso) será la del proxy; dímelo y se ajusta (cabecera `X-Forwarded-For` de confianza). Las cabeceras de seguridad ya están, y HSTS solo se activa cuando se sirve por https.
- **Seguridad del pool**: `open_basedir` limita PHP a la carpeta del proyecto, la de datos y `/tmp`; están desactivadas la subida de ficheros, `allow_url_fopen` y las funciones de ejecución de comandos.
- **Sin secretos en el repositorio**: la clave que firma los formularios se genera sola en `/var/lib/calculadora-importacion/secreto.key` (permisos 0600).
