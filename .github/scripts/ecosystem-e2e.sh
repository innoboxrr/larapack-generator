#!/usr/bin/env bash
# Prueba punta a punta del ecosistema: una aplicación Laravel 13 recién creada,
# convertida en la aplicación base con la ÚLTIMA innoboxrr/laravel-setup
# PUBLICADA, y LaraPack tomado DE ESTE CHECKOUT.
#
# Existe porque el choque entre laravel-setup y larapack 9 lo descubrió un
# cliente y no la CI: laravel-setup pedía `^7.10 || ^8.0` y Composer no dejaba
# instalar la 9.0.0. Ninguna prueba de este repositorio instala el paquete
# como lo instala una aplicación —desde su dist, junto a los paquetes
# publicados del ecosistema—, así que nada podía verlo. Esto sí.
#
# Lo que recorre, en orden, como lo haría quien empieza una aplicación:
#
#    1. composer create-project laravel/laravel (Laravel 13)
#    2. LaraPack desde este checkout, como repositorio `path` con la versión de
#       VERSION, y la última laravel-setup publicada, fijada a esa versión
#    3. php artisan app:setup [--react]
#    4. php artisan app:install --without-build (composer update, migraciones
#       de la aplicación base, sitio de ejemplo, route:json)
#    5. el laraimport de ejemplo (ecosystem-e2e.laraimport.json) añadido al
#       que deja laravel-setup, larapack:validate y larapack:import
#    6. migrate en SQLite y route:json
#    7. npm install y npm run build
#    8. php artisan test: todos los tests generados, incluidos los de
#       autorización de 9.1, ninguno saltado
#    9. larapack:verify --strict --format=json
#   10. larapack:ci --dry-run
#   11. la guardia de los hooks, con el artisan y el vendor/ de la aplicación
#
# Si un paso falla, el script termina con un mensaje que nombra el paquete
# responsable y por qué (un choque de versiones, la ruta de la traza, el
# paquete npm que no resuelve).
#
# Uso (desde la raíz del repositorio; el workflow ecosystem.yml lo invoca así):
#
#   bash .github/scripts/ecosystem-e2e.sh vue
#   bash .github/scripts/ecosystem-e2e.sh react
#
# Variables, todas opcionales:
#
#   ECOSYSTEM_WORK          directorio de trabajo (por omisión, uno temporal).
#                           Se conserva si algo falla, para mirar la aplicación.
#   ECOSYSTEM_KEEP=1        lo conserva también si todo pasa.
#   ECOSYSTEM_LARAPACK_REF  qué commit de este checkout se instala (HEAD). Se
#                           instala con `git archive`, como la dist de
#                           Packagist: lo que no esté comiteado no entra.
#   ECOSYSTEM_LARAPACK_VERSION  la versión con la que se presenta (VERSION).
#   SETUP_CONSTRAINT        qué laravel-setup se prueba (^7.2.1). Se toma la
#                           última estable publicada que la cumpla y se fija a
#                           esa versión exacta; una versión exacta también es
#                           una restricción. Si ninguna la cumple, el script
#                           falla diciéndolo; si hay una más nueva fuera de
#                           ella, lo avisa.
#   ECOSYSTEM_LARAVEL       la restricción del esqueleto (^13.0).
#   ECOSYSTEM_COMPOSER_AUTH el COMPOSER_AUTH para Composer (el workflow pone
#                           ahí el token de GitHub). Ésta y COMPOSER_AUTH se
#                           sacan del entorno al empezar y sólo se le pasan a
#                           Composer y a app:install, que lo lanza: npm y los
#                           tests de la aplicación no las ven.
#
# `larapack:verify --strict` tiene que salir limpio, sin hallazgos esperados ni
# excepciones: un aviso conocido que se deja en rojo tapa al siguiente.
#
# Las lecturas (qué versión, de dónde salió LaraPack, el informe JUnit, el de
# verify) están en ecosystem-e2e-checks.php, que sale con 1 al fallar y tiene
# sus propios tests (tests/Unit/EcosystemE2eChecksTest.php).
#
# Composer se lanza tal cual: lo que cambie cómo descarga (COMPOSER_HOME con
# preferred-install=source en una máquina sin acceso a las dist de GitHub,
# COMPOSER_ALLOW_SUPERUSER) se pone en el entorno, no aquí. CONTRIBUTING.md
# dice cómo correrlo en local.
set -euo pipefail

