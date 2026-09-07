# .assets

Aquí solo quedan el logo y este aviso.

`JSONGeneratorPromt.md` era un prompt de 66 KB que describía el formato del
`laraimport.json` y pegaba el código de siete tools para que un modelo lo
leyera. Era una segunda fuente de verdad, y había divergido: su `ModelViewTool`
seguía generando Vuex en `resources/vue/app/sections/admin/models/`, una ruta y
una librería que el generador ya no usa.

Lo que describía vive ahora en tres sitios que no pueden divergir, porque son
los que el generador ejecuta:

| Qué | Dónde |
|---|---|
| El formato del `laraimport.json` | `schema/laraimport.schema.json`, o `larapack:schema` |
| Cómo usar el generador | `skill/SKILL.md`, o `larapack:skill` |
| Qué hace cada tool | El código de `src/Tools/`, y los tests de `tests/` |

Si necesitas el original, está en el historial de git.
