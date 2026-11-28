# {{title}}

Paso `{{id}}` · ámbito {{scope}}

{{description}}

Primero, siempre: `larapack:realign apply {{id}} --dry-run` y después sin
`--dry-run`. La orden añade a cada `<Modelo>EndpointsTest` las pruebas que
trae hoy la plantilla —sin sesión no se entra, cada acción pregunta a la
política, el listado pasa por su ManagedFilter—, los auxiliares que llaman y
los `use` que necesitan, sin tocar lo que ya hay. Este playbook es para lo que
la orden **salte**: lee su razón.

## Lo que hay que resolver

{{findings}}

## A mano, para cada test que la orden saltó

1. Mira por qué se saltó:
   - **«no se reconoce con certeza»**: el test tiene más de una clase o una
     importación agrupada (`use A\{B, C}`). Sepáralas y vuelve a correr la
     orden: es lo más corto.
   - **«la clase no es la que generaría hoy el contrato»**: el test se movió o
     se renombró. Copia a mano las pruebas de abajo.
   - **«ya importa ese nombre para…»**: dos clases con el mismo nombre corto.
     Importa la de la plantilla con un alias y úsalo en las pruebas que
     copies.
2. Copia las pruebas que faltan de lo regenerado, en
   `.larapack/realign/cache/<ámbito>/<clave>/tests/Feature/Models/<Modelo>EndpointsTest.php`
   (`larapack:realign status --format=json` dice el directorio de cada
   ámbito). Sólo las que terminan en `cada_accion_requiere_sesion`,
   `cada_accion_pregunta_a_la_politica` e `index_pasa_por_su_managed_filter`,
   y los auxiliares privados que llamen (`signInApplicationUser()`).
3. **No borres ni reescribas** lo que el test ya tenía: es del proyecto.
4. Corre el test. Si una prueba nueva falla, **no la quites ni la relajes**:
   destapa una acción que responde sin sesión o que no pregunta a su
   política. Eso se arregla en la request o en la ruta (y es una decisión si
   cambia lo que ve el cliente).

## Riesgo

{{risk}}

## Comprobar

    {{verify}}
    {{check}}

Está hecho cuando {{done_when}}