UI="${1:-${ECOSYSTEM_UI:-vue}}"
case "$UI" in
    vue | react) ;;
    *) echo "Uso: $0 vue|react" >&2; exit 64 ;;
esac

REPO="${GITHUB_WORKSPACE:-$(pwd)}"
[ -f "$REPO/composer.json" ] && [ -f "$REPO/VERSION" ] || { echo "Corre el script desde la raíz de larapack-generator (o define GITHUB_WORKSPACE)." >&2; exit 64; }

FIXTURE="$REPO/.github/scripts/ecosystem-e2e.laraimport.json"
CHECKS="$REPO/.github/scripts/ecosystem-e2e-checks.php"
SETUP_CONSTRAINT="${SETUP_CONSTRAINT:-^7.2.1}"
LARAPACK_REF="${ECOSYSTEM_LARAPACK_REF:-HEAD}"
LARAPACK_VERSION="${ECOSYSTEM_LARAPACK_VERSION:-$(tr -d '[:space:]' < "$REPO/VERSION")}"
LARAVEL="${ECOSYSTEM_LARAVEL:-^13.0}"

if [ -n "${ECOSYSTEM_WORK:-}" ]; then
    WORK="$ECOSYSTEM_WORK"
    mkdir -p "$WORK"
    [ -z "$(ls -A "$WORK")" ] || { echo "ECOSYSTEM_WORK=$WORK no está vacío." >&2; exit 64; }
else
    WORK="$(mktemp -d)"
fi
APP="$WORK/app"
DIST="$WORK/larapack-generator"
LOGS="$WORK/logs"
mkdir -p "$LOGS"

STEP=0
FINISHED=0
on_exit() {
    if [ "$FINISHED" = 1 ] && [ "${ECOSYSTEM_KEEP:-}" != 1 ]; then
        rm -rf "$WORK"
    else
        echo "Directorio de trabajo conservado: $WORK (registros en $LOGS)" >&2
    fi
}
trap on_exit EXIT

# El token sólo le hace falta a Composer. Se saca del entorno para que no lo
# hereden npm install (los postinstall de terceros) ni php artisan test: queda
# en una variable sin exportar y se le pone a Composer en cada llamada.
COMPOSER_AUTH_JSON="${ECOSYSTEM_COMPOSER_AUTH:-${COMPOSER_AUTH:-}}"
unset ECOSYSTEM_COMPOSER_AUTH COMPOSER_AUTH

# Nada de lo que corre aquí puede quedarse esperando una respuesta.
composer() {
    if [ -n "$COMPOSER_AUTH_JSON" ]; then
        COMPOSER_AUTH="$COMPOSER_AUTH_JSON" command composer --no-interaction "$@"
    else
        command composer --no-interaction "$@"
    fi
}
artisan() { (cd "$APP" && php artisan --no-interaction "$@"); }
# Para la orden de artisan que lanza Composer por dentro (app:install).
artisan_with_composer() {
    if [ -n "$COMPOSER_AUTH_JSON" ]; then
        (cd "$APP" && COMPOSER_AUTH="$COMPOSER_AUTH_JSON" php artisan --no-interaction "$@")
    else
        artisan "$@"
    fi
}

annotate() { # [error|warning] título mensaje
    # En Actions, la anotación aparece en el resumen del run; fuera, sólo en
    # stderr. Los dos puntos y las comas cortarían el título.
    local level=error
    case "$1" in error | warning) level="$1"; shift ;; esac
    if [ -n "${GITHUB_ACTIONS:-}" ]; then
        local title="${1//'%'/'%25'}" msg="${2//'%'/'%25'}"
        title="${title//':'/'%3A'}"; title="${title//','/'%2C'}"
        msg="${msg//$'\r'/'%0D'}"; msg="${msg//$'\n'/'%0A'}"
        echo "::$level title=$title::$msg"
    fi
}

