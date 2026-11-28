# Cuadrar rutas y requests con lo que declara el contrato

Paso `{{id}}` · ámbito {{scope}}

**Objetivo.** Lo que verify ve en las rutas, los controladores y las requests
generados coincide con lo que declara `laraimport.json`: ni una acción, ruta o
request de más ni de menos. Se resuelve **en el contrato** (declarándolo o
retirándolo) y regenerando; nunca editando a mano lo generado.

## Lo que marca verify

{{findings}}

Archivos:

{{files}}

## Pasos exactos, según el hallazgo

- **`route-not-declared`: hay una acción, una ruta o una request que el
  modelo no declara.**
  - Si el mensaje dice que **lo generó LaraPack** cuando se declaraba: se
    retiró del contrato a propósito. Regenera (`larapack:import --force`) para
    quitarlo del controlador y de las rutas, y borra su request y su test.
  - Si dice que **alguien lo escribió a mano**: o se declara (una acción CRUD
    en `routes`; otra cosa en `endpoints` del modelo, o en `controllers[]` si
    no tiene modelo) y se regenera, o se retira. Antes de declararlo, mira que
    el nombre de la ruta que generaría sea el que ya usa el front
    (`route_name` lo conserva si tu versión lo trae); si no, es una decisión.
  - Una ruta **sin nombre** en un archivo generado: alguien la añadió a mano.
    Lo mismo: declararla con su endpoint o llevarla a su propio archivo.
- **`endpoint-missing`**: el contrato declara un endpoint y falta algo de lo
  que genera (su request, su operación `<name>Model`, su método de
  política). Regenera; la operación del modelo la escribes tú en el trait
  `Operations` (el mensaje dice el nombre exacto).
- **`route-prefix`**: el prefijo del módulo JS dejó de cuadrar con el
  `->as()` del RouteServiceProvider. Corrige el que se cambió a mano para que
  vuelvan a decir lo mismo; no cambies el nombre de las rutas.

Después:

    larapack:validate --format=json
    larapack:import --dry-run --format=json
    larapack:import --force

## Qué NO hacer

- No borres una ruta que el front llama sin saberlo: búscala por nombre en el
  front antes de retirarla.
- No cambies nombres de ruta ni URIs que el contrato no declare.
- No edites a mano el archivo de rutas ni el controlador generados para que
  verify pase: el siguiente import los vuelve a escribir.
- Si algo de esto se queda así a propósito (una ruta interna que todavía no
  se declara), no lo escondas: pide la excepción
  (`larapack:realign except {{id}} --option reason="…"`) y que la apruebe el
  dueño.

## Riesgo

{{risk}}

## Comprobar

    {{verify}}

    {{check}}

Está hecho cuando {{done_when}}
