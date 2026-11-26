#!/usr/bin/env bash
# Prueba de humo de la guardia tal como la lanza Claude Code: el comando del
# hook sale del .claude/settings.json que escribe `larapack:skill --hooks` y se
# ejecuta con bash, con CLAUDE_PROJECT_DIR y el JSON del evento por stdin.
#
# Existe por Windows: Claude Code ejecuta ahí los hooks con Git Bash, y
# CLAUDE_PROJECT_DIR llega como ruta de Windows (C:\...). Las pruebas de PHPUnit
# no pasan por bash ni por esas rutas; esto sí.
#
# Uso: bash .github/scripts/guard-smoke.sh   (desde la raíz del repositorio)
set -euo pipefail

REPO="$(pwd)"
WORK="$(mktemp -d)"
P="$WORK/proyecto"
trap 'rm -rf "$WORK"' EXIT

# En Git Bash, cygpath da la forma que usa Windows; en Linux no existe y la
# ruta se queda como está.
winpath() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else printf '%s' "$1"; fi; }

fail() { echo "FALLO: $*" >&2; exit 1; }

mkdir -p "$P/src/Http/Controllers" "$P/src/Http/Requests/Note" "$P/vendor/bin"
cat > "$P/composer.json" <<'JSON'
{ "name": "acme/notes", "autoload": { "psr-4": { "Acme\\Notes\\": "src/" } } }
JSON

# vendor/bin/builder de mentira: delega en el builder de este repositorio, que
# encuentra su propio vendor/autoload.php.
printf '<?php\nrequire %s;\n' "$(php -r 'echo var_export($argv[1], true);' "$REPO/builder")" > "$P/vendor/bin/builder"

cat > "$P/src/Http/Requests/Note/IndexRequest.php" <<'PHP'
<?php

namespace Acme\Notes\Http\Requests\Note;

use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    public function handle(): array
    {
        return [];
    }
}
PHP

cat > "$P/src/Http/Controllers/NoteController.php" <<'PHP'
<?php

namespace Acme\Notes\Http\Controllers;

use Acme\Notes\Http\Requests\Note\IndexRequest;

class NoteController
{
    public function index(IndexRequest $request)
    {
        return $request->handle();
    }
}
PHP

php "$REPO/builder" larapack:skill --hooks --root="$P" >/dev/null
[ -f "$P/.claude/settings.json" ] || fail "larapack:skill --hooks no escribió .claude/settings.json"

hook() { php -r '$s=json_decode(file_get_contents($argv[1]),true); foreach($s["hooks"][$argv[2]] as $g) foreach($g["hooks"] as $h) if(str_contains($h["command"],"larapack:guard")) { echo $h["command"]; exit; }' "$P/.claude/settings.json" "$1"; }
EDIT_CMD="$(hook PostToolUse)"
[ -n "$EDIT_CMD" ] || fail "no hay hook PostToolUse de larapack:guard"
echo "hook: $EDIT_CMD"

CONTROLLER="$P/src/Http/Controllers/NoteController.php"

# $1 = forma de la ruta (unix|windows). Devuelve el código de salida del hook.
run_edit() {
    local dir="$P" file="$CONTROLLER"
    if [ "$1" = windows ]; then dir="$(winpath "$P")"; file="$(winpath "$CONTROLLER")"; fi
    local payload
    payload="$(php -r 'echo json_encode(["hook_event_name"=>"PostToolUse","tool_name"=>"Write","tool_input"=>["file_path"=>$argv[1]],"cwd"=>$argv[2]]);' "$file" "$dir")"
    set +e
    printf '%s' "$payload" | CLAUDE_PROJECT_DIR="$dir" bash -c "$EDIT_CMD" 2>"$WORK/stderr"
    local code=$?
    set -e
    return $code
}

for form in unix windows; do
    run_edit "$form" || fail "[$form] un controlador limpio no debe bloquear (código $?): $(cat "$WORK/stderr")"
    echo "ok [$form] controlador limpio: 0"
done

# Ahora con lógica en la acción: es exactamente la deriva que la guardia existe
# para frenar.
cat > "$CONTROLLER" <<'PHP'
<?php

namespace Acme\Notes\Http\Controllers;

use Illuminate\Http\Request;

class NoteController
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string']]);

        return response()->json($data);
    }
}
PHP

for form in unix windows; do
    code=0; run_edit "$form" || code=$?
    [ "$code" = 2 ] || fail "[$form] con lógica en el controlador el hook debe salir con 2 y salió con $code: $(cat "$WORK/stderr")"
    grep -q 'controller-shape' "$WORK/stderr" || fail "[$form] el mensaje no nombra controller-shape: $(cat "$WORK/stderr")"
    echo "ok [$form] controlador con lógica: 2 (bloquea)"
done

# El hook de antes de la herramienta: filtra en la shell (case, $(cat), una
# tubería a un grupo { …; }) y sólo entonces llama a PHP. Es la parte con más
# sintaxis de shell, y la que más fácil se rompe en Git Bash.
PRE_CMD="$(hook PreToolUse)"
[ -n "$PRE_CMD" ] || fail "no hay hook PreToolUse de larapack:guard"
echo "hook previo: $PRE_CMD"

# $1 = forma de la ruta, $2 = la orden de Bash que el agente quiere correr.
run_pre() {
    local dir="$P"
    if [ "$1" = windows ]; then dir="$(winpath "$P")"; fi
    local payload
    payload="$(php -r 'echo json_encode(["hook_event_name"=>"PreToolUse","tool_name"=>"Bash","tool_input"=>["command"=>$argv[1]],"cwd"=>$argv[2]]);' "$2" "$dir")"
    set +e
    printf '%s' "$payload" | CLAUDE_PROJECT_DIR="$dir" bash -c "$PRE_CMD" 2>"$WORK/stderr"
    local code=$?
    set -e
    return $code
}

for form in unix windows; do
    code=0; run_pre "$form" 'php vendor/bin/builder larapack:hand-rolled approve --all' || code=$?
    [ "$code" = 2 ] || fail "[$form] aprobar una excepción debe salir con 2 y salió con $code: $(cat "$WORK/stderr")"
    grep -q 'dueño del proyecto' "$WORK/stderr" || fail "[$form] el mensaje no dice quién aprueba: $(cat "$WORK/stderr")"
    echo "ok [$form] aprobar una excepción: 2 (bloquea)"

    run_pre "$form" 'git status' || fail "[$form] una orden cualquiera no debe bloquear (código $?): $(cat "$WORK/stderr")"
    echo "ok [$form] orden cualquiera: 0"
done

# Sin vendor/bin/builder (antes del composer install) el hook no puede fallar
# en cada edición: sale limpio.
rm "$P/vendor/bin/builder"
for form in unix windows; do
    run_edit "$form" || fail "[$form] sin vendor/bin/builder el hook debe salir con 0 (código $?)"
    echo "ok [$form] sin builder: 0"
done

echo "Guardia: todo en orden."