# Por qué falló, a partir del registro del paso. No adivina: cita lo que el
# registro dice de cada paquete, y si no dice nada, lo deja en el responsable
# que el paso declara.
diagnose() {
    local log="$1"

    # Composer: «X requires Y C -> found Y[V] but it does not match». Es el
    # choque que este script existe para ver.
    local conflicts
    conflicts="$(grep -E '^\s*- .*(requires|conflicts with|found ).*' "$log" | grep -E 'innoboxrr/|laravel/' | sed 's/^\s*- //' | sort -u | head -n 12 || true)"
    if [ -n "$conflicts" ]; then
        echo "Composer no resuelve. Lo que dice de los paquetes implicados:"
        printf '%s\n' "$conflicts" | sed 's/^/    /'
    fi

    # PHP: qué paquete de innoboxrr aparece en la traza o en el mensaje.
    local php
    php="$(grep -oE 'vendor/innoboxrr/[a-z0-9_-]+' "$log" | sed 's#vendor/##' | sort | uniq -c | sort -rn | head -n 3 || true)"
    if [ -n "$php" ]; then
        echo "Paquetes de innoboxrr que aparecen en el error (veces):"
        printf '%s\n' "$php" | sed 's/^ */    /'
    fi

    # npm: paquetes innoboxrr-* que no resuelven, con conflictos de pares o
    # con imports que rollup no encuentra.
    local npm
    npm="$(grep -E 'ERESOLVE|ETARGET|E404|peer |Could not resolve|failed to resolve|is not exported|Rollup failed' "$log" | grep -oE '(innoboxrr-[a-z0-9-]+|@?[a-z0-9-]+/[a-z0-9-]+@[^ ]+)' | sort -u | head -n 8 || true)"
    if [ -n "$npm" ]; then
        echo "npm señala:"
        printf '%s\n' "$npm" | sed 's/^/    /'
    fi
}

# step "<título>" "<responsable si el registro no dice otra cosa>" orden...
#
# La salida va a la consola y a un registro por paso. Si falla, se diagnostica
# y se termina: un paso posterior no tiene sentido sobre una aplicación a
# medias. Salvo con KEEP_GOING=1, que se pone para las comprobaciones finales,
# independientes entre sí: se apunta el fallo, se sigue, y el script termina
# en rojo al final con todos.
KEEP_GOING=0
FAILED=()
step() {
    local title="$1" owner="$2"
    shift 2
    STEP=$((STEP + 1))
    local log
    log="$LOGS/$(printf '%02d' "$STEP")-$(printf '%s' "$title" | tr -c 'a-zA-Z0-9' '-' | tr -s '-' | cut -c1-40).log"

    echo
    echo "==> [$STEP] $title"
    [ -z "${GITHUB_ACTIONS:-}" ] || echo "::group::[$STEP] $title"

    local code=0
    set +e
    ( set -e; "$@" ) 2>&1 | tee "$log"
    code="${PIPESTATUS[0]}"
    set -e

    [ -z "${GITHUB_ACTIONS:-}" ] || echo "::endgroup::"

    if [ "$code" != 0 ]; then
        local why
        why="$(diagnose "$log")"
        {
            echo
            echo "FALLO en el paso $STEP, «$title» (código $code)."
            echo "Responsable: $owner."
            [ -z "$why" ] || printf '%s\n' "$why"
            echo "Registro completo: $log"
        } >&2
        annotate error "Ecosistema ($UI): $title" "Responsable: $owner."$'\n'"$why"
        [ "$KEEP_GOING" = 1 ] || exit 1
        FAILED+=("$title")
    fi
}

# Una comprobación que no es una orden: si no se cumple, falla con el motivo.
check() {
    local owner="$1" msg="$2"
    echo >&2
    echo "FALLO: $msg" >&2
    echo "Responsable: $owner." >&2
    annotate error "Ecosistema ($UI)" "$msg Responsable: $owner."
    exit 1
}

echo "Ecosistema: Laravel $LARAVEL, interfaz $UI, LaraPack $LARAPACK_VERSION desde $LARAPACK_REF de $REPO"
echo "Trabajo en $WORK"

# --- 1. La aplicación, como la crea cualquiera ------------------------------

step "Laravel $LARAVEL recién creado" "laravel/laravel (el esqueleto) o Composer" \
    composer create-project laravel/laravel "$APP" "$LARAVEL" --no-progress

[ -f "$APP/artisan" ] || check "laravel/laravel" "create-project no dejó artisan en $APP."
grep -q 'DB_CONNECTION=sqlite' "$APP/.env" || check "laravel/laravel" "el esqueleto ya no trae SQLite por omisión en .env; el script lo da por hecho."

# --- 2. LaraPack desde este checkout, laravel-setup publicada ---------------

# Como la dist de Packagist: git archive, que respeta export-ignore. Si algo que
# el paquete necesita en tiempo de ejecución quedara fuera de la dist, aquí se
# ve; con el árbol de trabajo no se vería.
export_larapack() {
    mkdir -p "$DIST"
    git -C "$REPO" archive --format=tar "$LARAPACK_REF" | tar -x -C "$DIST"
}
step "LaraPack $LARAPACK_REF como dist" "este repositorio (git archive)" export_larapack

