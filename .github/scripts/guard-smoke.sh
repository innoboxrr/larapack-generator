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

    # La vía sin terminal de una persona (LARAPACK_APPROVER + --approver)
    # también: el filtro de la shell la deja pasar a la guardia porque
    # `--approver` contiene `approve`.
    code=0; run_pre "$form" 'LARAPACK_APPROVER=Ana php vendor/bin/builder larapack:x --approver=Ana' || code=$?
    [ "$code" = 2 ] || fail "[$form] la vía sin terminal debe salir con 2 y salió con $code: $(cat "$WORK/stderr")"
    echo "ok [$form] la vía sin terminal (--approver): 2 (bloquea)"

    run_pre "$form" 'git status' || fail "[$form] una orden cualquiera no debe bloquear (código $?): $(cat "$WORK/stderr")"
    echo "ok [$form] orden cualquiera: 0"

    # El filtro de la shell busca también sin comillas ni barras: la shell
    # quita las de en medio de una palabra, y una continuación de línea la
    # parte por cualquier sitio. Ninguna de estas formas contiene `approve`
    # tal cual.
    for split in "php artisan l:h appr''ove --all" $'php artisan :h appr\\\nove --all'; do
        code=0; run_pre "$form" "$split" || code=$?
        [ "$code" = 2 ] || fail "[$form] «$split» debe salir con 2 y salió con $code: $(cat "$WORK/stderr")"
    done
    echo "ok [$form] aprobar con la palabra partida: 2 (bloquea)"

    code=0; run_pre "$form" "echo '[]' > .larapack/hand\"-\"rolled.lock" || code=$?
    [ "$code" = 2 ] || fail "[$form] redirigir al candado debe salir con 2 y salió con $code: $(cat "$WORK/stderr")"
    echo "ok [$form] redirigir al candado: 2 (bloquea)"
done

# Los hooks de los otros agentes (larapack:skill --agents=all), como los lanza
# cada uno: el comando sale de SU archivo y se ejecuta con bash, con las
# variables que ese agente exporta. El controlador sigue con lógica.
php "$REPO/builder" larapack:skill --agents=all --root="$P" >/dev/null

# $1 = archivo, $2 = evento, $3 = agente. Imprime el comando de LaraPack.
agent_cmd() { php -r '$c=json_decode(file_get_contents($argv[1]),true); foreach($c["hooks"][$argv[2]] as $e) foreach(array_merge([$e["command"]??null,$e["bash"]??null],array_column($e["hooks"]??[],"command")) as $x) if(is_string($x)&&str_contains($x,"--agent=".$argv[3])) { echo $x; exit; }' "$P/$1" "$2" "$3"; }

# $1 = forma, $2 = archivo, $3 = evento, $4 = agente, $5 = entrada. Deja la
# salida estándar en $WORK/stdout y devuelve el código del hook.
run_agent() {
    local dir="$P"
    if [ "$1" = windows ]; then dir="$(winpath "$P")"; fi
    local cmd
    cmd="$(agent_cmd "$2" "$3" "$4")"
    [ -n "$cmd" ] || fail "no hay comando de LaraPack para $4 en $3"
    set +e
    (cd "$P" && printf '%s' "$5" | CURSOR_PROJECT_DIR="$dir" GEMINI_PROJECT_DIR="$dir" bash -c "$cmd" >"$WORK/stdout" 2>"$WORK/stderr")
    local code=$?
    set -e
    return $code
}

json() { php -r 'echo json_encode(json_decode($argv[1], true));' "$1"; }

