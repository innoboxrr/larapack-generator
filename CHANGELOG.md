# Changelog

## 6.0.0

Salto de mayor: el paquete pasa a Laravel 13 y PHP 8.3+, los comandos cambian
de nombre y el módulo de interfaz cambia de sitio. Nada de esto es compatible
con la 5.x.

### Requisitos

- **PHP `^8.3`** (antes `^7.2`).
- **`illuminate/support ^13.0`**, `symfony/console ^7.4 || ^8.0`.
- **`innoboxrr/search-surge ^3.0`** e **`innoboxrr/traits ^1.7`**. Las versiones
  anteriores no declaraban ningún `require`, así que Composer no tenía con qué
  detectar un conflicto.

### Comandos

Todos viven ahora en el espacio `larapack:`:

```
make:full-model   ->  larapack:full-model
json:importer     ->  larapack:import
remove:full-model ->  larapack:remove-full-model
```

En Artisan no se registran con los nombres antiguos porque `make:model`,
`make:policy`, `make:factory` y `make:observer` son de Laravel. El binario
`builder` los mantiene como alias.

Comandos nuevos:

| | |
|---|---|
| `larapack:validate` | Valida un `laraimport.json` sin generar nada. |
| `larapack:schema` | Emite el JSON Schema del contrato. |
| `larapack:verify` | Busca desviaciones respecto al manifiesto. |
| `larapack:skill` | Instala las instrucciones para un agente. |
| `larapack:react-view` | Genera el módulo React de un modelo. |

Todos aceptan `--format=json` y `--root=<ruta>`; los generadores, además,
`--force` y `--dry-run`.

### El contrato

`laraimport.json` es ahora un **JSON Schema versionado**
(`schema/laraimport.schema.json`). Sólo `models[].name`, `props[].name` y
`props[].type` son obligatorios; el resto tiene valor por defecto. El
importador valida —forma y coherencia— **antes de tocar el disco**: un archivo
incompleto ya no deja el proyecto a medio generar.

Se resuelven solos el orden de migración (topológico por claves foráneas) y el
namespace de las relaciones.

`assignments` y `filters` quedan marcadas obsoletas.

### Interfaz

La UI vive dentro del paquete, en `resources/<vue|react>/`, y se publica como
su propio npm. `--vue` y `--react` no son excluyentes.

**`models/<entidad>/index.js` es el mismo archivo en los dos frameworks.**

Las clases CSS salen de un tema (`innoboxrr-form-core`), no de un mixin global:
un formulario generado no lleva ninguna clase.

### Correcciones

- `RequestsTool` reescribía el cuerpo de `rules()` con un `preg_replace` cuyo
  `[^}]+` no equilibra llaves: una regla con cuantificador se llevaba por
  delante `messages()`, `attributes()` y `handle()`.
- El trait de relaciones emitía siempre `use App\Models\X`, una clase que en
  modo paquete no existe.
- Las migraciones salían en el orden del array, así que un `constrained()`
  hacia una tabla creada después hacía fallar `migrate`.
- `larapack:remove-full-model` añadía `ModelView` a la lista y luego iteraba
  otra, así que `--vue` no borraba nunca el módulo.
- Los formularios generados leían `validator.status`, que empieza en `false`:
  el primer envío siempre se descartaba y había que pulsar dos veces.
- Los `sleep` de las migraciones desaparecen; el orden lo garantiza un contador
  monotónico.

### Regenerar

`.larapack/manifest.json` registra cada archivo generado con su hash. Sin
`--force` no se toca nada existente; con `--force` se regenera **salvo** lo que
se haya editado a mano.

#major