# La última estable publicada que cumpla $1 (sin $1, la última de todas).
# `composer show` con una restricción ya lista sólo las que la cumplen, y sale
# con error si no hay ninguna.
latest_setup() {
    (cd "$WORK" && composer show --all --format=json innoboxrr/laravel-setup ${1:+"$1"} 2>/dev/null) \
        | php "$CHECKS" pick-version "${1:-*}"
}
SETUP_VERSION="$(latest_setup "$SETUP_CONSTRAINT")" \
    || check "innoboxrr/laravel-setup" "ninguna versión publicada de innoboxrr/laravel-setup cumple SETUP_CONSTRAINT=$SETUP_CONSTRAINT (o Packagist no respondió). Si la que se espera aún no está publicada, el arreglo es publicarla; para probar otra, SETUP_CONSTRAINT."
echo "laravel-setup: $SETUP_VERSION (la última que cumple $SETUP_CONSTRAINT)"

# Que la restricción no deje de probar lo que instala un cliente: si hay una
# laravel-setup más nueva fuera de ella, se avisa (no es un fallo: se probó lo
# que se pidió).
SETUP_NEWEST="$(latest_setup "" || true)"
if [ -n "$SETUP_NEWEST" ] && [ "$SETUP_NEWEST" != "$SETUP_VERSION" ] \
    && [ "$(printf '%s\n%s\n' "$SETUP_VERSION" "$SETUP_NEWEST" | sort -V | tail -n 1)" = "$SETUP_NEWEST" ]; then
    echo "AVISO: la última laravel-setup publicada es $SETUP_NEWEST, fuera de SETUP_CONSTRAINT=$SETUP_CONSTRAINT: no es la que se prueba." >&2
    annotate warning "Ecosistema ($UI): laravel-setup $SETUP_NEWEST sin probar" "SETUP_CONSTRAINT=$SETUP_CONSTRAINT deja fuera la última publicada; se probó $SETUP_VERSION."
fi

# La versión exacta: sin ella, si la última laravel-setup no admitiera este
# LaraPack, Composer bajaría en silencio a una laravel-setup más vieja que sí,
# y la prueba pasaría con lo que ningún cliente instala.
require_ecosystem() {
    cd "$APP"
    composer config repositories.larapack-generator \
        "{\"type\": \"path\", \"url\": \"$DIST\", \"options\": {\"symlink\": false, \"versions\": {\"innoboxrr/larapack-generator\": \"$LARAPACK_VERSION\"}}}"
    composer require --dev --no-progress --with-all-dependencies \
        "innoboxrr/larapack-generator:$LARAPACK_VERSION" \
        "innoboxrr/laravel-setup:$SETUP_VERSION"
}
step "composer require laravel-setup $SETUP_VERSION y LaraPack $LARAPACK_VERSION" \
    "innoboxrr/laravel-setup $SETUP_VERSION: su restricción sobre innoboxrr/larapack-generator no admite $LARAPACK_VERSION, o alguna dependencia de los dos choca" \
    require_ecosystem

# Que el LaraPack instalado sea éste y no el de Packagist. No basta mirarlo
# una vez: app:setup reescribe la restricción de LaraPack en composer.json y
# app:install corre composer update, así que se vuelve a mirar después de cada
# paso que puede reinstalarlo.
assert_installed_from() { # $1 = después de qué
    local why
    why="$(php "$CHECKS" installed-from "$APP/vendor/composer/installed.json" "$LARAPACK_VERSION" 2>&1)" \
        || check "el script (o $1, que cambió el origen de LaraPack)" "después de $1: $why"
    echo "$why"
}
assert_installed_from "composer require"

# --- 3 y 4. La aplicación base -----------------------------------------------

SETUP_FLAGS=()
[ "$UI" = react ] && SETUP_FLAGS+=(--react)

step "app:setup ($UI)" \
    "innoboxrr/laravel-setup (app:setup), o innoboxrr/larapack-generator si el fallo es de larapack:import del User" \
    artisan app:setup "${SETUP_FLAGS[@]}"

# Instala, migra y siembra, sin compilar: la interfaz se compila una vez, con
# los módulos de este laraimport incluidos.
step "app:install --without-build" \
    "innoboxrr/laravel-setup (app:install) o el paquete que nombre Composer" \
    artisan_with_composer app:install --without-build
assert_installed_from "app:install"