for form in unix windows; do
    dir="$P"; file="$CONTROLLER"
    if [ "$form" = windows ]; then dir="$(winpath "$P")"; file="$(winpath "$CONTROLLER")"; fi
    jdir="$(php -r 'echo json_encode($argv[1]);' "$dir")"; jfile="$(php -r 'echo json_encode($argv[1]);' "$file")"

    code=0; run_agent "$form" .cursor/hooks.json preToolUse cursor "{\"hook_event_name\":\"preToolUse\",\"cursor_version\":\"2\",\"workspace_roots\":[$jdir],\"tool_name\":\"Shell\",\"tool_input\":{\"command\":\"git commit --no-verify -m x\"}}" || code=$?
    [ "$code" = 2 ] && grep -q '"permission":"deny"' "$WORK/stdout" || fail "[$form] Cursor: --no-verify debe negarse (código $code): $(cat "$WORK/stdout" "$WORK/stderr")"
    code=0; run_agent "$form" .cursor/hooks.json postToolUse cursor "{\"hook_event_name\":\"postToolUse\",\"cursor_version\":\"2\",\"workspace_roots\":[$jdir],\"tool_name\":\"Write\",\"tool_input\":{\"file_path\":$jfile}}" || code=$?
    [ "$code" = 0 ] && grep -q 'additional_context' "$WORK/stdout" && grep -q 'controller-shape' "$WORK/stdout" || fail "[$form] Cursor: la edición debe volver en additional_context (código $code): $(cat "$WORK/stdout" "$WORK/stderr")"
    echo "ok [$form] Cursor: niega --no-verify y devuelve controller-shape"

    code=0; run_agent "$form" .codex/hooks.json PreToolUse codex "{\"hook_event_name\":\"PreToolUse\",\"turn_id\":\"t\",\"cwd\":$jdir,\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"php artisan l:h approve --all\"}}" || code=$?
    [ "$code" = 2 ] || fail "[$form] Codex: aprobar debe salir con 2 y salió con $code: $(cat "$WORK/stderr")"
    code=0; run_agent "$form" .codex/hooks.json PostToolUse codex "{\"hook_event_name\":\"PostToolUse\",\"turn_id\":\"t\",\"cwd\":$jdir,\"tool_name\":\"apply_patch\",\"tool_input\":{\"command\":\"*** Begin Patch\\n*** Update File: src/Http/Controllers/NoteController.php\\n*** End Patch\"}}" || code=$?
    [ "$code" = 0 ] && grep -q '"decision":"block"' "$WORK/stdout" || fail "[$form] Codex: el parche debe volver con decision block (código $code): $(cat "$WORK/stdout" "$WORK/stderr")"
    echo "ok [$form] Codex: bloquea la aprobación y el parche con lógica"

    code=0; run_agent "$form" .gemini/settings.json AfterTool gemini "{\"hook_event_name\":\"AfterTool\",\"cwd\":$jdir,\"tool_name\":\"write_file\",\"tool_input\":{\"file_path\":$jfile}}" || code=$?
    [ "$code" = 2 ] && grep -q 'controller-shape' "$WORK/stderr" || fail "[$form] Gemini: la edición debe salir con 2 (código $code): $(cat "$WORK/stderr")"
    echo "ok [$form] Gemini CLI: AfterTool con lógica: 2"

    code=0; run_agent "$form" .github/hooks/larapack.json preToolUse copilot "{\"sessionId\":\"s\",\"cwd\":$jdir,\"toolName\":\"bash\",\"toolArgs\":\"{\\\"command\\\":\\\"git config core.hooksPath x\\\"}\"}" || code=$?
    [ "$code" = 0 ] && grep -q '"permissionDecision":"deny"' "$WORK/stdout" || fail "[$form] Copilot: cambiar core.hooksPath debe negarse (código $code): $(cat "$WORK/stdout" "$WORK/stderr")"
    echo "ok [$form] Copilot: niega cambiar core.hooksPath"

    code=0; run_agent "$form" .windsurf/hooks.json pre_run_command windsurf "{\"agent_action_name\":\"pre_run_command\",\"tool_info\":{\"command_line\":\"git push --no-verify\",\"cwd\":$jdir}}" || code=$?
    [ "$code" = 2 ] || fail "[$form] Windsurf: push --no-verify debe salir con 2 y salió con $code: $(cat "$WORK/stderr")"
    echo "ok [$form] Windsurf: bloquea push --no-verify"
done

# El hook de git: lo lanza git con su shell (en Windows, la de Git for
# Windows), sea quien sea quien comitee.
printf 'vendor/\n' > "$P/.gitignore"
git -C "$P" init --quiet
git -C "$P" config user.name larapack
git -C "$P" config user.email larapack@example.test
git -C "$P" config commit.gpgsign false
php "$REPO/builder" larapack:skill --hooks --root="$P" >/dev/null
[ "$(git -C "$P" config --get core.hooksPath)" = .githooks ] || fail "larapack:skill --hooks no configuró core.hooksPath"

git -C "$P" add --all
code=0; git -C "$P" commit --quiet -m 'con lógica' </dev/null 2>"$WORK/stderr" || code=$?
[ "$code" != 0 ] && grep -q 'controller-shape' "$WORK/stderr" || fail "el commit con lógica en el controlador debía frenarse (código $code): $(cat "$WORK/stderr")"
echo "ok git: el commit con lógica en el controlador no entra"

cat > "$CONTROLLER" <<'PHP'
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
git -C "$P" add --all
git -C "$P" commit --quiet -m limpio </dev/null 2>"$WORK/stderr" || fail "el commit limpio debía entrar: $(cat "$WORK/stderr")"
echo "ok git: el commit limpio entra"

# El candado sin terminal: como lo comitearía un agente. Si este proceso
# tiene terminal y no hay setsid para quitársela, el hook preguntaría en ella:
# se salta este caso en vez de quedarse esperando.
mkdir -p "$P/.larapack"; printf '[]\n' > "$P/.larapack/hand-rolled.lock"
git -C "$P" add --all
if command -v setsid >/dev/null 2>&1; then
    code=0; setsid -w git -C "$P" commit --quiet -m candado </dev/null 2>"$WORK/stderr" || code=$?
elif ! (exec </dev/tty) 2>/dev/null; then
    code=0; git -C "$P" commit --quiet -m candado </dev/null 2>"$WORK/stderr" || code=$?
else
    code=skip
fi
if [ "$code" = skip ]; then
    echo "-- git: el candado sin terminal no se prueba aquí (hay terminal y no hay setsid)"
else
    [ "$code" != 0 ] && grep -q 'sin una terminal' "$WORK/stderr" || fail "el candado sin terminal no debía entrar (código $code): $(cat "$WORK/stderr")"
    echo "ok git: el candado sin terminal no entra"
fi

# Sin vendor/bin/builder (antes del composer install) el hook no puede fallar
# en cada edición: sale limpio.
rm "$P/vendor/bin/builder"
for form in unix windows; do
    run_edit "$form" || fail "[$form] sin vendor/bin/builder el hook debe salir con 0 (código $?)"
    echo "ok [$form] sin builder: 0"
done

echo "Guardia: todo en orden."
