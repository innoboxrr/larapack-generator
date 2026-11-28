# {{title}}

Paso `{{id}}` · ámbito {{scope}}

{{description}}

Primero, siempre: `larapack:realign apply {{id}} --dry-run` y después sin
`--dry-run`. La orden mueve cada trait de `Http/Controllers/Concerns` que sólo
usan requests a `Http/Requests/Concerns`, cambia su namespace y reescribe por
tokens cada nombre que lo apunta. Este playbook es para lo que la orden
**salte**: lee su razón.

## Lo que hay que resolver

{{findings}}

## A mano, para cada trait que la orden saltó

1. **«Lo usa un controlador»**: no es este paso. La lógica del trait no va en
   el controlador: llévala a una request base o a un trait de
   `Http/Requests/Concerns`, y deja cada acción en `return $request->handle();`
   (el playbook `controller-shape`).
2. **«lo nombra en una cadena»**: suele ser configuración (un array de
   middleware o de traits por nombre). Cambia la cadena al namespace nuevo en
   el mismo commit en que muevas el trait, y comprueba quién la lee.
3. **«`use` agrupado»**: separa la importación en una por línea y vuelve a
   correr la orden.
4. **«por su nombre corto desde su mismo namespace»**: otro archivo de
   `Http/Controllers/Concerns` lo usa sin importarlo. Muévelos juntos, o añade
   el `use` explícito antes de mover.
5. Mover a mano: el archivo a `Http/Requests/Concerns/<Nombre>.php`, su
   `namespace` a `…\Http\Requests\Concerns`, y cada
   `use …\Http\Controllers\Concerns\<Nombre>;` del proyecto al nuevo. No
   cambies ni un método: el comportamiento no cambia.
6. Corre las pruebas del paquete.

## Riesgo

{{risk}}

## Comprobar

    {{verify}}
    {{check}}

Está hecho cuando {{done_when}}