# --- 5. Lo que declara quien usa la aplicación ------------------------------

merge_laraimport() {
    php -r '
        $app = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        $add = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
        $app["models"] = [...($app["models"] ?? []), ...$add["models"]];
        $app["controllers"] = [...($app["controllers"] ?? []), ...$add["controllers"]];
        file_put_contents($argv[1], json_encode($app, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    ' "$APP/laraimport.json" "$FIXTURE"
}
[ -f "$APP/laraimport.json" ] || check "innoboxrr/laravel-setup" "app:setup no dejó laraimport.json."
step "laraimport de ejemplo" "el script (ecosystem-e2e.laraimport.json)" merge_laraimport

step "larapack:validate --$UI --strict" \
    "innoboxrr/larapack-generator (validate rechaza el laraimport de laravel-setup o el de ejemplo)" \
    artisan larapack:validate laraimport.json "--$UI" --strict

step "larapack:import --$UI" "innoboxrr/larapack-generator (import)" \
    artisan larapack:import laraimport.json "--$UI"
assert_installed_from "larapack:import"

# --- 6. Base de datos y rutas ------------------------------------------------

step "migrate (SQLite)" "innoboxrr/larapack-generator (las migraciones generadas)" \
    artisan migrate --force

step "route:json" "innoboxrr/routes-to-json o las rutas generadas" \
    artisan route:json

# --- 7. La interfaz ----------------------------------------------------------

step "npm install" "los paquetes npm del ecosistema (innoboxrr-*) o el package.json de innoboxrr/laravel-setup" \
    bash -c "cd '$APP' && npm install --no-audit --no-fund"

step "npm run build" "el módulo $UI que genera innoboxrr/larapack-generator, o la interfaz de innoboxrr/laravel-setup" \
    bash -c "cd '$APP' && npm run build"

# --- 8. Los tests generados --------------------------------------------------

# Sin --no-interaction: `artisan test` pasa a PHPUnit lo que va detrás de su
# nombre, y PHPUnit lo tomaría por el archivo de test.
step "php artisan test" "innoboxrr/larapack-generator (los tests y el código los genera él) sobre la aplicación de innoboxrr/laravel-setup" \
    bash -c "cd '$APP' && php artisan test --log-junit '$WORK/junit.xml'"

# Que se ejecutaran todos los generados, enteros, y que estén los que cierran
# la autorización (9.1). Se cuentan por archivo, los escritos contra los
# ejecutados: ecosystem-e2e-checks.php junit.
junit_check() {
    php "$CHECKS" junit "$WORK/junit.xml" "$APP" \
        user_cada_accion_requiere_sesion \
        user_cada_accion_pregunta_a_la_politica \
        product_cada_accion_requiere_sesion \
        product_cada_accion_pregunta_a_la_politica \
        product_index_pasa_por_su_managed_filter \
        category_index_pasa_por_su_managed_filter \
        con_sesion_pregunta_a_la_autorizacion \
        con_sesion_basta_la_sesion
}
step "los tests generados se ejecutaron" "innoboxrr/larapack-generator (faltan tests generados o se saltan)" junit_check

# --- 9 a 11. La puerta de la CI y la guardia ---------------------------------

# Independientes entre sí: que falle una no dice nada de las otras, así que se
# corren todas. La guardia, la última, es la única que escribe en la aplicación
# (los hooks y la skill), y deja el controlador como estaba.
KEEP_GOING=1

# Limpio, sin excepciones: ni un error ni un aviso. Cada hallazgo dice de
# dónde viene el archivo —idéntico a un stub de laravel-setup, en sus stubs
# pero reescrito, o generado por LaraPack—, que es lo que hace falta para saber
# a qué repositorio va el arreglo (ecosystem-e2e-checks.php verify).
verify_strict() {
    cd "$APP"
    local code=0
    php artisan --no-interaction larapack:verify --strict --format=json > "$WORK/verify.json" || code=$?
    cat "$WORK/verify.json"
    echo
    php "$CHECKS" verify "$WORK/verify.json" "$APP" || code=1
    return "$code"
}
step "larapack:verify --strict --format=json" \
    "el paquete que puso cada archivo señalado (lo dice la lista de arriba); sin hallazgos, innoboxrr/larapack-generator (verify)" \
    verify_strict

ci_dry_run() {
    (cd "$APP" && php artisan --no-interaction larapack:ci --dry-run)
    [ ! -e "$APP/.github/workflows/larapack.yml" ] || { echo "--dry-run escribió .github/workflows/larapack.yml"; return 1; }
}
step "larapack:ci --dry-run" "innoboxrr/larapack-generator (larapack:ci)" ci_dry_run

# guard-smoke.sh prueba el hook con un vendor/bin/builder de mentira en un
# proyecto sin artisan. Aquí es la aplicación de verdad: el hook llama a su
# artisan, que arranca con el LaraPack instalado por Composer.
guard_app() {
    cd "$APP"
    php artisan --no-interaction larapack:skill --hooks >/dev/null
    [ -f .claude/settings.json ] || { echo "larapack:skill --hooks no escribió .claude/settings.json"; return 1; }

    hook() { php -r '$s=json_decode(file_get_contents(".claude/settings.json"),true); foreach($s["hooks"][$argv[1]] as $g) foreach($g["hooks"] as $h) if(str_contains($h["command"],"larapack:guard")) { echo $h["command"]; exit; }' "$1"; }
    local post pre
    post="$(hook PostToolUse)"; pre="$(hook PreToolUse)"
    [ -n "$post" ] && [ -n "$pre" ] || { echo "faltan los hooks de larapack:guard"; return 1; }
    case "$post" in *artisan*) ;; *) echo "en una aplicación el hook debe llamar a artisan: $post"; return 1 ;; esac

    local controller
    controller="$(grep -rl 'class DeskController' app/Http/Controllers | head -n 1)"
    [ -n "$controller" ] || { echo "no se generó DeskController"; return 1; }

    run() { # $1 = evento, $2 = JSON del evento
        set +e
        printf '%s' "$2" | CLAUDE_PROJECT_DIR="$APP" bash -c "$1" 2>"$WORK/guard.stderr"
        local c=$?
        set -e
        return $c
    }
    edit() { php -r 'echo json_encode(["hook_event_name"=>"PostToolUse","tool_name"=>"Write","tool_input"=>["file_path"=>$argv[1]],"cwd"=>$argv[2]]);' "$APP/$controller" "$APP"; }

    local code=0
    run "$post" "$(edit)" || code=$?
    [ "$code" = 0 ] || { echo "el controlador generado bloquea (código $code): $(cat "$WORK/guard.stderr")"; return 1; }
    echo "ok controlador generado: 0"

    cp "$controller" "$WORK/DeskController.php"
    php -r '$f=$argv[1]; $c=file_get_contents($f); $c=preg_replace("/return \\\$request->handle\(\);/", "\$data = \$request->validate([\"q\" => [\"nullable\"]]);\n\n        return response()->json(\$data);", $c, 1, $n); if (! $n) exit(1); file_put_contents($f, $c);' "$controller" \
        || { echo "el controlador generado no tiene la forma esperada (return \$request->handle();)"; return 1; }
    code=0; run "$post" "$(edit)" || code=$?
    cp "$WORK/DeskController.php" "$controller"
    [ "$code" = 2 ] || { echo "con lógica en el controlador el hook debe salir con 2 y salió con $code: $(cat "$WORK/guard.stderr")"; return 1; }
    grep -q 'controller-shape' "$WORK/guard.stderr" || { echo "el bloqueo no nombra controller-shape: $(cat "$WORK/guard.stderr")"; return 1; }
    echo "ok controlador con lógica: 2 (bloquea)"

    local approve
    approve="$(php -r 'echo json_encode(["hook_event_name"=>"PreToolUse","tool_name"=>"Bash","tool_input"=>["command"=>"php artisan larapack:hand-rolled approve --all"],"cwd"=>$argv[1]]);' "$APP")"
    code=0; run "$pre" "$approve" || code=$?
    [ "$code" = 2 ] || { echo "aprobar una excepción debe salir con 2 y salió con $code: $(cat "$WORK/guard.stderr")"; return 1; }
    echo "ok aprobar una excepción: 2 (bloquea)"
}
step "guardia con el artisan de la aplicación" "innoboxrr/larapack-generator (larapack:guard o larapack:skill --hooks)" guard_app

if [ "${#FAILED[@]}" -gt 0 ]; then
    echo >&2
    echo "El ecosistema NO está en orden ($UI). Fallaron:" >&2
    printf '    %s\n' "${FAILED[@]}" >&2
    exit 1
fi

FINISHED=1
echo
echo "Ecosistema en orden: Laravel $LARAVEL + laravel-setup $SETUP_VERSION + LaraPack $LARAPACK_VERSION ($LARAPACK_REF), interfaz $UI."
